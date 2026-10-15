<?php
/**
 * WebhookService: validation + secret generation for webhook
 * subscriptions, layered over WebhookRepository.
 *
 * The repository takes pre-validated values; this service owns the
 * business rules:
 *   - label: non-empty, <=128 chars;
 *   - url: absolute http(s) URL, <=2048 chars, loopback hosts are
 *     rejected in enforcement mode (configurable via
 *     webhooks.allow_private_urls, default false) to prevent SSRF;
 *   - events: non-empty subset of Webhook::KNOWN_EVENTS;
 *   - secret: generated automatically (wh_ + 43 url-safe chars from
 *     32 random bytes) unless the caller supplies one. It is
 *     RECOVERABLE by design - the dispatcher must recompute HMAC
 *     signatures - so the UI must never echo it back in full; use
 *     Webhook::maskSecret().
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

use App\Core\Config;
use InvalidArgumentException;

final class WebhookService
{
    public const SECRET_PREFIX = 'wh_';

    /**
     * Test seam: deterministic secret generation.
     * fn(int $bytes): string - must return exactly $bytes bytes.
     */
    private ?\Closure $randomBytes = null;

    public function __construct(private readonly WebhookRepository $repository) {}

    /** Direct repository access for read/delete operations. */
    public function repository(): WebhookRepository
    {
        return $this->repository;
    }

    public function setRandomBytes(\Closure $fn): void { $this->randomBytes = $fn; }

    /**
     * Validate + create a subscription. A secret is generated when the
     * caller passes null. Returns the created Webhook.
     *
     * @param  list<string> $events
     * @throws InvalidArgumentException on invalid input
     */
    public function create(
        string  $label,
        string  $url,
        ?string $secret = null,
        array   $events = ['run.completed'],
        bool    $active = true,
    ): Webhook {
        $label = trim($label);
        $url = trim($url);
        $this->assertLabel($label);
        $this->assertUrl($url);
        $events = $this->normalizeEvents($events);
        $secret = $secret === null || trim($secret) === ''
            ? $this->generateSecret()
            : $this->normalizeSecret($secret);
        return $this->repository->create($label, $url, $secret, $events, $active);
    }

    /**
     * Validate + update a subscription (secret is regenerated when the
     * caller passes null). Returns null when the id is unknown.
     *
     * @param  list<string> $events
     * @throws InvalidArgumentException on invalid input
     */
    public function update(
        int     $id,
        string  $label,
        string  $url,
        ?string $secret = null,
        array   $events = ['run.completed'],
        bool    $active = true,
    ): ?Webhook {
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            return null;
        }
        $label = trim($label);
        $url = trim($url);
        $this->assertLabel($label);
        $this->assertUrl($url);
        $events = $this->normalizeEvents($events);
        $secret = $secret === null || trim($secret) === ''
            ? $this->generateSecret()
            : $this->normalizeSecret($secret);
        return $this->repository->update($id, $label, $url, $secret, $events, $active);
    }

    /**
     * Validate an event subset, collapsing to Webhook::KNOWN_EVENTS.
     *
     * @param  list<string> $events
     * @return list<string>
     * @throws InvalidArgumentException when empty or contains an unknown event
     */
    public function normalizeEvents(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            if (!is_string($event)) {
                continue;
            }
            $event = trim($event);
            if ($event === '' || in_array($event, $out, true)) {
                continue;
            }
            if (!in_array($event, Webhook::KNOWN_EVENTS, true)) {
                throw new InvalidArgumentException(
                    "Unknown webhook event `{$event}`. Allowed: " . implode(', ', Webhook::KNOWN_EVENTS)
                );
            }
            $out[] = $event;
        }
        if ($out === []) {
            throw new InvalidArgumentException('At least one webhook event is required');
        }
        return $out;
    }

    /** Generate a random signing secret: wh_ + 43 url-safe chars. */
    public function generateSecret(?string $suffix = null): string
    {
        $raw = $this->randomBytes !== null
            ? (string)(($this->randomBytes)(32))
            : random_bytes(32);
        $secret = self::SECRET_PREFIX . rtrim(
            strtr(base64_encode($raw), '+/', '-_'),
            '=',
        );
        return $suffix === null ? $secret : $secret . $suffix;
    }

    private function assertLabel(string $label): void
    {
        if ($label === '') {
            throw new InvalidArgumentException('Webhook label is required');
        }
        if (mb_strlen($label) > 128) {
            throw new InvalidArgumentException('Webhook label must be at most 128 characters');
        }
    }

    private function assertUrl(string $url): void
    {
        if ($url === '') {
            throw new InvalidArgumentException('Webhook URL is required');
        }
        if (strlen($url) > 2048) {
            throw new InvalidArgumentException('Webhook URL must be at most 2048 characters');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('Webhook URL must be an absolute http(s) URL');
        }
        // SSRF guard: private/loopback hosts may only be targeted when
        // the operator explicitly opts in (e.g. intranet receivers).
        $allowPrivate = (bool)Config::get('webhooks.allow_private_urls', false);
        if (!$allowPrivate && $this->isPrivateHost((string)$parts['host'])) {
            throw new InvalidArgumentException(
                'Webhook URL host must be publicly routable (set webhooks.allow_private_urls=true for intranet receivers)'
            );
        }
    }

    private function normalizeSecret(string $secret): string
    {
        if (strlen($secret) > 191) {
            throw new InvalidArgumentException('Webhook secret must be at most 191 characters');
        }
        return $secret;
    }

    private function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'localhost.') {
            return true;
        }
        if ($host === '::1' || $host === '0:0:0:0:0:0:0:1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isPrivateIpv4($host);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::isPrivateIpv6($host);
        }
        // Resolvable hostname: resolve once and reject when EVERY
        // resolved address is private. A public address anywhere is
        // enough to allow (DNS rebinding is mitigated on the request
        // side by cURL resolving the same name; this is a best-effort
        // pre-flight check, not a guarantee).
        $resolved = @gethostbynamel($host);
        if (is_array($resolved) && $resolved !== []) {
            foreach ($resolved as $addr) {
                $addr = (string)$addr;
                if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && !self::isPrivateIpv4($addr)) {
                    return false; // at least one public address
                }
                if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                    && !self::isPrivateIpv6($addr)) {
                    return false;
                }
            }
            return true;
        }
        return false; // unresolvable -> assume public (fail-open on validation)
    }

    private static function isPrivateIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        return (
            ($long >= ip2long('0.0.0.0')        && $long <= ip2long('0.255.255.255'))
            || ($long >= ip2long('10.0.0.0')    && $long <= ip2long('10.255.255.255'))
            || ($long >= ip2long('100.64.0.0')  && $long <= ip2long('100.127.255.255'))
            || ($long >= ip2long('127.0.0.0')   && $long <= ip2long('127.255.255.255'))
            || ($long >= ip2long('169.254.0.0') && $long <= ip2long('169.254.255.255'))
            || ($long >= ip2long('172.16.0.0')  && $long <= ip2long('172.31.255.255'))
            || ($long >= ip2long('192.0.0.0')   && $long <= ip2long('192.0.0.255'))
            || ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255'))
            || ($long >= ip2long('198.18.0.0')  && $long <= ip2long('198.19.255.255'))
            || ($long >= ip2long('224.0.0.0')   && $long <= ip2long('239.255.255.255'))
        );
    }

    private static function isPrivateIpv6(string $ip): bool
    {
        $lower = strtolower($ip);
        if ($lower === '::1' || $lower === '0:0:0:0:0:0:0:1') {
            return true;
        }
        if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
            return true; // fc00::/7 unique local
        }
        if (str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9')
            || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
            return true; // fe80::/10 link-local
        }
        if (str_starts_with($lower, '::ffff:')) {
            $ipv4 = substr($lower, 7);
            return self::isPrivateIpv4($ipv4);
        }
        return false;
    }
}
