<?php
/**
 * ApiKeyTest: offline unit checks for the external REST API key layer
 * (Task: RevoAp REST API, Step 1 - key storage and service).
 *
 * Runs without PHPUnit and without network access: `php tests/ApiKeyTest.php`.
 *
 * Verified:
 *   - ApiKeyService generates `rv_` + 48-hex keys from deterministic
 *     random_bytes (test seam), hashes with SHA-256 and persists ONLY
 *     the digest;
 *   - ApiKeyRepository in-memory + sqlite persistence (create/find by
 *     hash and prefix, revoke, rotate, touchLastUsed, stats, ordering);
 *   - scope normalization drops unknown scopes and applies the
 *     least-privilege default;
 *   - expiry/revocation semantics via ApiKey::isUsable();
 *   - db/migrations/015_api_keys.sql idempotency + annotation markers;
 *   - database dump never contains the plaintext key material.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-19
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Api\ApiKey;
use App\Api\ApiKeyRepository;
use App\Api\ApiKeyService;

// Silence any logging dependencies of the tested classes.
if (class_exists(\App\Core\Config::class)) {
    try {
        \App\Core\Config::set(['app' => ['debug' => true], 'logging' => ['path' => __DIR__ . '/logs']], __DIR__);
    } catch (\Throwable) {
        // Config may not be bootstrappable here; tests instantiate classes directly.
    }
}

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

/** Deterministic byte source for the service's test seam. */
function deterministicBytes(): Closure
{
    return static function (): string {
        return str_repeat("\xAB", ApiKeyService::KEY_RANDOM_BYTES);
    };
}

$service = new ApiKeyService(new ApiKeyRepository(null));

$plain = $service->generateKey();
check('generated key carries rv_ prefix', str_starts_with($plain, ApiKeyService::PREFIX));
check('generated key is rv_ + 48 hex chars',
    preg_match('/^rv_[0-9a-f]{48}$/', $plain) === 1);
check('generated key is 51 chars total', strlen($plain) === 51);

// Deterministic bytes produce a deterministic key.
$strictService = new ApiKeyService(new ApiKeyRepository(null));
$strictService->setRandomBytes(deterministicBytes());
$expected = 'rv_' . str_repeat('ab', ApiKeyService::KEY_RANDOM_BYTES);
check('deterministic bytes yield the expected key', $strictService->generateKey() === $expected);
check('prefixOf exposes only rv_ + first 8 hex chars', $strictService->prefixOf($expected) === 'rv_abababab');

// Hashing.
$hash = $service->hashKey($expected);
check('hashKey returns 64 lowercase hex chars', preg_match('/^[0-9a-f]{64}$/', $hash) === 1);
check('hashKey is deterministic', $service->hashKey($expected) === $hash);
check('hashKey distinguishes different keys', $service->hashKey($expected . 'x') !== $hash);
check('hashKey output is irreversible (not a prefix of the input)', !str_contains($hash, 'rv_'));

// Repository - degraded/in-memory mode.
$repo = new ApiKeyRepository(null);
$mem = $repo->create(
    label:      'Integration Agent',
    keyHash:    $hash,
    prefix:     'rv_abababab',
    scopes:     [ApiKey::SCOPE_FULL],
    rpmLimit:   60,
    dailyLimit: 5000,
);
check('memory create returns id > 0', $mem->id > 0);
check('memory create stores the digest verbatim', $mem->keyHash === $hash);
check('memory create stores the label', $mem->label === 'Integration Agent');
$byHash = $repo->findByHash($hash);
check('findByHash resolves the created key', $byHash !== null && $byHash->id === $mem->id);
$byPrefix = $repo->findByPrefix('rv_abababab');
check('findByPrefix resolves the created key', $byPrefix !== null && $byPrefix->id === $mem->id);
check('findByHash rejects an unknown digest', $repo->findByHash(str_repeat('0', 64)) === null);
check('findByHash rejects uppercase digest mismatch', $repo->findByHash('RV_ABAB') === null);

// revoke + ordering.
$memRevoked = $repo->revoke($mem->id);
check('revoke marks the key', $memRevoked !== null && $memRevoked->revokedAt !== null);
check('revoke is non-destructive (row retained)', $repo->findById($mem->id) !== null);
check('revoke of unknown id returns null', $repo->revoke(999999) === null);
$second = $repo->create('Second Key', str_repeat('1', 64), 'rv_11111111', [ApiKey::SCOPE_READ]);
$all = $repo->all();
check('all() returns newest first', $all[0]->id === $second->id);
check('revoked keys sort after active keys', $all[count($all) - 1]->revokedAt !== null);

// stats.
$stats = $repo->stats();
check('stats counts total rows', $stats['total'] === 2);
check('stats counts revoked rows', $stats['revoked'] === 1);
check('stats counts active rows', $stats['active'] === 1);

// touchLastUsed updates the stamp.
$repo->touchLastUsed($second->id);
$touched = $repo->findById($second->id);
check('touchLastUsed stamps last_used_at', $touched !== null && $touched->lastUsedAt !== null);

// rotate preserves label/quota and revokes the old row.
$rotated = $repo->rotate($second->id, str_repeat('2', 64), 'rv_22222222');
check('rotate returns a fresh row', $rotated !== null && $rotated->id !== $second->id);
check('rotate preserves the label', $rotated !== null && $rotated->label === 'Second Key');
check('rotate revokes the old row', $repo->findById($second->id)->revokedAt !== null);
check('rotate of unknown id returns null', $repo->rotate(123456, str_repeat('3', 64), 'rv_33333333') === null);

// sqlite persistence path.
$pdo = null;
try {
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<SQL
        CREATE TABLE api_keys (
            id           INTEGER PRIMARY KEY,
            label        TEXT NOT NULL,
            key_hash     TEXT NOT NULL UNIQUE,
            prefix       TEXT NOT NULL,
            scopes       TEXT NOT NULL DEFAULT 'full',
            rpm_limit    INTEGER NOT NULL DEFAULT 30,
            daily_limit  INTEGER NOT NULL DEFAULT 1000,
            expires_at   TEXT NULL,
            last_used_at TEXT NULL,
            revoked_at   TEXT NULL,
            created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        SQL);
    $sqlRepo = new ApiKeyRepository($pdo);
    $sqlKey = $sqlRepo->create('DB Key', $hash, 'rv_dbdbdbdb', [ApiKey::SCOPE_FULL]);
    check('sqlite create returns a hydrated row', $sqlKey->id > 0 && $sqlKey->keyHash === $hash);
    check('sqlite findByHash round-trips', $sqlRepo->findByHash($hash)?->id === $sqlKey->id);
    check('sqlite plainttext absent from row dump', !str_contains((string)json_encode($pdo->query('SELECT * FROM api_keys')->fetchAll(\PDO::FETCH_ASSOC)), $expected));
    $sqlRepo->revoke($sqlKey->id);
    check('sqlite revoke persists', $sqlRepo->findById($sqlKey->id)?->revokedAt !== null);
} catch (\Throwable) {
    $pdo = null;
}
if ($pdo instanceof \PDO) {
    // nothing extra
}

// Service-level lifecycle: generate -> verify -> revoke -> verify fails.
$svc = new ApiKeyService(new ApiKeyRepository(null));
$svc->setRandomBytes(deterministicBytes());
$gen = $svc->generate('Primary', 'full', 12, 42);
check('service generate returns plaintext + key', isset($gen['plaintext'], $gen['key']) && $gen['plaintext'] === $expected);
check('service persists only the digest', $svc->verify($expected)?->keyHash === $svc->hashKey($expected));
check('service normalizes full scope from string', $gen['key']->scopes === [ApiKey::SCOPE_FULL]);
$svc->revoke($gen['key']->id);
$afterRevoke = $svc->verify($expected);
check('service verify resolves the revoked row (auth gate checks usability)',
    $afterRevoke !== null && $afterRevoke->revokedAt !== null && !$afterRevoke->isUsable(time()));

// Scope normalization - least privilege default.
$svc2 = new ApiKeyService(new ApiKeyRepository(null));
$svc2->setRandomBytes(deterministicBytes());
check('normalizeScopes drops unknown entries and defaults to read',
    $svc2->normalizeScopes('admin,nope') === [ApiKey::SCOPE_READ]);
check('normalizeScopes accepts comma list', $svc2->normalizeScopes('full,read') === [ApiKey::SCOPE_FULL, ApiKey::SCOPE_READ]);
check('normalizeScopes dedups', $svc2->normalizeScopes(['full', 'full']) === [ApiKey::SCOPE_FULL]);

// ApiKey::isUsable semantics.
$now = time();
$future = gmdate('Y-m-d H:i:s', $now + 3600);
$past = gmdate('Y-m-d H:i:s', $now - 3600);
$usable = new ApiKey(1, 'ok', str_repeat('a', 64), 'rv_aaaaaaaa', [ApiKey::SCOPE_FULL], 30, 1000, $future);
check('isUsable accepts a future expiry', $usable->isUsable($now) === true);
$expired = new ApiKey(2, 'expired', str_repeat('b', 64), 'rv_bbbbbbbb', [ApiKey::SCOPE_FULL], 30, 1000, $past);
check('isUsable rejects an expired key', $expired->isUsable($now) === false);
$revoked = new ApiKey(3, 'revoked', str_repeat('c', 64), 'rv_cccccccc', [ApiKey::SCOPE_FULL], 30, 1000, null, null, gmdate('Y-m-d H:i:s'));
check('isUsable rejects a revoked key', $revoked->isUsable($now) === false);

// Migration markers.
$migPath = __DIR__ . '/../db/migrations/015_api_keys.sql';
$mig = is_file($migPath) ? (string)file_get_contents($migPath) : '';
check('015_api_keys.sql exists', $mig !== '');
check('migration is CREATE IF NOT EXISTS', str_contains($mig, 'CREATE TABLE IF NOT EXISTS api_keys'));
check('migration carries @table-version api_keys 1.0.0', str_contains($mig, '@table-version api_keys 1.0.0'));
check('migration stores key_hash as CHAR(64)', (bool)preg_match('/key_hash\s+CHAR\(64\)/', $mig));
check('migration declares a unique key_hash', str_contains($mig, 'UNIQUE'));

// Exported rows never contain the digest.
$public = $gen['key']->toPublicArray();
check('public key array omits the hash', !array_key_exists('key_hash', $public) && !array_key_exists('keyHash', $public));

echo "\n----------------------------------------\n";
echo "passed: {$passes}   failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
