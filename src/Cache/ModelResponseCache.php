<?php
/**
 * ModelResponseCache: Cached LLM responses keyed by a deterministic
 * sha256 over (provider, model, NORMALIZED messages + options).
 *
 * Normalization rules (v1.8.46):
 *  - Identifiers (provider, model) are part of the key EXACTLY as
 *    supplied, only lower-cased/trimmed: `gpt-4.1` and `gpt41` are two
 *    different models and must never share a slot. (Pre-1.8.46 both went
 *    through the text normalizer below, which deleted the punctuation and
 *    collapsed them into one key.)
 *  - Message BODIES are normalized as text: outer whitespace trimmed,
 *    internal whitespace runs collapsed, and every character that is not
 *    a Unicode letter (\p{L}) or digit (\p{N}) dropped. Case is preserved
 *    (no lowercasing) so distinct identifiers do not collide.
 *  - Each normalized message is framed as `len(role):role|len(content):
 *    content` before concatenation, so role boundaries and message
 *    boundaries are unambiguous. (Pre-1.8.46 a bare concatenation let
 *    [{user,"ab"},{user,"user"}] and [{user,"abuser"},{user,""}] produce
 *    the same key.)
 *  - Every option that is a provider parameter participates in the key.
 *    Transport-only keys (see NON_MODEL_OPTIONS) do not.
 *  - Provider + model identity is included in the hash material so the
 *    same text routed to two LLMs never shares a cache slot.
 *
 * Filesystem layout (v1.8.21):
 *   <root>/cache/<safe-provider>/<safe-model-hierarchy>/<sha>.json
 *
 * Example for connector "openrouter" + model "anthropic/claude-sonnet-4.6":
 *   <root>/cache/openrouter/anthropic/claude-sonnet-4_6/<sha>.json
 *
 * `safe-*` segments are derived by replacing any character that is not
 * a letter/digit/dash with `_`. Slashes inside the model identifier are
 * preserved as additional directory levels so the on-disk tree mirrors
 * the human-readable provider/model hierarchy.
 *
 * Backends, in order of preference:
 *  1. Database — table `model_response_cache` (when available).
 *  2. Filesystem — JSON files under <root>/cache/ (v1.8.21 layout).
 *  3. Legacy filesystem — flat JSON files under
 *     <root>/var/cache/model-responses/ (lookup-only fallback so caches
 *     persisted by v1.8.20 and earlier still hit until they age out).
 *
 * Stored JSON envelope contains BOTH the original (unnormalized) request
 * messages/options AND the original unmodified response, plus diagnostic
 * metadata (normalized checksum input length, short key). API keys and
 * Authorization headers are NEVER part of the key or the stored value.
 *
 * Cache is strictly best-effort: any backend error degrades to a fresh
 * network call. Failed responses are NEVER cached — {@see self::store()}
 * refuses empty answers and error envelopes, so a single provider outage
 * cannot pin a broken answer into every subsequent run (v1.8.46).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.8.46
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Cache;

use App\Connectors\ChatResponse;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Helpers\JsonHelper;
use PDO;
use PDOException;

final class ModelResponseCache
{
    /**
     * Cache-format version. Part of the key material, so bumping it
     * invalidates every previously written entry.
     *
     * 2 → 3 (v1.8.46): identifier handling, message framing and option
     * coverage in buildKey() were corrected. Entries written by version 2
     * are no longer reachable and can be reclaimed with
     * {@see self::purge()}.
     */
    public const VERSION = 3;

    /**
     * Keys that must never influence the cache key: transport concerns the
     * connector consumes and strips before the provider payload (mirrors
     * {@see \App\Connectors\AbstractConnector}, where TRANSPORT_ONLY_OPTIONS
     * is private), plus per-request bookkeeping that cannot change a
     * model's answer. Everything else in `$options` IS a provider
     * parameter and therefore part of the key — see buildKey().
     */
    private const NON_MODEL_OPTIONS = [
        'stream',
        'timeout',
        'max_retries',
        'retries',
        'request_id',
        'client_run_id',
        'job_id',
        'session_id',
    ];

    private bool $tableChecked = false;
    private bool $tableAvailable = false;

    /** Optional legacy flat-file lookup root (v1.8.20 and earlier). */
    private ?string $legacyFlatRoot = null;

    public function __construct(
        private readonly ?Database $db,
        private readonly ?string   $fileRoot = null,
        private readonly bool      $enabled  = true,
        private readonly int       $ttlSeconds = 0,
    ) {}

    public static function fromConfig(?Database $db, string $rootDir): self
    {
        $cfg = Config::get('cache.model_responses', []);
        $enabled = is_array($cfg) ? (bool)($cfg['enabled'] ?? true) : true;
        $ttl     = is_array($cfg) ? (int)($cfg['ttl_seconds'] ?? 0)  : 0;
        // v1.8.21: hierarchical cache lives at <root>/cache/. The flat
        // var/cache/model-responses directory remains read-only fallback
        // so existing entries continue to hit until they are regenerated.
        $fileRoot   = $rootDir . DIRECTORY_SEPARATOR . 'cache';
        $legacyRoot = $rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache'
                    . DIRECTORY_SEPARATOR . 'model-responses';
        $self = new self($db, $fileRoot, $enabled, $ttl);
        $self->legacyFlatRoot = $legacyRoot;
        return $self;
    }

    /**
     * Normalize a free-form input string for cache-key derivation.
     *
     * Rules (deterministic — see class header):
     *  1. Trim outer whitespace.
     *  2. Collapse internal `\s+` runs to a single ASCII space.
     *  3. Drop every character not in `\p{L}` (Unicode letter) or
     *     `\p{N}` (Unicode digit). Case is preserved.
     *
     * Returns the normalized string; safe for non-UTF-8 input (the
     * Unicode regex is invoked with /u and falls back gracefully if the
     * input is not valid UTF-8).
     */
    public static function normalizeText(string $input): string
    {
        if ($input === '') {
            return '';
        }
        // Trim outer whitespace including Unicode spaces.
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $input);
        if (!is_string($trimmed)) { $trimmed = trim($input); }
        // Collapse internal whitespace runs to single ASCII space.
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);
        if (!is_string($collapsed)) { $collapsed = $trimmed; }
        // Drop every non-letter / non-digit character (Unicode-aware).
        $stripped = preg_replace('/[^\p{L}\p{N}]+/u', '', $collapsed);
        if (!is_string($stripped)) {
            // Fall back to ASCII-only normalization when the regex
            // refuses (invalid UTF-8). Caller still gets a deterministic
            // key — the result is just less precise.
            $stripped = preg_replace('/[^A-Za-z0-9]+/', '', $collapsed) ?? '';
        }
        return $stripped;
    }

    /**
     * Build the hierarchical filesystem path for a cache entry.
     * Path is `<root>/<safeProvider>/<safeModel>/<sha>.json` with
     * `safeModel` further split on `/` to preserve namespace hierarchy.
     *
     * Path components are guaranteed to contain only [A-Za-z0-9_-] and
     * to never contain `..` so the result is safe to feed into
     * file_put_contents / file_get_contents without further validation.
     */
    public static function safePathFor(string $rootDir, string $provider, string $model, string $key): string
    {
        $segments = [rtrim($rootDir, DIRECTORY_SEPARATOR), self::sanitizeSegment($provider)];
        foreach (explode('/', $model) as $part) {
            $clean = self::sanitizeSegment($part);
            if ($clean !== '') { $segments[] = $clean; }
        }
        // Guard against pathological inputs — empty model becomes "_".
        if (count($segments) <= 2) { $segments[] = '_'; }
        $segments[] = self::sanitizeSegment(substr($key, 0, 64)) . '.json';
        return implode(DIRECTORY_SEPARATOR, $segments);
    }

    /** Sanitize one path segment. Allow [A-Za-z0-9._-]; everything else → "_". */
    public static function sanitizeSegment(string $segment): string
    {
        $segment = (string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $segment);
        $segment = trim($segment, '._-');
        return $segment === '' ? '_' : $segment;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Backend description for diagnostics. */
    public function backendInfo(): array
    {
        return [
            'enabled'            => $this->enabled,
            'database'           => $this->db !== null && $this->ensureTable(),
            'filesystem'         => $this->fileRoot !== null,
            'file_root'          => $this->fileRoot,
            'file_layout'        => 'cache/<provider>/<model>/<sha>.json',
            'legacy_flat_root'   => $this->legacyFlatRoot,
            'ttl_seconds'        => $this->ttlSeconds,
            'cache_version'      => self::VERSION,
            'normalization'      => 'whitespace_collapse_plus_letters_digits_only',
            // v1.8.46 — spell out what does and does not enter the key so an
            // operator can tell a miss caused by a real difference from one
            // caused by noise.
            'key_identity'       => 'provider (lower-cased) + model (verbatim)',
            'key_options'        => 'all options except transport-only: ' . implode(', ', self::NON_MODEL_OPTIONS),
            'error_responses'    => 'never cached (empty content / error envelope refused)',
        ];
    }

    /**
     * Build the canonical cache key.
     *
     * v1.8.46 — key material is composed from:
     *   * the provider slug, lower-cased/trimmed but otherwise verbatim;
     *   * the model identifier, verbatim (case preserved);
     *   * every message, as `len(role):role|len(content):content` over the
     *     NORMALIZED role/body (see normalizeText()) — length framing makes
     *     the concatenation unambiguous, so no two different message
     *     sequences can hash to the same key;
     *   * every option except {@see self::NON_MODEL_OPTIONS}, JSON-encoded
     *     with sorted keys.
     *
     * Text normalization (whitespace collapse, punctuation removal) is
     * applied ONLY to message bodies and roles: it is deliberately
     * punctuation-insensitive for prose, but it must never be applied to
     * identifiers, where `-`, `.` and `/` are significant
     * (`gpt-4.1` ≠ `gpt41`, `claude-3.5-sonnet` ≠ `claude35sonnet`).
     *
     * The short key returned alongside `key` is sha256(model + "|" +
     * normalized_user_query) — the same view the operator picked in
     * the spec, so the value can appear in UI tooltips.
     *
     * @param  array<int,array{role:string,content:string}> $messages
     * @param  array<string,mixed> $options
     * @return array{key:string,short_key:string,normalized:string,normalized_user_query:string}
     */
    public function buildKey(string $provider, string $model, array $messages, array $options): array
    {
        $providerSlug = strtolower(trim($provider));
        $modelId      = trim($model);

        // 1. Normalize every message body and frame it. The role label and
        //    a byte-length prefix per field make the concatenation
        //    unambiguous: a {system, user} pair never collides with a
        //    {user, user} pair carrying the same content, and no message
        //    boundary can be forged by embedding the role word in content.
        $normalizedParts = [];
        $normalizedBody  = '';
        foreach ($messages as $m) {
            if (!is_array($m)) { continue; }
            $role    = self::normalizeText((string)($m['role'] ?? ''));
            $content = self::normalizeText((string)($m['content'] ?? ''));
            $normalizedParts[] = $role . $content;
            $normalizedBody   .= mb_strlen($role) . ':' . $role
                               . '|' . mb_strlen($content) . ':' . $content . "\x1e";
        }

        // 2. Every provider parameter participates in the key. The
        //    historical implementation whitelisted a fixed list, which
        //    silently ignored any option added later (seed, stop,
        //    response_format, top_k, …) and could therefore serve the
        //    answer produced under a DIFFERENT setting. Transport-only
        //    keys are dropped instead — a blacklist that fails safe.
        $significant = [];
        foreach ($options as $k => $v) {
            $key = is_string($k) ? $k : (string)$k;
            if (in_array($key, self::NON_MODEL_OPTIONS, true)) { continue; }
            $significant[$key] = $v;
        }
        ksort($significant); // deterministic ordering for stable keys

        // 3. Hash the identity + framed body + options.
        $material = $providerSlug
            . "\x1f" . $modelId
            . "\x1f" . $normalizedBody
            . "\x1f" . json_encode($significant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\x1f" . (string)self::VERSION;
        $key = hash('sha256', $material);

        // 4. The short, user-facing key — sha256(model + normalized_user_query).
        $userQuery = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $row = $messages[$i] ?? null;
            if (is_array($row) && ($row['role'] ?? '') === 'user') {
                $userQuery = (string)($row['content'] ?? '');
                break;
            }
        }
        $normalizedUserQuery = self::normalizeText($userQuery);
        $shortKey = hash('sha256', $modelId . '|' . $normalizedUserQuery);
        return [
            'key'                   => $key,
            'short_key'             => $shortKey,
            'normalized'            => implode('', $normalizedParts),
            'normalized_user_query' => $normalizedUserQuery,
        ];
    }

    /**
     * v1.8.46 — is this response safe to persist?
     *
     * A cached failure is far worse than a cache miss: the provider stays
     * down for seconds, the bad answer stays cached for the whole TTL (and
     * with the shipped `ttl_seconds = 0`, forever), and every later run —
     * including a worker retry — replays the outage instead of retrying
     * the call. So an entry is written only when the response carries
     * actual, non-empty content and shows no error marker.
     *
     * Recognised error shapes (defensive on purpose — connectors differ):
     * `error`/`error_info`/`errorInfo`/`errors` on the raw envelope,
     * `ok: false`, `success: false`, and a `finish_reason` of `error` or
     * `content_filter`.
     */
    public static function isCacheable(ChatResponse $response): bool
    {
        if (trim($response->content) === '') {
            return false;
        }
        $raw = $response->rawResponse;
        if ($raw === []) {
            return true; // no envelope to inspect — content alone decides
        }
        foreach (['error', 'error_info', 'errorInfo', 'errors'] as $marker) {
            if (!array_key_exists($marker, $raw)) { continue; }
            $value = $raw[$marker];
            if ($value === null || $value === false || $value === '' || $value === []) { continue; }
            return false;
        }
        if (($raw['ok'] ?? true) === false || ($raw['success'] ?? true) === false) {
            return false;
        }
        $choices = $raw['choices'] ?? null;
        if (is_array($choices) && isset($choices[0]) && is_array($choices[0])) {
            $finish = strtolower((string)($choices[0]['finish_reason'] ?? ''));
            if ($finish === 'error' || $finish === 'content_filter') {
                return false;
            }
        }
        return true;
    }

    /**
     * Look up a cached response. Returns null on miss / disabled / TTL
     * expired. The returned ChatResponse mirrors the network shape so
     * callers can use it as a drop-in.
     *
     * Lookup order: database → new hierarchical filesystem
     * (cache/<provider>/<model>/<sha>.json, v1.8.21) → legacy flat
     * filesystem (var/cache/model-responses/<sha>.json).
     *
     * @return array{response:ChatResponse,key:string,short_key:string,hits:int,created_at:string,backend:string,path:string|null}|null
     */
    public function lookup(string $provider, string $model, array $messages, array $options): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        $keys = $this->buildKey($provider, $model, $messages, $options);
        $row = null;
        $backend = '';
        $path = null;
        $dbHit = $this->dbLookup($keys['key']);
        if ($dbHit !== null) {
            $row = $dbHit;
            $backend = 'database';
        } else {
            $fsHit = $this->fileLookup($keys['key'], $provider, $model);
            if ($fsHit !== null) {
                $row = $fsHit['row'];
                $backend = 'filesystem';
                $path = $fsHit['path'];
            } else {
                $legacy = $this->legacyFileLookup($keys['key']);
                if ($legacy !== null) {
                    $row = $legacy['row'];
                    $backend = 'filesystem_legacy';
                    $path = $legacy['path'];
                }
            }
        }
        if ($row === null) {
            return null;
        }
        if ($this->ttlSeconds > 0) {
            $createdTs = strtotime((string)($row['created_at'] ?? ''));
            if ($createdTs !== false && time() - $createdTs > $this->ttlSeconds) {
                // v1.8.46 — reclaim the entry instead of merely ignoring it.
                // Pre-1.8.46 an expired row stayed on disk / in the table
                // forever and was re-read (and re-decided) on every single
                // call; with the shipped `ttl_seconds = 0` nothing ever
                // pruned it either.
                $this->dropKey($keys['key'], $provider, $model);
                Logger::info('cache.entry.expired', [
                    'key'        => $keys['key'],
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'ttl_seconds'=> $this->ttlSeconds,
                ]);
                return null;
            }
        }
        $payload = $row['payload'] ?? null;
        if (!is_array($payload)) {
            return null;
        }
        try {
            $response = $this->hydrate($payload, $model, $provider);
        } catch (\Throwable) {
            return null;
        }
        return [
            'response'   => $response,
            'key'        => $keys['key'],
            'short_key'  => $keys['short_key'],
            'hits'       => (int)($row['hits'] ?? 0) + 1,
            'created_at' => (string)($row['created_at'] ?? ''),
            'backend'    => $backend,
            'path'       => $path,
        ];
    }

    /**
     * Persist a chat response. Failures during write are logged but never
     * thrown — caching is strictly best-effort.
     *
     * v1.8.46 — a response is only written when
     * {@see self::isCacheable()} accepts it. Empty answers, provider
     * error envelopes and content-filtered completions are refused, so a
     * transient outage can never be pinned into the cache and replayed on
     * top of every later run.
     *
     * @return array{stored:bool,db_stored?:bool,fs_stored?:bool,key?:string,short_key?:string,refused?:string}
     */
    public function store(string $provider, string $model, array $messages, array $options, ChatResponse $response): array
    {
        if (!$this->enabled) {
            return ['stored' => false];
        }
        if (!self::isCacheable($response)) {
            // warn (not debug) so a provider that answers with an error
            // envelope is visible in production. Logger collapses repeated
            // identical level+message pairs once per process, so a whole
            // outage still costs one line.
            Logger::warn('cache.store.refused', [
                'provider'   => strtolower($provider),
                'model'      => $model,
                'content_chars' => mb_strlen($response->content),
                'reason'     => trim($response->content) === '' ? 'empty_content' : 'error_envelope',
            ]);
            return ['stored' => false, 'refused' => trim($response->content) === '' ? 'empty_content' : 'error_envelope'];
        }
        $keys = $this->buildKey($provider, $model, $messages, $options);
        $payload = $this->serializeResponse($response);
        // Pass the original (unmodified) request through so the JSON
        // envelope preserves the user's text alongside the response.
        $stored = $this->dbStore($keys['key'], $provider, $model, $payload);
        $fsStored = $this->fileStore($keys['key'], $provider, $model, $payload, $messages, $options, $keys);
        return [
            'stored'     => $stored || $fsStored,
            'db_stored'  => $stored,
            'fs_stored'  => $fsStored,
            'key'        => $keys['key'],
            'short_key'  => $keys['short_key'],
        ];
    }

    /**
     * v1.8.46 — remove one entry from every backend. Used to reclaim an
     * entry the moment it is found to be expired, and exposed so an
     * operator can invalidate a single answer without nuking the cache.
     *
     * Best-effort: returns true when at least one backend removed a row
     * or file; never throws.
     */
    public function dropKey(string $key, ?string $provider = null, ?string $model = null): bool
    {
        $removed = false;
        if ($this->db !== null && $this->ensureTable()) {
            try {
                $this->db->run(static function (PDO $pdo) use ($key): void {
                    $stmt = $pdo->prepare('DELETE FROM model_response_cache WHERE cache_key = :k');
                    $stmt->execute(['k' => $key]);
                });
                $removed = true;
            } catch (\Throwable $e) {
                Logger::warn('cache drop failed', ['error' => $e->getMessage()]);
            }
        }
        foreach ($this->candidatePaths($key, $provider, $model) as $path) {
            if (is_file($path) && @unlink($path)) {
                $removed = true;
            }
        }
        return $removed;
    }

    /**
     * v1.8.46 — bulk invalidation. Removes entries older than
     * `$olderThanSeconds` from every backend; `0` purges everything.
     * Returns the counts so a CLI/operator can report what was reclaimed.
     *
     * This is the escape hatch for the shipped `ttl_seconds = 0` ("keep
     * until manually invalidated") default: without it, "manual"
     * invalidation meant deleting files by hand.
     *
     * v1.8.47 — the legacy flat tree (`var/cache/model-responses/`, the
     * v1.8.20-and-earlier layout) is now walked too. It used to be
     * lookup-only, so a purge left behind exactly the entries an operator
     * was trying to get rid of, and the Settings card could only show them
     * (`legacy_flat_count`) without offering any way to remove them. Its
     * counters are reported separately — `filesystem_legacy` /
     * `bytes_legacy` — because `summary()` never measured that tree, and
     * folding its bytes into `bytes` would make the dashboard's
     * `before.size_bytes - after.size_bytes` disagree with `bytes_human`.
     *
     * @return array{database:int,filesystem:int,filesystem_legacy:int,bytes:int,bytes_legacy:int}
     */
    public function purge(int $olderThanSeconds = 0): array
    {
        $cutoff = $olderThanSeconds > 0 ? time() - $olderThanSeconds : null;
        $result = [
            'database'          => 0,
            'filesystem'        => 0,
            'filesystem_legacy' => 0,
            'bytes'             => 0,
            'bytes_legacy'      => 0,
        ];

        if ($this->db !== null && $this->ensureTable()) {
            try {
                $result['database'] = (int)$this->db->run(static function (PDO $pdo) use ($cutoff): int {
                    if ($cutoff === null) {
                        return (int)$pdo->exec('DELETE FROM model_response_cache');
                    }
                    $stmt = $pdo->prepare('DELETE FROM model_response_cache WHERE created_at < :cutoff');
                    $stmt->execute(['cutoff' => date('Y-m-d H:i:s', $cutoff)]);
                    return $stmt->rowCount();
                });
            } catch (\Throwable $e) {
                Logger::warn('cache purge db failed', ['error' => $e->getMessage()]);
            }
        }

        $hierarchical = $this->purgeTree($this->fileRoot, $cutoff, 'cache purge fs failed');
        $result['filesystem'] = $hierarchical['count'];
        $result['bytes']      = $hierarchical['bytes'];

        // Null whenever the instance was built without fromConfig(), which is
        // how the tests construct it — purge() must stay a no-op then.
        $legacy = $this->purgeTree($this->legacyFlatRoot, $cutoff, 'cache purge legacy fs failed');
        $result['filesystem_legacy'] = $legacy['count'];
        $result['bytes_legacy']      = $legacy['bytes'];

        return $result;
    }

    /**
     * Delete the `*.json` cache entries under `$root` that are older than
     * `$cutoff` (`null` deletes all of them), then drop the directories the
     * deletion left empty. Shared by both on-disk layouts: the hierarchical
     * `cache/<provider>/<model>/` tree and the legacy flat
     * `var/cache/model-responses/` tree, which is why it recurses — nothing
     * guarantees the legacy layout on disk is actually flat.
     *
     * Semantics deliberately match the original hierarchical walk:
     *   - only `*.json` files are considered, so the `.tmp` staging files
     *     {@see self::fileStore()} writes are never removed;
     *   - the window boundary is strict — `mtime >= cutoff` is kept, so an
     *     entry written exactly at the cutoff survives;
     *   - a counter only advances when `unlink()` actually succeeded, and
     *     the size is read before the unlink;
     *   - a missing/unreadable root yields zeros rather than an error.
     *
     * @return array{count:int,bytes:int}
     */
    private function purgeTree(?string $root, ?int $cutoff, string $logMessage): array
    {
        $out = ['count' => 0, 'bytes' => 0];
        if ($root === null || !is_dir($root)) {
            return $out;
        }
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $entry) {
                $path = $entry->getPathname();
                if ($entry->isDir()) {
                    @rmdir($path); // only removes it when empty
                    continue;
                }
                if (substr($entry->getFilename(), -5) !== '.json') { continue; }
                if ($cutoff !== null && $entry->getMTime() >= $cutoff) { continue; }
                $size = (int)$entry->getSize();
                if (@unlink($path)) {
                    $out['count']++;
                    $out['bytes'] += $size;
                }
            }
        } catch (\Throwable $e) {
            Logger::warn($logMessage, ['root' => $root, 'error' => $e->getMessage()]);
        }
        return $out;
    }

    /**
     * Every on-disk location a key can live at: the hierarchical v1.8.21
     * layout (needs provider+model to rebuild the directory chain) and the
     * legacy flat root.
     *
     * @return string[]
     */
    private function candidatePaths(string $key, ?string $provider, ?string $model): array
    {
        $paths = [];
        if ($this->fileRoot !== null && $provider !== null && $model !== null) {
            $paths[] = self::safePathFor($this->fileRoot, $provider, $model, $key);
        }
        if ($this->legacyFlatRoot !== null) {
            $paths[] = $this->legacyFlatRoot . DIRECTORY_SEPARATOR . substr($key, 0, 64) . '.json';
        }
        return $paths;
    }

    /**
     * Increment the per-key hit counter. Best-effort; ignores failures.
     */
    public function recordHit(string $key): void
    {
        if (!$this->enabled || $this->db === null || !$this->ensureTable()) {
            return;
        }
        try {
            $this->db->run(static function (PDO $pdo) use ($key): void {
                $stmt = $pdo->prepare(
                    'UPDATE model_response_cache SET hits = hits + 1, last_hit_at = CURRENT_TIMESTAMP WHERE cache_key = :k'
                );
                $stmt->execute(['k' => $key]);
            });
        } catch (\Throwable $e) {
            Logger::warn('cache hit counter failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array{count:int|null,size_bytes:int|null} */
    public function summary(): array
    {
        $count = null;
        $size  = null;
        if ($this->db !== null && $this->ensureTable()) {
            try {
                $row = $this->db->run(static function (PDO $pdo): array {
                    $stmt = $pdo->query('SELECT COUNT(*) AS c FROM model_response_cache');
                    $r = $stmt ? $stmt->fetch() : null;
                    return is_array($r) ? $r : [];
                });
                if (isset($row['c'])) { $count = (int)$row['c']; }
            } catch (\Throwable) {}
        }
        if ($this->fileRoot !== null && is_dir($this->fileRoot)) {
            try {
                $files = [];
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->fileRoot, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iter as $f) {
                    if ($f->isFile() && substr($f->getFilename(), -5) === '.json') {
                        $files[] = $f->getPathname();
                    }
                }
                if ($count === null) { $count = count($files); }
                $size = 0;
                foreach ($files as $f) {
                    $size += (int)@filesize($f);
                }
            } catch (\Throwable) {}
        }
        return ['count' => $count, 'size_bytes' => $size];
    }

    // ----- internals -----

    private function dbLookup(string $key): ?array
    {
        if ($this->db === null || !$this->ensureTable()) {
            return null;
        }
        try {
            $row = $this->db->run(static function (PDO $pdo) use ($key): ?array {
                $stmt = $pdo->prepare(
                    'SELECT payload, hits, created_at FROM model_response_cache WHERE cache_key = :k LIMIT 1'
                );
                $stmt->execute(['k' => $key]);
                $r = $stmt->fetch();
                return is_array($r) ? $r : null;
            });
        } catch (\Throwable $e) {
            Logger::warn('cache lookup failed', ['error' => $e->getMessage()]);
            return null;
        }
        if ($row === null) {
            return null;
        }
        $payload = is_string($row['payload'] ?? null) ? json_decode((string)$row['payload'], true) : null;
        if (!is_array($payload)) {
            return null;
        }
        return [
            'payload'    => $payload,
            'hits'       => (int)($row['hits'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private function dbStore(string $key, string $provider, string $model, array $payload): bool
    {
        if ($this->db === null || !$this->ensureTable()) {
            return false;
        }
        try {
            $this->db->run(function (PDO $pdo) use ($key, $provider, $model, $payload): void {
                $stmt = $pdo->prepare(
                    'INSERT INTO model_response_cache (cache_key, provider, model, payload, version)
                     VALUES (:k, :p, :m, :payload, :v)
                     ON DUPLICATE KEY UPDATE payload = VALUES(payload), provider = VALUES(provider), model = VALUES(model), version = VALUES(version)'
                );
                $stmt->execute([
                    'k'       => $key,
                    'p'       => strtolower($provider),
                    'm'       => $model,
                    'payload' => JsonHelper::encode($payload),
                    'v'       => self::VERSION,
                ]);
            });
            return true;
        } catch (\Throwable $e) {
            Logger::warn('cache db store failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Hierarchical filesystem lookup (v1.8.21 layout). Returns the decoded
     * envelope row + on-disk path so the caller can record diagnostics.
     *
     * @return array{row:array<string,mixed>,path:string}|null
     */
    private function fileLookup(string $key, string $provider, string $model): ?array
    {
        if ($this->fileRoot === null) {
            return null;
        }
        $path = self::safePathFor($this->fileRoot, $provider, $model, $key);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = (string)file_get_contents($path);
        if ($raw === '') {
            return null;
        }
        $row = json_decode($raw, true);
        if (!is_array($row) || !isset($row['payload']) || !is_array($row['payload'])) {
            return null;
        }
        return [
            'row'  => [
                'payload'    => $row['payload'],
                'hits'       => (int)($row['hits'] ?? 0),
                'created_at' => (string)($row['created_at'] ?? ''),
            ],
            'path' => $path,
        ];
    }

    /**
     * Legacy flat filesystem lookup. Older entries from v1.8.20 and
     * earlier are at `<root>/var/cache/model-responses/<sha>.json` and
     * remain readable so we do not lose cache after the upgrade.
     *
     * @return array{row:array<string,mixed>,path:string}|null
     */
    private function legacyFileLookup(string $key): ?array
    {
        if ($this->legacyFlatRoot === null) {
            return null;
        }
        $path = $this->legacyFlatRoot . DIRECTORY_SEPARATOR . substr($key, 0, 64) . '.json';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = (string)file_get_contents($path);
        if ($raw === '') {
            return null;
        }
        $row = json_decode($raw, true);
        if (!is_array($row) || !isset($row['payload']) || !is_array($row['payload'])) {
            return null;
        }
        return [
            'row'  => [
                'payload'    => $row['payload'],
                'hits'       => (int)($row['hits'] ?? 0),
                'created_at' => (string)($row['created_at'] ?? ''),
            ],
            'path' => $path,
        ];
    }

    /**
     * @param array<string,mixed> $payload    serialized response
     * @param array<int,array{role:string,content:string}> $messages  original request
     * @param array<string,mixed> $options    original options
     * @param array{key:string,short_key:string,normalized:string,normalized_user_query:string} $keys
     */
    private function fileStore(string $key, string $provider, string $model, array $payload, array $messages = [], array $options = [], array $keys = []): bool
    {
        if ($this->fileRoot === null) {
            return false;
        }
        $path = self::safePathFor($this->fileRoot, $provider, $model, $key);
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            Logger::warn('cache.fs_store.mkdir_failed', ['dir' => $dir]);
            return false;
        }
        // The cache envelope stores BOTH the original (unmodified) request
        // AND the response, plus checksum metadata for diagnostics. The
        // normalized digest input is NOT stored verbatim — only its byte
        // length — so noisy prompts cannot be reconstructed from cache.
        $envelope = [
            'cache_key'  => $key,
            'short_key'  => $keys['short_key'] ?? null,
            'provider'   => strtolower($provider),
            'model'      => $model,
            'request'    => [
                'messages' => array_values(array_map(static function ($m): array {
                    return [
                        'role'    => (string)($m['role']    ?? ''),
                        'content' => (string)($m['content'] ?? ''),
                    ];
                }, array_filter($messages, 'is_array'))),
                'options'  => $options,
            ],
            'normalized' => [
                'message_chars' => mb_strlen($keys['normalized']            ?? ''),
                'user_chars'    => mb_strlen($keys['normalized_user_query'] ?? ''),
            ],
            'payload'    => $payload,
            'version'    => self::VERSION,
            'hits'       => 0,
            'created_at' => date('c'),
        ];
        try {
            $json = JsonHelper::encode($envelope);
        } catch (\Throwable $e) {
            Logger::warn('cache.fs_store.encode_failed', ['error' => $e->getMessage()]);
            return false;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            Logger::warn('cache.fs_store.write_failed', ['path' => $tmp]);
            return false;
        }
        return @rename($tmp, $path);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hydrate(array $payload, string $model, string $provider): ChatResponse
    {
        $content          = (string)($payload['content'] ?? '');
        $promptTokens     = (int)($payload['prompt_tokens'] ?? 0);
        $completionTokens = (int)($payload['completion_tokens'] ?? 0);
        $totalTokens      = (int)($payload['total_tokens'] ?? ($promptTokens + $completionTokens));
        if ($totalTokens !== $promptTokens + $completionTokens) {
            $totalTokens = $promptTokens + $completionTokens;
        }
        $raw = is_array($payload['raw_response'] ?? null) ? $payload['raw_response'] : [];
        $usageDetails = is_array($payload['usage_details'] ?? null) ? $payload['usage_details'] : [];
        return new ChatResponse(
            content:          $content,
            model:            (string)($payload['model'] ?? $model),
            provider:         (string)($payload['provider'] ?? $provider),
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            totalTokens:      $totalTokens,
            latencyMs:        0.0,
            rawResponse:      $raw,
            tokensEstimated:  (bool)($payload['tokens_estimated'] ?? false),
            actualModel:      isset($payload['actual_model']) ? (string)$payload['actual_model'] : null,
            cost:             isset($payload['cost']) && $payload['cost'] !== null ? (float)$payload['cost'] : null,
            cachedTokens:     isset($payload['cached_tokens']) && $payload['cached_tokens'] !== null ? (int)$payload['cached_tokens'] : null,
            reasoningTokens:  isset($payload['reasoning_tokens']) && $payload['reasoning_tokens'] !== null ? (int)$payload['reasoning_tokens'] : null,
            generationId:     isset($payload['generation_id']) && $payload['generation_id'] !== null ? (string)$payload['generation_id'] : null,
            usageDetails:     $usageDetails,
        );
    }

    /** @return array<string,mixed> */
    private function serializeResponse(ChatResponse $r): array
    {
        return [
            'content'           => $r->content,
            'model'             => $r->model,
            'actual_model'      => $r->actualModel,
            'provider'          => $r->provider,
            'prompt_tokens'     => $r->promptTokens,
            'completion_tokens' => $r->completionTokens,
            'total_tokens'      => $r->totalTokens,
            'tokens_estimated'  => $r->tokensEstimated,
            'cost'              => $r->cost,
            'cached_tokens'     => $r->cachedTokens,
            'reasoning_tokens'  => $r->reasoningTokens,
            'generation_id'     => $r->generationId,
            'usage_details'     => $r->usageDetails,
            'raw_response'      => $r->rawResponse,
        ];
    }

    private function ensureTable(): bool
    {
        if ($this->db === null) {
            return false;
        }
        if ($this->tableChecked) {
            return $this->tableAvailable;
        }
        $this->tableChecked = true;
        try {
            $this->db->run(static function (PDO $pdo): void {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS model_response_cache (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        cache_key CHAR(64) NOT NULL UNIQUE,
                        provider VARCHAR(64) NOT NULL,
                        model VARCHAR(192) NOT NULL,
                        payload MEDIUMTEXT NOT NULL,
                        version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                        hits INT UNSIGNED NOT NULL DEFAULT 0,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        last_hit_at DATETIME NULL,
                        INDEX idx_mrc_provider_model (provider, model)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            });
            $this->tableAvailable = true;
        } catch (PDOException $e) {
            Logger::warn('cache table ensure failed', ['error' => $e->getMessage()]);
            $this->tableAvailable = false;
        }
        return $this->tableAvailable;
    }
}
