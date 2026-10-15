<?php
/**
 * CurlBatchTransport: the production ext-curl transport for the Batch API.
 *
 * Three request shapes are needed on the Batch surface, and they map onto
 * three different curl option sets rather than the single JSON-POST shape
 * {@see \App\Connectors\AbstractConnector::httpJson()} implements:
 *
 *   * JSON           — `POST /batches`, `POST /batches/{id}/cancel`.
 *   * multipart      — `POST /files` (a `.jsonl` upload plus `purpose`).
 *                      Built as a curl field array so curl writes the
 *                      boundary; hand-rolling the body would have to get
 *                      MIME escaping right for arbitrary content.
 *   * raw download   — `GET /files/{id}` returns a JSONL document, not an
 *                      object. The body is handed back verbatim; nothing
 *                      here tries to decode it.
 *
 * ## ext-curl is optional
 *
 * composer declares `ext-curl`, but hardened hosts and minimal PHP builds
 * ship without it, and this is the deployment the test suite runs in. When
 * the extension is missing, `curl_init()` is not a "returns false" failure
 * — the symbol does not exist, so calling it raises a fatal `Error` and
 * takes the process with it. The probe therefore happens before any
 * `curl_*` symbol is touched, and the resulting
 * {@see ConnectorException} names the missing extension and the one-line
 * remedy instead of dying inside the engine. This mirrors the guard in
 * {@see \App\Connectors\ParallelChatDispatcher}.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Connectors\Batch;

use App\Connectors\Exceptions\ConnectorException;
use App\Core\Logger;
use App\Helpers\JsonHelper;

final class CurlBatchTransport implements BatchTransportInterface
{
    /**
     * Test seam. Production leaves this null so the real probe decides;
     * the offline suite pins it to `false` to prove the missing-extension
     * path produces a ConnectorException instead of a fatal `Error`.
     */
    private static ?bool $curlAvailableOverride = null;

    /**
     * Force the availability probe's verdict. `null` restores the real
     * probe. Kept public+static because the override is a property of the
     * process, not of one transport instance.
     *
     * @internal test seam
     */
    public static function setCurlAvailableOverride(?bool $available): void
    {
        self::$curlAvailableOverride = $available;
    }

    /**
     * Is ext-curl usable in this process?
     *
     * Both probes are deliberate: `extension_loaded('curl')` is the
     * authoritative yes/no, while `function_exists('curl_init')`
     * additionally catches a build where the symbol is suppressed via
     * `disable_functions` — there the extension is loaded but the call
     * still throws.
     */
    public static function isAvailable(): bool
    {
        if (self::$curlAvailableOverride !== null) {
            return self::$curlAvailableOverride;
        }
        return extension_loaded('curl') && function_exists('curl_init') && function_exists('curl_exec');
    }

    /**
     * The message used wherever the Batch path cannot run because the
     * extension is absent. One sentence, and it names the remedy — an
     * operator who sees this in the job error should not have to guess.
     */
    public static function unavailableMessage(): string
    {
        return 'The Batch API transport needs the PHP ext-curl extension, which is not available in this '
            . 'process. Enable it (php-curl / php8.x-curl) and restart the worker, then run the batch again.';
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?array $jsonBody = null,
        ?array $multipart = null,
        int $timeout = 60,
    ): BatchHttpResponse {
        if (!self::isAvailable()) {
            throw new ConnectorException(self::unavailableMessage());
        }
        $timeout = max(1, $timeout);
        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectorException('Batch transport: curl_init() failed');
        }
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($multipart !== null) {
            // curl reads CURLFile from disk, so the bytes go through a
            // temporary file. Deleted in the finally block below. The curl
            // field array form (rather than a hand-built body) is what makes
            // curl emit a correct multipart boundary.
            $tmp = tempnam(sys_get_temp_dir(), 'revoap-batch-');
            if ($tmp === false) {
                throw new ConnectorException('Batch transport: cannot create a temporary file for the JSONL upload');
            }
            if (file_put_contents($tmp, $multipart['content']) === false) {
                @unlink($tmp);
                throw new ConnectorException('Batch transport: cannot write the JSONL upload to a temporary file');
            }
            $opts[CURLOPT_POSTFIELDS] = [
                $multipart['field'] => new \CURLFile($tmp, $multipart['content_type'], $multipart['filename']),
            ];
            if ($jsonBody !== null) {
                // Multipart carries extra form fields (purpose=batch) as
                // plain strings alongside the file.
                foreach ($jsonBody as $key => $value) {
                    if (is_scalar($value)) {
                        $opts[CURLOPT_POSTFIELDS][(string)$key] = (string)$value;
                    }
                }
            }
            try {
                return $this->exec($ch, $opts, $method, $url);
            } finally {
                @unlink($tmp);
            }
        }
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = JsonHelper::encode($jsonBody);
        }
        return $this->exec($ch, $opts, $method, $url);
    }

    /**
     * @param array<int,mixed> $opts
     */
    private function exec(\CurlHandle $ch, array $opts, string $method, string $url): BatchHttpResponse
    {
        try {
            curl_setopt_array($ch, $opts);
            $raw  = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }
        if ($raw === false || $err !== '') {
            // Transport-level failure: no HTTP answer at all. Sanitized,
            // because curl error text can quote the URL (which can carry a
            // query string) back at us.
            throw new ConnectorException(sprintf(
                'Batch transport: %s %s failed: %s',
                $method,
                $url,
                Logger::sanitizeScalar($err !== '' ? $err : 'no response'),
            ));
        }
        return new BatchHttpResponse($code, (string)$raw, $url, $method);
    }
}
