<?php
declare(strict_types=1);
/**
 * PromptFingerprintTest: a prompt version derived from the prompt itself.
 *
 * A hand-kept version number drifts the first time someone edits a prompt and
 * forgets the constant. These checks pin the properties that make the
 * fingerprint usable as a version: identical text gives an identical version,
 * a changed text gives a different one, and the shape is short and readable.
 *
 *   php tests/PromptFingerprintTest.php
 */
use App\Profiles\PromptFingerprint;

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

check('the fingerprint helper is loadable', class_exists(PromptFingerprint::class));
check('twelve characters are visible', PromptFingerprint::VISIBLE_LENGTH === 12);

$a = PromptFingerprint::of('You are the arbiter.');
$b = PromptFingerprint::of('You are the arbiter.');
$c = PromptFingerprint::of('You are the arbiter!');
check('the same text gives the same version', $a === $b, $a . ' vs ' . $b);
check('a changed text gives a different version', $a !== $c, $a . ' vs ' . $c);
check('the version is prefixed and readable', str_starts_with($a, 'sha256:') && strlen($a) === 19, $a);
check('an empty prompt still has a version', strlen(PromptFingerprint::of('')) === 19);
check('whitespace alone is a different version than nothing', PromptFingerprint::of(' ') !== PromptFingerprint::of(''));

$s1 = PromptFingerprint::ofAnything('role text');
check('a plain string fingerprints like of()', $s1 === PromptFingerprint::of('role text'));
$roleA = ['key' => 'arbiter', 'prompt' => 'Decide.'];
$roleB = ['key' => 'arbiter', 'prompt' => 'Decide!'];
check('an array fingerprint is stable', PromptFingerprint::ofAnything($roleA) === PromptFingerprint::ofAnything($roleA));
check('a changed array field changes the version', PromptFingerprint::ofAnything($roleA) !== PromptFingerprint::ofAnything($roleB));
check('a role definition and its raw string differ', PromptFingerprint::ofAnything($roleA) !== PromptFingerprint::of('Decide.'));

$engineSrc = (string)file_get_contents($root . '/src/Pipeline/PipelineEngine.php');
check('the engine records a skill version', str_contains($engineSrc, "PromptFingerprint::ofAnything(\$skill)"));
check('and a version per role', str_contains($engineSrc, "\$this->promptVersions['roles'][\$roleName]"));
check('the versions ride with every step', str_contains($engineSrc, "\$meta['prompt_versions'] = \$this->promptVersions;"));
check('the plan rides with every step too', str_contains($engineSrc, "['plan' => \$this->planSnapshot()]"));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
