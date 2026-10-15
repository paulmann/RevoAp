<?php
/**
 * SkillRepository: PDO-backed CRUD for the `skills` table.
 *
 * Accepts either a raw PDO (legacy) or a resilient App\Core\Database
 * wrapper. When a Database is supplied every statement survives a
 * single "MySQL server has gone away" / "Server shutdown in progress"
 * disconnect via Database::run().
 *
 * That distinction is NOT cosmetic. This repository sits on the
 * pipeline's critical path — the engine resolves a skill slug and
 * increments `use_count` on every run — and it deliberately has no
 * swallow-and-continue error handling: a broken skill store must be
 * visible, not silently degraded to "no skill matched". The
 * consequence is that a handle cached at boot aborts the whole run the
 * moment MySQL restarts. Handing in a `Database` instead of a `PDO` is
 * what lets a long-lived worker survive that restart.
 *
 * Falls back to in-memory storage when no database is available, so the
 * rest of the app can operate without one (useful for CI / smoke).
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Skills;

use App\Core\Database;
use App\Helpers\JsonHelper;
use PDO;

final class SkillRepository
{
    /** @var array<int,Skill> */
    private array $memory = [];
    private int $memoryAutoId = 0;
    private readonly ?Database $db;

    public function __construct(null|PDO|Database $db = null)
    {
        $this->db = $db instanceof PDO ? Database::fromPdo($db) : $db;
    }

    /** The resilient wrapper backing this repository, when there is one. */
    public function database(): ?Database
    {
        return $this->db;
    }

    public function findBySlug(string $slug): ?Skill
    {
        if ($this->db === null) {
            foreach ($this->memory as $s) {
                if ($s->slug === $slug) { return $s; }
            }
            return null;
        }
        $row = $this->db->run(static function (PDO $pdo) use ($slug): ?array {
            $stmt = $pdo->prepare('SELECT * FROM skills WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $slug]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            return is_array($row) ? $row : null;
        });
        return $row === null ? null : Skill::fromRow($row);
    }

    public function findById(int $id): ?Skill
    {
        if ($this->db === null) {
            return $this->memory[$id] ?? null;
        }
        $row = $this->db->run(static function (PDO $pdo) use ($id): ?array {
            $stmt = $pdo->prepare('SELECT * FROM skills WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            $stmt->closeCursor();
            return is_array($row) ? $row : null;
        });
        return $row === null ? null : Skill::fromRow($row);
    }

    /** @return Skill[] */
    public function all(): array
    {
        if ($this->db === null) {
            return array_values($this->memory);
        }
        $rows = $this->db->run(static function (PDO $pdo): array {
            $stmt = $pdo->query('SELECT * FROM skills ORDER BY id ASC');
            $rows = $stmt !== false ? $stmt->fetchAll() : [];
            return is_array($rows) ? $rows : [];
        });
        return array_map(
            static fn(array $r): Skill => Skill::fromRow($r),
            array_values(array_filter($rows, 'is_array')),
        );
    }

    public function save(Skill $skill): Skill
    {
        if ($this->db === null) {
            $id = $skill->id ?? ++$this->memoryAutoId;
            $stored = new Skill(
                id:          $id,
                slug:        $skill->slug,
                name:        $skill->name,
                description: $skill->description,
                definition:  $skill->definition,
                isSystem:    $skill->isSystem,
                useCount:    $skill->useCount,
            );
            $this->memory[$id] = $stored;
            return $stored;
        }

        if ($skill->id === null) {
            $id = (int)$this->db->run(static function (PDO $pdo) use ($skill): int {
                $stmt = $pdo->prepare(
                    'INSERT INTO skills (slug, name, description, definition, is_system, use_count)
                     VALUES (:slug, :name, :description, :definition, :is_system, :use_count)'
                );
                $stmt->execute([
                    'slug'        => $skill->slug,
                    'name'        => $skill->name,
                    'description' => $skill->description,
                    'definition'  => JsonHelper::encode($skill->definition),
                    'is_system'   => $skill->isSystem ? 1 : 0,
                    'use_count'   => $skill->useCount,
                ]);
                $id = (int)$pdo->lastInsertId();
                $stmt->closeCursor();
                return $id;
            });
            return new Skill($id, $skill->slug, $skill->name, $skill->description, $skill->definition, $skill->isSystem, $skill->useCount);
        }

        $this->db->run(static function (PDO $pdo) use ($skill): void {
            $stmt = $pdo->prepare(
                'UPDATE skills
                 SET name = :name, description = :description, definition = :definition,
                     is_system = :is_system, use_count = :use_count
                 WHERE id = :id'
            );
            $stmt->execute([
                'id'          => $skill->id,
                'name'        => $skill->name,
                'description' => $skill->description,
                'definition'  => JsonHelper::encode($skill->definition),
                'is_system'   => $skill->isSystem ? 1 : 0,
                'use_count'   => $skill->useCount,
            ]);
            $stmt->closeCursor();
        });
        return $skill;
    }

    public function delete(int $id): void
    {
        if ($this->db === null) {
            unset($this->memory[$id]);
            return;
        }
        $this->db->run(static function (PDO $pdo) use ($id): void {
            $stmt = $pdo->prepare('DELETE FROM skills WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $stmt->closeCursor();
        });
    }

    public function incrementUseCount(int $id): void
    {
        if ($this->db === null) {
            if (isset($this->memory[$id])) {
                $s = $this->memory[$id];
                $this->memory[$id] = new Skill($s->id, $s->slug, $s->name, $s->description, $s->definition, $s->isSystem, $s->useCount + 1);
            }
            return;
        }
        $this->db->run(static function (PDO $pdo) use ($id): void {
            $stmt = $pdo->prepare('UPDATE skills SET use_count = use_count + 1 WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $stmt->closeCursor();
        });
    }
}
