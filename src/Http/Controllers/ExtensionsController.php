<?php
/**
 * ExtensionsController: HTTP layer for the admin Extensions tab.
 *
 * Every endpoint requires:
 *   1. `ExtensionsTab=true` in config.php - when false, returns 404
 *      `extensions_disabled` (the matching API surface goes dark too).
 *   2. Admin token (env / .admin.key, presented via header / cookie /
 *      query) - the same gate as Settings / Logs / Security.
 *
 * Endpoints:
 *   GET  /api/extensions/status   - public probe (tab + admin state) plus
 *                                   the local install state. When the admin
 *                                   token is unlocked it also runs the
 *                                   outbound reachability probe, so an
 *                                   anonymous visitor can never make the
 *                                   server call GitHub.
 *   POST /api/extensions/download - server-side proxy: fetch updater.php
 *                                   into extensions/md-viewer/.
 *   GET  /api/extensions/check    - read API_KEY from .md.ini and run
 *                                   `action=conflicts` (read-only).
 *   POST /api/extensions/install  - `dry_run` (default true) returns the
 *                                   plan; `dry_run=false` performs the real
 *                                   install and returns the result. An
 *                                   optional `settings` object is validated
 *                                   and written to .md.ini first, so the run
 *                                   uses the values selected in the tab.
 *   GET  /api/extensions/settings - the .md.ini install menu: the six
 *                                   boolean keys plus the BROWSE_DIR string
 *                                   setting, with labels, defaults, current
 *                                   values, file/key state and whether a PHP
 *                                   CLI interpreter was found.
 *   POST /api/extensions/settings - write the selected values (strict
 *                                   true/false booleans, one validated path,
 *                                   idempotent, key preserved).
 *   POST /api/extensions/rotate-key - generate a new machine API key;
 *                                   requires an explicit `confirm: true`.
 *
 * The API_KEY never appears in any response: it is read and written only
 * inside MdViewerInstaller, travels to the installer in a subprocess
 * environment, and reaches the browser as 'key set / not set' plus a
 * `mdv_....` mask - never as key material. Writing .md.ini here (rather
 * than waiting for a first browser visit) is what makes the install use
 * the operator's values instead of the installer's own defaults.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-26
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\AdminAuth;
use App\Core\Config;
use App\Core\Logger;
use App\Extensions\MdViewerApi;
use App\Extensions\MdViewerIni;
use App\Extensions\MdViewerInstaller;
use App\Helpers\JsonHelper;
use App\Http\Response;

final class ExtensionsController
{
    public function __construct(
        private readonly MdViewerInstaller $installer,
    ) {}

    private function requireAdmin(): ?string
    {
        if (!Config::isExtensionsTabEnabled()) {
            return Response::json([
                'error'   => 'extensions_disabled',
                'message' => 'Extensions tab is disabled in config.php (ExtensionsTab=false).',
            ], 404);
        }
        $gate = AdminAuth::gateState();
        if (!$gate['allowed']) {
            if (!$gate['configured']) {
                return Response::json([
                    'error'   => 'admin_not_configured',
                    'message' => SettingsController::adminNotConfiguredMessage('Extensions tab'),
                    'admin_diagnostics' => AdminAuth::diagnostics(),
                ], 503);
            }
            return Response::json(['error' => 'unauthorized', 'message' => 'admin token required'], 401);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = JsonHelper::tryDecode($raw, []);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Public probe. Always reports the tab/admin state and the local install
     * state; the GitHub reachability probe is only run for an unlocked admin.
     */
    public function status(): string
    {
        $tabEnabled = Config::isExtensionsTabEnabled();
        $gate       = AdminAuth::gateState();
        $state      = $this->installer->state();

        $payload = [
            'tab_enabled'       => $tabEnabled,
            'admin_protection'  => $gate['protected'],
            'admin_configured'  => $gate['configured'],
            'admin_unlocked'    => $tabEnabled && $gate['unlocked'],
            'admin_diagnostics' => AdminAuth::diagnostics(),
            'repository'        => [
                'name'         => 'MD.Viewer',
                'path'         => MdViewerApi::REPOSITORY,
                'branch'       => MdViewerApi::BRANCH,
                'url'          => 'https://github.com/' . MdViewerApi::REPOSITORY,
                'installer_url' => MdViewerApi::INSTALLER_URL,
                'min_api_version' => MdViewerApi::MIN_VERSION,
                'expected_sha256' => MdViewerApi::INSTALLER_SHA256,
            ],
            'directory'  => $state['directory'],
            'transport'  => $state['transport'],
            'installer'  => $state['installer'],
            'instance'   => $state['instance'],
            'ini_present' => $state['ini_present'],
            'network'    => null,
        ];
        // Reachability is operator-only: never let an anonymous GET trigger
        // an outbound request from the server.
        if ($tabEnabled && $gate['unlocked']) {
            $payload['network'] = $this->installer->probeNetwork();
        }
        return Response::json($payload);
    }

    /** Download the installer through the server (browser never hits GitHub). */
    public function download(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $result = $this->installer->download();
        $result['state'] = $this->installer->state();
        return Response::json($result);
    }

    /** Read-only pre-install conflict report (action=conflicts). */
    public function check(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }

        if (!$this->installer->installerPresent()) {
            return Response::json([
                'ok'             => false,
                'error'          => MdViewerApi::ERR_INSTALLER_MISSING,
                'message'        => MdViewerApi::errorLabel(MdViewerApi::ERR_INSTALLER_MISSING),
                'installer'      => ['present' => false, 'version' => null, 'api_supported' => false],
            ]);
        }
        $version = $this->installer->installedVersion();
        if (!MdViewerApi::supportsMachineApi($version)) {
            return Response::json([
                'ok'                => false,
                'error'             => MdViewerApi::ERR_OLD_INSTALLER,
                'message'           => MdViewerApi::errorLabel(MdViewerApi::ERR_OLD_INSTALLER),
                'installer'         => ['present' => true, 'version' => $version, 'api_supported' => false],
                'min_api_version'   => MdViewerApi::MIN_VERSION,
            ]);
        }

        // .md.ini has to exist before the machine API is asked anything:
        // the installer reads it through DOCUMENT_ROOT and falls back to
        // its own defaults (ALLOW_UPDATE = false) for every key it cannot
        // find, which is not what the install menu asked for.
        if (($ini = $this->ensureIni()) !== null) {
            return $ini;
        }

        $call    = $this->installer->callApi('conflicts', [], 'GET', MdViewerInstaller::API_TIMEOUT);
        $payload = $this->shapeConflictCall($call, $version);
        $payload['ini'] = $this->installer->settingsState();
        return Response::json($payload);
    }

    /**
     * Install (or plan the install). `dry_run` defaults to TRUE, so an
     * accidental/empty POST can never mutate the filesystem.
     */
    /**
     * @param array<string,mixed>|null $body Injected by the offline tests
     *        (the CLI SAPI has no `php://input`); null reads the request.
     */
    public function install(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }

        $body ??= $this->readJsonBody();
        $dryRun = !array_key_exists('dry_run', $body) ? true : (bool)$body['dry_run'];
        $force  = (bool)($body['force'] ?? false);

        // The install menu sends the values it shows, so the run uses them
        // and not just the defaults. Same strict check as the settings
        // endpoint (true/false booleans, one validated path) - a rejected
        // value stops the install.
        $selected = $this->collectSettings($body);
        if (!$selected['ok']) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_settings',
                'message' => 'The .md.ini settings were rejected: '
                    . implode('; ', $selected['errors']) . '.',
                'errors'  => $selected['errors'],
            ], 400);
        }

        if (!$this->installer->installerPresent()) {
            return Response::json([
                'ok'      => false,
                'error'   => MdViewerApi::ERR_INSTALLER_MISSING,
                'message' => MdViewerApi::errorLabel(MdViewerApi::ERR_INSTALLER_MISSING),
            ]);
        }
        $version = $this->installer->installedVersion();
        if (!MdViewerApi::supportsMachineApi($version)) {
            return Response::json([
                'ok'      => false,
                'error'   => MdViewerApi::ERR_OLD_INSTALLER,
                'message' => MdViewerApi::errorLabel(MdViewerApi::ERR_OLD_INSTALLER),
                'installer' => ['present' => true, 'version' => $version, 'api_supported' => false],
            ]);
        }

        // .md.ini first, with the selected values and the machine API key the
        // call below is authorised with.
        if (($ini = $this->ensureIni($selected['values'])) !== null) {
            return $ini;
        }

        $timeout = $dryRun ? MdViewerInstaller::API_TIMEOUT : MdViewerInstaller::INSTALL_TIMEOUT;
        $call    = $this->installer->callApi(
            'install',
            ['dry_run' => $dryRun ? '1' : '0', 'force' => $force ? '1' : '0'],
            'POST',
            $timeout
        );
        $payload = $this->shapeInstallCall($call, $version, $dryRun, $force);
        $payload['state'] = $this->installer->state();
        $payload['ini']   = $this->installer->settingsState();
        return Response::json($payload);
    }

    /**
     * GET /api/extensions/settings - the `.md.ini` install menu.
     *
     * Answers everything the panel renders: the six boolean keys and the
     * BROWSE_DIR string setting with their labels, the shipped defaults, the
     * values currently on disk (defaults folded in), whether the file and
     * the machine API key exist, and whether the CLI interpreter the updater
     * needs was found. The key itself is never part of this payload -
     * `key_present` and a mask only.
     */
    public function settings(): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        return Response::json($this->settingsPayload());
    }

    /**
     * POST /api/extensions/settings - write the selected values.
     *
     * Strict: every boolean must be `true`/`false`, the BROWSE_DIR string
     * must survive a single .md.ini line, and every key must be one this
     * install manages; anything else is a 400 naming the offending key.
     * Idempotent: the existing API key is preserved (rotation is its own
     * confirmed action) and identical bytes are not rewritten.
     *
     * @param array<string,mixed>|null $body Injected by the offline tests
     *        (the CLI SAPI has no `php://input`); null reads the request.
     */
    public function saveSettings(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body ??= $this->readJsonBody();

        $selected = $this->collectSettings($body);
        if (!$selected['ok']) {
            return Response::json([
                'ok'      => false,
                'error'   => 'invalid_settings',
                'message' => 'Rejected: ' . implode('; ', $selected['errors']) . '.',
                'errors'  => $selected['errors'],
            ], 400);
        }
        if ($selected['values'] === []) {
            return Response::json([
                'ok'      => false,
                'error'   => 'no_settings',
                'message' => 'No settings were supplied. Send {"settings":{"ALLOW_UPDATE":true,...}}.',
            ], 400);
        }

        $write = $this->installer->writeIni($selected['values']);
        if ($write['ok'] !== true) {
            return Response::json([
                'ok'       => false,
                'error'    => (string)($write['error'] ?? 'write_failed'),
                'message'  => (string)($write['message'] ?? 'The settings were not saved.'),
                'settings' => $this->installer->settingsState(),
            ], 500);
        }

        return Response::json([
            'ok'       => true,
            'action'   => 'settings_saved',
            'created'  => $write['created'],
            'changed'  => $write['changed'],
            'rotated'  => false,
            'message'  => $write['message'],
            'settings' => $write['state'],
            'runtime'  => $this->installer->runtimeState(),
        ]);
    }

    /**
     * POST /api/extensions/rotate-key - generate a new machine API key.
     *
     * Deliberately separate from saveSettings: the panel's own advice is
     * "rotate the key", and rotation must never be a side effect of saving a
     * checkbox. Requires an explicit `confirm: true`, which the panel sends
     * only after the operator confirmed the dialog.
     *
     * @param array<string,mixed>|null $body
     */
    public function rotateKey(?array $body = null): string
    {
        if (($r = $this->requireAdmin()) !== null) { return $r; }
        $body ??= $this->readJsonBody();
        if (($body['confirm'] ?? null) !== true) {
            return Response::json([
                'ok'      => false,
                'error'   => 'confirm_required',
                'message' => 'Key rotation must be confirmed: send {"confirm":true}.',
            ], 400);
        }

        $write = $this->installer->rotateIniKey();
        if ($write['ok'] !== true) {
            return Response::json([
                'ok'       => false,
                'error'    => (string)($write['error'] ?? 'write_failed'),
                'message'  => (string)($write['message'] ?? 'The key was not rotated.'),
                'settings' => $this->installer->settingsState(),
            ], 500);
        }

        // Audit trail only - the file name, never the key.
        Logger::warn('extensions.api_key_rotated', ['file' => MdViewerInstaller::DIR . '/' . MdViewerIni::FILE]);

        return Response::json([
            'ok'       => true,
            'action'   => 'key_rotated',
            'rotated'  => true,
            'changed'  => $write['changed'],
            'message'  => 'A new API key is in place. It is stored in '
                . MdViewerInstaller::DIR . '/' . MdViewerIni::FILE
                . ' and is never sent to the browser - copy it into your external clients.',
            'settings' => $write['state'],
            'runtime'  => $this->installer->runtimeState(),
        ]);
    }

    /**
     * The payload the install menu renders.
     *
     * @return array<string,mixed>
     */
    private function settingsPayload(): array
    {
        return [
            'ok'              => true,
            'keys'            => MdViewerIni::KEYS,
            'labels'          => MdViewerIni::LABELS,
            'defaults'        => MdViewerIni::DEFAULTS,
            'string_keys'     => MdViewerIni::STRING_KEYS,
            'string_defaults' => MdViewerIni::STRING_DEFAULTS,
            'recommended'     => MdViewerIni::RECOMMENDED,
            'settings'        => $this->installer->settingsState(),
            'runtime'         => $this->installer->runtimeState(),
        ];
    }

    /**
     * Pull `settings` out of a request body and validate it strictly.
     *
     * @param  array<string,mixed> $body
     * @return array{ok:bool,values:array<string,bool|string>,errors:list<string>}
     */
    private function collectSettings(array $body): array
    {
        $raw = $body['settings'] ?? null;
        if ($raw === null || $raw === []) {
            return ['ok' => true, 'values' => [], 'errors' => []];
        }
        if (!is_array($raw) || array_is_list($raw)) {
            return ['ok' => false, 'values' => [], 'errors' => ['settings must be an object keyed by setting name (true/false booleans and the BROWSE_DIR string)']];
        }
        return MdViewerIni::validate($raw);
    }

    /**
     * Write .md.ini before the machine API is called, or return the response
     * the operator should get instead. Returns null when the file is in place.
     *
     * @param array<string,bool|string> $values Values selected in the install menu.
     */
    private function ensureIni(array $values = []): ?string
    {
        $write = $values === [] ? $this->installer->ensureIni() : $this->installer->writeIni($values);
        if ($write['ok'] === true) {
            return null;
        }
        return Response::json([
            'ok'       => false,
            'error'    => (string)($write['error'] ?? 'write_failed'),
            'message'  => (string)($write['message'] ?? 'The .md.ini file could not be written.')
                . ' The installer would fall back to its own defaults (ALLOW_UPDATE = false), so nothing was attempted.',
            'settings' => $this->installer->settingsState(),
        ], 500);
    }
    /**
     * Shape an `action=conflicts` call into the payload the table renders.
     *
     * @param  array<string,mixed> $call
     * @return array<string,mixed>
     */
    private function shapeConflictCall(array $call, string $version): array
    {
        if (!$call['format_valid']) {
            return [
                'ok'            => false,
                'format_valid'  => false,
                'error'         => $call['error'],
                'message'       => MdViewerApi::errorLabel((string)$call['error']),
                'raw_excerpt'   => $call['excerpt'],
                'bytes'         => $call['bytes'],
                'installer_version' => $version,
                'timed_out'     => $call['timed_out'],
                'transport'     => MdViewerInstaller::transport(),
            ];
        }
        $json = (array)$call['json'];
        if (!MdViewerApi::isOk($json)) {
            $error = MdViewerApi::errorOf($json);
            return [
                'ok'                => false,
                'format_valid'      => true,
                'error'             => $error,
                'message'           => MdViewerApi::errorLabel($error),
                'installer_version' => (string)($json['version'] ?? $version),
            ];
        }
        return [
            'ok'                => true,
            'format_valid'      => true,
            'action'            => 'conflicts',
            'version'           => (string)($json['version'] ?? $version),
            'dry_run'           => ($json['dry_run'] ?? true) === true,
            'rows'              => MdViewerApi::conflictRows($json),
            'counts'            => MdViewerApi::conflictCounts($json),
            'warnings'          => MdViewerApi::warnings($json),
            'steps'             => MdViewerApi::steps($json),
            'ms'                => $call['ms'],
        ];
    }

    /**
     * Shape an `action=install` call into the plan (dry_run) or the result.
     *
     * @param  array<string,mixed> $call
     * @return array<string,mixed>
     */
    private function shapeInstallCall(array $call, string $version, bool $dryRun, bool $force): array
    {
        if (!$call['format_valid']) {
            return [
                'ok'          => false,
                'format_valid' => false,
                'error'       => $call['error'],
                'message'     => MdViewerApi::errorLabel((string)$call['error']),
                'raw_excerpt' => $call['excerpt'],
                'bytes'       => $call['bytes'],
                'dry_run'     => $dryRun,
                'force'       => $force,
                'timed_out'   => $call['timed_out'],
                'transport'   => MdViewerInstaller::transport(),
            ];
        }
        $json = (array)$call['json'];
        if (!MdViewerApi::isOk($json)) {
            $error = MdViewerApi::errorOf($json);
            return [
                'ok'          => false,
                'format_valid' => true,
                'error'       => $error,
                'message'     => MdViewerApi::errorLabel($error),
                'dry_run'     => $dryRun,
                'force'       => $force,
                'version'     => (string)($json['version'] ?? $version),
            ];
        }
        $plan = MdViewerApi::installPlan($json);
        return [
            'ok'        => true,
            'format_valid' => true,
            'action'    => 'install',
            'dry_run'   => $dryRun,
            'force'     => $force,
            'version'   => (string)($json['version'] ?? $version),
            'rows'      => $plan['rows'],
            'summary'   => $plan['summary'],
            'steps'     => $plan['steps'],
            'warnings'  => $plan['warnings'],
            'errors'    => is_array($json['errors'] ?? null) ? array_values($json['errors']) : [],
            'counts'    => is_array($json['counts'] ?? null) ? $json['counts'] : [],
            'ms'        => $call['ms'],
        ];
    }
}
