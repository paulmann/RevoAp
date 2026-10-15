<?php
declare(strict_types=1);
/**
 * DomainPresetTransferTest: importing presets may not break a working council.
 *
 * The import is the dangerous half of item 12, so the rules are pinned here:
 * an unknown slug is skipped rather than created, a model the active catalogue
 * does not serve is reported rather than written, a preset whose models ALL
 * fail validation is skipped rather than emptied, and an identical document is
 * reported as "unchanged" so a round trip of one's own export is a no-op.
 *
 *   php tests/DomainPresetTransferTest.php
 */
use App\DomainPresets\DomainPreset;
use App\DomainPresets\DomainPresetTransfer as T;

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

check('the transfer helper is loadable', class_exists(T::class));

$default = new DomainPreset(
    id: 1, slug: 'default', name: 'Default', description: 'Base pool',
    models: [['provider' => 'bothub', 'model' => 'gpt-6-luna-pro']],
    arbiter: ['provider' => 'bothub', 'model' => 'gpt-6-astra-pro'],
    skillSlug: 'semantic-consensus-reasoning', isSystem: true,
);
$physics = new DomainPreset(
    id: 2, slug: 'physics', name: 'Physics', description: '',
    models: [['provider' => 'bothub', 'model' => 'gpt-6-luna-pro']],
    arbiter: null, skillSlug: 'physics-reasoning', isSystem: false,
);

$doc = T::document([$default, $physics], '2026-09-28T00:00:00Z');
check('the document says what it is', ($doc['kind'] ?? '') === 'revoap.domain-presets');
check('and carries its version', ($doc['version'] ?? 0) === T::DOCUMENT_VERSION);
check('every preset is in it', count($doc['presets'] ?? []) === 2);
check('a preset carries its models', ($doc['presets'][0]['models'][0]['model'] ?? '') === 'gpt-6-luna-pro');
check('and its arbiter', ($doc['presets'][0]['arbiter']['model'] ?? '') === 'gpt-6-astra-pro');

$active = [
    ['provider' => 'bothub', 'model' => 'gpt-6-luna-pro'],
    ['provider' => 'bothub', 'model' => 'gpt-6-astra-pro'],
];
$existing = ['default' => $default, 'physics' => $physics];

$same = T::plan($doc, $existing, $active);
check('a round trip of the same document is accepted', ($same['ok'] ?? false) === true, json_encode($same['error'] ?? ''));
check('and reports nothing to do', ($same['plan'][0]['action'] ?? '') === 'unchanged', (string)($same['plan'][0]['action'] ?? ''));

$changed = $doc;
$changed['presets'][0]['models'][] = ['provider' => 'bothub', 'model' => 'gpt-6-astra-pro'];
$planned = T::plan($changed, $existing, $active);
check('an added model is reported as an addition', ($planned['plan'][0]['added'] ?? []) === ['bothub/gpt-6-astra-pro'],
    json_encode($planned['plan'][0]['added'] ?? []));
check('and the preset is marked for update', ($planned['plan'][0]['action'] ?? '') === 'update');

$dead = $doc;
$dead['presets'][0]['models'] = [['provider' => 'bothub', 'model' => 'retired-model-9']];
$deadPlan = T::plan($dead, $existing, $active);
check('a model the catalogue does not serve is reported, not written',
    in_array('bothub/retired-model-9', $deadPlan['plan'][0]['unknown_models'] ?? [], true));
check('and a preset whose models all fail is skipped', ($deadPlan['plan'][0]['action'] ?? '') === 'skipped');
check('with the reason spelled out', ($deadPlan['plan'][0]['reason'] ?? '') === 'no_usable_models');

$mystery = $doc;
$mystery['presets'][] = ['slug' => 'never-existed', 'name' => 'X', 'models' => [['provider' => 'bothub', 'model' => 'gpt-6-luna-pro']]];
$mysteryPlan = T::plan($mystery, $existing, $active);
$last = end($mysteryPlan['plan']);
check('an unknown preset is skipped rather than created', ($last['action'] ?? '') === 'skipped');
check('because an import restores, it does not invent', ($last['reason'] ?? '') === 'unknown_preset');

$badSlug = $doc;
$badSlug['presets'][0]['slug'] = '../../etc/passwd';
check('a hostile slug is refused', (T::plan($badSlug, $existing, $active)['plan'][0]['reason'] ?? '') === 'invalid_slug');

$notOurs = T::plan(['kind' => 'something-else', 'presets' => []], $existing, $active);
check('a foreign document is refused', ($notOurs['ok'] ?? true) === false && ($notOurs['error'] ?? '') === 'wrong_kind');
check('a document without a list is refused', (T::plan([], $existing, $active)['error'] ?? '') === 'invalid_document');

$ctrl = (string)file_get_contents($root . '/src/Http/Controllers/DomainPresetsController.php');
$index = (string)file_get_contents($root . '/index.php');
// A preset is more than its models: an import that only compared models would
// report "unchanged" for a document whose names differ, so a restore of one's
// own export would silently keep the old names.
$renamed = $doc;
$renamed['presets'][0]['name'] = 'Default (renamed)';
$renamedPlan = T::plan($renamed, $existing, $active);
check('a renamed preset counts as a change', ($renamedPlan['plan'][0]['action'] ?? '') === 'update',
    (string)($renamedPlan['plan'][0]['action'] ?? ''));
check('and the new name is carried in the plan', ($renamedPlan['plan'][0]['name'] ?? '') === 'Default (renamed)');
$newArbiter = $doc;
$newArbiter['presets'][0]['arbiter'] = ['provider' => 'bothub', 'model' => 'gpt-6-astra-pro'];
check('an identical arbiter is not a change',
    (T::plan($newArbiter, $existing, $active)['plan'][0]['action'] ?? '') === 'unchanged');
$droppedArbiter = $doc;
$droppedArbiter['presets'][0]['arbiter'] = null;
check('a removed arbiter is a change',
    (T::plan($droppedArbiter, $existing, $active)['plan'][0]['action'] ?? '') === 'update');

check('the controller exposes the export', str_contains($ctrl, 'public function export(): string'));
check('and the import', str_contains($ctrl, 'public function import(): string'));
check('both are routed', str_contains($index, "'/api/domain-presets/export'") && str_contains($index, "'/api/domain-presets/import'"));
check('the literal route is registered before the {slug} one',
    strpos($index, "'/api/domain-presets/export'") < strpos($index, "'/api/domain-presets/{slug}'"));
check('nothing is written without apply', str_contains($ctrl, "(\$payload['apply'] ?? false) === true"));
check('a write failure is reported, not swallowed', str_contains($ctrl, "'ok'      => \$failed === []"));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
