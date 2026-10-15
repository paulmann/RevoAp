<?php
declare(strict_types=1);
/**
 * ModelSubstitutionTest: a council keeps its size when a model is broken.
 *
 * The dispatcher skips a model whose breaker is open - right, but it leaves the
 * council one member short. The substitution happens at pool assembly, before
 * anything is dispatched, and these are the rules:
 *
 *   1. a healthy pool is untouched;
 *   2. an open model is replaced by an active candidate, and the pool keeps its
 *      size;
 *   3. a vendor the pool does not use yet is preferred, because the point of a
 *      council is independent perspectives;
 *   4. no candidate means the slot stays empty and is reported - never filled
 *      with a duplicate or an unrelated id;
 *   5. a model whose breaker is open but which is not in this pool changes
 *      nothing;
 *   6. the substitution is announced, not silent.
 *
 *   php tests/ModelSubstitutionTest.php
 */
use App\Pipeline\ModelSubstitution as S;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $c) {
    if (is_file($root . $c)) { require $root . $c; }
}
$passes = 0; $failed = 0;
function check(string $label, bool $cond, string $detail = ''): void {
    global $passes, $failed;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failed++; }
}
function id(array $row): string { return ($row['provider'] ?? '') . '/' . ($row['model'] ?? ''); }

check('the helper is loadable', class_exists(S::class));

$pool = [
    ['provider' => 'bothub', 'model' => 'gpt-6-luna-pro'],
    ['provider' => 'gemini', 'model' => '3.1-pro-preview'],
];
$candidates = [
    ['provider' => 'bothub', 'model' => 'gpt-6-luna-pro'],
    ['provider' => 'gemini', 'model' => '3.1-pro-preview'],
    ['provider' => 'bothub', 'model' => 'deepseek-v4-pro-0813'],
    ['provider' => 'minimax', 'model' => 'm3'],
];

$clean = S::plan($pool, [], $candidates);
check('a healthy pool is untouched', $clean['pool'] === $pool && $clean['replaced'] === []);

$broken = S::plan($pool, ['bothub/gpt-6-luna-pro'], $candidates);
check('the open model is replaced', id($broken['pool'][0]) !== 'bothub/gpt-6-luna-pro', id($broken['pool'][0]));
check('the pool keeps its size', count($broken['pool']) === count($pool));
check('the pair is reported', ($broken['replaced'][0]['from'] ?? '') === 'bothub/gpt-6-luna-pro');
check('and the stand-in too', ($broken['replaced'][0]['to'] ?? '') !== '', json_encode($broken['replaced']));
check('a vendor not yet in the pool is preferred',
    !in_array(explode('/', (string)$broken['replaced'][0]['to'])[0], ['bothub', 'gemini'], true),
    (string)$broken['replaced'][0]['to']);
check('no duplicate enters the pool',
    count(array_unique(array_map('id', $broken['pool']))) === count($broken['pool']));

$noCandidates = S::plan($pool, ['bothub/gpt-6-luna-pro'], $pool);
check('without a candidate the slot stays empty', count($noCandidates['pool']) === count($pool));
check('and the empty slot is reported', ($noCandidates['unfilled'][0]['model'] ?? '') === 'gpt-6-luna-pro');
check('nothing is invented to fill it', $noCandidates['replaced'] === []);

$unrelated = S::plan($pool, ['other/vendor-model'], $candidates);
check('an open model outside the pool changes nothing', $unrelated['pool'] === $pool);

$several = S::plan($pool, ['bothub/gpt-6-luna-pro', 'gemini/3.1-pro-preview'], $candidates);
check('two broken models are both replaced', count($several['replaced']) === 2, json_encode($several['replaced']));
check('still without duplicates',
    count(array_unique(array_map('id', $several['pool']))) === count($several['pool']));

$engine = (string)file_get_contents($root . '/src/Pipeline/PipelineEngine.php');
check('the engine substitutes before dispatching', str_contains($engine, 'ModelSubstitution::plan('));
check('it uses the dispatcher breaker state', str_contains($engine, '$this->dispatcher->openModels()'));
check('and announces the substitution', str_contains($engine, "'MODEL_SUBSTITUTED'"));

$dispatcher = (string)file_get_contents($root . '/src/Connectors/ParallelChatDispatcher.php');
check('the dispatcher exposes its open models', str_contains($dispatcher, 'public function openModels(): array'));
// The shape of summary() has no per-row "open" key: requiring one made the
// accessor silently return nothing.
check('and does not require a key summary() never sets',
    !str_contains($dispatcher, "\$row['open']"));
// The second half: the breaker can trip DURING a run, so every round asks
// again before it builds its participants.
check('every round substitutes before building its participants',
    substr_count($engine, 'ModelSubstitution::plan(') >= 2, (string)substr_count($engine, 'ModelSubstitution::plan('));
check('the per-round call is inside the L1 distribution',
    // The window has to clear the docblock and the signature: this is a
    // structural check, not a distance one.
    preg_match('/private function layer1Distribute\([\s\S]{0,9000}ModelSubstitution::plan\(/', $engine) === 1);
check('and it reports the round it happened in', str_contains($engine, "'round'    => \$round,"));
check('the mid-run reason is distinguishable', str_contains($engine, "'reason'   => 'model_breaker_open_mid_run',"));

check('the interface knows the event', str_contains((string)file_get_contents($root . '/assets/js/app.js'), "'MODEL_SUBSTITUTED'"));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
