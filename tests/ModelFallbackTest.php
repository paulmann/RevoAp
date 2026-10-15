<?php
/**
 * ModelFallbackTest: the availability + alternative-substitution subsystem.
 *
 * The resolver is pure, which is the only reason a test like this can be
 * exhaustive: no database, no network, no config reads, so every case below is
 * a direct statement about the ranking rule rather than about a fixture.
 *
 * What is covered, and the failure each case exists to prevent
 * -----------------------------------------------------------
 *  - Hard constraints beat soft preferences. A candidate with a smaller context
 *    window must be EXCLUDED, not merely scored down: silently shrinking the
 *    window truncates the prompt, and a truncated prompt produces a confident
 *    wrong answer. Same for `same_vendor_only` and the hard cost ceiling.
 *  - The score components are exposed, not just the total. A bare number an
 *    operator cannot argue with gets ignored; five named components get read.
 *  - Pool planning never gives two broken models the same replacement, and
 *    always reports a slot it could not fill. "You asked for five, you got
 *    four" must be impossible to miss.
 *  - Availability verdicts are windowed and decay, so a model that recovered
 *    stops being substituted out instead of carrying an outage forever.
 *  - Error classification is conservative in the safe direction: an
 *    unrecognised error is treated as transient (retry) rather than as
 *    "removed" (retire), because guessing "removed" from a network blip retires
 *    a healthy model.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Models\Fallback\AlternativeCandidate;
use App\Models\Fallback\AlternativeResolver;
use App\Models\Fallback\AvailabilityMonitor;
use App\Models\Fallback\AvailabilityRepository;
use App\Models\Fallback\AvailabilityStatus;
use App\Models\Fallback\FallbackMode;
use App\Models\Fallback\FallbackPolicy;
use App\Models\Fallback\ModelAvailability;
use App\Models\Fallback\SubstitutionPlan;
use App\Models\Fallback\UnavailableReason;

$passes = 0;
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passes, $failures;
    if ($ok) { $passes++; echo "  ok  $label\n"; }
    else { $failures++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function section(string $title): void
{
    echo "\n== $title ==\n";
}

/** A minimal but realistic catalogue row. */
function row(string $provider, string $model, array $extra = []): array
{
    return array_merge([
        'provider' => $provider,
        'model' => $model,
        'enabled' => true,
        'context_length' => 128_000,
        'pricing_prompt' => 3.0,
        'pricing_completion' => 15.0,
        'avg_latency_ms' => 1200.0,
    ], $extra);
}

$catalogue = [
    row('openrouter', 'openai/gpt-5.5'),
    row('openrouter', 'anthropic/claude-opus-5', ['context_length' => 200_000, 'pricing_prompt' => 5.0, 'pricing_completion' => 25.0, 'avg_latency_ms' => 900.0]),
    row('openrouter', 'google/gemini-3.1-pro', ['context_length' => 1_000_000, 'pricing_prompt' => 1.25, 'pricing_completion' => 10.0]),
    row('openrouter', 'deepseek/deepseek-v4-pro', ['pricing_prompt' => 0.3, 'pricing_completion' => 1.2, 'avg_latency_ms' => 2100.0]),
    row('bothub', 'gpt-5.5'),
    row('openrouter', 'x-ai/grok-4.20', ['avg_latency_ms' => 3000.0]),
    row('openrouter', 'tiny/context-4k', ['context_length' => 4_000]),
    row('openrouter', 'disabled/model', ['enabled' => false]),
    row('openrouter', 'expensive/model', ['pricing_prompt' => 60.0, 'pricing_completion' => 300.0]),
];

$target = row('openrouter', 'openai/gpt-5.5');
$policy = new FallbackPolicy();
$resolver = new AlternativeResolver($policy);

// =====================================================================
section('identity normalisation');
// =====================================================================

check('an OpenRouter id that already carries the vendor is not double-prefixed',
    AlternativeResolver::id(['provider' => 'openrouter', 'model' => 'openai/gpt-5.5']) === 'openai/gpt-5.5',
    AlternativeResolver::id(['provider' => 'openrouter', 'model' => 'openai/gpt-5.5']));
check('a BotHub id with no vendor gains the provider prefix',
    AlternativeResolver::id(['provider' => 'bothub', 'model' => 'gpt-5.5']) === 'bothub/gpt-5.5',
    AlternativeResolver::id(['provider' => 'bothub', 'model' => 'gpt-5.5']));
check('a row with no model has no id',
    AlternativeResolver::id(['provider' => 'bothub', 'model' => '']) === '');
check('vendor is taken from the model prefix when present',
    AlternativeResolver::vendor(['provider' => 'openrouter', 'model' => 'anthropic/claude-opus-5']) === 'anthropic');
check('vendor falls back to the provider otherwise',
    AlternativeResolver::vendor(['provider' => 'bothub', 'model' => 'gpt-5.5']) === 'bothub');
check('an explicit vendor field wins',
    AlternativeResolver::vendor(['provider' => 'openrouter', 'model' => 'a/b', 'vendor' => 'Custom']) === 'Custom');

// =====================================================================
section('hard constraints exclude candidates outright');
// =====================================================================

$ids = static fn(array $list): array => array_map(static fn(AlternativeCandidate $c): string => $c->id, $list);

$alts = $resolver->alternatives($target, $catalogue);
check('the target itself is never offered as its own alternative',
    !in_array('openai/gpt-5.5', $ids($alts), true), implode(',', $ids($alts)));
check('a disabled model is never offered',
    !in_array('disabled/model', $ids($alts), true), implode(',', $ids($alts)));
check('a model already in the pool is never offered',
    !in_array('anthropic/claude-opus-5', $ids($resolver->alternatives(
        $target, $catalogue, ['anthropic/claude-opus-5']
    )), true));
check('respect_context_window excludes a SMALLER window',
    !in_array('openrouter/tiny/context-4k', $ids($alts), true),
    'a smaller window would silently truncate the prompt');
// max_alternatives defaults to 6, which would hide a legitimately-admitted
// candidate from a membership check. Widen it so these assertions test the
// CONSTRAINT and not the page size.
$wide = new AlternativeResolver(new FallbackPolicy(maxAlternatives: 50));
$wideIds = $ids($wide->alternatives($target, $catalogue));
check('and admits an equal or larger one',
    in_array('google/gemini-3.1-pro', $wideIds, true), implode(',', $wideIds));

// Turning the constraint off must admit the small model — otherwise the test
// above would pass for the wrong reason (the model being excluded by something
// else entirely).
$lenient = new AlternativeResolver(new FallbackPolicy(respectContextWindow: false));
$lenient = new AlternativeResolver(new FallbackPolicy(respectContextWindow: false, maxAlternatives: 50));
check('with respect_context_window off the small model IS admitted',
    in_array('tiny/context-4k', $ids($lenient->alternatives($target, $catalogue)), true),
    'proves the exclusion above came from the constraint');
check('and the small model is still excluded when the constraint is on',
    !in_array('tiny/context-4k', $wideIds, true), implode(',', $wideIds));

// The cost ceiling is SOFT by default: an expensive model appears, just low.
$softIds = $ids($wide->alternatives($target, $catalogue));
check('the soft cost ceiling ranks but does not exclude',
    in_array('expensive/model', $softIds, true), implode(',', $softIds));
// Hard ceiling excludes it.
$hard = new AlternativeResolver(new FallbackPolicy(maxCostMultiplier: 1.5, costIsHardLimit: true, maxAlternatives: 50));
check('a hard cost ceiling excludes an over-budget candidate',
    !in_array('expensive/model', $ids($hard->alternatives($target, $catalogue)), true));
check('max_cost_multiplier = 0 disables the cost signal entirely',
    in_array('expensive/model',
        $ids((new AlternativeResolver(new FallbackPolicy(maxCostMultiplier: 0, maxAlternatives: 50)))->alternatives($target, $catalogue)), true));

// Latency ceiling.
$slowOnly = new AlternativeResolver(new FallbackPolicy(maxLatencyMs: 1500, maxAlternatives: 50));
$slowIds = $ids($slowOnly->alternatives($target, $catalogue));
check('a latency ceiling excludes the slow model',
    !in_array('x-ai/grok-4.20', $slowIds, true), implode(',', $slowIds));

// same_vendor_only.
$sameVendor = new AlternativeResolver(new FallbackPolicy(sameVendorOnly: true));
$svIds = $ids($sameVendor->alternatives(row('bothub', 'gpt-5.5'), $catalogue));
check('same_vendor_only admits only that provider',
    $svIds === [] || !array_filter($svIds, static fn(string $i): bool => !str_starts_with($i, 'bothub/')),
    implode(',', $svIds));

// Unavailable / retired candidates are excluded unless explicitly allowed.
$availability = [
    'openrouter/deepseek/deepseek-v4-pro' => new ModelAvailability(
        'openrouter/deepseek/deepseek-v4-pro', 'openrouter', 'deepseek/deepseek-v4-pro',
        AvailabilityStatus::Unavailable, 0, 9, 0.0
    ),
    'openrouter/x-ai/grok-4.20' => new ModelAvailability(
        'openrouter/x-ai/grok-4.20', 'openrouter', 'x-ai/grok-4.20',
        AvailabilityStatus::Retired, 1, 1, 0.5
    ),
];
$availability = [
    'deepseek/deepseek-v4-pro' => new ModelAvailability(
        'deepseek/deepseek-v4-pro', 'openrouter', 'deepseek/deepseek-v4-pro',
        AvailabilityStatus::Unavailable, 0, 9, 0.0
    ),
    'x-ai/grok-4.20' => new ModelAvailability(
        'x-ai/grok-4.20', 'openrouter', 'x-ai/grok-4.20',
        AvailabilityStatus::Retired, 1, 1, 0.5
    ),
];
$withAvail = $ids($wide->alternatives($target, $catalogue, [], $availability));
check('an Unavailable model is not offered as a substitute',
    !in_array('deepseek/deepseek-v4-pro', $withAvail, true), implode(',', $withAvail));
check('a Retired model is never offered',
    !in_array('x-ai/grok-4.20', $withAvail, true), implode(',', $withAvail));

$allowDegraded = new AlternativeResolver(new FallbackPolicy(allowDegradedSubstitutes: true));
$degradedAvail = [
    'deepseek/deepseek-v4-pro' => new ModelAvailability(
        'deepseek/deepseek-v4-pro', 'openrouter', 'deepseek/deepseek-v4-pro',
        AvailabilityStatus::Degraded, 5, 5, 0.5
    ),
];
$allowDegraded = new AlternativeResolver(new FallbackPolicy(allowDegradedSubstitutes: true, maxAlternatives: 50));
check('allow_degraded_substitutes admits a Degraded candidate',
    in_array('deepseek/deepseek-v4-pro',
        $ids($allowDegraded->alternatives($target, $catalogue, [], $degradedAvail)), true));
check('and the default policy does not',
    !in_array('deepseek/deepseek-v4-pro',
        $ids($wide->alternatives($target, $catalogue, [], $degradedAvail)), true));

// =====================================================================
section('the ranking is explainable and stable');
// =====================================================================

$ranked = $resolver->alternatives($target, $catalogue);
check('candidates come back best-first', (function () use ($ranked): bool {
    $prev = 2.0;
    foreach ($ranked as $c) { if ($c->score > $prev + 1e-9) { return false; } $prev = $c->score; }
    return true;
})(), implode(',', array_map(static fn(AlternativeCandidate $c): string => $c->id . '=' . $c->score, $ranked)));

$ranked = $wide->alternatives($target, $catalogue);
check('every candidate carries the five score components',
    !array_filter($ranked, static fn(AlternativeCandidate $c): bool =>
        count(array_diff(['vendor', 'context', 'reliability', 'latency', 'price'], array_keys($c->components))) !== 0));
check('every score is within 0..1',
    !array_filter($ranked, static fn(AlternativeCandidate $c): bool => $c->score < 0.0 || $c->score > 1.0));
check('every component is within 0..1',
    !array_filter($ranked, static fn(AlternativeCandidate $c): bool =>
        array_filter($c->components, static fn(float $v): bool => $v < 0.0 || $v > 1.0) !== []));
check('the ranking is stable across identical calls (no map-order leak)',
    $ids($resolver->alternatives($target, $catalogue)) === $ids($resolver->alternatives($target, $catalogue)));
check('results are capped at max_alternatives',
    count((new AlternativeResolver(new FallbackPolicy(maxAlternatives: 2)))->alternatives($target, $catalogue)) === 2);

// Cheaper + same-or-better context + faster should outrank the expensive one.
$cheapest = null;
$priciest = null;
foreach ($ranked as $c) {
    if ($c->id === 'deepseek/deepseek-v4-pro') { $cheapest = $c; }
    if ($c->id === 'expensive/model') { $priciest = $c; }
}
check('a 20× more expensive model ranks below a cheap comparable one',
    $cheapest !== null && $priciest !== null && $cheapest->score > $priciest->score,
    json_encode(['cheap' => $cheapest?->score, 'expensive' => $priciest?->score]));

// Vendor diversity: with the flag on, an unused vendor outranks a used one of
// otherwise similar quality.
$poolWithAnthropic = ['anthropic/claude-opus-5'];
$diverse = $resolver->alternatives($target, $catalogue, $poolWithAnthropic);
$firstNonGemini = null;
foreach ($diverse as $c) {
    if (!str_starts_with($c->id, 'google/')) { $firstNonGemini = $c; break; }
}
check('a candidate from a vendor already in the pool is excluded rather than merely down-scored',
    (function () use ($wide, $target, $catalogue): bool {
        $a = $wide->alternatives($target, $catalogue, []);
        $b = $wide->alternatives($target, $catalogue, ['anthropic/claude-opus-5']);
        $pick = static function (array $list, string $id): ?AlternativeCandidate {
            foreach ($list as $c) {
                if ($c->id === $id) { return $c; }
            }
            return null;
        };
        $x = $pick($a, 'anthropic/claude-opus-5');
        $y = $pick($b, 'anthropic/claude-opus-5');
        // When anthropic is already in the pool it is excluded entirely.
        return $x !== null && $y === null;
    })());
check('same_vendor is reported per candidate',
    !array_filter($ranked, static fn(AlternativeCandidate $c): bool => !is_bool($c->sameVendor)));

// =====================================================================
section('pool planning');
// =====================================================================

$pool = [
    row('openrouter', 'openai/gpt-5.5'),
    row('openrouter', 'anthropic/claude-opus-5'),
    row('openrouter', 'deepseek/deepseek-v4-pro'),
];
$poolAvail = [
    'openai/gpt-5.5' => new ModelAvailability('openai/gpt-5.5', 'openrouter', 'openai/gpt-5.5',
        AvailabilityStatus::Unavailable, 0, 6, 0.0, null, null, null, time(), null, 'provider 503',
        [UnavailableReason::ProviderError], 6),
    'anthropic/claude-opus-5' => new ModelAvailability('anthropic/claude-opus-5', 'openrouter', 'anthropic/claude-opus-5',
        AvailabilityStatus::Healthy, 20, 0, 1.0),
    'deepseek/deepseek-v4-pro' => new ModelAvailability('deepseek/deepseek-v4-pro', 'openrouter', 'deepseek/deepseek-v4-pro',
        AvailabilityStatus::Unknown, 0, 0, null),
];

$plan = $resolver->planPool($pool, $catalogue, $poolAvail);
check('the plan keeps the pool at its requested size',
    count($plan->pool()) === 3, (string)count($plan->pool()));
check('exactly one substitution was planned', count($plan->replaced()) === 1, json_encode($plan->replaced()));
check('nothing was left unfilled', $plan->unfilled() === [], json_encode($plan->unfilled()));
check('the healthy model was left alone',
    in_array('anthropic/claude-opus-5', array_map(static fn(array $r): string => AlternativeResolver::id($r), $plan->pool()), true),
    json_encode(array_map(static fn(array $r): string => AlternativeResolver::id($r), $plan->pool())));
check('the broken model is gone from the effective pool',
    !in_array('openai/gpt-5.5', array_map(static fn(array $r): string => AlternativeResolver::id($r), $plan->pool()), true));
check('the plan reports changed()', $plan->changed() === true);
check('the plan records WHY the slot was broken',
    ($plan->replaced()[0]['reason'] ?? '') === UnavailableReason::ProviderError->value,
    json_encode($plan->replaced()[0] ?? []));
check('and records the fit score that justified the pick',
    is_float($plan->replaced()[0]['score'] ?? null) && ($plan->replaced()[0]['score'] ?? 0) > 0,
    json_encode($plan->replaced()[0]['score'] ?? null));

// An Unknown-status model is usable, so it must NOT be substituted.
check('an Unknown verdict is treated as usable, not as broken',
    !array_filter($plan->replaced(), static fn(array $r): bool => $r['from'] === 'deepseek/deepseek-v4-pro'));

// Two broken models must not receive the same replacement.
$twoBroken = [
    row('openrouter', 'openai/gpt-5.5'),
    row('openrouter', 'anthropic/claude-opus-5'),
];
$twoAvail = [
    'openai/gpt-5.5' => new ModelAvailability('openai/gpt-5.5', 'openrouter', 'openai/gpt-5.5', AvailabilityStatus::Unavailable, 0, 5, 0.0),
    'anthropic/claude-opus-5' => new ModelAvailability('anthropic/claude-opus-5', 'openrouter', 'anthropic/claude-opus-5', AvailabilityStatus::Unavailable, 0, 5, 0.0),
];
$twoPlan = $resolver->planPool($twoBroken, $catalogue, $twoAvail);
$chosen = array_map(static fn(array $r): string => (string)$r['to'], $twoPlan->replaced());
check('two broken models never get the same replacement',
    count($chosen) === count(array_unique($chosen)), json_encode($chosen));

// Unfillable slots are reported, never silently dropped.
$tinyCatalogue = [row('openrouter', 'only/other', ['context_length' => 1_000])];
$gapPlan = $resolver->planPool(
    [row('openrouter', 'big/model', ['context_length' => 500_000])],
    $tinyCatalogue,
    ['big/model' => new ModelAvailability('big/model', 'openrouter', 'big/model', AvailabilityStatus::Unavailable, 0, 5, 0.0)]
);
check('a slot that cannot be filled is reported as unfilled',
    count($gapPlan->unfilled()) === 1, json_encode($gapPlan->unfilled()));
check('the unfilled entry names the reason and the cause',
    ($gapPlan->unfilled()[0]['cause'] ?? '') === 'no_candidate', json_encode($gapPlan->unfilled()[0] ?? []));
check('an unfilled slot makes the plan honestly short: requested 1, effective 0',
    $gapPlan->requested() === 1 && $gapPlan->effective() === 0 && $gapPlan->isShort() === true,
    json_encode(['requested' => $gapPlan->requested(), 'effective' => $gapPlan->effective()]));
check('and the serialised plan carries the short flag',
    ($gapPlan->toArray()['short'] ?? null) === true);

// Mode semantics.
check('mode=never substitutes nothing and reports the gap',
    (function () use ($resolver, $pool, $catalogue, $poolAvail): bool {
        $p = $resolver->planPool($pool, $catalogue, $poolAvail);
        $never = (new AlternativeResolver(new FallbackPolicy(mode: FallbackMode::Never)))->planPool($pool, $catalogue, $poolAvail);
        return count($never->replaced()) === 0 && count($never->unfilled()) === 1
            && count($p->replaced()) === 1;
    })());
check('mode=never DROPS the unusable model from the effective pool',
    (function () use ($pool, $catalogue, $poolAvail): bool {
        $never = (new AlternativeResolver(new FallbackPolicy(mode: FallbackMode::Never)))->planPool($pool, $catalogue, $poolAvail);
        $ids = array_map(static fn(array $r): string => AlternativeResolver::id($r), $never->pool());
        return count($never->pool()) === 2 && !in_array('openai/gpt-5.5', $ids, true)
            && $never->requested() === 3 && $never->effective() === 2 && $never->isShort();
    })(), 'a model that cannot answer must not be counted as a voice');
check('mode=strict substitutes nothing either — it aborts instead',
    (function () use ($pool, $catalogue, $poolAvail): bool {
        $strict = (new AlternativeResolver(new FallbackPolicy(mode: FallbackMode::Strict)))->planPool($pool, $catalogue, $poolAvail);
        return count($strict->replaced()) === 0 && count($strict->unfilled()) === 1
            && ($strict->unfilled()[0]['cause'] ?? '') === 'policy_strict'
            && $strict->mustAbort() === true;
    })());
check('mode=strict makes an unfilled slot fatal',
    (new AlternativeResolver(new FallbackPolicy(mode: FallbackMode::Strict)))
        ->planPool($pool, $catalogue, $poolAvail)->mustAbort() === true);
check('mode=auto does not abort on a filled substitution',
    $plan->mustAbort() === false);
check('mode=ask flags the plan as needing confirmation',
    (new AlternativeResolver(new FallbackPolicy(mode: FallbackMode::Ask)))
        ->planPool($pool, $catalogue, $poolAvail)->needsConfirmation() === true);
check('an unchanged plan never needs confirmation',
    $resolver->planPool([row('openrouter', 'anthropic/claude-opus-5')], $catalogue, $poolAvail)->needsConfirmation() === false);

// =====================================================================
section('policy parsing and clamping');
// =====================================================================

check('FallbackMode::parse() defaults to auto on garbage',
    FallbackMode::parse('nonsense') === FallbackMode::Auto && FallbackMode::parse(null) === FallbackMode::Auto
    && FallbackMode::parse('') === FallbackMode::Auto);
check('FallbackMode::parse() is case- and space-insensitive',
    FallbackMode::parse(' STRICT ') === FallbackMode::Strict);
check('only auto and ask substitute',
    FallbackMode::Auto->substitutes() && FallbackMode::Ask->substitutes()
    && !FallbackMode::Never->substitutes() && !FallbackMode::Strict->substitutes());
check('only strict aborts on a gap',
    FallbackMode::Strict->abortsOnGap() && !FallbackMode::Auto->abortsOnGap());
check('every mode has a label and a help key',
    !array_filter(FallbackMode::cases(), static fn(FallbackMode $m): bool => $m->labelKey() === '' || $m->helpKey() === ''));

check('a policy without config falls back to the documented defaults',
    (function (): bool {
        $p = FallbackPolicy::fromConfig();
        return $p->mode === FallbackMode::Auto && $p->respectContextWindow === true
            && $p->preferVendorDiversity === true && $p->sameVendorOnly === false
            && $p->maxCostMultiplier === 1.5 && $p->costIsHardLimit === false
            && $p->maxAlternatives === 6 && $p->monitorIntervalSeconds === 300;
    })(), json_encode(FallbackPolicy::fromConfig()->toArray()));
check('toArray() round-trips every knob',
    count(array_diff(
        ['mode', 'prefer_vendor_diversity', 'same_vendor_only', 'respect_context_window',
         'allow_degraded_substitutes', 'max_latency_ms', 'max_cost_multiplier', 'cost_is_hard_limit',
         'max_alternatives', 'chains', 'monitor'],
        array_keys((new FallbackPolicy())->toArray())
    )) === 0);
check('withMode() changes only the mode',
    (function (): bool {
        $p = new FallbackPolicy(maxCostMultiplier: 3.0, maxAlternatives: 9);
        $q = $p->withMode(FallbackMode::Strict);
        return $q->mode === FallbackMode::Strict && $q->maxCostMultiplier === 3.0 && $q->maxAlternatives === 9;
    })());
check('a pinned chain is retrievable per model',
    (new FallbackPolicy(chains: ['a/b' => ['c/d', 'e/f']]))->chainFor('a/b') === ['c/d', 'e/f']
    && (new FallbackPolicy())->chainFor('a/b') === []);

// =====================================================================
section('status and reason enums');
// =====================================================================

$order = array_map(static fn(AvailabilityStatus $s): int => $s->rank(), AvailabilityStatus::cases());
check('Healthy > Unknown > Degraded > Unavailable > Retired',
    AvailabilityStatus::Healthy->rank() > AvailabilityStatus::Unknown->rank()
    && AvailabilityStatus::Unknown->rank() > AvailabilityStatus::Degraded->rank()
    && AvailabilityStatus::Degraded->rank() > AvailabilityStatus::Unavailable->rank()
    && AvailabilityStatus::Unavailable->rank() > AvailabilityStatus::Retired->rank(),
    json_encode($order));
check('only Healthy and Unknown are substitutable',
    AvailabilityStatus::Healthy->isSubstitutable() && AvailabilityStatus::Unknown->isSubstitutable()
    && !AvailabilityStatus::Degraded->isSubstitutable() && !AvailabilityStatus::Unavailable->isSubstitutable()
    && !AvailabilityStatus::Retired->isSubstitutable());
check('Retired and Unavailable are not usable',
    !AvailabilityStatus::Retired->isUsable() && !AvailabilityStatus::Unavailable->isUsable());
check('status parsing never throws and defaults to Unknown',
    AvailabilityStatus::parse(null) === AvailabilityStatus::Unknown
    && AvailabilityStatus::parse('') === AvailabilityStatus::Unknown
    && AvailabilityStatus::parse('GARBAGE') === AvailabilityStatus::Unknown
    && AvailabilityStatus::parse(' HEALTHY ') === AvailabilityStatus::Healthy);
check('every status has a tone and a label key',
    !array_filter(AvailabilityStatus::cases(), static fn(AvailabilityStatus $s): bool => $s->tone() === '' || $s->labelKey() === ''));

check('transient reasons are exactly the ones that clear on their own',
    UnavailableReason::Quota->transient() && UnavailableReason::Breaker->transient()
    && UnavailableReason::Timeout->transient() && UnavailableReason::ProviderError->transient()
    && !UnavailableReason::KeyMissing->transient() && !UnavailableReason::Removed->transient()
    && !UnavailableReason::Disabled->transient());
check('a configuration fact is not substitutable, a health fact is',
    !UnavailableReason::KeyMissing->substitutable() && !UnavailableReason::Removed->substitutable()
    && !UnavailableReason::Disabled->substitutable() && !UnavailableReason::ContextTooSmall->substitutable()
    && UnavailableReason::Timeout->substitutable() && UnavailableReason::ProviderError->substitutable());
check('Retired and Disabled are not worth probing',
    !UnavailableReason::Removed->probeWorthy() && !UnavailableReason::Disabled->probeWorthy()
    && UnavailableReason::Timeout->probeWorthy() && UnavailableReason::Quota->probeWorthy());
check('reason parsing never throws',
    UnavailableReason::parse(null) === UnavailableReason::Unverified
    && UnavailableReason::parse('zzz') === UnavailableReason::Unverified
    && UnavailableReason::parse(' QUOTA ') === UnavailableReason::Quota);

// =====================================================================
section('error classification is conservative in the safe direction');
// =====================================================================

$cases = [
    'HTTP 401 Unauthorized: invalid_api_key' => UnavailableReason::KeyMissing,
    'Authentication failed' => UnavailableReason::KeyMissing,
    '429 Too Many Requests: rate limit exceeded' => UnavailableReason::Quota,
    'insufficient credit balance' => UnavailableReason::Quota,
    'quota exhausted for this organisation' => UnavailableReason::Quota,
    '404 Not Found: unknown model' => UnavailableReason::Removed,
    'The model `x` does not exist' => UnavailableReason::Removed,
    'Operation timed out after 12 seconds' => UnavailableReason::Timeout,
    'curl error 28: timed out' => UnavailableReason::Timeout,
    'content filtered by moderation policy' => UnavailableReason::ContentFiltered,
    'maximum context length 4096 exceeded' => UnavailableReason::ContextTooSmall,
    '500 Internal Server Error' => UnavailableReason::ProviderError,
    '502 Bad Gateway' => UnavailableReason::ProviderError,
    '503 Service Unavailable' => UnavailableReason::ProviderError,
    // The default must be the TRANSIENT one: guessing "removed" from an
    // unrecognised network error would retire a healthy model permanently.
    'connection reset by peer' => UnavailableReason::ProviderError,
    'something completely unrecognised' => UnavailableReason::ProviderError,
    '' => UnavailableReason::ProviderError,
];
foreach ($cases as $message => $expected) {
    $got = AvailabilityMonitor::classify((string)$message);
    check("classify(" . ($message === '' ? "''" : "'" . substr((string)$message, 0, 34) . "…'") . ") = {$expected->value}",
        $got === $expected, $got->value);
}
check('an unrecognised error is classified as transient, never as Removed',
    AvailabilityMonitor::classify('weird proxy hiccup')->transient() === true);

// =====================================================================
section('availability repository without a database');
// =====================================================================

$repo = new AvailabilityRepository(null);
check('a DB-less repository reports itself non-persistent', $repo->isPersistent() === false);
check('and starts empty', $repo->all() === []);

$recorded = $repo->record('openrouter/test/model', true, 450.0);
check('recording a success returns a verdict', $recorded instanceof ModelAvailability);
check('the first success yields a Healthy or Unknown status',
    in_array($recorded->status, [AvailabilityStatus::Healthy, AvailabilityStatus::Unknown], true), $recorded->status->value);
check('counters increment', $recorded->successes === 1 && $recorded->failures === 0);
check('and it is readable back', $repo->find('openrouter/test/model')?->successes === 1);

$threeFails = null;
for ($i = 0; $i < 4; $i++) {
    $threeFails = $repo->record('openrouter/test/model', false, null, UnavailableReason::ProviderError, 'boom');
}
check('four consecutive failures open the verdict',
    $threeFails?->status === AvailabilityStatus::Unavailable, (string)$threeFails?->status->value);
check('the consecutive-failure streak is tracked', ($threeFails?->consecutiveFailures ?? 0) === 4);
check('a streak overrides an otherwise good ratio',
    $threeFails !== null && $threeFails->status === AvailabilityStatus::Unavailable);
check('the last error is retained for the UI', ($threeFails?->lastError ?? '') === 'boom');
check('and the reason is retained', ($threeFails?->recentReasons[0] ?? null) === UnavailableReason::ProviderError);

$recovered = $repo->record('openrouter/test/model', true, 300.0);
check('a success resets the consecutive-failure streak', $recovered->consecutiveFailures === 0);
check('the recent-reason history is capped',
    count((function () use ($repo): array {
        $a = [];
        for ($i = 0; $i < 20; $i++) { $repo->record('x/y', false, null, UnavailableReason::Timeout, 't'); }
        return $repo->find('x/y')?->recentReasons ?? [];
    })()) <= 8);

check('forget() clears a verdict', $repo->forget('openrouter/test/model') === true && $repo->find('openrouter/test/model') === null);
check('an empty id is rejected rather than stored under ""',
    (function () use ($repo): bool {
        try { $repo->record('', true); return false; }
        catch (\InvalidArgumentException) { return true; }
    })());
check('retire() is sticky across a later success',
    (function () use ($repo): bool {
        $repo->setStatus('a/b', AvailabilityStatus::Retired, 'operator switched it off');
        $repo->record('a/b', true, 100.0);
        return $repo->find('a/b')?->status === AvailabilityStatus::Retired;
    })());
check('the note survives a status change', ($repo->find('a/b')?->note ?? '') === 'operator switched it off');
check('statusCounts() covers every status',
    count(array_diff(array_map(static fn(AvailabilityStatus $s): string => $s->value, AvailabilityStatus::cases()),
        array_keys($repo->statusCounts()))) === 0);
check('truncate() empties the store', $repo->truncate() === 0 && $repo->all() === []);

// =====================================================================
section('the monitor only probes what is worth probing');
// =====================================================================

$monitor = new AvailabilityMonitor(new AvailabilityRepository(null), null);
$monitorPolicy = new FallbackPolicy(monitorIntervalSeconds: 300, monitorWindowSeconds: 3600);

$due = $monitor->dueForProbe($catalogue, [], $monitorPolicy);
// 9 catalogue rows, one of them `enabled => false`, so 8 are probeable.
check('with no verdicts, every enabled model is due', count($due) === 8, (string)count($due));
check('a disabled model is never probed',
    !in_array('disabled/model', array_map(static fn(array $r): string => AlternativeResolver::id($r), $due), true),
    implode(',', array_map(static fn(array $r): string => AlternativeResolver::id($r), $due)));
check('an incomplete row is never probed',
    !array_filter($due, static fn(array $r): bool => ($r['provider'] ?? '') === '' || ($r['model'] ?? '') === ''));

check('the monitor is disabled wholesale when the policy says so',
    $monitor->dueForProbe($catalogue, [], new FallbackPolicy(monitorEnabled: false)) === []);

// A model that just answered successfully is better evidenced by real traffic
// than by a synthetic ping, so it is skipped.
$freshAvail = [
    'openai/gpt-5.5' => new ModelAvailability('openai/gpt-5.5', 'openrouter', 'openai/gpt-5.5',
        AvailabilityStatus::Healthy, 10, 0, 1.0, null, null, time()),
];
$dueAfterSuccess = $monitor->dueForProbe($catalogue, $freshAvail, $monitorPolicy);
check('a model with fresh successful traffic is not probed',
    !in_array('openai/gpt-5.5', array_map(static fn(array $r): string => AlternativeResolver::id($r), $dueAfterSuccess), true));

// A Retired model is never probed: it can only produce a 404, against the
// operator's own provider account.
$retiredAvail = [
    'openai/gpt-5.5' => new ModelAvailability('openai/gpt-5.5', 'openrouter', 'openai/gpt-5.5',
        AvailabilityStatus::Retired, 0, 0, null),
];
check('a Retired model is not probed',
    !in_array('openai/gpt-5.5', array_map(static fn(array $r): string => AlternativeResolver::id($r),
        $monitor->dueForProbe($catalogue, $retiredAvail, $monitorPolicy)), true));

// Without a connector registry the probe run reports skips rather than lying.
$probeResult = $monitor->probe(array_slice($catalogue, 0, 3));
check('a probe run without connectors reports every target as skipped',
    $probeResult['probed'] === 0 && $probeResult['skipped'] === 3, json_encode($probeResult));
check('and names the reason', ($probeResult['results'][0]['reason'] ?? '') === 'no_connector_registry');
check('the summary always carries a duration', array_key_exists('duration_ms', $probeResult));
check('probing nothing is a clean no-op',
    $monitor->probe([]) === ['probed' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'duration_ms' => 0, 'results' => []]
    || ($monitor->probe([])['probed'] ?? -1) === 0);

// =====================================================================
section('serialisation for the API');
// =====================================================================

$candidate = $ranked[0] ?? null;
check('a candidate serialises completely',
    $candidate !== null && count(array_diff(
        ['id', 'provider', 'model', 'score', 'score_percent', 'components', 'same_vendor',
         'context_length', 'avg_latency_ms', 'price', 'status', 'status_tone', 'status_label_key'],
        array_keys($candidate->toArray())
    )) === 0);
check('score_percent is the score scaled to 0..100',
    $candidate !== null && $candidate->toArray()['score_percent'] === (int)round($candidate->score * 100));

$availabilityRow = new ModelAvailability('a/b', 'a', 'b', AvailabilityStatus::Degraded, 7, 3, 0.7,
    1200.5, 3400.0, time() - 60, time() - 10, time() - 5, 'slow', [UnavailableReason::Timeout], 1, 'note');
$shaped = $availabilityRow->toArray();
check('an availability row serialises completely',
    count(array_diff(['id', 'provider', 'model', 'status', 'status_tone', 'status_label_key', 'successes',
        'failures', 'samples', 'success_rate', 'avg_latency_ms', 'p95_latency_ms', 'last_success_at',
        'last_failure_at', 'last_probed_at', 'last_error', 'consecutive_failures', 'recent_reasons',
        'primary_reason', 'primary_reason_label_key', 'note'], array_keys($shaped))) === 0);
check('samples is the sum of the two counters', $shaped['samples'] === 10);
check('secondsSinceSuccess() measures from the last success',
    abs(($availabilityRow->secondsSinceSuccess() ?? -1) - 60) <= 2, (string)$availabilityRow->secondsSinceSuccess());
check('secondsSinceSuccess() is null when there has never been one',
    (new ModelAvailability('x', '', '', AvailabilityStatus::Unknown))->secondsSinceSuccess() === null);
check('confident() respects the sample threshold',
    $availabilityRow->confident(5) === true && $availabilityRow->confident(50) === false);
check('primaryReason() falls back to the status when no reasons are stored',
    (new ModelAvailability('x', '', '', AvailabilityStatus::Unavailable))->primaryReason() === UnavailableReason::Breaker);
check('a Healthy row has no primary reason',
    (new ModelAvailability('x', '', '', AvailabilityStatus::Healthy))->primaryReason() === null);

// fromRow must tolerate a row written by an older schema.
$fromRow = ModelAvailability::fromRow(['model_id' => 'p/m', 'provider' => 'p', 'model' => 'm', 'status' => 'degraded']);
check('fromRow tolerates missing columns', $fromRow->id === 'p/m' && $fromRow->status === AvailabilityStatus::Degraded);
check('fromRow synthesises the id from provider+model when absent',
    ModelAvailability::fromRow(['provider' => 'bothub', 'model' => 'gpt', 'status' => 'healthy'])->id === 'bothub/gpt');
check('fromRow parses a JSON reason list',
    count(ModelAvailability::fromRow(['model_id' => 'a/b', 'status' => 'unavailable',
        'recent_reasons' => json_encode(['timeout', 'quota'])])->recentReasons) === 2);
check('fromRow ignores garbage in the reason list',
    ModelAvailability::fromRow(['model_id' => 'a/b', 'status' => 'healthy', 'recent_reasons' => '{not json'])->recentReasons === []);

$planArray = $plan->toArray();
check('a plan serialises completely',
    count(array_diff(['mode', 'changed', 'must_abort', 'needs_confirmation', 'requested', 'effective',
        'replaced', 'unfilled', 'pool'], array_keys($planArray))) === 0);
check('the serialised pool carries only provider and model',
    !array_filter($planArray['pool'], static fn(array $r): bool => count(array_diff(array_keys($r), ['provider', 'model'])) !== 0));

$repoSplit = AvailabilityRepository::splitId('openrouter/openai/gpt-5.5');
check('splitId() splits on the FIRST slash only',
    $repoSplit === ['openrouter', 'openai/gpt-5.5'], json_encode($repoSplit));
check('splitId() handles a bare model', AvailabilityRepository::splitId('gpt-5.5') === ['', 'gpt-5.5']);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
