<?php
/**
 * SkillDoc: one consensus skill, rendered as the markdown the Skills page
 * opens in a new window.
 *
 * The page lists skills with one short line each; this document is the full
 * record - what the skill is, which council it binds to, every role with its
 * verbatim system prompt, and the arXiv papers the skill carries as its
 * sources. Pure and deterministic on purpose: the same skill renders the
 * same bytes, so what the viewer opens is exactly what the server wrote.
 *
 * Rol rendering is defensive about the two shapes in play: bundled
 * definitions use {label, system}, skills kept through the draft flow may
 * use {name, system_prompt}; a role renders with whichever it has and never
 * crashes the document.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
declare(strict_types=1);

namespace App\Skills;

final class SkillDoc
{
    /**
     * @param array{
     *   slug:string,name:string,description:string,source:string,scoring:string,
     *   preset:?array<string,mixed>,models:array<int,array<string,mixed>>,uses_user_pool:bool,
     *   roles:array<int,array<string,mixed>>,
     *   sources:array<int,array<string,mixed>>
     * } $skill
     */
    public static function markdown(array $skill): string
    {
        $lines = [];
        $lines[] = '# ' . self::text($skill['name'] ?? '', '(untitled skill)');
        $lines[] = '';

        $description = trim((string)($skill['description'] ?? ''));
        if ($description !== '') {
            $lines[] = $description;
            $lines[] = '';
        }

        $preset = is_array($skill['preset'] ?? null) ? $skill['preset'] : null;
        $presetLabel = '_none - the council runs on the selected pool_';
        if ($preset !== null) {
            $presetLabel = '`' . self::text($preset['slug'] ?? ($preset['name'] ?? ''), '-') . '`';
        }
        $models = self::modelNames(is_array($skill['models'] ?? null) ? $skill['models'] : []);
        if ($models === [] && ($skill['uses_user_pool'] ?? false) === true) {
            $models = ['_(from the selected pool)_'];
        }

        $lines[] = '| | |';
        $lines[] = '|---|---|';
        $lines[] = '| Slug | `' . self::text($skill['slug'] ?? '', '-') . '` |';
        $lines[] = '| Source | ' . (($skill['source'] ?? '') === 'user' ? 'user - kept through the draft flow' : 'bundled') . ' |';
        $lines[] = '| Consensus scoring | `' . self::text($skill['scoring'] ?? '', '-') . '` |';
        $lines[] = '| Preset | ' . $presetLabel . ' |';
        $lines[] = '| Models | ' . ($models === [] ? '-' : implode(', ', $models)) . ' |';
        $lines[] = '';

        if (($skill['auto_selector'] ?? false) === true) {
            // The selector is machinery, not a council: its page explains the
            // stages instead of showing an empty role list.
            $stages = is_array($skill['stages'] ?? null) ? array_values($skill['stages']) : [];
            $lines[] = '## How it works';
            $lines[] = '';
            foreach ($stages as $i => $stage) {
                if (!is_array($stage)) {
                    continue;
                }
                $lines[] = ($i + 1) . '. **' . self::cell((string)($stage['title'] ?? ('Stage ' . ($i + 1)))) . '** - '
                    . self::text($stage['text'] ?? '', '');
            }
            $lines[] = '';
        } else {
        $roles = is_array($skill['roles'] ?? null) ? array_values($skill['roles']) : [];
        $lines[] = '## Roles (' . count($roles) . ')';
        $lines[] = '';
        foreach ($roles as $i => $role) {
            if (!is_array($role)) {
                continue;
            }
            $label = self::text($role['label'] ?? ($role['name'] ?? ($role['key'] ?? '')), 'Role ' . ($i + 1));
            $key = trim((string)($role['key'] ?? ''));
            $lines[] = '### ' . ($i + 1) . '. ' . $label . ($key !== '' ? ' - `' . self::cell($key) . '`' : '');
            $lines[] = '';
            $prompt = (string)($role['system'] ?? ($role['system_prompt'] ?? ''));
            if (trim($prompt) !== '') {
                $lines[] = '```text';
                foreach (preg_split('/\r\n|\r|\n/', $prompt) ?: [] as $promptLine) {
                    $lines[] = rtrim($promptLine);
                }
                $lines[] = '```';
                $lines[] = '';
            }
        }
        }

        $sources = self::sourceLinks(is_array($skill['sources'] ?? null) ? $skill['sources'] : []);
        if ($sources === []) {
            // No "## Sources" heading on purpose: MD.Viewer lifts such a
            // section out of the body, and an empty one would vanish there.
            $lines[] = '_No sources are recorded for this skill._';
        } else {
            // The numbered "**Title**" plus "- URL:" shape is MD.Viewer's
            // canonical sources section: the viewer swaps it for a Sources List
            // with clickable links. Every entry still carries its URL in plain
            // text for any other reader of the file.
            $lines[] = '## Sources';
            $lines[] = '';
            $index = 0;
            foreach ($sources as $source) {
                if ($index > 0) {
                    $lines[] = '';
                }
                $index++;
                $title = str_replace(['\\[', '\\]', '\\|'], ['[', ']', '|'], $source['title']);
                $lines[] = $index . '. **' . $title . '**';
                $lines[] = '   - URL: ' . $source['url'];
            }
        }
        $lines[] = '';

        $lines[] = '---';
        $lines[] = '_Rendered by RevoAp on demand from the skill definition; the file is written next to the app and, when MD.Viewer is installed, opened through it._';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Model rows arrive as provider/model ids from the catalogue; render
     * them the way the composer shows them.
     *
     * @param array<int,mixed> $models
     * @return array<int,string>
     */
    private static function modelNames(array $models): array
    {
        $out = [];
        foreach ($models as $model) {
            if (is_string($model) && trim($model) !== '') {
                $out[] = '`' . self::cell(trim($model)) . '`';
                continue;
            }
            if (is_array($model)) {
                $provider = trim((string)($model['provider'] ?? ''));
                $name = trim((string)($model['model'] ?? ''));
                $label = trim($provider . '/' . $name, '/');
                if ($label !== '') {
                    $out[] = '`' . self::cell($label) . '`';
                }
            }
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $sources
     * @return array<int,array{title:string,url:string}>
     */
    private static function sourceLinks(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $url = trim((string)($source['url'] ?? ''));
            if (preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            $title = trim((string)($source['title'] ?? ''));
            if ($title === '') {
                $title = $url;
            }
            $out[] = [
                'title' => str_replace(['[', ']', '|'], ['\\[', '\\]', '\\|'], $title),
                'url' => $url,
            ];
        }
        return $out;
    }

    /** Escape table-breaking characters inside one cell. */
    private static function cell(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], trim($value));
    }

    private static function text(mixed $value, string $fallback): string
    {
        $text = is_string($value) ? self::cell($value) : '';
        return $text === '' ? $fallback : $text;
    }
}
