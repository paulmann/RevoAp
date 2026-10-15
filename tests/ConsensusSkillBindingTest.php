<?php
/**
 * ConsensusSkillBindingTest: a consensus skill's model binding, end to end on
 * the server side.
 *
 * Covers the third half of the v1.8.61 defect: selecting a Consensus skill
 * under the chat changed nothing in the model panel beside it, although a
 * skill's council runs on the models its domain preset defines.
 *
 * Verified here:
 *   - GET /api/consensus/skills carries the binding — `preset`, `models`
 *     (active + selected rows only), `arbiter`, `uses_user_pool` — and keeps
 *     every key it published before;
 *   - the join is exact slug equality, `preset.skill_slug === skill.slug`, and
 *     nothing else: a near miss (trailing space, different case, a prefix, a
 *     NULL) binds nothing rather than "the closest preset";
 *   - a skill no preset names is reported as `uses_user_pool: true` with no
 *     models, never given another preset's pool;
 *   - duplicate bindings resolve the same way on every request;
 *   - the payload discloses no secret (provider/model ids only), which is why
 *     the route stays public — asserted, not assumed;
 *   - index.php builds the list through the catalog and keeps the response
 *     contract and its guard-free route;
 *   - app.js applies the binding through applyPresetToSelection(), never
 *     invents one, reports `applied`/`skipped`/`reason`, and leaves
 *     `consensus_skill` in the request body untouched.
 *
 * The behavioural half of the browser side (the pool really changing, the note
 * the operator reads) lives in tests/harness/chat-stream.mjs, driven by
 * tests/ChatStreamDomTest.php.
 *
 * Runs without PHPUnit, a database or a network:
 *   php tests/ConsensusSkillBindingTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-22
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\DomainPresets\DomainPreset;
use App\DomainPresets\DomainPresetRepository;
use App\Skills\ConsensusSkillCatalog;

$failures = 0;
$passes   = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
        $failures++;
    }
}

$root    = dirname(__DIR__);
$defsDir = $root . '/src/Skills/definitions';
$catalog = new ConsensusSkillCatalog($defsDir);

/** A preset with just enough shape for the binding rule. */
function preset(
    int $id,
    string $slug,
    ?string $skillSlug,
    array $models = [],
    ?array $arbiter = null,
): DomainPreset {
    return new DomainPreset(
        id: $id,
        slug: $slug,
        name: ucfirst($slug),
        description: '',
        models: $models,
        arbiter: $arbiter,
        skillSlug: $skillSlug,
        isSystem: true,
    );
}

/** @param array<int,array<string,mixed>> $skills */
function indexBySlug(array $skills): array
{
    $out = [];
    foreach ($skills as $skill) { $out[(string)$skill['slug']] = $skill; }
    return $out;
}

// =====================================================================
echo "== the bundled consensus skills, with the binding attached ==\n";
// =====================================================================
$shipped = $catalog->list(DomainPresetRepository::canonicalPresets());
$bySlug  = indexBySlug($shipped);

check('the definitions directory is read (the shipped skills are listed)',
    $bySlug !== [], 'no skill was listed');
check('the four bundled consensus skills are there',
    isset($bySlug['mathematics-reasoning'], $bySlug['physics-reasoning'],
        $bySlug['semantic-consensus-reasoning'], $bySlug['consensus-reasoning']),
    implode(',', array_keys($bySlug)));
check('a definition without the consensus tag is never listed',
    !isset($bySlug['db-optimizer'], $bySlug['php-code-refiner'], $bySlug['ux-frontend']),
    implode(',', array_keys($bySlug)));
// `ConsensusSkillCatalog::list()` sorts by slug (see the `usort` in
// src/Skills/ConsensusSkillCatalog.php) and every bundled definition that
// carries the `consensus` tag is listed - which now includes the Automatic
// Skill Selector entry (`auto-selector`). The sort order is what this check is
// about, so it asserts that order over the slugs actually present instead of
// hard-coding "consensus-reasoning is first": that expectation belonged to the
// four-skill catalogue and turned a new, correctly-listed skill into a failure.
$slugs  = array_keys($bySlug);
$sorted = $slugs;
usort($sorted, static fn(string $a, string $b): int => strcmp($a, $b));
check('the list is sorted by slug, so the response is stable',
    $slugs === array_values(array_unique($slugs)) && $slugs === $sorted,
    'actual=' . implode(',', $slugs) . ' expected=' . implode(',', $sorted));

$contractKeys = ['slug', 'file', 'name', 'description', 'consensus_scoring', 'tags'];
$bindingKeys  = ['preset', 'models', 'arbiter', 'uses_user_pool'];
$missing = [];
foreach ($shipped as $skill) {
    foreach (array_merge($contractKeys, $bindingKeys) as $key) {
        if (!array_key_exists($key, $skill)) { $missing[] = $skill['slug'] . '.' . $key; }
    }
}
check('every entry keeps the keys it published before (additions only)',
    $missing === [], implode(',', $missing));
check('the shipped fields still carry the definition values',
    $bySlug['mathematics-reasoning']['name'] === 'Mathematics Reasoning Council'
    && $bySlug['mathematics-reasoning']['file'] === 'mathematics_reasoning'
    && $bySlug['mathematics-reasoning']['consensus_scoring'] === 'semantic_components'
    && in_array('consensus', $bySlug['mathematics-reasoning']['tags'], true),
    json_encode($bySlug['mathematics-reasoning']));

// =====================================================================
echo "\n== the join is exact slug equality, and nothing else ==\n";
// =====================================================================
check('a preset naming the skill binds it',
    $bySlug['mathematics-reasoning']['preset'] === 'mathematics'
    && $bySlug['mathematics-reasoning']['uses_user_pool'] === false,
    json_encode($bySlug['mathematics-reasoning']['preset']));
check('the second bound skill binds to its own preset',
    $bySlug['physics-reasoning']['preset'] === 'physics'
    && $bySlug['physics-reasoning']['uses_user_pool'] === false,
    json_encode($bySlug['physics-reasoning']['preset']));
check('a skill no preset names has no binding at all',
    $bySlug['semantic-consensus-reasoning']['preset'] === null
    && $bySlug['semantic-consensus-reasoning']['models'] === []
    && $bySlug['semantic-consensus-reasoning']['arbiter'] === null
    && $bySlug['semantic-consensus-reasoning']['uses_user_pool'] === true,
    json_encode($bySlug['semantic-consensus-reasoning']));
check('and another preset\'s models are not substituted for the missing binding',
    $bySlug['semantic-consensus-reasoning']['models'] === []
    && !str_contains(json_encode($bySlug['semantic-consensus-reasoning']), 'gpt-5.5'),
    json_encode($bySlug['semantic-consensus-reasoning']));
check('the default preset (skill_slug NULL) binds nothing',
    !in_array('default', array_column($shipped, 'preset'), true),
    implode(',', array_map(static fn(array $s): string => (string)$s['preset'], $shipped)));

// Near misses must stay near misses: a prefix, a case difference, a trailing
// space and an empty string are four different strings, and none of them is
// the slug the skill carries.
$nearMisses = [
    preset(1, 'prefix', 'mathematics', [['provider' => 'alpha', 'model' => 'p-1']]),
    preset(2, 'case', 'Mathematics-Reasoning', [['provider' => 'alpha', 'model' => 'c-1']]),
    preset(3, 'spaced', 'mathematics-reasoning ', [['provider' => 'alpha', 'model' => 's-1']]),
    preset(4, 'unset', null, [['provider' => 'alpha', 'model' => 'u-1']]),
    preset(5, 'empty', '', [['provider' => 'alpha', 'model' => 'e-1']]),
];
$nearBySlug = indexBySlug($catalog->list($nearMisses));
check('no near miss binds the skill it approximates',
    $nearBySlug['mathematics-reasoning']['preset'] === null
    && $nearBySlug['mathematics-reasoning']['models'] === []
    && $nearBySlug['mathematics-reasoning']['uses_user_pool'] === true,
    json_encode($nearBySlug['mathematics-reasoning']));
check('a prefix of the slug is not the slug',
    ConsensusSkillCatalog::binding('mathematics-reasoning', ConsensusSkillCatalog::bindingsBySkillSlug([$nearMisses[0]]))['preset'] === null);
check('case differences are not the slug',
    ConsensusSkillCatalog::binding('mathematics-reasoning', ConsensusSkillCatalog::bindingsBySkillSlug([$nearMisses[1]]))['preset'] === null);
check('a trailing space is not the slug',
    ConsensusSkillCatalog::binding('mathematics-reasoning', ConsensusSkillCatalog::bindingsBySkillSlug([$nearMisses[2]]))['preset'] === null);
check('an empty or NULL skill_slug never binds',
    ConsensusSkillCatalog::bindingsBySkillSlug([$nearMisses[3], $nearMisses[4]]) === []);

// =====================================================================
echo "\n== the bound pool is the preset's ACTIVE participants ==\n";
// =====================================================================
$mixed = preset(9, 'mathematics', 'mathematics-reasoning', [
    ['provider' => 'alpha', 'model' => 'kept-1', 'active' => true,  'selected' => true],
    ['provider' => 'alpha', 'model' => 'unselected', 'active' => true,  'selected' => false],
    ['provider' => 'alpha', 'model' => 'inactive',   'active' => false, 'selected' => true],
    ['provider' => 'beta',  'model' => 'kept-2', 'active' => true,  'selected' => true],
    ['provider' => '',      'model' => 'no-provider', 'active' => true, 'selected' => true],
], ['provider' => 'alpha', 'model' => 'arbiter-1', 'role' => 'main']);
$bound = ConsensusSkillCatalog::binding('mathematics-reasoning', ConsensusSkillCatalog::bindingsBySkillSlug([$mixed]));
check('only the rows that are both active and selected are offered',
    $bound['models'] === [
        ['provider' => 'alpha', 'model' => 'kept-1'],
        ['provider' => 'beta',  'model' => 'kept-2'],
    ],
    json_encode($bound['models']));
check('every offered row is exactly {provider, model}',
    array_keys($bound['models'][0]) === ['provider', 'model']);
check('a row without a provider is dropped rather than published half-empty',
    !in_array('no-provider', array_column($bound['models'], 'model'), true));
check('the binding names the preset it came from', $bound['preset'] === 'mathematics');
check('the arbiter travels with the binding',
    $bound['arbiter'] === ['provider' => 'alpha', 'model' => 'arbiter-1', 'role' => 'main'],
    json_encode($bound['arbiter']));
check('a preset with no arbiter reports none',
    ConsensusSkillCatalog::binding('mathematics-reasoning', ConsensusSkillCatalog::bindingsBySkillSlug([preset(10, 'x', 'mathematics-reasoning', [['provider' => 'a', 'model' => 'm']])]))['arbiter'] === null);

// The shipped presets are the ones the Models tab lists, so the two surfaces
// cannot disagree about what a preset's pool is.
$mathPreset = null;
foreach (DomainPresetRepository::canonicalPresets() as $p) {
    if ($p->slug === 'mathematics') { $mathPreset = $p; }
}
check('the shipped binding is the shipped preset\'s own pool',
    $mathPreset !== null
    && $bySlug['mathematics-reasoning']['models'] === $mathPreset->selectedModels()
    && count($bySlug['mathematics-reasoning']['models']) === 5,
    json_encode($bySlug['mathematics-reasoning']['models']));
check('the bound pool holds no batch-only or empty id',
    array_reduce(
        $bySlug['mathematics-reasoning']['models'],
        static fn(bool $ok, array $m): bool => $ok && $m['provider'] !== '' && $m['model'] !== '',
        true,
    ));

// =====================================================================
echo "\n== duplicates and non-preset input ==\n";
// =====================================================================
$dupes = [
    preset(1, 'first',  'mathematics-reasoning', [['provider' => 'alpha', 'model' => 'first-1']]),
    preset(2, 'second', 'mathematics-reasoning', [['provider' => 'alpha', 'model' => 'second-1']]),
];
check('the first preset that names a skill is the binding (stable across requests)',
    ConsensusSkillCatalog::binding('mathematics-reasoning', ConsensusSkillCatalog::bindingsBySkillSlug($dupes))['preset'] === 'first');
check('bindingsBySkillSlug ignores anything that is not a preset',
    ConsensusSkillCatalog::bindingsBySkillSlug([null, 'mathematics-reasoning', 7]) === []);
check('an unknown skill slug gets the unbound shape, not an exception',
    ConsensusSkillCatalog::binding('no-such-skill', ConsensusSkillCatalog::bindingsBySkillSlug($dupes))
        === ['preset' => null, 'models' => [], 'arbiter' => null, 'uses_user_pool' => true]);
check('a missing definitions directory yields an empty list instead of a fatal',
    (new ConsensusSkillCatalog($root . '/src/Skills/definitions-not-here'))->list([]) === []);

// =====================================================================
echo "\n== through the real repository the route reads ==\n";
// =====================================================================
// No PDO is handed in, so this is the documented in-memory fallback: exactly
// what a host without a database serves, and the only path to the route's
// `$presetsRepo->all()` that can be exercised here.
$repo = new DomainPresetRepository();
$repo->seedDefaultsIfEmpty();
$viaRepo = indexBySlug($catalog->list($repo->all()));
check('the repository the route reads the presets from is not empty',
    $repo->all() !== [], json_encode($repo->all()));
check('and through it the skill binds to the preset the Models tab lists',
    $viaRepo['mathematics-reasoning']['preset'] === 'mathematics'
    && $viaRepo['physics-reasoning']['preset'] === 'physics'
    && $viaRepo['mathematics-reasoning']['uses_user_pool'] === false,
    json_encode($viaRepo['mathematics-reasoning']['preset']));
check('the unbound skill stays unbound through the repository as well',
    $viaRepo['semantic-consensus-reasoning']['preset'] === null
    && $viaRepo['semantic-consensus-reasoning']['models'] === []
    && $viaRepo['semantic-consensus-reasoning']['uses_user_pool'] === true);
check('a store without the table is reported as such, not pretended away',
    $repo->tableAvailable() === false);
check('the bound pool survives the round trip through the repository',
    $viaRepo['mathematics-reasoning']['models']
        === indexBySlug($catalog->list(DomainPresetRepository::canonicalPresets()))['mathematics-reasoning']['models'],
    json_encode($viaRepo['mathematics-reasoning']['models']));

// =====================================================================
echo "\n== the route keeps its contract, and nothing else leaks ==\n";
// =====================================================================
$index = (string)file_get_contents($root . '/index.php');
check('the endpoint builds its list through the catalog',
    str_contains($index, 'new \\App\\Skills\\ConsensusSkillCatalog(__DIR__ . \'/src/Skills/definitions\')'));
check('from the same preset repository the Models tab uses',
    str_contains($index, '$catalog->list($presetsRepo->all())'));
check('the response keeps its two contract keys',
    str_contains($index, "'consensus_skills' =>") && str_contains($index, "'default'          => 'semantic_consensus_reasoning'"));
$routeBody = '';
if (preg_match("/\\\$router->get\('\/api\/consensus\/skills',[\s\S]*?\n\}\);/", $index, $m) === 1) {
    $routeBody = $m[0];
}
check('the route body was found (the checks below are not vacuous)', $routeBody !== '');
check('the route stays public: no admin guard on it',
    $routeBody !== '' && !str_contains($routeBody, 'AdminAuth')
    && !str_contains($routeBody, 'guard()') && !str_contains($routeBody, 'unauthorized'));
check('no key, token or credential can travel in the payload',
    $routeBody !== '' && !preg_match('/api_key|token|secret|password/i', $routeBody));
$payload = json_encode($shipped);
check('and the payload itself carries ids and prose only',
    !preg_match('/api[_-]?key|secret|bearer/i', (string)$payload));

// =====================================================================
echo "\n== the client applies it, or says why it did not ==\n";
// =====================================================================
$appJs = (string)file_get_contents($root . '/assets/js/app.js');

check('the binding is applied through the existing selector helper',
    str_contains($appJs, 'applyPresetToSelection(models)'));
check('the helper is imported from the model selector',
    preg_match("/import \{[^}]*applyPresetToSelection[^}]*\} from '\.\/model-selector\.js'/", $appJs) === 1);
check('the no-binding branch is taken BEFORE anything is applied',
    ($noPool = strpos($appJs, 'uses_user_pool === true')) !== false
    && ($apply = strpos($appJs, 'applyPresetToSelection(models)')) !== false
    && $noPool < $apply,
    "uses_user_pool@{$noPool} apply@{$apply}");
check('a skill with no link leaves the pool alone and says so',
    str_contains($appJs, 'is not linked to a preset') && str_contains($appJs, 'left exactly as you assembled it'));
check('no preset is invented for it',
    !str_contains($appJs, "item.preset || 'default'")
    && !str_contains($appJs, "preset: 'default'"));
check('the count that was really applied is what is reported',
    str_contains($appJs, 'result.applied') && str_contains($appJs, 'result.applied === 0'));
check('applied === 0 is stated instead of glossed over',
    str_contains($appJs, 'but no model was ') && str_contains($appJs, 'The pool is unchanged.'));
check('the models no connector serves are named',
    str_contains($appJs, 'result.skipped') && str_contains($appJs, 'no configured connector serves'));
check('the reason a preset could not be applied travels with it',
    str_contains($appJs, '${result.reason}'));
check('choosing a skill is what triggers it',
    str_contains($appJs, "skillSel.addEventListener('change', applyConsensusSkillBinding)"));
check('and it runs once for the value already selected',
    str_contains($appJs, 'applyConsensusSkillBinding();'));
check('the status line lives with the skill control',
    str_contains($appJs, "'consensus-skill-status'"));
check('the run is untouched: consensus_skill still goes as chosen',
    str_contains($appJs, 'if (consensusSkill) body.consensus_skill = consensusSkill'));
check('the option tooltip says what the skill runs on before it is picked',
    str_contains($appJs, 'No linked preset: this council runs on the consensus pool')
    && str_contains($appJs, 'Linked to the'));

$chatHtml = (string)file_get_contents($root . '/assets/templates/chat.html');
check('the template ships the status element next to the skill select',
    str_contains($chatHtml, 'id="consensus-skill-status"'));
check('and it is announced as a status region',
    preg_match('/id="consensus-skill-status"[^>]*role="status"/', $chatHtml) === 1
    || preg_match('/role="status"[^>]*id="consensus-skill-status"/', $chatHtml) === 1,
    'no role="status" on the note');
check('the note element is not the chat status line', str_contains($chatHtml, 'id="chat-status"'));

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
