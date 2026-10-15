<?php
/**
 * PopularModels: curated list of recommended models per provider.
 *
 * The Settings UI shows a "Top 10" recommendation list — these are the
 * picks we have validated against the live `/models` endpoints. When the
 * user runs "Import OpenRouter models" or "Import BotHub models", the
 * controller intersects this curated list with the upstream catalog so
 * the UI never recommends a slug the provider has retired.
 *
 * Since v1.8.15 the source of truth for these picks is the shipped
 * `config/models/*.php` catalogues — this class merely flattens those
 * files into a `provider/model/note` shape for backward-compatible
 * callers. New picks should be added to the catalogue file, not here.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.1.0
 * @since    2026-05-07
 */
declare(strict_types=1);

namespace App\Settings;

use App\Core\Config;
use App\Helpers\RootFiles;

final class PopularModels
{
    /**
     * @return array<int,array{provider:string,model:string,note:string}>
     */
    public static function recommended(): array
    {
        $rows = self::loadFromCatalogue();
        if ($rows === []) {
            // Fallback so tooling never receives an empty recommendation
            // list when the catalogue files are missing or unreadable —
            // matches the four fresh-install defaults shipped in v1.8.15.
            $rows = [
                ['provider' => 'openrouter', 'model' => 'openai/gpt-chat-latest',       'note' => 'GPT (latest)'],
                ['provider' => 'bothub', 'model' => 'gpt-6-luna-pro',           'note' => 'GPT-6 Luna Pro'],
                ['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-4.6',  'note' => 'Claude Sonnet 4.6'],
                ['provider' => 'openrouter', 'model' => 'google/gemini-3.1-pro-preview','note' => 'Gemini 3.1 Pro'],
            ];
        }
        return $rows;
    }

    /**
     * Intersect the curated list with the live OpenRouter catalog so the UI
     * never shows recommendations whose IDs have been retired.
     *
     * @param  array<int,array<string,mixed>> $catalog
     * @return array<int,array{provider:string,model:string,note:string,available:bool}>
     */
    public static function annotateOpenRouter(array $catalog): array
    {
        $live = [];
        foreach ($catalog as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (is_string($id)) {
                $live[strtolower($id)] = true;
            }
        }
        $out = [];
        foreach (self::recommended() as $rec) {
            if ($rec['provider'] !== 'openrouter') { continue; }
            $rec['available'] = isset($live[strtolower($rec['model'])]);
            $out[] = $rec;
        }
        return $out;
    }

    /**
     * Flatten the configured `model_files` catalogues (or the runtime
     * `models` list when an operator has supplied one) into the
     * `provider/model/note` shape expected by callers.
     *
     * @return array<int,array{provider:string,model:string,note:string}>
     */
    private static function loadFromCatalogue(): array
    {
        $rows = [];
        // 1. Prefer the runtime catalogue — it already reflects the
        //    operator's `.config.php` / `runtime.json` overrides.
        try {
            $live = (array)Config::get('models', []);
        } catch (\Throwable) {
            $live = [];
        }
        if ($live !== []) {
            foreach ($live as $entry) {
                if (!is_array($entry)) { continue; }
                $row = self::flattenRow($entry);
                if ($row !== null) { $rows[] = $row; }
            }
            return $rows;
        }
        // 2. Fallback to the shipped files directly so callers that
        //    instantiate this class before Config::load() (CLI tools,
        //    tests) still see the curated list.
        $rootDir = '';
        try {
            $rootDir = Config::rootDir();
        } catch (\Throwable) {
            $rootDir = '';
        }
        if ($rootDir === '') {
            $rootDir = RootFiles::resolveRoot();
        }
        foreach (['config/models/openrouter.php', 'config/models/bothub.php', 'config/models/direct.php'] as $rel) {
            $abs = $rootDir . DIRECTORY_SEPARATOR . $rel;
            if (!is_file($abs)) {
                continue;
            }
            try {
                /** @var mixed $loaded */
                $loaded = (static function (string $f): mixed {
                    return require $f;
                })($abs);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($loaded)) {
                continue;
            }
            foreach ($loaded as $entry) {
                if (!is_array($entry)) { continue; }
                $row = self::flattenRow($entry);
                if ($row !== null) { $rows[] = $row; }
            }
        }
        return $rows;
    }

    /**
     * @param  array<string,mixed> $entry
     * @return array{provider:string,model:string,note:string}|null
     */
    private static function flattenRow(array $entry): ?array
    {
        $provider = strtolower(trim((string)($entry['provider'] ?? '')));
        $model    = trim((string)($entry['model'] ?? ''));
        if ($provider === '' || $model === '') {
            return null;
        }
        $note = isset($entry['label']) && $entry['label'] !== ''
            ? (string)$entry['label']
            : $model;
        return ['provider' => $provider, 'model' => $model, 'note' => $note];
    }
}
