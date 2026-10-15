<?php
/**
 * MdViewerApi - pure parsing/planning for the MD.Viewer machine API.
 *
 * Everything here is deliberately side-effect free: no filesystem, no
 * sockets, no config, no subprocess. The Extensions tab needs to be
 * trustworthy in exactly the situation where it is hardest to debug - a
 * third-party installer answering at the other end of a network call - so
 * the interpretation of that answer is isolated from the call itself and
 * covered by an offline test (tests/ExtensionsApiTest.php).
 *
 * The contract it understands (MD.Viewer updater.php >= 3.9.0):
 *   GET  updater.php?api_key=<KEY>&action=status|conflicts&format=json
 *   POST updater.php?api_key=<KEY>&action=install&format=json[&dry_run=1][&force=1]
 *   {"ok":bool,"action":"...","version":"...","steps":[...],"files":[...],
 *    "conflicts":[...],"errors":[...],"counts":{...},"dry_run":bool,"force":bool}
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-26
 */
declare(strict_types=1);

namespace App\Extensions;

use App\Helpers\JsonHelper;

final class MdViewerApi
{
    /** Oldest installer version that exposes the machine JSON API. */
    public const MIN_VERSION = '3.9.0';

    /** Upstream project + the raw file the server proxy pulls. */
    public const REPOSITORY    = 'paulmann/MD.Viewer';
    public const BRANCH        = 'main';
    public const INSTALLER_URL = 'https://raw.githubusercontent.com/paulmann/MD.Viewer/main/updater.php';

    /** sha256 of updater.php 3.9.0, recorded for the operator's peace of mind. */
    public const INSTALLER_SHA256 = '6d38a6270fcc1d4d9fa52dd55db0712f36ceafa3affdce71d25b2723f30b3478';

    public const ERR_EMPTY               = 'empty';
    public const ERR_NON_JSON            = 'non_json';
    public const ERR_UNAUTHORIZED        = 'unauthorized';
    public const ERR_API_KEY_UNAVAILABLE = 'api_key_unavailable';
    public const ERR_UNKNOWN_ACTION      = 'unknown_action';
    public const ERR_METHOD_NOT_ALLOWED  = 'method_not_allowed';
    public const ERR_OLD_INSTALLER       = 'old_installer';
    public const ERR_INSTALLER_MISSING   = 'installer_missing';
    public const ERR_NO_TRANSPORT        = 'no_transport';
    public const ERR_NO_PHP_CLI          = 'no_php_cli';
    public const ERR_NETWORK             = 'network_unreachable';

    /**
     * Version of a PHP file, read from the ` * Version: X.Y.Z` banner the
     * upstream project keeps in the first 1000 bytes. Returns null when the
     * banner is absent - never a guessed value.
     */
    public static function extractVersion(string $body): ?string
    {
        $head = substr($body, 0, 1000);
        if (preg_match('/^\s*(?:\/\/|#|;|\*)?\s*Version\s*[:=]\s*v?(\d+(?:\.\d+){1,3})/mi', $head, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** True when `$version` is a real version and is >= the machine-API floor. */
    public static function supportsMachineApi(?string $version): bool
    {
        if ($version === null || $version === '') {
            return false;
        }
        return version_compare($version, self::MIN_VERSION, '>=');
    }

    /**
     * Decode one raw response body the way the Extensions tab needs it.
     *
     * Never throws and never guesses: a body that is not a JSON object is
     * reported as `format_valid=false` with a short excerpt, so the UI can
     * show the operator what actually came back instead of an empty panel.
     *
     * @return array{format_valid:bool,json:?array<string,mixed>,error:?string,excerpt:string,bytes:int}
     */
    public static function parseResponse(string $raw): array
    {
        $bytes = strlen($raw);
        $trim  = trim($raw);
        if ($trim === '') {
            return ['format_valid' => false, 'json' => null, 'error' => self::ERR_EMPTY, 'excerpt' => '', 'bytes' => $bytes];
        }
        $decoded = JsonHelper::tryDecode($trim, null);
        if (!is_array($decoded)) {
            return [
                'format_valid' => false,
                'json'         => null,
                'error'        => self::ERR_NON_JSON,
                'excerpt'      => self::excerpt($raw),
                'bytes'        => $bytes,
            ];
        }
        return ['format_valid' => true, 'json' => $decoded, 'error' => null, 'excerpt' => '', 'bytes' => $bytes];
    }

    /** The API's own `ok` flag of an already-decoded envelope. */
    public static function isOk(array $json): bool
    {
        return ($json['ok'] ?? false) === true;
    }

    /** The API's own `error` code of an already-decoded envelope ('' when none). */
    public static function errorOf(array $json): string
    {
        $error = $json['error'] ?? '';
        return is_string($error) ? $error : '';
    }

    /** Human wording for every error code this feature can surface. */
    public static function errorLabel(string $error): string
    {
        return match ($error) {
            self::ERR_UNAUTHORIZED        => 'The installer rejected the request: wrong or missing API key.',
            self::ERR_API_KEY_UNAVAILABLE => 'The installer has no usable API_KEY in .md.ini - open it once on the server or rotate the key.',
            self::ERR_UNKNOWN_ACTION      => 'The installer does not recognise this action.',
            self::ERR_METHOD_NOT_ALLOWED  => 'The installer requires POST for this action.',
            'internal_error'              => 'The installer reported an internal error.',
            self::ERR_NON_JSON            => 'The installer did not return JSON - the response format does not match the machine API.',
            self::ERR_EMPTY               => 'The installer returned an empty response.',
            self::ERR_OLD_INSTALLER       => 'The remote installer is older than ' . self::MIN_VERSION . ' - the machine API is unavailable.',
            self::ERR_INSTALLER_MISSING   => 'The installer is not present. Download it first.',
            self::ERR_NO_TRANSPORT        => 'No HTTPS transport is available on the server (enable ext-curl or ext-openssl).',
            // A missing interpreter is a different failure from a missing
            // key, and it must not be reported as one (2026-09-26).
            self::ERR_NO_PHP_CLI          => 'No PHP CLI interpreter was found on the server, so the installer cannot be run at all. Set its path in the extension settings (config.php: MdViewerPhpCli, or the REVOAP_MDV_PHP_CLI environment variable).',
            self::ERR_NETWORK             => 'Could not reach GitHub - the server may have no outbound network access.',
            ''                            => 'Unknown installer error.',
            default                       => $error,
        };
    }

    /**
     * Normalise the `conflicts` array of a conflicts/install answer into
     * table rows. Every entry the installer sent is kept, including the
     * `ok` ones, so the operator sees the whole manifest.
     *
     * @param  array<string,mixed> $json
     * @return list<array{path:string,status:string,status_label:string,existing_path:string,size_bytes:int,managed:bool,note:string}>
     */
    public static function conflictRows(array $json): array
    {
        $rows = [];
        $list = is_array($json['conflicts'] ?? null) ? $json['conflicts'] : [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $status = is_string($entry['status'] ?? null) ? (string)$entry['status'] : 'unknown';
            $managed = ($entry['managed'] ?? false) === true;
            $rows[] = [
                'path'          => (string)($entry['path'] ?? ''),
                'status'        => $status,
                'status_label'  => self::statusLabel($status),
                'existing_path' => (string)($entry['existing_path'] ?? ''),
                'size_bytes'    => (int)($entry['size_bytes'] ?? 0),
                'managed'       => $managed,
                'note'          => self::conflictNote($status, $managed),
            ];
        }
        return $rows;
    }

    /**
     * Counts for the conflict report. Prefers the installer's own `counts`
     * block and falls back to counting the rows - so a truncated/old answer
     * still shows a truthful total instead of a hard-coded zero.
     *
     * @param  array<string,mixed> $json
     * @return array{total:int,ok:int,exists:int,same_name_elsewhere:int,clashes:int}
     */
    public static function conflictCounts(array $json): array
    {
        $counts = is_array($json['counts'] ?? null) ? $json['counts'] : null;
        if ($counts === null) {
            $ok = $exists = $elsewhere = 0;
            foreach (self::conflictRows($json) as $row) {
                if ($row['status'] === 'ok')                  { $ok++; }
                elseif ($row['status'] === 'exists')          { $exists++; }
                elseif ($row['status'] === 'same_name_elsewhere') { $elsewhere++; }
            }
            return [
                'total'               => $ok + $exists + $elsewhere,
                'ok'                  => $ok,
                'exists'              => $exists,
                'same_name_elsewhere' => $elsewhere,
                'clashes'             => $exists + $elsewhere,
            ];
        }
        $ok       = (int)($counts['ok'] ?? 0);
        $exists   = (int)($counts['exists'] ?? 0);
        $elsewhere = (int)($counts['same_name_elsewhere'] ?? 0);
        $total    = (int)($counts['total'] ?? ($ok + $exists + $elsewhere));
        return [
            'total'               => $total,
            'ok'                  => $ok,
            'exists'              => $exists,
            'same_name_elsewhere' => $elsewhere,
            'clashes'             => $exists + $elsewhere,
        ];
    }

    /**
     * Turn one install answer into a plan/result table plus its summary.
     *
     * Works for both the `dry_run=1` plan and the real run: a dry-run row is
     * `would_write` and a real row is `created`/`updated`/`force-updated`/
     * `current`/`skipped`/`error`, and both are bucketed into
     * create / update / skip / error so the two steps read the same way.
     *
     * @param  array<string,mixed> $json
     * @return array{
     *   rows:list<array{path:string,action:string,action_label:string,decision:string,status:string,status_label:string,from_version:string,version:string,existing_path:string,error:string}>,
     *   summary:array{create:int,update:int,skip:int,error:int,total:int,written:?int,skipped:?int,errors:?int},
     *   steps:list<array{step:string,status:string,detail:string,ms:int}>,
     *   warnings:list<string>
     * }
     */
    public static function installPlan(array $json): array
    {
        $rows = [];
        $create = $update = $skip = $error = 0;
        $files = is_array($json['files'] ?? null) ? $json['files'] : [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $decision = is_string($file['decision'] ?? null) ? (string)$file['decision'] : '';
            $status   = is_string($file['status'] ?? null) ? (string)$file['status'] : '';
            $action   = self::bucket($decision, $status);
            if ($action === 'error')      { $error++; }
            elseif ($action === 'skip')   { $skip++; }
            elseif ($action === 'update') { $update++; }
            else                          { $create++; }
            $rows[] = [
                'path'          => (string)($file['path'] ?? ''),
                'action'        => $action,
                'action_label'  => self::bucketLabel($action),
                'decision'      => $decision,
                'status'        => $status,
                'status_label'  => self::statusLabel($status),
                'from_version'  => (string)($file['from_version'] ?? ''),
                'version'       => (string)($file['version'] ?? ''),
                'existing_path' => (string)($file['existing_path'] ?? ''),
                'error'         => (string)($file['error'] ?? ''),
            ];
        }

        $counts  = is_array($json['counts'] ?? null) ? $json['counts'] : [];
        return [
            'rows'    => $rows,
            'summary' => [
                'create'  => $create,
                'update'  => $update,
                'skip'    => $skip,
                'error'   => $error,
                'total'   => count($rows),
                'written' => array_key_exists('written', $counts) ? (int)$counts['written'] : null,
                'skipped' => array_key_exists('skipped', $counts) ? (int)$counts['skipped'] : null,
                'errors'  => array_key_exists('errors', $counts) ? (int)$counts['errors'] : null,
            ],
            'steps'    => self::steps($json),
            'warnings' => self::warnings($json),
        ];
    }

    /**
     * Normalise the `steps` array into display records.
     *
     * @param  array<string,mixed> $json
     * @return list<array{step:string,status:string,detail:string,ms:int}>
     */
    public static function steps(array $json): array
    {
        $out  = [];
        $list = is_array($json['steps'] ?? null) ? $json['steps'] : [];
        foreach ($list as $step) {
            if (!is_array($step)) {
                continue;
            }
            $out[] = [
                'step'   => (string)($step['step'] ?? ''),
                'status' => (string)($step['status'] ?? ''),
                'detail' => (string)($step['detail'] ?? ''),
                'ms'     => (int)($step['ms'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Warnings the operator should read before confirming a real install:
     * a foreign file at an occupied path, or a same-named file living
     * elsewhere in the tree.
     *
     * @param  array<string,mixed> $json
     * @return list<string>
     */
    public static function warnings(array $json): array
    {
        $warnings = [];
        $list     = is_array($json['conflicts'] ?? null) ? $json['conflicts'] : [];
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $path   = (string)($entry['path'] ?? '');
            $status = (string)($entry['status'] ?? '');
            $managed = ($entry['managed'] ?? false) === true;
            if ($status === 'same_name_elsewhere') {
                $warnings[] = 'A file named "' . $path . '" already exists at "'
                    . (string)($entry['existing_path'] ?? '') . '" - the installer keeps it and writes its own copy.';
            } elseif ($status === 'exists' && !$managed) {
                $warnings[] = 'The path "' . $path . '" is occupied by a file that is not part of MD.Viewer.'
                    . ' It will be skipped unless force is enabled.';
            }
        }
        return $warnings;
    }

    /**
     * Fold an `action=status` answer into the fields the state card shows.
     *
     * @param  array<string,mixed> $json
     * @return array{installed:bool,updater_version:string,version:string,tracked:int,present:int,missing:int,api_install:bool}
     */
    public static function statusSummary(array $json): array
    {
        $files  = is_array($json['files'] ?? null) ? $json['files'] : [];
        $counts = is_array($json['counts'] ?? null) ? $json['counts'] : [];
        $ini    = is_array($json['ini'] ?? null) ? $json['ini'] : [];
        return [
            'installed'       => ($json['installed'] ?? false) === true,
            'updater_version' => (string)($json['updater_version'] ?? ''),
            'version'         => (string)($json['version'] ?? ''),
            'tracked'         => (int)($counts['tracked'] ?? count($files)),
            'present'         => (int)($counts['present'] ?? 0),
            'missing'         => (int)($counts['missing'] ?? 0),
            'api_install'     => ($ini['api_install'] ?? false) === true,
        ];
    }

    /** Bucket one install row into create / update / skip / error. */
    private static function bucket(string $decision, string $status): string
    {
        if (in_array($status, ['error', 'blocked'], true)) {
            return 'error';
        }
        if ($status === 'current' || $status === 'skipped' || $decision === 'skipped_conflict') {
            return 'skip';
        }
        if ($decision === 'update' || $decision === 'overwrite_forced') {
            return 'update';
        }
        return 'create';
    }

    private static function bucketLabel(string $action): string
    {
        return match ($action) {
            'update' => 'Update',
            'skip'   => 'Skip',
            'error'  => 'Error',
            default  => 'Create',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'ok'                   => 'Free',
            'exists'               => 'Path occupied',
            'same_name_elsewhere'  => 'Name used elsewhere',
            'would_write'          => 'Would write',
            'created'              => 'Created',
            'updated'              => 'Updated',
            'force-updated'        => 'Force-updated',
            'current'              => 'Up to date',
            'skipped'              => 'Skipped',
            'present'              => 'Present',
            'missing'              => 'Missing',
            'error'                => 'Error',
            'blocked'              => 'Blocked',
            default                => $status === '' ? '' : $status,
        };
    }

    private static function conflictNote(string $status, bool $managed): string
    {
        return match ($status) {
            'ok'                  => '',
            'exists'              => $managed ? 'Existing MD.Viewer file' : 'Foreign file at the target path',
            'same_name_elsewhere' => 'A file with this name exists elsewhere',
            default               => '',
        };
    }

    /** Short, control-char-free excerpt of a non-JSON body for display. */
    private static function excerpt(string $raw): string
    {
        $flat = (string)preg_replace('/\s+/', ' ', $raw);
        $flat = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $flat);
        $flat = trim($flat);
        // Invalid UTF-8 must not turn the excerpt into a false - fall back to
        // a byte slice; the caller renders it as plain text either way.
        $cut = @mb_substr($flat, 0, 500, 'UTF-8');
        return is_string($cut) ? $cut : substr($flat, 0, 500);
    }
}
