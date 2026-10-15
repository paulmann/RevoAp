<?php
/**
 * SettingsPersist: one honest save path for the admin settings endpoints.
 *
 * Why this exists: `POST /api/settings/models`, `/api/settings/providers`,
 * `/api/analyzer/settings` and friends used to answer `{"ok":true}` as
 * soon as they had *called* the writer. `RuntimeConfig::write()` can fail
 * (a file left owned by root after a manual edit is the reported case)
 * and `file_put_contents()` only returns `false` — so the API said
 * "Saved" while nothing was stored, and the very next page load re-read
 * the old file and painted the shipped defaults. The operator saw a save
 * button that "resets to the initial settings".
 *
 * Every endpoint that mutates persistent state now goes through here:
 *
 *   1. {@see RuntimeConfig::updateChecked()} writes, then reads the file
 *      back and compares it byte-for-byte;
 *   2. on success the config tree is reloaded so the rest of the request
 *      sees the new values;
 *   3. on failure the caller gets a 500 response carrying the failing
 *      stage, the target path, the filesystem facts (mode, owner, process
 *      user, free space) and the PHP error text — never a bare "Saved".
 *
 * The response shape is deliberately HTTP, not domain: it is the same
 * body every one of these endpoints returns, and building it in one place
 * is what keeps the error identical across Models, Settings and Analyzer.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Settings;

use App\Core\Config;
use App\Core\Logger;
use App\Core\RuntimeConfig;
use App\Http\Response;

final class SettingsPersist
{
    /** Control word echoed in the failure message. */
    public const ERROR_CODE = 'config_write_failed';

    /**
     * Persist runtime overrides, reload the config, or describe the
     * failure. Never throws for a *write* problem: the caller decides
     * whether to return the error body (all current callers do).
     *
     * @param  array<string,mixed> $fields Dot-notation key => value.
     * @param  string              $context Log/telemetry label, e.g. 'settings.models'.
     * @return array{ok:bool,error:array<string,mixed>|null,response:string|null}
     */
    public static function runtime(array $fields, string $rootDir, string $context): array
    {
        if ($fields === []) {
            return ['ok' => true, 'error' => null, 'response' => null];
        }
        $result = RuntimeConfig::updateChecked($fields);
        if (!$result['ok']) {
            $error = $result['error'] ?? ['error' => self::ERROR_CODE, 'message' => 'The settings were not saved.'];
            Logger::error($context . '.persist_failed', [
                'keys'    => array_keys($fields),
                'stage'   => $error['stage'] ?? null,
                'target'  => $error['target'] ?? null,
                'message' => Logger::sanitizeScalar((string)($error['message'] ?? '')),
                'fs'      => $error['fs'] ?? null,
            ]);
            return ['ok' => false, 'error' => $error, 'response' => self::failureResponse($error, array_keys($fields))];
        }
        // Same-request visibility: the endpoints below re-read the config
        // to echo what is now persisted, so the tree has to reflect it.
        Config::reload($rootDir . '/config.php');
        return ['ok' => true, 'error' => null, 'response' => null];
    }

    /**
     * Write a `.{provider}.key` style secret and report a failure instead
     * of dropping it. Reads the file back, like the settings writer.
     *
     * @return array{ok:bool,error:array<string,mixed>|null,response:string|null}
     */
    public static function secret(string $name, string $value, string $context): array
    {
        $result = RuntimeConfig::writeSecretFileChecked($name, $value);
        if ($result['ok']) {
            return ['ok' => true, 'error' => null, 'response' => null];
        }
        $error = $result['error'] ?? ['error' => self::ERROR_CODE, 'message' => 'The key file was not written.'];
        Logger::error($context . '.secret_persist_failed', [
            'target'  => $name,
            'message' => Logger::sanitizeScalar((string)($error['message'] ?? '')),
            'fs'      => $error['fs'] ?? null,
        ]);
        return ['ok' => false, 'error' => $error, 'response' => self::failureResponse($error, [$name])];
    }

    /**
     * The 500 body every save endpoint returns when the bytes did not
     * land. Deliberately verbose: the operator is the only consumer, and
     * "which file, which stage, which permissions" is the whole point.
     *
     * @param array<string,mixed> $error
     * @param array<int,string>   $keys
     */
    public static function failureResponse(array $error, array $keys = []): string
    {
        $target = (string)($error['target'] ?? RuntimeConfig::FILE_RELATIVE);
        $stage  = (string)($error['stage'] ?? '');
        $detail = (string)($error['message'] ?? 'The file could not be written.');
        return Response::json([
            'ok'       => false,
            'error'    => self::ERROR_CODE,
            'message'  => 'Settings were NOT saved: ' . $detail
                . ' Nothing changed — the previous settings are still in effect.',
            'saved'    => false,
            'target'   => $target,
            'stage'    => $stage,
            'keys'     => array_values($keys),
            'details'  => $error,
        ], 500);
    }

    /**
     * The read side of the same contract: what the file on disk actually
     * contains right now, with no merging and no defaults. The UI compares
     * it against what it sent, which is what makes "Saved." a claim the
     * page can support.
     *
     * @return array{ok:bool,state:string,data:array<string,mixed>,error:array<string,mixed>|null}
     */
    public static function persisted(): array
    {
        $read = RuntimeConfig::readChecked();
        return [
            'ok'    => $read['ok'],
            'state' => $read['state'],
            'data'  => $read['data'],
            'error' => $read['error'],
        ];
    }
}
