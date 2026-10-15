<?php
/**
 * ApiKeyService: application service for external REST API keys.
 *
 * Owns the lifecycle of /v1/* credentials:
 *   - generate()  - creates the plaintext key (`rv_` + 48 hex chars,
 *                   192 bits from random_bytes), persists ONLY its
 *                   SHA-256 digest through ApiKeyRepository, and
 *                   returns the plaintext exactly once;
 *   - verify()    - resolves a presented plaintext key to its ApiKey
 *                   row (used by App\Api\ApiAuth);
 *   - rotate()    - replaces an existing key's material (new plaintext,
 *                   old digest marked revoked);
 *   - revoke()    - marks a key unusable while keeping the audit row;
 *   - listPublic()/stats() - operator-facing views that never contain
 *                   digests or plaintext.
 *
 * All plaintext handling is transient: no plaintext is ever written to
 * database, log, runtime config or disk.
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

final class ApiKeyService
{
    public const PREFIX = 'rv_';
    public const KEY_RANDOM_BYTES = 24;   // 192 bits -> 48 hex chars
    public const DEFAULT_RPM = 30;
    public const DEFAULT_DAILY = 1000;

    private ?\Closure $randomBytes = null;

    public function __construct(private readonly ApiKeyRepository $repo) {}

    /**
     * Test seam: inject a deterministic byte generator (must return a
     * string of exactly KEY_RANDOM_BYTES bytes).
     */
    public function setRandomBytes(\Closure $fn): void
    {
        $this->randomBytes = $fn;
    }

    /**
     * Generate a plaintext API key: `rv_` + 48 lowercase hex chars.
     * The key is returned ONLY here; the caller must show it once and
     * never store it.
     */
    public function generateKey(): string
    {
        if ($this->randomBytes !== null) {
            $bytes = ($this->randomBytes)();
        } else {
            $bytes = random_bytes(self::KEY_RANDOM_BYTES);
        }
        $bytes = substr($bytes, 0, self::KEY_RANDOM_BYTES);
        return self::PREFIX . bin2hex($bytes);
    }

    /** SHA-256 hex digest of a plaintext key (lowercase). */
    public function hashKey(string $plaintext): string
    {
        return strtolower(hash('sha256', $plaintext));
    }

    /**
     * Readable short prefix for lists and audit logs: `rv_` + the key's
     * first 8 hex chars. Never enough to reconstruct the full key.
     */
    public function prefixOf(string $plaintext): string
    {
        $hex = substr($plaintext, strlen(self::PREFIX));
        return self::PREFIX . substr($hex, 0, 8);
    }

    /**
     * Normalize + validate scopes. Unknown entries are dropped; the
     * result always contains at least one valid scope.
     *
     * @param  mixed $raw Comma list, array, or null.
     * @return list<string>
     */
    public function normalizeScopes(mixed $raw): array
    {
        $candidates = [];
        if (is_string($raw)) {
            $candidates = array_map('trim', explode(',', $raw));
        } elseif (is_array($raw)) {
            foreach ($raw as $s) {
                if (is_string($s)) {
                    $candidates[] = trim($s);
                }
            }
        }
        $out = [];
        foreach ($candidates as $s) {
            if (in_array($s, ApiKey::SCOPES, true) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out === [] ? [ApiKey::SCOPE_READ] : $out;
    }

    /**
     * Create a key and return BOTH its stored metadata and the
     * one-time plaintext.
     *
     * @param  string $label      Human-readable name (defaults to a generic label).
     * @param  mixed  $scopes     Full/read list or comma string.
     * @return array{key:ApiKey, plaintext:string}
     */
    public function generate(
        string  $label = '',
        mixed   $scopes = null,
        int     $rpmLimit = self::DEFAULT_RPM,
        int     $dailyLimit = self::DEFAULT_DAILY,
        ?string $expiresAt = null,
    ): array {
        $label = trim($label);
        if ($label === '') {
            $label = 'API key ' . gmdate('Y-m-d');
        }
        $plaintext = $this->generateKey();
        $key = $this->repo->create(
            label:      $label,
            keyHash:    $this->hashKey($plaintext),
            prefix:     $this->prefixOf($plaintext),
            scopes:     $this->normalizeScopes($scopes),
            rpmLimit:   max(0, $rpmLimit),
            dailyLimit: max(0, $dailyLimit),
            expiresAt:  $expiresAt,
        );
        return ['key' => $key, 'plaintext' => $plaintext];
    }

    /**
     * Resolve a presented plaintext key to its stored row, or null when
     * the digest matches nothing.
     */
    public function verify(string $plaintext): ?ApiKey
    {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            return null;
        }
        return $this->repo->findByHash($this->hashKey($plaintext));
    }

    /**
     * Replace the key material of an existing key. Returns the new
     * plaintext + row, or null when the id does not exist.
     *
     * @return array{key:ApiKey, plaintext:string}|null
     */
    public function rotate(int $id): ?array
    {
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            return null;
        }
        $plaintext = $this->generateKey();
        $rotated = $this->repo->rotate($id, $this->hashKey($plaintext), $this->prefixOf($plaintext));
        if ($rotated === null) {
            return null;
        }
        return ['key' => $rotated, 'plaintext' => $plaintext];
    }

    public function revoke(int $id): ?ApiKey
    {
        return $this->repo->revoke($id);
    }

    /** Record a successful authenticated use of the key. */
    public function touchLastUsed(int $id): void
    {
        $this->repo->touchLastUsed($id);
    }

    /** @return list<array<string,mixed>> Operator-facing rows (no hashes). */
    public function listPublic(): array
    {
        $out = [];
        foreach ($this->repo->all() as $key) {
            $out[] = $key->toPublicArray();
        }
        return $out;
    }

    /** @return array{total:int,active:int,revoked:int,expired:int} */
    public function stats(): array
    {
        return $this->repo->stats();
    }
}
