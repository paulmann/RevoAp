<?php
/**
 * Planner: turn a message list into a prompt-cacheable one (C2).
 *
 * The whole request-side feature is this one pure function. It never
 * touches the network, never reads the clock and never writes anything, so
 * its output is fully determined by (messages, model, policy) and can be
 * asserted byte-for-byte in a test.
 *
 * ## What it does
 *
 * The documentation's rule is that `cache_control` may only sit on the
 * text part of a composite message, and that the cached prefix must be
 * byte-identical between requests while all the varying material goes at
 * the end. Translated onto this codebase's message lists — which are
 * always `[system…, user]`, the dynamic half already last — that gives
 * three mechanical steps:
 *
 *   1. the **stable prefix** is the leading run of `system` messages.
 *      Everything after it is the dynamic tail (user query, peer answers,
 *      consensus verdict, memory recall).
 *   2. a breakpoint is placed on the **last** `system` message of that run
 *      (or on the last N of them when the operator raises the count),
 *      which is the last stable part of the prefix.
 *   3. only those messages get composite `content`; every other message —
 *      and therefore the whole dynamic tail — is passed through untouched.
 *
 * ## What it deliberately does not do
 *
 * For an automatically-caching family (OpenAI, Grok, Moonshot, Groq,
 * DeepSeek) the **message format is left byte-identical**. Introducing
 * composite parts there would buy nothing and risk a provider rejecting a
 * payload shape it never asked for; that is a hard requirement, and
 * {@see Plan::REASON_AUTOMATIC_FAMILY} is the record of it. For an
 * undocumented family the same holds, with
 * {@see Plan::REASON_UNKNOWN_FAMILY} recorded instead.
 *
 * The `usage: {include: true}` request option is *not* tied to the family:
 * it asks Bothub to report cache tokens at the end of the response, which
 * is worth doing precisely for the automatic families — that is where the
 * saving happens without any request-side work. It never alters the
 * message list.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

namespace App\Connectors\PromptCache;

use App\Helpers\TokenHelper;

final class Planner
{
    public function __construct(private readonly Policy $policy) {}

    /**
     * Decide the request shape for one call.
     *
     * @param array<int,array<string,mixed>> $messages Role/content messages as the pipeline built them.
     */
    public function plan(string $model, array $messages): Plan
    {
        $capability   = Capabilities::forModel($model);
        $includeUsage = $this->policy->enabled && $this->policy->includeUsage;

        if (!$this->policy->enabled) {
            return $this->unchanged($model, $messages, $capability, Plan::REASON_DISABLED, false);
        }
        if ($capability->isAutomatic()) {
            // Hard requirement: an automatically-caching provider keeps the
            // legacy message format. Only the usage report is requested.
            return $this->unchanged($model, $messages, $capability, Plan::REASON_AUTOMATIC_FAMILY, $includeUsage);
        }
        if (!$capability->requiresBreakpoints()) {
            return $this->unchanged($model, $messages, $capability, Plan::REASON_UNKNOWN_FAMILY, $includeUsage);
        }

        $threshold     = $this->thresholdFor($model);
        $prefixIndexes = self::stablePrefixIndexes($messages);
        if ($prefixIndexes === []) {
            return new Plan(
                applied:       false,
                reason:        Plan::REASON_NO_STABLE_PREFIX,
                messages:      $messages,
                breakpoints:   0,
                prefixTokens:  0,
                threshold:     $threshold,
                cacheControl:  null,
                includeUsage:  $includeUsage,
                family:        $capability->family,
                mode:          $capability->mode,
            );
        }

        $prefixTokens = TokenHelper::estimateMessageTokens(
            array_values(array_map(static fn(int $i): array => $messages[$i], $prefixIndexes))
        );

        if ($threshold > 0 && $prefixTokens < $threshold) {
            return new Plan(
                applied:       false,
                reason:        Plan::REASON_BELOW_THRESHOLD,
                messages:      $messages,
                breakpoints:   0,
                prefixTokens:  $prefixTokens,
                threshold:     $threshold,
                cacheControl:  null,
                includeUsage:  $includeUsage,
                family:        $capability->family,
                mode:          $capability->mode,
            );
        }

        $control  = $this->policy->cacheControl();
        $anchors  = array_slice($prefixIndexes, -$this->policy->effectiveMaxBreakpoints());
        $cacheable = $messages;
        foreach ($anchors as $idx) {
            $cacheable[$idx]['content'] = self::withBreakpoint($cacheable[$idx]['content'] ?? '', $control);
        }

        return new Plan(
            applied:       true,
            reason:        Plan::REASON_APPLIED,
            messages:      $cacheable,
            breakpoints:   count($anchors),
            prefixTokens:  $prefixTokens,
            threshold:     $threshold,
            cacheControl:  $control,
            includeUsage:  $includeUsage,
            family:        $capability->family,
            mode:          $capability->mode,
        );
    }

    /**
     * The effective size guard: the operator's threshold, raised to the
     * family's documented minimum when the documentation states one.
     *
     * Raising rather than replacing is the conservative direction — a
     * breakpoint below the documented minimum is a breakpoint that costs a
     * round trip and caches nothing.
     */
    public function thresholdFor(string $model): int
    {
        return max(
            $this->policy->effectiveMinPromptTokens(),
            Capabilities::minPromptTokensFor($model) ?? 0,
        );
    }

    /**
     * Indexes of the leading run of `system` messages: the material that is
     * identical between two requests for the same role/skill/profile, and
     * therefore the only thing worth caching.
     *
     * @param  array<int,array<string,mixed>> $messages
     * @return int[]
     */
    public static function stablePrefixIndexes(array $messages): array
    {
        $out = [];
        foreach ($messages as $i => $m) {
            if (!is_array($m) || (string)($m['role'] ?? '') !== 'system') {
                break;
            }
            $out[] = (int)$i;
        }
        return $out;
    }

    /**
     * Turn one message body into a composite one and attach the breakpoint
     * to its last text part.
     *
     * A string body becomes `[{"type":"text","text":"…","cache_control":{…}}]`
     * — one part, which *is* the last stable part. A body that is already a
     * list of parts keeps its parts and gets the breakpoint on the last
     * `text` one, so a future multi-block prompt is handled without a
     * second code path.
     *
     * @param  string|array<mixed>  $content
     * @param  array<string,string> $control
     * @return array<int,array<string,mixed>>
     */
    private static function withBreakpoint(string|array $content, array $control): array
    {
        if (is_string($content)) {
            return [[
                'type'          => 'text',
                'text'          => $content,
                'cache_control' => $control,
            ]];
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_array($part)) {
                $parts[] = $part;
            }
        }
        if ($parts === []) {
            $parts[] = ['type' => 'text', 'text' => ''];
        }

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            if ((string)($parts[$i]['type'] ?? 'text') === 'text') {
                $parts[$i]['cache_control'] = $control;
                return $parts;
            }
        }
        // No text part at all: append one so the breakpoint still has a
        // legal carrier rather than being dropped silently.
        $parts[] = ['type' => 'text', 'text' => '', 'cache_control' => $control];
        return $parts;
    }

    /**
     * Plan for a request whose message list must not change.
     *
     * `threshold` is still reported: knowing the guard that *would* have
     * applied is what makes a log line actionable when the reason is
     * `below_threshold` or `disabled`, and it costs nothing.
     *
     * @param  array<int,array<string,mixed>> $messages
     */
    private function unchanged(string $model, array $messages, Capability $capability, string $reason, bool $includeUsage): Plan
    {
        return new Plan(
            applied:      false,
            reason:       $reason,
            messages:     $messages,
            breakpoints:  0,
            prefixTokens: 0,
            threshold:    $this->thresholdFor($model),
            cacheControl: null,
            includeUsage: $includeUsage,
            family:       $capability->family,
            mode:         $capability->mode,
        );
    }
}
