<?php
/**
 * DomainPreset: readonly DTO for a domain → model-pool mapping.
 *
 * Each preset binds a slug (`default`, `mathematics`, `physics`, …) to
 * a list of selected models and a designated arbiter/main/checker
 * model used during synthesis. Optional `skill_slug` links the preset
 * to a skill JSON definition so auto-routing can pick up math/physics
 * prompts in one step.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\DomainPresets;

final readonly class DomainPreset
{
    /**
     * @param array<int,array{provider:string,model:string,label?:string,active?:bool,selected?:bool}> $models
     * @param array{provider:string,model:string,role?:string}|null $arbiter
     */
    public function __construct(
        public ?int    $id,
        public string  $slug,
        public string  $name,
        public string  $description,
        public array   $models,
        public ?array  $arbiter,
        public ?string $skillSlug,
        public bool    $isSystem = false,
    ) {
        if ($this->slug === '' || $this->name === '') {
            throw new \InvalidArgumentException('DomainPreset slug and name are required');
        }
    }

    /** @return array<int,array{provider:string,model:string}> */
    public function selectedModels(): array
    {
        $out = [];
        foreach ($this->models as $m) {
            $selected = (bool)($m['selected'] ?? true);
            $active   = (bool)($m['active']   ?? true);
            if (!$selected || !$active) { continue; }
            $p = (string)($m['provider'] ?? '');
            $id = (string)($m['model'] ?? '');
            if ($p === '' || $id === '') { continue; }
            $out[] = ['provider' => $p, 'model' => $id];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'models'      => $this->models,
            'arbiter'     => $this->arbiter,
            'skill_slug'  => $this->skillSlug,
            'is_system'   => $this->isSystem,
        ];
    }

    /** @param array<string,mixed> $definition */
    public static function fromDefinition(
        ?int $id,
        string $slug,
        string $name,
        string $description,
        array $definition,
        bool $isSystem,
    ): self {
        $models = [];
        if (is_array($definition['models'] ?? null)) {
            foreach ($definition['models'] as $m) {
                if (!is_array($m)) { continue; }
                $models[] = [
                    'provider' => (string)($m['provider'] ?? ''),
                    'model'    => (string)($m['model'] ?? ''),
                    'label'    => isset($m['label']) ? (string)$m['label'] : null,
                    'active'   => array_key_exists('active', $m) ? (bool)$m['active'] : true,
                    'selected' => array_key_exists('selected', $m) ? (bool)$m['selected'] : true,
                ];
            }
        }
        $arbiter = null;
        $a = $definition['arbiter'] ?? null;
        if (is_array($a) && isset($a['provider'], $a['model'])) {
            $arbiter = [
                'provider' => (string)$a['provider'],
                'model'    => (string)$a['model'],
                'role'     => isset($a['role']) ? (string)$a['role'] : 'main',
            ];
        }
        $skillSlug = isset($definition['skill_slug']) ? (string)$definition['skill_slug'] : null;
        return new self(
            id: $id,
            slug: $slug,
            name: $name,
            description: $description,
            models: $models,
            arbiter: $arbiter,
            skillSlug: $skillSlug === '' ? null : $skillSlug,
            isSystem: $isSystem,
        );
    }
}
