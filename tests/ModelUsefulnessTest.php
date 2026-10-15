<?php
/**
 * ModelUsefulnessTest - how the runs describe a model's usefulness (R9).
 *
 * The rules this suite pins are the ones that keep the number honest:
 *
 *   - a round where most of the model's content was corroborated counts as
 *     strongly useful, a round where almost none of it was counts as weak, and
 *     a round in between counts as neither - they are not made to add up;
 *   - **a call the model did not answer is never a negative contribution**: a
 *     timeout, a rate limit, a provider refusal and a breaker skip are counted
 *     separately, with their reasons, because those are facts about the
 *     connection, not about the model's output;
 *   - an empty input produces nothing at all rather than a zero that would read
 *     as "this model is bad".
 *
 * Run: php tests/ModelUsefulnessTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-23
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Models\ModelUsefulness;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failures++;
    }
}

$round = static fn(array $models): array => ['scoring' => 'semantic_components', 'sum' => 1.0, 'models' => $models];

// =====================================================================
echo "\n== (a) a round's numbers fold into strong / weak / neutral ==\n";
// =====================================================================
$folded = ModelUsefulness::fromRounds([
    $round([
        ['model' => 'bothub/strong', 'provider' => 'bothub', 'role' => 'r1', 'share' => 0.6, 'ratio' => 0.8],
        ['model' => 'bothub/weak',   'provider' => 'bothub', 'role' => 'r2', 'share' => 0.4, 'ratio' => 0.05],
    ]),
    $round([
        ['model' => 'bothub/strong', 'provider' => 'bothub', 'role' => 'r1', 'share' => 0.5, 'ratio' => 0.6],
        ['model' => 'bothub/weak',   'provider' => 'bothub', 'role' => 'r2', 'share' => 0.5, 'ratio' => 0.9],
    ]),
]);
check('a model is counted once per round it took part in',
    ($folded['bothub/strong']['runs'] ?? 0) === 2 && ($folded['bothub/weak']['runs'] ?? 0) === 2,
    json_encode($folded));
check('mostly-corroborated rounds are strong, barely-corroborated rounds are weak',
    ($folded['bothub/strong']['strongly'] ?? 0) === 2 && ($folded['bothub/weak']['weakly'] ?? 0) === 1,
    json_encode($folded));
// The same model appears twice with opposite ratios, so this pins that the
// classification follows the ratio and not the share.
check('the classification follows the ratio, not the share',
    ($folded['bothub/weak']['weakly'] ?? 0) === 1 && ($folded['bothub/weak']['strongly'] ?? 0) === 1);
check('the averages are per round, not per model',
    abs(($folded['bothub/strong']['avg_ratio'] ?? 0) - 0.7) < 0.001,
    (string)($folded['bothub/strong']['avg_ratio'] ?? null));
check('the provider and the last role travel with the numbers',
    ($folded['bothub/strong']['provider'] ?? '') === 'bothub' && ($folded['bothub/strong']['last_role'] ?? '') === 'r1');

// =====================================================================
echo "\n== (b) a call that was not answered is not a negative contribution ==\n";
// =====================================================================
$nonAnswers = ModelUsefulness::fromNonAnswers([
    ['model' => 'bothub/strong', 'reason' => 'timed out'],
    ['model' => 'bothub/strong', 'reason' => 'rate limited'],
    ['model' => 'bothub/silent', 'reason' => 'model no longer served'],
]);
check('non-answers are counted with their reasons',
    ($nonAnswers['bothub/strong']['non_answers'] ?? 0) === 2
    && ($nonAnswers['bothub/strong']['reasons']['timed out'] ?? 0) === 1
    && ($nonAnswers['bothub/strong']['reasons']['rate limited'] ?? 0) === 1,
    json_encode($nonAnswers));

$combined = ModelUsefulness::combine(
    [$round([['model' => 'bothub/strong', 'provider' => 'bothub', 'role' => 'r1', 'share' => 1.0, 'ratio' => 0.9]])],
    [['model' => 'bothub/strong', 'reason' => 'timed out']],
);
$row = $combined[0] ?? [];
check('a model that did not answer still shows a positive record for the rounds it did answer',
    ($row['strongly'] ?? 0) === 1 && ($row['weakly'] ?? 0) === 0, json_encode($row));
check('its non-answers are reported next to the record, not inside it',
    ($row['non_answers'] ?? 0) === 1 && ($row['runs'] ?? 0) === 1, json_encode($row));
check('the note says in words that the two are different things',
    str_contains((string)($row['note'] ?? ''), 'counted separately'), (string)($row['note'] ?? ''));

$silentOnly = ModelUsefulness::combine([], [['model' => 'bothub/silent', 'reason' => 'connection failed']]);
$silent = $silentOnly[0] ?? [];
check('a model with only non-answers has no weak rounds invented for it',
    ($silent['weakly'] ?? -1) === 0 && ($silent['runs'] ?? -1) === 0 && ($silent['non_answers'] ?? 0) === 1,
    json_encode($silent));
check('and it is told plainly that no round has measured it yet',
    str_contains((string)($silent['note'] ?? ''), 'No consensus round has measured'), (string)($silent['note'] ?? ''));

// =====================================================================
echo "\n== (c) nothing measured produces nothing, not a zero ==\n";
// =====================================================================
check('empty input folds to an empty result', ModelUsefulness::combine([], []) === []);
check('a round without models is ignored rather than counted', ModelUsefulness::fromRounds([$round([])]) === []);
check('a model without a name is skipped', ModelUsefulness::fromRounds([$round([['share' => 1.0, 'ratio' => 1.0]])]) === []);
check('the thresholds are the documented ones',
    ModelUsefulness::STRONG_RATIO === 0.5 && ModelUsefulness::WEAK_RATIO === 0.2);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
