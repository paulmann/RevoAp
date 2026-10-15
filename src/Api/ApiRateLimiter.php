<?php
/**
 * ApiRateLimiter: file-backed rate limiter for external API keys.
 *
 * Enforces the per-key `rpm_limit` (requests per rolling minute) and
 * `daily_limit` (requests per UTC calendar day) quotas from the
 * `api_keys` table. State lives in one small JSON file per key under
 * `<base>/key_<id>.json`; the same directory is used by the PHP-FPM
 * pool, which makes the limiter consistent across requests that would
 * otherwise share no in-memory state. Every read-modify-write cycle is
 * guarded by an exclusive flock so concurrent workers cannot double-
 * spend a quota window.
 *
 * Behaviour details:
 *   - A limit of 0 is an explicit total ban for that window;
 *   - When the state file cannot be opened/locked the limiter fails
 *     OPEN (upstream firewalls remain the backstop);
 *   - On success, counters persist and the response carries
 *     remaining/reset values for `X-RateLimit-*` headers.
 *
 * The clock is injectable (setNow) for deterministic tests.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

namespace App\Api;

final class ApiRateLimiter
{
    /** @var callable|null Test seam returning the current unix timestamp. */
    private $now = null;

    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /** Inject a deterministic clock. Accepts fn(): int. */
    public function setNow(?callable $fn): void
    {
        $this->now = $fn;
    }

    /**
     * Register one request and report whether the key still has quota.
     * A rejected request is NOT counted so the client can retry after
     * the window resets without further reducing their allowance.
     *
     * @return array{
     *   allowed:bool,
     *   remaining_minute:int,
     *   minute_reset_at:int,
     *   remaining_daily:int,
     *   retry_after:int|null,
     * }
     */
    public function registerAndCheck(int $keyId, int $rpm, int $daily): array
    {
        $now    = $this->ts();
        $minute = intdiv($now, 60) * 60;
        $day    = gmdate('Y-m-d', $now);
        $file   = $this->path($keyId);

        if ($rpm <= 0 || $daily <= 0) {
            return $this->hardDeny($rpm, $daily, $minute);
        }

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            // Directory unavailable - fail open instead of locking out
            // the API. Proxy-level limits remain the backstop.
            return $this->allowed($rpm, $daily, $minute);
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                fclose($fh);
                return $this->allowed($rpm, $daily, $minute);
            }

            $raw   = stream_get_contents($fh);
            $state = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : [];
            if (!is_array($state)) {
                $state = [];
            }

            $minuteCount = ((int)($state['minute_window'] ?? 0) === $minute)
                ? (int)($state['minute_count'] ?? 0)
                : 0;
            $dayCount = (($state['day'] ?? '') === $day)
                ? (int)($state['day_count'] ?? 0)
                : 0;

            $allowed = $minuteCount < $rpm && $dayCount < $daily;

            if (!$allowed) {
                flock($fh, LOCK_UN);
                fclose($fh);
                $minuteReset = $minute + 60;
                $dayReset    = ((intdiv($now, 86400) + 1) * 86400);
                $retry = $minuteCount >= $rpm
                    ? max(1, $minuteReset - $now)
                    : max(1, $dayReset - $now);
                return [
                    'allowed'          => false,
                    'remaining_minute' => max(0, $rpm - $minuteCount),
                    'minute_reset_at'  => $minuteReset,
                    'remaining_daily'  => max(0, $daily - $dayCount),
                    'retry_after'      => $retry,
                ];
            }

            $minuteCount++;
            $dayCount++;
            $state = [
                'minute_window' => $minute,
                'minute_count'  => $minuteCount,
                'day'           => $day,
                'day_count'     => $dayCount,
            ];
            rewind($fh);
            $ok = ftruncate($fh, 0) && fwrite($fh, (string)json_encode($state)) !== false;
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            return [
                'allowed'          => true,
                'remaining_minute' => max(0, $rpm - $minuteCount),
                'minute_reset_at'  => $minute + 60,
                'remaining_daily'  => max(0, $daily - $dayCount),
                'retry_after'      => null,
            ];
        } catch (\Throwable) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            return $this->allowed($rpm, $daily, $minute);
        }
    }

    /**
     * Register one request against an attacker-visible IP and report
     * whether that IP still has quota.
     *
     * Unlike registerAndCheck() this boundary is SECURITY-relevant
     * (brute-force / flood protection), so unavailable counters fail
     * CLOSED - the request is refused until the directory is writable
     * again (the upstream proxy remains a second layer for availability).
     * A rejected request is NOT counted, mirroring the key quota logic.
     *
     * The IP never lands in the response or audit trail; only an opaque
     * sha256 digest is used for the state filename.
     *
     * @return array{allowed:bool,remaining_minute:int,minute_reset_at:int,retry_after:int|null}
     */
    public function registerAndCheckIp(string $ip, int $rpm): array
    {
        if ($rpm <= 0) {
            return [
                'allowed'          => false,
                'remaining_minute' => 0,
                'minute_reset_at'  => intdiv($this->ts(), 60) * 60 + 60,
                'retry_after'      => 60,
            ];
        }

        $now    = $this->ts();
        $minute = intdiv($now, 60) * 60;
        $digest = hash('sha256', trim(strtolower($ip)));
        $file   = rtrim($this->dir, '/\\')
            . DIRECTORY_SEPARATOR
            . 'ip_' . substr($digest, 0, 32) . '.json';

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            // Security boundary: refuse instead of opening the door.
            return [
                'allowed'          => false,
                'remaining_minute' => 0,
                'minute_reset_at'  => $minute + 60,
                'retry_after'      => max(1, ($minute + 60) - $now),
            ];
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                fclose($fh);
                return [
                    'allowed'          => false,
                    'remaining_minute' => 0,
                    'minute_reset_at'  => $minute + 60,
                    'retry_after'      => max(1, ($minute + 60) - $now),
                ];
            }

            $raw     = stream_get_contents($fh);
            $state   = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : [];
            if (!is_array($state)) {
                $state = [];
            }
            $count = ((int)($state['minute_window'] ?? 0) === $minute)
                ? (int)($state['minute_count'] ?? 0)
                : 0;

            if ($count >= $rpm) {
                flock($fh, LOCK_UN);
                fclose($fh);
                return [
                    'allowed'          => false,
                    'remaining_minute' => 0,
                    'minute_reset_at'  => $minute + 60,
                    'retry_after'      => max(1, ($minute + 60) - $now),
                ];
            }

            $count++;
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, (string)json_encode([
                'minute_window' => $minute,
                'minute_count'  => $count,
            ]));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            return [
                'allowed'          => true,
                'remaining_minute' => max(0, $rpm - $count),
                'minute_reset_at'  => $minute + 60,
                'retry_after'      => null,
            ];
        } catch (\Throwable) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
            return [
                'allowed'          => false,
                'remaining_minute' => 0,
                'minute_reset_at'  => $minute + 60,
                'retry_after'      => max(1, ($minute + 60) - $now),
            ];
        }
    }

    /** Drop the persisted state for a key. */
    public function reset(int $keyId): void
    {
        @unlink($this->path($keyId));
    }

    private function hardDeny(int $rpm, int $daily, int $minute): array
    {
        $now = $this->ts();
        $retry = null;
        if ($rpm <= 0) {
            $retry = max(1, ($minute + 60) - $now);
        } elseif ($daily <= 0) {
            $retry = max(1, ((intdiv($now, 86400) + 1) * 86400) - $now);
        }
        return [
            'allowed'          => false,
            'remaining_minute' => max(0, $rpm),
            'minute_reset_at'  => $minute + 60,
            'remaining_daily'  => max(0, $daily),
            'retry_after'      => $retry,
        ];
    }

    private function allowed(int $rpm, int $daily, int $minute): array
    {
        return [
            'allowed'          => true,
            'remaining_minute' => $rpm,
            'minute_reset_at'  => $minute + 60,
            'remaining_daily'  => $daily,
            'retry_after'      => null,
        ];
    }

    private function ts(): int
    {
        if ($this->now !== null) {
            return (int)($this->now)();
        }
        return time();
    }

    private function path(int $keyId): string
    {
        return rtrim($this->dir, '/\\')
            . DIRECTORY_SEPARATOR
            . 'key_' . $keyId . '.json';
    }
}
