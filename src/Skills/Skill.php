<?php
/**
 * Skill: Domain entity describing a reusable consensus configuration.
 *
 * Each skill names a set of roles (LLMs answering specialized sub-questions),
 * a synthesis strategy, and optional output schemas for normalization.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Skills;

final readonly class Skill
{
    /**
     * @param array<string,mixed> $definition
     */
    public function __construct(
        public ?int    $id,
        public string  $slug,
        public string  $name,
        public string  $description,
        public array   $definition,
        public bool    $isSystem = false,
        public int     $useCount = 0,
    ) {
        if ($this->slug === '' || $this->name === '') {
            throw new \InvalidArgumentException('Skill slug and name are required');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function roles(): array
    {
        $r = $this->definition['roles'] ?? [];
        return is_array($r) ? array_values($r) : [];
    }

    public function synthesisPrompt(): string
    {
        return (string)($this->definition['synthesis_prompt'] ?? '');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'definition'  => $this->definition,
            'is_system'   => $this->isSystem,
            'use_count'   => $this->useCount,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $def = $row['definition'] ?? [];
        if (is_string($def)) {
            $decoded = json_decode($def, true);
            $def = is_array($decoded) ? $decoded : [];
        }
        return new self(
            id:          isset($row['id']) ? (int)$row['id'] : null,
            slug:        (string)($row['slug'] ?? ''),
            name:        (string)($row['name'] ?? ''),
            description: (string)($row['description'] ?? ''),
            definition:  is_array($def) ? $def : [],
            isSystem:    (bool)($row['is_system'] ?? false),
            useCount:    (int)($row['use_count'] ?? 0),
        );
    }
}
