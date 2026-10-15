<?php
/**
 * CacheStatistics: read-only reporting and bulk invalidation for the
 * model response cache, as consumed by the Settings UI.
 *
 * The heavy lifting (backend discovery, entry counts, deletion) stays in
 * {@see ModelResponseCache} — this class only *describes* what that class
 * already reports and adds the per-backend detail an operator needs to
 * answer three questions without touching a shell:
 *
 *   1. Is the cache on, which backends answer, and where does it live?
 *   2. How much is in there (entries, bytes, oldest/newest) and is it
 *      actually being used (hit counter)?
 *   3. What exactly will `Purge` remove, and what did it remove?
 *
 * Backend asymmetry (deliberate, and the reason the payload is explicit
 * about it): the database backend records a per-entry `hits` counter and
 * `last_hit_at`, the filesystem backend records nothing at all — no
 * counter, no miss log. This class therefore reports the hit counter as
 * unavailable (`hits.counter_available = false`) when only the
 * filesystem backend is reachable instead of pretending a zero means
 * "never hit". Equally, no true request-level hit *rate* is derivable
 * from the cache tables (misses are not recorded anywhere), so the
 * reported `hit_rate_on_entries` is what the data supports: the share of
 * stored entries that have served at least one hit. See `hits.note`.
 *
 * Every read is bounded: one aggregate over `model_response_cache`, one
 * `GROUP BY provider, model ... LIMIT n`, one `information_schema` row
 * lookup and a single recursive directory walk. The endpoint this feeds
 * is polled while its card is visible, so no unbounded `pipeline_events`
 * scan is attempted here (the raw per-request `cache_hit` flags live
 * there and would need an index this deployment does not have).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-20
 */
declare(strict_types=1);

namespace App\Cache;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use Throwable;

final class CacheStatistics
{
    /** How many provider/model rows the distribution lists at most. */
    public const TOP_N = 8;

    /** Upper bound for `older_than_days` (100 years). */
    public const MAX_OLDER_THAN_DAYS = 36500;

    /** Table backing the database backend (mirrors ModelResponseCache). */
    private const TABLE = 'model_response_cache';

    /** Connection for the read-only aggregates. */
    private readonly ?Database $db;

    /**
     * @param ModelResponseCache  $cache  already-configured cache instance
     *                                    (the same one the pipeline uses)
     * @param null|PDO|Database   $db     connection for the read-only
     *                                    aggregates; null when the request
     *                                    has no database at all. A
     *                                    `Database` wrapper is preferred:
     *                                    the aggregates then survive a
     *                                    connection that went away since
     *                                    the request started.
     * @param string              $rootDir project root, used for the
     *                                    project-relative display paths
     */
    public function __construct(
        private readonly ModelResponseCache $cache,
        null|PDO|Database $db,
        private readonly string $rootDir,
    ) {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    /**
     * Full statistics snapshot. Never throws: an unreachable database, a
     * missing cache directory or a filesystem error degrades into an
     * `available => false` / zero-count section so the card can always
     * render something truthful.
     *
     * @param  int $topN provider/model rows in the distribution lists
     * @return array<string,mixed>
     */
    public function report(int $topN = self::TOP_N): array
    {
        $topN = max(1, min(50, $topN));
        $info = $this->cache->backendInfo();

        $fileRoot = is_string($info['file_root'] ?? null) ? $info['file_root'] : null;
        $legacy   = is_string($info['legacy_flat_root'] ?? null) ? $info['legacy_flat_root'] : null;

        $databaseSection   = $this->databaseSection($topN);
        $filesystemSection = $this->filesystemSection($fileRoot, $legacy, $topN);

        $counterAvailable = $databaseSection['available'] === true;
        $total = (int)($databaseSection['count'] ?? 0) + (int)($filesystemSection['count'] ?? 0);
        $bytes = (int)($databaseSection['size_bytes'] ?? 0) + (int)($filesystemSection['size_bytes'] ?? 0);

        return [
            'ok'               => true,
            'generated_at'     => date('c'),
            'enabled'          => $this->cache->isEnabled(),
            'cache_version'    => ModelResponseCache::VERSION,
            'ttl_seconds'      => (int)($info['ttl_seconds'] ?? 0),
            'ttl_human'        => self::humanTtl((int)($info['ttl_seconds'] ?? 0)),
            'normalization'    => (string)($info['normalization'] ?? ''),
            'key_identity'     => (string)($info['key_identity'] ?? ''),
            'key_options'      => (string)($info['key_options'] ?? ''),
            'error_responses'  => (string)($info['error_responses'] ?? ''),
            'backends'         => [
                'database'   => $databaseSection,
                'filesystem' => $filesystemSection,
            ],
            'totals'           => [
                'count'      => $total,
                'size_bytes' => $bytes,
                'size_human' => self::humanBytes($bytes),
                'oldest'     => self::earliest([$databaseSection['oldest'], $filesystemSection['oldest']]),
                'newest'     => self::latest([$databaseSection['newest'], $filesystemSection['newest']]),
            ],
            'hits'             => $this->hitsSection($databaseSection, $counterAvailable, $total),
            // Raw view of the method the CLI uses, so the card and
            // `php bin/cache-purge.php --status` never disagree.
            'summary'          => $this->cache->summary(),
            'purge_available'  => (bool)($databaseSection['available'] || $filesystemSection['available']),
            'top_n'            => $topN,
        ];
    }

    /**
     * Delete entries from every backend and report what was reclaimed.
     *
     * `older_than_days = 0` means "everything" (the shipped default is
     * `ttl_seconds = 0`, so this is the only way an operator reclaims
     * space). The window is validated by the caller; this method clamps
     * defensively.
     *
     * v1.8.47: the legacy flat layout is purged as well, and reported under
     * its own counter. `bytes` (and therefore `bytes_human`) still covers the
     * hierarchical tree only, because that is all `summary()` — and hence
     * `before`/`after` — has ever measured; the card derives "freed" from
     * the difference of those two, so widening `bytes` would make the
     * reported size and the reported delta contradict each other.
     *
     * @return array<string,mixed>
     */
    public function purge(int $olderThanDays): array
    {
        $olderThanDays = max(0, min(self::MAX_OLDER_THAN_DAYS, $olderThanDays));
        $before = $this->cache->summary();
        $deleted = $this->cache->purge($olderThanDays > 0 ? $olderThanDays * 86400 : 0);
        $after = $this->cache->summary();

        $bytes = (int)($deleted['bytes'] ?? 0);
        $bytesLegacy = (int)($deleted['bytes_legacy'] ?? 0);
        Logger::warn('settings.cache.purge', [
            'older_than_days'   => $olderThanDays,
            'database'          => (int)($deleted['database'] ?? 0),
            'filesystem'        => (int)($deleted['filesystem'] ?? 0),
            'filesystem_legacy' => (int)($deleted['filesystem_legacy'] ?? 0),
            'bytes'             => $bytes,
            'bytes_legacy'      => $bytesLegacy,
        ]);

        return [
            'ok'              => true,
            'generated_at'    => date('c'),
            'older_than_days' => $olderThanDays,
            'window'          => $olderThanDays > 0
                ? 'entries older than ' . $olderThanDays . ' day' . ($olderThanDays === 1 ? '' : 's')
                : 'every entry in the database, the hierarchical cache and the legacy flat cache',
            'deleted'         => [
                'database'          => (int)($deleted['database'] ?? 0),
                'filesystem'        => (int)($deleted['filesystem'] ?? 0),
                'filesystem_legacy' => (int)($deleted['filesystem_legacy'] ?? 0),
                'bytes'             => $bytes,
                'bytes_human'       => self::humanBytes($bytes),
                'bytes_legacy'      => $bytesLegacy,
                'bytes_legacy_human'=> self::humanBytes($bytesLegacy),
            ],
            'before'          => self::summaryView($before),
            'after'           => self::summaryView($after),
            'cache_version'   => ModelResponseCache::VERSION,
        ];
    }

    /**
     * Render a byte count for humans. Binary units (KiB/MiB/GiB) because
     * that is what `du -h` and every file manager on the operator's
     * desktop show for the same directory.
     */
    public static function humanBytes(int $bytes): string
    {
        $abs = abs($bytes);
        if ($abs < 1024) {
            return $bytes . ' B';
        }
        foreach (['KiB' => 1024, 'MiB' => 1048576, 'GiB' => 1073741824, 'TiB' => 1099511627776] as $unit => $factor) {
            if ($abs < $factor * 1024 || $unit === 'TiB') {
                return number_format($bytes / $factor, 1, '.', '') . ' ' . $unit;
            }
        }
        return $bytes . ' B';
    }

    /** Human-readable TTL: 0 is the shipped "keep until purged" default. */
    public static function humanTtl(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'no expiry (0) — entries stay until purged';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $parts = [];
        if ($days > 0) { $parts[] = $days . 'd'; }
        if ($hours > 0) { $parts[] = $hours . 'h'; }
        if ($parts === []) { $parts[] = $seconds . 's'; }
        return implode(' ', $parts) . ' (' . $seconds . 's)';
    }

    /**
     * Database backend section: one aggregate, one bounded distribution
     * query and one information_schema lookup. "Available" means the
     * aggregate query answered — a missing table, a dead connection or a
     * permission error degrades into a zeroed section with a `note`
     * instead of a 500.
     *
     * Deliberately no DDL here: reading statistics must never create the
     * cache table.
     *
     * @return array<string,mixed>
     */
    private function databaseSection(int $topN): array
    {
        $section = [
            'available'   => false,
            'label'       => 'database (table ' . self::TABLE . ')',
            'count'       => null,
            'size_bytes'  => null,
            'size_human'  => null,
            'size_source' => null,
            'table_bytes' => null,
            'table_human' => null,
            'oldest'      => null,
            'newest'      => null,
            'last_hit_at' => null,
            'hits'        => null,
            'entries_with_hits'  => null,
            'entries_never_hit'  => null,
            'distribution'       => [],
            'distribution_available' => false,
            'note'        => 'no database connection in this request',
        ];
        if ($this->db === null) {
            return $section;
        }
        try {
            $row = $this->db->run(static function (PDO $pdo): ?array {
                $stmt = $pdo->query(
                    'SELECT COUNT(*) AS entries,
                            COALESCE(SUM(hits), 0) AS hits,
                            COALESCE(SUM(CASE WHEN hits > 0 THEN 1 ELSE 0 END), 0) AS entries_with_hits,
                            COALESCE(SUM(LENGTH(payload)), 0) AS payload_bytes,
                            MIN(created_at) AS oldest,
                            MAX(created_at) AS newest,
                            MAX(last_hit_at) AS last_hit_at
                       FROM ' . self::TABLE
                );
                $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($stmt !== false) { $stmt->closeCursor(); }
                return is_array($row) ? $row : null;
            });
            if (!is_array($row)) {
                $section['note'] = 'the aggregate query returned no row';
                return $section;
            }
            $count = (int)($row['entries'] ?? 0);
            $withHits = (int)($row['entries_with_hits'] ?? 0);
            $payloadBytes = (int)($row['payload_bytes'] ?? 0);
            $section['available'] = true;
            $section['note'] = null;
            $section['count'] = $count;
            $section['hits'] = (int)($row['hits'] ?? 0);
            $section['entries_with_hits'] = $withHits;
            $section['entries_never_hit'] = max(0, $count - $withHits);
            $section['size_bytes'] = $payloadBytes;
            $section['size_human'] = self::humanBytes($payloadBytes);
            $section['size_source'] = 'payload_bytes';
            $section['oldest'] = self::normalizeTimestamp($row['oldest'] ?? null);
            $section['newest'] = self::normalizeTimestamp($row['newest'] ?? null);
            $section['last_hit_at'] = self::normalizeTimestamp($row['last_hit_at'] ?? null);
        } catch (Throwable $e) {
            Logger::warn('settings.cache.db.aggregate.failed', ['error' => $e->getMessage()]);
            $section['note'] = 'the ' . self::TABLE . ' table is not reachable from this request '
                . '(import db/schema.sql, or let one cached request create it)';
            return $section;
        }

        try {
            $rows = $this->db->run(static function (PDO $pdo) use ($topN): array {
                $stmt = $pdo->query(
                    'SELECT provider, model, COUNT(*) AS entries,
                            COALESCE(SUM(hits), 0) AS hits,
                            COALESCE(SUM(LENGTH(payload)), 0) AS bytes
                       FROM ' . self::TABLE . '
                   GROUP BY provider, model
                   ORDER BY entries DESC, provider ASC, model ASC
                      LIMIT ' . (int)$topN
                );
                $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                if ($stmt !== false) { $stmt->closeCursor(); }
                return is_array($rows) ? $rows : [];
            });
            foreach ($rows as $row) {
                if (!is_array($row)) { continue; }
                $section['distribution'][] = [
                    'provider'   => (string)($row['provider'] ?? ''),
                    'model'      => (string)($row['model'] ?? ''),
                    'entries'    => (int)($row['entries'] ?? 0),
                    'hits'       => (int)($row['hits'] ?? 0),
                    'bytes'      => (int)($row['bytes'] ?? 0),
                    'bytes_human'=> self::humanBytes((int)($row['bytes'] ?? 0)),
                    'backend'    => 'database',
                ];
            }
            $section['distribution_available'] = true;
        } catch (Throwable $e) {
            Logger::warn('settings.cache.distribution.failed', ['error' => $e->getMessage()]);
            $section['note'] = 'distribution query failed (counts above are still valid)';
        }

        // Table footprint (data + index). Informational only: it includes
        // InnoDB page overhead, so it is reported next to — never instead
        // of — the payload byte total.
        try {
            $tableBytes = $this->db->run(static function (PDO $pdo): ?int {
                $stmt = $pdo->prepare(
                    'SELECT data_length + index_length AS bytes
                       FROM information_schema.TABLES
                      WHERE table_schema = DATABASE() AND table_name = :t
                      LIMIT 1'
                );
                $stmt->execute(['t' => self::TABLE]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($row) && $row['bytes'] !== null ? (int)$row['bytes'] : null;
            });
            if ($tableBytes !== null) {
                $section['table_bytes'] = $tableBytes;
                $section['table_human'] = self::humanBytes($tableBytes);
            }
        } catch (Throwable) {
            // Shared hosts often hide information_schema; harmless.
        }

        return $section;
    }

    /**
     * Filesystem backend section. Single recursive walk that fills the
     * entry count, the byte total, the oldest/newest mtimes and the
     * provider/model distribution (derived from the
     * `cache/<provider>/<model>/<sha>.json` layout). A missing directory
     * is a valid, empty state — not an error.
     *
     * @return array<string,mixed>
     */
    private function filesystemSection(?string $fileRoot, ?string $legacyRoot, int $topN): array
    {
        $section = [
            'available'   => false,
            'label'       => 'filesystem (cache/<provider>/<model>/<sha>.json)',
            'root'        => $fileRoot,
            'root_display'=> $this->displayPath($fileRoot),
            'layout'      => 'cache/<provider>/<model>/<sha>.json',
            'legacy_flat_root'  => $legacyRoot,
            'legacy_flat_display' => $this->displayPath($legacyRoot),
            'legacy_flat_count' => 0,
            'count'       => 0,
            'size_bytes'  => 0,
            'size_human'  => self::humanBytes(0),
            'oldest'      => null,
            'newest'      => null,
            'hits'        => null,
            'entries_with_hits' => null,
            'entries_never_hit' => null,
            'distribution'       => [],
            'distribution_available' => false,
            'note'        => 'no cache directory configured',
        ];

        if ($fileRoot !== null && is_dir($fileRoot)) {
            $section['available'] = true;
            $section['note'] = null;
            $buckets = [];
            try {
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fileRoot, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iter as $entry) {
                    if (!$entry->isFile() || substr($entry->getFilename(), -5) !== '.json') {
                        continue;
                    }
                    $path = $entry->getPathname();
                    $section['count']++;
                    $size = (int)@filesize($path);
                    $section['size_bytes'] += $size;
                    $mtime = (int)@filemtime($path);
                    if ($mtime > 0) {
                        $iso = date('c', $mtime);
                        if ($section['oldest'] === null || $iso < $section['oldest']) { $section['oldest'] = $iso; }
                        if ($section['newest'] === null || $iso > $section['newest']) { $section['newest'] = $iso; }
                    }
                    [$provider, $model] = $this->describeRelative($fileRoot, $path);
                    $key = $provider . "\0" . $model;
                    if (!isset($buckets[$key])) {
                        $buckets[$key] = ['provider' => $provider, 'model' => $model, 'entries' => 0, 'bytes' => 0];
                    }
                    $buckets[$key]['entries']++;
                    $buckets[$key]['bytes'] += $size;
                }
            } catch (Throwable $e) {
                Logger::warn('settings.cache.fs.scan.failed', ['error' => $e->getMessage()]);
                $section['note'] = 'directory walk failed: ' . Logger::sanitizeScalar($e->getMessage());
            }
            $section['size_human'] = self::humanBytes((int)$section['size_bytes']);
            $section['distribution'] = $this->sortDistribution($buckets, $topN);
            $section['distribution_available'] = true;
        }

        // Legacy flat layout (v1.8.20 and earlier). Lookup-only fallback:
        // still counted so the operator can see why a purge shrinks the
        // "everything" figure by more than the hierarchical tree holds.
        if ($legacyRoot !== null && is_dir($legacyRoot)) {
            try {
                $legacyCount = 0;
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($legacyRoot, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iter as $entry) {
                    if ($entry->isFile() && substr($entry->getFilename(), -5) === '.json') {
                        $legacyCount++;
                    }
                }
                $section['legacy_flat_count'] = $legacyCount;
            } catch (Throwable) {
                // Best effort only.
            }
        }

        return $section;
    }

    /**
     * Hit-attribution section. The counter exists only in the database
     * backend, so `counter_available` is false for a filesystem-only
     * deployment and the UI must say so rather than render a zero.
     *
     * @param  array<string,mixed> $databaseSection
     * @return array<string,mixed>
     */
    private function hitsSection(array $databaseSection, bool $counterAvailable, int $totalEntries): array
    {
        if (!$counterAvailable) {
            return [
                'counter_available'  => false,
                'recorded_total'     => null,
                'entries_with_hits'  => null,
                'entries_never_hit'  => null,
                'last_hit_at'        => null,
                'hit_rate_on_entries'=> null,
                'hit_rate_percent'   => null,
                'note'               => 'The hit counter lives in the database backend only '
                    . '(model_response_cache.hits / last_hit_at); the filesystem backend does not '
                    . 'record hits, so this deployment cannot report whether the cache was used.',
            ];
        }

        $hits = (int)($databaseSection['hits'] ?? 0);
        $withHits = (int)($databaseSection['entries_with_hits'] ?? 0);
        $entries = (int)($databaseSection['count'] ?? 0);
        $rate = $entries > 0 ? $withHits / $entries : null;

        return [
            'counter_available'   => true,
            'recorded_total'      => $hits,
            'entries_with_hits'   => $withHits,
            'entries_never_hit'   => (int)($databaseSection['entries_never_hit'] ?? max(0, $entries - $withHits)),
            'last_hit_at'         => $databaseSection['last_hit_at'] ?? null,
            'hit_rate_on_entries' => $rate,
            'hit_rate_percent'    => $rate === null ? null : number_format($rate * 100, 1, '.', '') . '%',
            'note'                => $entries > 0 && $hits === 0
                ? 'No hit has ever been recorded: ' . $entries . ' entr'
                    . ($entries === 1 ? 'y is' : 'ies are') . ' stored but never served — '
                    . 'check that the key material (provider/model/messages/options) really repeats, '
                    . 'and that the pipeline is running with this cache enabled.'
                : 'hits/entries: the share of stored entries that served at least one hit. '
                    . 'Misses are not recorded anywhere, so a request-level hit rate cannot be derived '
                    . 'from the cache tables. Total entries considered: ' . $totalEntries . '.',
        ];
    }

    /**
     * @param  array<string,array{provider:string,model:string,entries:int,bytes:int}> $buckets
     * @return list<array<string,mixed>>
     */
    private function sortDistribution(array $buckets, int $topN): array
    {
        $rows = array_values($buckets);
        usort($rows, static function (array $a, array $b): int {
            return ($b['entries'] <=> $a['entries'])
                ?: strcmp($a['provider'], $b['provider'])
                ?: strcmp($a['model'], $b['model']);
        });
        $rows = array_slice($rows, 0, $topN);
        return array_map(static fn(array $r): array => [
            'provider'    => $r['provider'],
            'model'       => $r['model'],
            'entries'     => $r['entries'],
            'hits'        => null,
            'bytes'       => $r['bytes'],
            'bytes_human' => self::humanBytes($r['bytes']),
            'backend'     => 'filesystem',
        ], $rows);
    }

    /**
     * Derive provider/model from the
     * `cache/<provider>/<model parts...>/<sha>.json` layout. An
     * unexpected file (e.g. a stray JSON directly under `cache/`) is
     * reported as `(unscoped)` rather than dropped, so counts still add up.
     *
     * @return array{0:string,1:string} [provider, model]
     */
    private function describeRelative(string $root, string $path): array
    {
        $rel = str_replace('\\', '/', substr($path, strlen(rtrim($root, '\\/')) + 1));
        $parts = explode('/', $rel);
        array_pop($parts); // file name
        if ($parts === []) {
            return ['(unscoped)', '(unscoped)'];
        }
        $provider = array_shift($parts);
        $model = $parts === [] ? '(unscoped)' : implode('/', $parts);
        return [$provider, $model];
    }

    /**
     * Absolute path for API consumers, project-relative for display.
     * The endpoint is admin-gated, so the absolute path is not a leak —
     * it is what the operator needs to point `du` at the directory.
     */
    private function displayPath(?string $absolute): ?string
    {
        if ($absolute === null || $absolute === '') {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $this->rootDir), '/');
        $norm = str_replace('\\', '/', $absolute);
        if ($root !== '' && str_starts_with($norm, $root . '/')) {
            return substr($norm, strlen($root) + 1);
        }
        return $norm;
    }

    /** @return array{count:int|null,size_bytes:int|null,size_human:string|null} */
    private static function summaryView(array $summary): array
    {
        $count = $summary['count'] ?? null;
        $bytes = $summary['size_bytes'] ?? null;
        return [
            'count'      => $count === null ? null : (int)$count,
            'size_bytes' => $bytes === null ? null : (int)$bytes,
            'size_human' => $bytes === null ? null : self::humanBytes((int)$bytes),
        ];
    }

    /** Normalize a MySQL DATETIME to ISO-8601 (or null). */
    private static function normalizeTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime(trim($value));
        return $ts === false ? trim($value) : date('c', $ts);
    }

    /** Earliest of a set of ISO-8601 timestamps (null-safe). */
    private static function earliest(array $values): ?string
    {
        $clean = array_values(array_filter($values, static fn(mixed $v): bool => is_string($v) && $v !== ''));
        return $clean === [] ? null : min($clean);
    }

    /** Latest of a set of ISO-8601 timestamps (null-safe). */
    private static function latest(array $values): ?string
    {
        $clean = array_values(array_filter($values, static fn(mixed $v): bool => is_string($v) && $v !== ''));
        return $clean === [] ? null : max($clean);
    }
}
