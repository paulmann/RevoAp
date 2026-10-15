<?php
/**
 * ErrorDiagnostics: classify and explain database / connector errors.
 *
 * The pipeline surfaces three families of failures to the operator:
 *  - Database errors (PDOException) — connect, select, insert, update.
 *  - Connector errors (ConnectorException) — HTTP 401/402/404/429/5xx etc.
 *  - Other Throwables — generic unexpected errors.
 *
 * In production (`app.debug=false`) we keep messages terse and safe.
 * In debug (`app.debug=true`) we emit structured `error_context` blocks
 * that explain WHERE the error happened (driver/host/port/dbname for DB,
 * provider/host/path for HTTP) and WHY it likely happened (actionable hint).
 *
 * Secrets are NEVER included: passwords, API keys, Authorization headers
 * are stripped (or never read) before any value enters the structured
 * payload. Every public helper is idempotent and pure — no IO, no globals
 * other than Config (read-only).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Core;

use PDOException;
use Throwable;

final class ErrorDiagnostics
{
    /**
     * Build the structured DB error context. Always sanitized, never
     * contains a password.
     *
     * @param  array<string,mixed> $extra Optional caller-supplied fields:
     *                                    - dsn      (string) raw DSN (will be parsed; password stripped)
     *                                    - user     (string) DB user
     *                                    - operation (string) one of: connect|select|insert|update|other
     *                                    - statement (string) optional SQL hint (sanitized)
     * @return array<string,mixed>
     */
    public static function describeDatabaseError(?Throwable $e, array $extra = []): array
    {
        $dsn = (string)($extra['dsn'] ?? Config::get('database.dsn', ''));
        $user = (string)($extra['user'] ?? Config::get('database.user', ''));
        $operation = (string)($extra['operation'] ?? 'other');

        $parsed = self::parseDsn($dsn);

        $sqlState = null;
        $vendorCode = null;
        $message = null;
        $class = null;
        if ($e !== null) {
            $class = (new \ReflectionClass($e))->getShortName();
            $message = Logger::sanitizeScalar($e->getMessage());
            if ($e instanceof PDOException && is_array($e->errorInfo ?? null)) {
                $sqlState = isset($e->errorInfo[0]) ? (string)$e->errorInfo[0] : null;
                $vendorCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : null;
            } else {
                // Some PDOException paths put the SQLSTATE in code; mine the
                // message for the vendor code if errorInfo is unset.
                $code = (int)$e->getCode();
                if ($code > 0) { $vendorCode = $code; }
                if (preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/i', (string)$e->getMessage(), $m) === 1) {
                    $sqlState = $m[1];
                }
                if ($vendorCode === null && preg_match('/\b(1045|1049|2002|2006|2013)\b/', (string)$e->getMessage(), $m2) === 1) {
                    $vendorCode = (int)$m2[1];
                }
            }
        }

        $hint = self::dbHint($vendorCode, $sqlState, $message);

        $context = [
            'category'   => 'database',
            'class'      => $class,
            'operation'  => $operation,
            'driver'     => $parsed['driver'] ?? null,
            'host'       => $parsed['host'] ?? null,
            'port'       => $parsed['port'] ?? null,
            'dbname'     => $parsed['dbname'] ?? null,
            'unix_socket'=> $parsed['unix_socket'] ?? null,
            'user'       => $user !== '' ? $user : null,
            'sqlstate'   => $sqlState,
            'vendor_code'=> $vendorCode,
            'message'    => $message,
            'hint'       => $hint,
        ];
        if (isset($extra['statement']) && is_string($extra['statement']) && $extra['statement'] !== '') {
            $context['statement'] = Logger::sanitizeScalar($extra['statement']);
        }
        // Drop nulls for tidier output.
        return Logger::sanitize(array_filter($context, static fn($v) => $v !== null));
    }

    /**
     * Parse a PDO DSN and return its public components — host, port, dbname,
     * driver, unix_socket. Strips any embedded password. Tolerant of the
     * sqlite/empty cases.
     *
     * @return array<string,string|int|null>
     */
    public static function parseDsn(string $dsn): array
    {
        if ($dsn === '') {
            return ['driver' => null, 'host' => null, 'port' => null, 'dbname' => null, 'unix_socket' => null];
        }
        $driver = null;
        $rest = $dsn;
        if (($colon = strpos($dsn, ':')) !== false) {
            $driver = substr($dsn, 0, $colon);
            $rest = substr($dsn, $colon + 1);
        }
        $out = ['driver' => $driver, 'host' => null, 'port' => null, 'dbname' => null, 'unix_socket' => null];
        if ($driver === 'sqlite') {
            // sqlite::memory: -> rest = ":memory:" ; sqlite:/path/to/file -> rest = "/path/to/file"
            if ($rest === '' || $rest === ':memory:') {
                $out['dbname'] = ':memory:';
            } else {
                $out['dbname'] = '<file>';
            }
            return $out;
        }
        // key=value;key=value …
        $parts = preg_split('/;+/', $rest) ?: [];
        foreach ($parts as $segment) {
            if ($segment === '' || !str_contains($segment, '=')) { continue; }
            [$k, $v] = explode('=', $segment, 2);
            $k = strtolower(trim($k));
            $v = trim($v);
            switch ($k) {
                case 'host':
                    $out['host'] = $v !== '' ? $v : null;
                    break;
                case 'port':
                    $out['port'] = ctype_digit($v) ? (int)$v : null;
                    break;
                case 'dbname':
                case 'database':
                    $out['dbname'] = $v !== '' ? $v : null;
                    break;
                case 'unix_socket':
                case 'socket':
                    $out['unix_socket'] = $v !== '' ? $v : null;
                    break;
                // Intentionally NEVER read 'password' / 'pass' / 'pwd'.
            }
        }
        if ($out['port'] === null && $driver === 'mysql') {
            $out['port'] = 3306;
        }
        if ($out['port'] === null && $driver === 'pgsql') {
            $out['port'] = 5432;
        }
        return $out;
    }

    /**
     * Actionable hint for a DB error code/message. Covers the most common
     * MySQL/MariaDB / PostgreSQL connect-time and transient codes.
     */
    public static function dbHint(?int $vendorCode, ?string $sqlState, ?string $message): ?string
    {
        $msg = strtolower((string)$message);
        switch ($vendorCode) {
            case 2002:
                return 'Cannot reach the database server (connection refused / no such host). '
                     . 'Verify host/port, that mysqld/postgres is running, and that the firewall allows access.';
            case 1045:
                return 'Authentication failed: the user/password combination was rejected. '
                     . 'Check database.user and the password source (config.database.password, env, or .key file).';
            case 1049:
                return 'Unknown database: the dbname in the DSN does not exist. '
                     . 'Create it or fix database.dsn (dbname=…).';
            case 2006:
                return 'MySQL server has gone away — the idle TCP socket was dropped (often by wait_timeout). '
                     . 'The Database wrapper retries once. If this persists, raise wait_timeout or reduce parallel L1.';
            case 2013:
                return 'Lost connection to MySQL during query — network blip or server-side kill. '
                     . 'Same retry path as 2006; investigate slow queries or proxy timeouts if recurrent.';
        }
        if ($sqlState === '28000') {
            return 'Authentication denied. Check user/password.';
        }
        if ($sqlState === 'HY000' && (str_contains($msg, 'gone away') || str_contains($msg, 'lost connection'))) {
            return 'Idle MySQL connection dropped — handled by the Database retry path.';
        }
        if ($sqlState === '08006' || $sqlState === '08001') {
            return 'PostgreSQL connection failure — verify host/port and that the service is reachable.';
        }
        return null;
    }

    /**
     * Build the structured connector error context. Sanitized, never
     * contains an Authorization header or API key.
     *
     * @param  array<string,mixed> $extra Optional caller-supplied fields:
     *                                    - provider (string)
     *                                    - model    (string)
     *                                    - endpoint (string) full URL — host/path are extracted
     *                                    - http_status (int)
     *                                    - request_options (array) sanitized request knobs
     *                                    - request_id (string)
     * @return array<string,mixed>
     */
    public static function describeConnectorError(?Throwable $e, array $extra = []): array
    {
        $provider = isset($extra['provider']) ? (string)$extra['provider'] : null;
        $model    = isset($extra['model']) ? (string)$extra['model'] : null;
        $endpoint = isset($extra['endpoint']) ? (string)$extra['endpoint'] : null;
        $httpStatus = isset($extra['http_status']) ? (int)$extra['http_status'] : null;

        $message = null;
        $class = null;
        if ($e !== null) {
            $class = (new \ReflectionClass($e))->getShortName();
            $message = Logger::sanitizeScalar($e->getMessage());
            if ($httpStatus === null) {
                $httpStatus = self::extractHttpStatus($e->getMessage());
            }
        }

        $endpointParts = $endpoint !== null ? self::parseEndpoint($endpoint) : ['host' => null, 'path' => null, 'scheme' => null];

        $reqOpts = is_array($extra['request_options'] ?? null) ? $extra['request_options'] : [];
        $reqOpts = self::filterRequestOptions($reqOpts);

        $providerErrorCode = isset($extra['provider_error_code']) ? (string)$extra['provider_error_code'] : null;
        $providerErrorMessage = isset($extra['provider_error_message']) ? Logger::sanitizeScalar((string)$extra['provider_error_message']) : null;

        $context = [
            'category'         => 'connector',
            'class'            => $class,
            'provider'         => $provider,
            'model'            => $model,
            'endpoint_host'    => $endpointParts['host'],
            'endpoint_path'    => $endpointParts['path'],
            'endpoint_scheme'  => $endpointParts['scheme'],
            'http_status'      => $httpStatus,
            'provider_error_code'    => $providerErrorCode,
            'provider_error_message' => $providerErrorMessage,
            'request'          => $reqOpts !== [] ? $reqOpts : null,
            'message'          => $message,
            'hint'             => self::connectorHint($httpStatus, $message, $provider, $model),
        ];
        if (isset($extra['request_id']) && is_string($extra['request_id']) && $extra['request_id'] !== '') {
            $context['request_id'] = $extra['request_id'];
        }
        return Logger::sanitize(array_filter($context, static fn($v) => $v !== null && $v !== ''));
    }

    /**
     * Return the host/path/scheme of a URL. Always strips any user-info
     * and query-string secrets defensively.
     *
     * @return array{host:?string,path:?string,scheme:?string}
     */
    public static function parseEndpoint(string $url): array
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return ['host' => null, 'path' => null, 'scheme' => null];
        }
        return [
            'host'   => isset($parts['host']) ? (string)$parts['host'] : null,
            'path'   => isset($parts['path']) ? (string)$parts['path'] : null,
            'scheme' => isset($parts['scheme']) ? (string)$parts['scheme'] : null,
        ];
    }

    /**
     * Strip secret-looking keys from a request-options array and keep
     * only the connector knobs that are useful for diagnosing behaviour.
     *
     * @param  array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function filterRequestOptions(array $opts): array
    {
        $allow = [
            'model', 'reasoning', 'temperature', 'top_p', 'max_tokens',
            'max_completion_tokens', 'stream', 'timeout', 'retry_count',
            'max_retries', 'response_format', 'stop', 'tool_choice',
        ];
        $out = [];
        foreach ($allow as $k) {
            if (array_key_exists($k, $opts)) {
                $out[$k] = $opts[$k];
            }
        }
        return Logger::sanitize($out);
    }

    /**
     * Best-effort extraction of the HTTP status from a connector error
     * message. The standard connector format is `<provider> HTTP <code>: ...`.
     */
    public static function extractHttpStatus(string $message): ?int
    {
        if (preg_match('/HTTP\s+(\d{3})\b/i', $message, $m) === 1) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * The provider's OWN machine-readable error code, dug out of a raw
     * error body.
     *
     * Providers wrap failures in an OpenAI-shaped envelope whose `code`
     * field is the only piece an operator can act on:
     *
     *   {"status":"error","error":{"message":"","code":"MODEL_NOT_FOUND"}}
     *   {"error":{"message":"No endpoints found","code":404}}
     *   {"error":{"type":"invalid_request_error","message":"..."}}
     *
     * Only a *string* code is reported. A numeric one is either the HTTP
     * status again (redundant — it is already in the message) or an HTTP
     * status that disagrees with the transport one, and neither is worth a
     * second, contradictory number in the operator's face. `type` is the
     * fallback when `code` is absent, because OpenAI-compatible providers
     * use the two interchangeably.
     *
     * Pure and never throws: a body that is not JSON, not an array, or not
     * even a body at all simply yields null, which leaves the message
     * exactly as it was before this helper existed.
     *
     * @param string $raw The provider's raw error body (already truncated
     *                    by the caller if it wants a cap).
     */
    public static function providerErrorCodeFromBody(string $raw): ?string
    {
        $decoded = self::decodeBody($raw);
        if ($decoded === null) {
            return null;
        }
        $error = $decoded['error'] ?? null;
        $candidates = [
            is_array($error) ? ($error['code'] ?? null) : null,
            is_string($error) ? $error : null,
            is_array($error) ? ($error['type'] ?? null) : null,
            $decoded['code'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) { continue; }
            $code = trim($candidate);
            // A pure number is the HTTP status restated — skip it.
            if ($code === '' || preg_match('/^\d+$/', $code) === 1) { continue; }
            // Keep it short and printable: this lands in a one-line message.
            $code = preg_replace('/[^\x20-\x7E]/', '', $code) ?? '';
            if ($code === '') { continue; }
            return mb_substr($code, 0, 60);
        }
        return null;
    }

    /**
     * The provider's own error code, dug out of a *connector message*
     * rather than out of a raw body.
     *
     * `connectorHttpMessage()` writes the code into the sentence
     * ("bothub HTTP 404 [MODEL_NOT_FOUND] for model …"), so by the time a
     * `ConnectorException` reaches the pipeline the body is no longer the
     * whole string and {@see self::providerErrorCodeFromBody()} cannot see
     * it. Three sources are tried, in order of trust:
     *
     *   1. the whole message, when it IS a body (a caller that passes the
     *      payload straight through);
     *   2. the bracketed marker this class's own formatter inserts;
     *   3. the first JSON object inside the message — the pre-v1.8.48
     *      layout (`openrouter HTTP 404: {…}`) and anything a custom
     *      connector built by hand.
     *
     * Returns null when none of them yields a non-numeric code, which is
     * the same "say nothing rather than guess" contract the body parser
     * has.
     */
    public static function providerErrorCodeFromMessage(string $message): ?string
    {
        $direct = self::providerErrorCodeFromBody($message);
        if ($direct !== null) {
            return $direct;
        }
        if (preg_match('/\bHTTP\s+\d{3}\s*\[([^\]\r\n]{1,60})\]/', $message, $m) === 1) {
            $code = trim($m[1]);
            if ($code !== '' && preg_match('/^\d+$/', $code) !== 1) {
                return mb_substr($code, 0, 60);
            }
        }
        $start = strpos($message, '{');
        if ($start !== false) {
            return self::providerErrorCodeFromBody(substr($message, $start));
        }
        return null;
    }

    /**
     * The provider's human-readable error message, when the body carries
     * one. Sanitized, single-line, length-capped. Returns null for the
     * bothub shape above where `message` is present but empty — an empty
     * string must not turn into an empty pair of quotes in the UI.
     */
    public static function providerErrorMessageFromBody(string $raw): ?string
    {
        $fromBody = self::errorMessageFromBody($raw);
        if ($fromBody !== null) {
            return $fromBody;
        }
        $start = strpos($raw, '{');
        return $start === false ? null : self::errorMessageFromBody(substr($raw, $start));
    }

    /** @return string|null */
    private static function errorMessageFromBody(string $raw): ?string
    {
        $decoded = self::decodeBody($raw);
        if ($decoded === null) {
            return null;
        }
        $error = $decoded['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null)
            : (is_string($error) ? $error : ($decoded['message'] ?? null));
        if (!is_string($message) || trim($message) === '') {
            return null;
        }
        $clean = Logger::sanitizeScalar(preg_replace('/\s+/', ' ', $message) ?? '');
        return $clean === '' ? null : mb_substr($clean, 0, 200);
    }

    /**
     * The one place a failed provider call is turned into a sentence.
     *
     * Before this helper, a rejected model produced nothing an operator
     * could act on:
     *
     *   bothub HTTP 404: {"status":"error","error":{"message":"","code":"MODEL_NOT_FOUND"}}
     *
     * — a status code, a body, and no indication of WHICH of the selected
     * models the provider refused. The shape below keeps the old prefix
     * (`<provider> HTTP <code>`) intact for every existing consumer —
     * `extractHttpStatus()`, RequestAnalyzer's body-free log excerpt, the
     * `/models` hint matchers in PipelineEngine — and inserts the two facts
     * that were missing:
     *
     *   bothub HTTP 404 [MODEL_NOT_FOUND] for model "gpt-5.6": {"status":…}
     *
     * The bracketed code comes from {@see self::providerErrorCodeFromBody()}
     * and is omitted when the body carries none, so a provider with a plain
     * body still reads exactly as it did before.
     *
     * The body is truncated LAST so the added metadata can never be what
     * pushes the body out of the message.
     */
    public static function connectorHttpMessage(
        string $provider,
        int $code,
        string $raw,
        ?string $model = null,
        int $bodyCap = 500,
    ): string {
        $head = $provider . ' HTTP ' . $code;
        $providerCode = self::providerErrorCodeFromBody($raw);
        if ($providerCode !== null) {
            $head .= ' [' . $providerCode . ']';
        }
        if ($model !== null && trim($model) !== '') {
            $head .= ' for model "' . trim($model) . '"';
        }
        $bodyCap = max(0, $bodyCap);
        $body    = $bodyCap === 0 ? '' : substr($raw, 0, $bodyCap);
        return $body === '' ? $head : $head . ': ' . $body;
    }

    /**
     * Decode a raw error body into an array, tolerating a body that is not
     * JSON at all (an HTML maintenance page, a proxy error, plain text).
     *
     * @return array<string,mixed>|null
     */
    private static function decodeBody(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || ($raw[0] ?? '') !== '{') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Actionable hint for an HTTP status / connector error.
     */
    public static function connectorHint(?int $status, ?string $message, ?string $provider, ?string $model): ?string
    {
        $msg = strtolower((string)$message);
        $prov = $provider ?? 'provider';
        switch ($status) {
            case 401:
                return sprintf('Authentication rejected by %s. Check connectors.%s.api_key, the .%s.key file, or %s_API_KEY.',
                    $prov, $prov, $prov, strtoupper($prov));
            case 402:
                return sprintf('Payment required by %s — your account is out of credit or needs a paid plan for this model.', $prov);
            case 403:
                return sprintf('Forbidden by %s — the API key is valid but lacks access to "%s".', $prov, $model ?? '?');
            case 404:
                return sprintf('Model "%s" was rejected by %s — verify the slug against the provider\'s /models endpoint.', $model ?? '?', $prov);
            case 408:
                return 'Provider timeout — increase connectors.<provider>.timeout_chat or retry the request.';
            case 429:
                return sprintf('Rate-limited by %s — reduce concurrency, raise retry budget, or upgrade your plan.', $prov);
            case 500: case 502: case 503: case 504:
                return sprintf('%s is returning %d — provider-side outage. Retry shortly; the connector backs off automatically.', $prov, $status);
        }
        if (str_contains($msg, 'curl error') || str_contains($msg, 'ssl') || str_contains($msg, 'resolve host')) {
            return sprintf('Network/TLS failure reaching %s. Verify outbound HTTPS, DNS, and the connector base_url.', $prov);
        }
        if (str_contains($msg, 'invalid json')) {
            return sprintf('%s returned a non-JSON body — likely an upstream proxy error or maintenance page.', $prov);
        }
        return null;
    }

    /**
     * Render a one-line, terse, safe message for production debug=false
     * surfaces. Only the category and the hint (if any) are exposed —
     * raw error text is omitted.
     */
    public static function terse(array $context): string
    {
        $category = (string)($context['category'] ?? 'error');
        $hint     = (string)($context['hint'] ?? '');
        if ($hint !== '') {
            return ucfirst($category) . ' error: ' . $hint;
        }
        return ucfirst($category) . ' error.';
    }
}
