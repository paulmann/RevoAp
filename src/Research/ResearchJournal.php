<?php
/**
 * ResearchJournal: the searcher's memory - runs, plans, and seen papers.
 *
 * The research card was ephemeral at first, and that was wrong: a search is
 * the cheap part, the analysis is not, and "which papers have I already seen"
 * cannot be answered without a memory. This journal is that memory. Every
 * search and analysis is recorded with what it showed, and the change plan
 * travels with the run - a draft, until the operator marks it active. Nothing
 * is promoted silently and nothing is deleted silently.
 *
 * Two tables. `research_runs` holds one row per run: the papers as shown
 * (JSON), the analysis, the proposals, the plan and its state.
 * `research_seen` remembers every paper the operator was shown, first
 * sighting wins - it is what "only new" filters against, and it survives the
 * deletion of the run it was born from, because "seen" stays true.
 *
 * Every method degrades to a safe default when the database or the tables are
 * missing, because a research search must never fail over its own
 * bookkeeping: the controller reports `saved: false` and the card says the
 * journal is unavailable. There is deliberately no in-memory fallback - a
 * journal that lives inside one request cannot remember anything.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Research;

use App\Core\Database;
use PDO;
use PDOException;

final class ResearchJournal
{
    public const RUNS_TABLE = 'research_runs';
    public const SEEN_TABLE = 'research_seen';

    /** Plan statuses an operator can choose; anything else is refused. */
    public const STATUSES = ['draft', 'active', 'done', 'dropped'];
    public const DEFAULT_STATUS = 'draft';

    /** Cap on the remembered list of checkbox states, per run. */
    private const CHECKED_CAP = 64;

    private readonly ?Database $db;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    /** The key a paper is remembered by: scheme and version folded away. */
    public static function paperKey(string $id): string
    {
        $id = strtolower(trim($id));
        $id = (string)preg_replace('#^https?://#', '', $id);
        return PaperRanker::baseId($id);
    }

    /** Whether a writable journal is present: the database and both tables. */
    public function available(): bool
    {
        if ($this->db === null) {
            return false;
        }
        return $this->tableExists(self::RUNS_TABLE) && $this->tableExists(self::SEEN_TABLE);
    }

    /**
     * Which of these papers were seen before, and when (first sighting).
     *
     * @param array<int,string> $keys paper ids or urls
     * @return array<string,string> paperKey => 'Y-m-d'
     */
    public function seenAt(array $keys): array
    {
        if (!$this->available()) {
            return [];
        }
        $clean = [];
        foreach ($keys as $key) {
            $key = self::paperKey((string)$key);
            if ($key !== '') {
                $clean[$key] = true;
            }
            if (count($clean) >= 100) {
                break;
            }
        }
        if ($clean === []) {
            return [];
        }
        try {
            $rows = $this->db?->run(static function (PDO $pdo) use ($clean): array {
                $list = array_keys($clean);
                $placeholders = implode(', ', array_fill(0, count($list), '?'));
                $stmt = $pdo->prepare(
                    'SELECT base_id, first_seen_at FROM research_seen WHERE base_id IN (' . $placeholders . ')'
                );
                $stmt->execute($list);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($rows) ? $rows : [];
            });
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ((array)$rows as $row) {
            if (is_array($row)) {
                $out[(string)($row['base_id'] ?? '')] = substr((string)($row['first_seen_at'] ?? ''), 0, 10);
            }
        }
        return $out;
    }

    /**
     * Record one run and remember every paper it showed.
     *
     * @param array{kind?:string,topic?:string,query?:string,candidates?:int,papers?:array,window?:array,provider?:string,model?:string,tokens?:int,analysis?:?string,proposals?:array,plan?:?string} $run
     * @return int|null the new run id, or null when the journal is unavailable
     */
    public function record(array $run): ?int
    {
        if (!$this->available()) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        $kind = ($run['kind'] ?? '') === 'search' ? 'search' : 'analyse';
        $topic = mb_substr((string)($run['topic'] ?? ''), 0, 400);
        $query = mb_substr((string)($run['query'] ?? ''), 0, 500);
        $candidates = max(0, (int)($run['candidates'] ?? 0));
        $papers = is_array($run['papers'] ?? null) ? array_values($run['papers']) : [];
        $papersJson = (string)json_encode($papers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $windowRows = is_array($run['window'] ?? null) ? array_values($run['window']) : [];
        $windowJson = $windowRows === []
            ? null : (string)json_encode($windowRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $provider = mb_substr((string)($run['provider'] ?? ''), 0, 64);
        $model = mb_substr((string)($run['model'] ?? ''), 0, 191);
        $tokens = max(0, (int)($run['tokens'] ?? 0));
        $analysis = isset($run['analysis']) && is_string($run['analysis']) && $run['analysis'] !== ''
            ? $run['analysis'] : null;
        $proposals = is_array($run['proposals'] ?? null) ? array_values($run['proposals']) : [];
        $proposalsJson = $proposals === []
            ? null : (string)json_encode($proposals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $plan = isset($run['plan']) && is_string($run['plan']) && $run['plan'] !== '' ? $run['plan'] : null;
        $checkedJson = $proposals === [] ? null : (string)json_encode(array_fill(0, count($proposals), false));

        try {
            $runId = (int)$this->db?->run(static function (PDO $pdo) use (
                $now, $kind, $topic, $query, $candidates, $papersJson, $windowJson, $provider, $model, $tokens,
                $analysis, $proposalsJson, $plan, $checkedJson
            ): int {
                $stmt = $pdo->prepare(
                    'INSERT INTO research_runs
                        (created_at, updated_at, kind, topic, query, candidates, papers_json, candidates_json,
                         provider, model, tokens, analysis, proposals_json, plan_md, plan_status, plan_checked_json)
                     VALUES
                        (:created_at, :updated_at, :kind, :topic, :query, :candidates, :papers, :window,
                         :provider, :model, :tokens, :analysis, :proposals, :plan, :status, :checked)'
                );
                $stmt->execute([
                    'created_at' => $now,
                    'updated_at' => $now,
                    'kind' => $kind,
                    'topic' => $topic,
                    'query' => $query,
                    'candidates' => $candidates,
                    'papers' => $papersJson,
                    'window' => $windowJson,
                    'provider' => $provider,
                    'model' => $model,
                    'tokens' => $tokens,
                    'analysis' => $analysis,
                    'proposals' => $proposalsJson,
                    'plan' => $plan,
                    'status' => self::DEFAULT_STATUS,
                    'checked' => $checkedJson,
                ]);
                $stmt->closeCursor();
                return (int)$pdo->lastInsertId();
            });
        } catch (PDOException) {
            return null;
        }
        if ($runId === null || $runId <= 0) {
            return null;
        }
        $this->remember($papers, $runId, $now);
        return $runId;
    }

    /**
     * Recent runs, newest first, as summaries for the history list.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 30): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            $rows = $this->db?->run(static function (PDO $pdo) use ($limit): array {
                $stmt = $pdo->query(
                    'SELECT id, created_at, kind, topic, candidates, provider, model, tokens,
                            plan_status, papers_json, candidates_json, proposals_json
                     FROM research_runs ORDER BY id DESC LIMIT ' . $limit
                );
                $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                return is_array($rows) ? $rows : [];
            });
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'created_at' => (string)($row['created_at'] ?? ''),
                'kind' => (string)($row['kind'] ?? ''),
                'topic' => (string)($row['topic'] ?? ''),
                'candidates' => (int)($row['candidates'] ?? 0),
                'provider' => (string)($row['provider'] ?? ''),
                'model' => (string)($row['model'] ?? ''),
                'tokens' => (int)($row['tokens'] ?? 0),
                'plan_status' => (string)($row['plan_status'] ?? self::DEFAULT_STATUS),
                'papers' => count(self::decodeList($row['papers_json'] ?? null)),
                'found' => count(self::decodeList($row['candidates_json'] ?? null)),
                'proposals' => count(self::decodeList($row['proposals_json'] ?? null)),
            ];
        }
        return $out;
    }

    /** One full run, or null. */
    public function find(int $id): ?array
    {
        if (!$this->available() || $id <= 0) {
            return null;
        }
        try {
            $row = $this->db?->run(static function (PDO $pdo) use ($id): ?array {
                $stmt = $pdo->prepare('SELECT * FROM research_runs WHERE id = ? LIMIT 1');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($row) ? $row : null;
            });
        } catch (PDOException) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'kind' => (string)($row['kind'] ?? ''),
            'topic' => (string)($row['topic'] ?? ''),
            'query' => (string)($row['query'] ?? ''),
            'candidates' => (int)($row['candidates'] ?? 0),
            'papers' => self::decodeList($row['papers_json'] ?? null),
            'candidates' => self::decodeList($row['candidates_json'] ?? null),
            'provider' => (string)($row['provider'] ?? ''),
            'model' => (string)($row['model'] ?? ''),
            'tokens' => (int)($row['tokens'] ?? 0),
            'analysis' => isset($row['analysis']) && is_string($row['analysis']) && $row['analysis'] !== ''
                ? $row['analysis'] : null,
            'proposals' => self::decodeList($row['proposals_json'] ?? null),
            'plan' => isset($row['plan_md']) && is_string($row['plan_md']) && $row['plan_md'] !== ''
                ? $row['plan_md'] : null,
            'plan_status' => (string)($row['plan_status'] ?? self::DEFAULT_STATUS),
            'plan_checked' => array_map(
                static fn(mixed $value): bool => (bool)$value,
                self::decodeList($row['plan_checked_json'] ?? null)
            ),
        ];
    }

    /** Delete one run; its seen entries stay, only the run_id pointing at it clears. */
    public function delete(int $id): bool
    {
        if (!$this->available() || $id <= 0) {
            return false;
        }
        try {
            return (bool)$this->db?->run(static function (PDO $pdo) use ($id): bool {
                $stmt = $pdo->prepare('UPDATE research_seen SET run_id = NULL WHERE run_id = ?');
                $stmt->execute([$id]);
                $stmt->closeCursor();
                $del = $pdo->prepare('DELETE FROM research_runs WHERE id = ?');
                $del->execute([$id]);
                $removed = $del->rowCount();
                $del->closeCursor();
                return $removed > 0;
            });
        } catch (PDOException) {
            return false;
        }
    }

    /** Delete every run AND the seen memory; returns the number of runs deleted. */
    public function clear(): int
    {
        if (!$this->available()) {
            return 0;
        }
        try {
            return (int)$this->db?->run(static function (PDO $pdo): int {
                $pdo->exec('DELETE FROM research_seen');
                $removed = $pdo->exec('DELETE FROM research_runs');
                return (int)($removed === false ? 0 : $removed);
            });
        } catch (PDOException) {
            return 0;
        }
    }

    /** Validate against STATUSES, then update. True when the run exists. */
    public function setPlanStatus(int $id, string $status): bool
    {
        $status = strtolower(trim($status));
        if (!$this->available() || $id <= 0 || !in_array($status, self::STATUSES, true)) {
            return false;
        }
        try {
            return (bool)$this->db?->run(static function (PDO $pdo) use ($id, $status): bool {
                $stmt = $pdo->prepare('UPDATE research_runs SET plan_status = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$status, date('Y-m-d H:i:s'), $id]);
                $stmt->closeCursor();
                return self::rowExists($pdo, $id);
            });
        } catch (PDOException) {
            return false;
        }
    }

    /** Store the checkbox state (sanitized to a capped list of booleans). */
    public function setPlanChecked(int $id, array $checked): bool
    {
        if (!$this->available() || $id <= 0) {
            return false;
        }
        $clean = [];
        foreach (array_slice(array_values($checked), 0, self::CHECKED_CAP) as $value) {
            $clean[] = (bool)$value;
        }
        $json = (string)json_encode($clean);
        try {
            return (bool)$this->db?->run(static function (PDO $pdo) use ($id, $json): bool {
                $stmt = $pdo->prepare('UPDATE research_runs SET plan_checked_json = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$json, date('Y-m-d H:i:s'), $id]);
                $stmt->closeCursor();
                return self::rowExists($pdo, $id);
            });
        } catch (PDOException) {
            return false;
        }
    }

    /** Remember the papers a run showed; first sighting wins. Best-effort. */
    private function remember(array $papers, int $runId, string $now): void
    {
        $keys = [];
        foreach ($papers as $paper) {
            if (!is_array($paper)) {
                continue;
            }
            $key = self::paperKey((string)($paper['id'] ?? ($paper['url'] ?? '')));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        if ($keys === [] || $this->db === null) {
            return;
        }
        try {
            $this->db->run(static function (PDO $pdo) use ($keys, $runId, $now): void {
                $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $sql = $driver === 'sqlite'
                    ? 'INSERT OR IGNORE INTO research_seen (base_id, run_id, first_seen_at) VALUES (?, ?, ?)'
                    : 'INSERT IGNORE INTO research_seen (base_id, run_id, first_seen_at) VALUES (?, ?, ?)';
                $stmt = $pdo->prepare($sql);
                foreach (array_keys($keys) as $key) {
                    $stmt->execute([$key, $runId, $now]);
                }
                $stmt->closeCursor();
            });
        } catch (PDOException) {
            // The run is already recorded; the memory is best-effort.
        }
    }

    private function tableExists(string $table): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            return (bool)$this->db->run(static function (PDO $pdo) use ($table): bool {
                $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
                    $stmt->execute([$table]);
                    $name = $stmt->fetchColumn();
                    $stmt->closeCursor();
                    return is_string($name) && $name !== '';
                }
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
                );
                $stmt->execute([$table]);
                $found = (bool)$stmt->fetchColumn();
                $stmt->closeCursor();
                return $found;
            });
        } catch (PDOException) {
            return false;
        }
    }

    /** Whether a run row exists - an UPDATE that changed nothing must still count. */
    private static function rowExists(PDO $pdo, int $id): bool
    {
        $check = $pdo->prepare('SELECT 1 FROM research_runs WHERE id = ? LIMIT 1');
        $check->execute([$id]);
        $exists = (bool)$check->fetchColumn();
        $check->closeCursor();
        return $exists;
    }

    /** @return array<int,mixed> */
    private static function decodeList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
