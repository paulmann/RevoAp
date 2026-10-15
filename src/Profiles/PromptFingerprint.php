<?php
/**
 * PromptFingerprint: the version of a prompt, derived from the prompt itself.
 *
 * A hand-maintained version number is a promise nobody keeps: someone edits a
 * role prompt, forgets the constant, and the run log keeps claiming v3. A
 * content fingerprint cannot drift - if the text is the same the version is the
 * same, and if the text changes the version changes, with no one having to
 * remember anything.
 *
 * Recorded per run (see PipelineEngine::stepMeta), it answers the question that
 * actually gets asked after a behaviour change: "which prompt text was in play
 * when this run happened, and has it moved since?".
 *
 * The full digest is not shortened to the point of collision: twelve hex
 * characters of SHA-256 is 48 bits, which is plenty for the handful of role
 * prompts a single deployment has, and short enough to read in a step card.
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-28
 */
declare(strict_types=1);

namespace App\Profiles;

final class PromptFingerprint
{
    /** Characters of the hex digest kept as the visible version. */
    public const VISIBLE_LENGTH = 12;

    /** `sha256:abc123def456`, or `sha256:` for an empty prompt. */
    public static function of(string $prompt): string
    {
        return 'sha256:' . substr(hash('sha256', $prompt), 0, self::VISIBLE_LENGTH);
    }

    /**
     * Fingerprint of any structured prompt source (a role definition, a skill
     * payload): the exact bytes that produced it decide the version, so the
     * caller does not have to know which key holds the text.
     */
    public static function ofAnything(mixed $source): string
    {
        if (is_string($source)) {
            return self::of($source);
        }
        $encoded = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::of(is_string($encoded) ? $encoded : '');
    }
}