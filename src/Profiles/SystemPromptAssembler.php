<?php
/**
 * SystemPromptAssembler: builds the final `system` message RevoAp
 * sends to every LLM call by concatenating, in this exact order:
 *
 *   1. The base system prompt loaded from `prompts/system/default.md`.
 *   2. The active user profile's `system_prompt_addendum` (Settings →
 *      Profile tab).
 *   3. The role/skill system prompt (already supplied by the caller).
 *
 * The assembler never mutates the caller's prompt. It composes
 * additional preamble sections and returns the concatenated string so
 * the caller can drop it into the chat `messages` array as a single
 * `system` entry.
 *
 * Failures (missing file, unreadable directory) degrade gracefully —
 * the assembler returns whatever sections it was able to load so the
 * pipeline keeps running.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-13
 */
declare(strict_types=1);

namespace App\Profiles;

final class SystemPromptAssembler
{
    public const DEFAULT_RELATIVE_PATH = 'prompts/system/default.md';

    public function __construct(
        private readonly string $rootDir,
    ) {}

    /**
     * Read the base system prompt file. Returns an empty string when the
     * file is missing or unreadable so the pipeline never crashes on a
     * partial deployment.
     */
    public function baseSystemPrompt(): string
    {
        $path = rtrim($this->rootDir, '/\\') . '/' . self::DEFAULT_RELATIVE_PATH;
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $contents = @file_get_contents($path);
        return is_string($contents) ? trim($contents) : '';
    }

    /**
     * Compose the full system prompt by concatenating the base prompt,
     * the profile addendum (when set) and the role/skill prompt. Empty
     * sections are omitted so the result never carries blank
     * delimiter blocks.
     */
    public function compose(?UserProfile $profile, string $roleSystemPrompt): string
    {
        $sections = [];
        $base = $this->baseSystemPrompt();
        if ($base !== '') {
            $sections[] = $base;
        }
        if ($profile !== null) {
            $addendum = trim($profile->systemPromptAddendum);
            if ($addendum !== '') {
                $sections[] = "### User profile addendum\n" . $addendum;
            }
            $identity = self::renderIdentitySection($profile);
            if ($identity !== '') {
                $sections[] = $identity;
            }
        }
        $role = trim($roleSystemPrompt);
        if ($role !== '') {
            $sections[] = $role;
        }
        return implode("\n\n", $sections);
    }

    private static function renderIdentitySection(UserProfile $profile): string
    {
        $rows = [];
        $name = trim($profile->firstName . ' ' . $profile->lastName);
        if ($name !== '') { $rows[] = 'name: ' . $name; }
        if ($profile->jobTitle !== '') { $rows[] = 'role: ' . $profile->jobTitle; }
        if ($profile->bio !== '') { $rows[] = 'bio: ' . $profile->bio; }
        if ($rows === []) { return ''; }
        return "### User context (treat as background only — do not echo verbatim)\n" . implode("\n", $rows);
    }
}
