<?php
/**
 * DomainPresetRepository: PDO-backed CRUD for the `domain_presets`
 * table plus an in-memory fallback when the database is unavailable.
 *
 * Accepts either a raw PDO (legacy) or a resilient App\Core\Database
 * wrapper. When a Database is supplied every statement survives a
 * single "MySQL server has gone away" / "Server shutdown in progress"
 * disconnect via Database::run(), which matters because the engine
 * resolves the default preset on the pipeline's critical path
 * ({@see PipelineEngine::run}).
 *
 * The repository seeds three canonical presets (`default`,
 * `mathematics`, `physics`) the first time it is invoked against an
 * empty store so a brand-new install has a working auto-routing
 * configuration without operator intervention.
 *
 * Default model picks (Mathematics / Physics) come from the user's
 * v1.8.32 brief and intentionally use the OpenRouter provider map so
 * existing connector configurations keep working out of the box.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\DomainPresets;

use App\Core\Database;
use PDO;
use PDOException;

final class DomainPresetRepository
{
    public const TABLE = 'domain_presets';

    /** @var array<string,DomainPreset> in-memory fallback keyed by slug */
    private array $memory = [];
    private int $autoId = 0;
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

    public function tableAvailable(): bool
    {
        if ($this->db === null) {
            return false;
        }
        try {
            return (bool)$this->db->run(static function (PDO $pdo): bool {
                $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $r = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . self::TABLE . "'");
                    $name = $r ? $r->fetchColumn() : false;
                    return is_string($name) && $name !== '';
                }
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
                );
                $stmt->execute([self::TABLE]);
                $found = (bool)$stmt->fetchColumn();
                $stmt->closeCursor();
                return $found;
            });
        } catch (PDOException) {
            return false;
        }
    }

    /** @return DomainPreset[] */
    public function all(): array
    {
        if ($this->db === null || !$this->tableAvailable()) {
            return array_values($this->memory);
        }
        try {
            $rows = $this->db->run(static function (PDO $pdo): array {
                $stmt = $pdo->query('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC');
                $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                return is_array($rows) ? $rows : [];
            });
        } catch (PDOException) {
            return array_values($this->memory);
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::hydrate($row);
            }
        }
        return $out;
    }

    public function findBySlug(string $slug): ?DomainPreset
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') { return null; }
        if ($this->db === null || !$this->tableAvailable()) {
            return $this->memory[$slug] ?? null;
        }
        try {
            $row = $this->db->run(static function (PDO $pdo) use ($slug): ?array {
                $stmt = $pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE slug = :slug LIMIT 1');
                $stmt->execute(['slug' => $slug]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();
                return is_array($row) ? $row : null;
            });
            return $row === null ? null : self::hydrate($row);
        } catch (PDOException) {
            return $this->memory[$slug] ?? null;
        }
    }

    public function save(DomainPreset $preset): DomainPreset
    {
        if ($this->db === null || !$this->tableAvailable()) {
            $id = $preset->id ?? ++$this->autoId;
            $stored = new DomainPreset(
                id: $id,
                slug: $preset->slug,
                name: $preset->name,
                description: $preset->description,
                models: $preset->models,
                arbiter: $preset->arbiter,
                skillSlug: $preset->skillSlug,
                isSystem: $preset->isSystem,
            );
            $this->memory[$preset->slug] = $stored;
            return $stored;
        }
        $definition = [
            'models'     => $preset->models,
            'arbiter'    => $preset->arbiter,
            'skill_slug' => $preset->skillSlug,
        ];
        $json = json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = 'INSERT INTO ' . self::TABLE . ' (slug, name, description, definition_json, is_system)
                VALUES (:slug, :name, :description, :def, :is_system)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    definition_json = VALUES(definition_json),
                    is_system = VALUES(is_system)';
        $this->db->run(static function (PDO $pdo) use ($sql, $preset, $json): void {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'slug' => $preset->slug,
                'name' => $preset->name,
                'description' => $preset->description,
                'def' => $json,
                'is_system' => $preset->isSystem ? 1 : 0,
            ]);
            $stmt->closeCursor();
        });
        return $this->findBySlug($preset->slug) ?? $preset;
    }

    /**
     * Seed the canonical Default / Mathematics / Physics presets when
     * the store is empty. Called from the bootstrap so a fresh install
     * always has a working auto-routing configuration.
     */
    public function seedDefaultsIfEmpty(): void
    {
        $existing = $this->all();
        if ($existing !== []) {
            return;
        }
        foreach (self::canonicalPresets() as $preset) {
            try { $this->save($preset); } catch (PDOException) { /* best effort */ }
        }
    }

    /** @return DomainPreset[] */
    public static function canonicalPresets(): array
    {
        $mathPool = [
            ['provider' => 'openrouter', 'model' => 'openai/o3',                            'label' => 'OpenAI o3',                       'active' => true, 'selected' => true],
            ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-r1-0528',            'label' => 'DeepSeek-R1-0528',                'active' => true, 'selected' => true],
            ['provider' => 'openrouter', 'model' => 'google/gemini-2.5-pro',                'label' => 'Gemini 2.5 Pro',                  'active' => true, 'selected' => true],
            ['provider' => 'openrouter', 'model' => 'anthropic/claude-opus-4.5',            'label' => 'Claude Opus 4.5',                 'active' => true, 'selected' => true],
            ['provider' => 'openrouter', 'model' => 'x-ai/grok-3-mini',                     'label' => 'Grok 3 Mini',                     'active' => true, 'selected' => true],
            ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-r1-distill-qwen-32b','label' => 'DeepSeek-R1 Distill Qwen 32B',    'active' => true, 'selected' => false],
            ['provider' => 'openrouter', 'model' => 'openai/o4-mini',                       'label' => 'OpenAI o4-mini',                  'active' => true, 'selected' => false],
            ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-prover-v2',          'label' => 'DeepSeek-Prover-V2',              'active' => true, 'selected' => false],
        ];
        $arbiter = [
            'provider' => 'bothub',
            'model'    => 'gpt-6-luna-pro',
            'role'     => 'main',
        ];
        $defaultPool = [
            ['provider' => 'openrouter', 'model' => 'openai/gpt-chat-latest',          'label' => 'GPT (latest)',           'active' => true, 'selected' => true],
            ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4.6',     'label' => 'Claude Sonnet 4.6',      'active' => true, 'selected' => true],
            ['provider' => 'openrouter', 'model' => 'google/gemini-3.1-pro-preview',   'label' => 'Gemini 3.1 Pro (preview)','active' => true,'selected' => true],
            ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-v4-pro',        'label' => 'DeepSeek V4 Pro',        'active' => true, 'selected' => true],
        ];
        $defaultArbiter = [
            'provider' => 'bothub',
            'model'    => 'gpt-6-luna-pro',
            'role'     => 'main',
        ];
        $physicsPool = $mathPool;

        return [
            new DomainPreset(
                id: null,
                slug: 'default',
                name: 'Default',
                description: 'Baseline consensus pool — the fresh-install picks used when no domain match is detected.',
                models: $defaultPool,
                arbiter: $defaultArbiter,
                skillSlug: null,
                isSystem: true,
            ),
            new DomainPreset(
                id: null,
                slug: 'mathematics',
                name: 'Mathematics',
                description: 'Reasoning-heavy pool for symbolic / arithmetic / algebraic / proof problems. Auto-applied when the analyzer detects math.',
                models: $mathPool,
                arbiter: $arbiter,
                skillSlug: 'mathematics-reasoning',
                isSystem: true,
            ),
            new DomainPreset(
                id: null,
                slug: 'physics',
                name: 'Physics',
                description: 'Reasoning-heavy pool for physics problems requiring unit checks, conservation laws, and limiting-case validation.',
                models: $physicsPool,
                arbiter: $arbiter,
                skillSlug: 'physics-reasoning',
                isSystem: true,
            ),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): DomainPreset
    {
        $def = $row['definition_json'] ?? '{}';
        if (is_string($def)) {
            $decoded = json_decode($def, true);
            $def = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($def)) { $def = []; }
        return DomainPreset::fromDefinition(
            id: isset($row['id']) ? (int)$row['id'] : null,
            slug: (string)($row['slug'] ?? ''),
            name: (string)($row['name'] ?? ''),
            description: (string)($row['description'] ?? ''),
            definition: $def,
            isSystem: (bool)($row['is_system'] ?? false),
        );
    }
}
