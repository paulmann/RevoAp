<?php
/**
 * SkillLoader: Loads JSON skill definitions from disk.
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

use App\Helpers\JsonHelper;

final class SkillLoader
{
    public function __construct(private readonly string $definitionsDir) {}

    /**
     * @return Skill[]
     */
    public function loadAll(): array
    {
        if (!is_dir($this->definitionsDir)) {
            return [];
        }
        $files = glob(rtrim($this->definitionsDir, '/') . '/*.json') ?: [];
        $out   = [];
        foreach ($files as $f) {
            $skill = $this->loadFile($f);
            if ($skill !== null) {
                $out[] = $skill;
            }
        }
        return $out;
    }

    public function loadFile(string $file): ?Skill
    {
        if (!is_file($file)) {
            return null;
        }
        $body = (string)file_get_contents($file);
        $data = JsonHelper::tryDecode($body);
        if (!is_array($data)) {
            return null;
        }
        return new Skill(
            id:          null,
            slug:        (string)($data['slug'] ?? pathinfo($file, PATHINFO_FILENAME)),
            name:        (string)($data['name'] ?? ''),
            description: (string)($data['description'] ?? ''),
            definition:  $data,
            isSystem:    (bool)($data['is_system'] ?? true),
            useCount:    0,
        );
    }
}
