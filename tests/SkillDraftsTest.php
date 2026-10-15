<?php
declare(strict_types=1);
/**
 * SkillDraftsTest: a skill can be drafted, reviewed, and kept - once.
 *
 * The draft endpoint must never write, the confirm endpoint must refuse a
 * definition a run could not use, and keeping a skill twice must not produce
 * two skills. Where a connector would be needed the test uses a registry with
 * none, which is the documented fallback path - so this runs without network.
 *
 *   php tests/SkillDraftsTest.php
 */
use App\Connectors\ConnectorRegistry;
use App\Http\Controllers\SkillDraftsController;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillRepository;

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

// The creator asks Config for the synthesis model, the way the pipeline always
// does; give the test the same shape instead of a bare process.
$tmpConfig = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-draft-config';
if (!is_dir($tmpConfig)) { @mkdir($tmpConfig, 0775, true); }
file_put_contents(
    $tmpConfig . DIRECTORY_SEPARATOR . 'config.php',
    "<?php\nreturn " . var_export([
        'app'      => ['name' => 'RevoAp', 'version' => '0.0.0', 'debug' => false],
        'logging'  => ['path' => $tmpConfig . DIRECTORY_SEPARATOR . 'logs'],
        'pipeline' => ['synthesis_provider' => 'bothub', 'synthesis_model' => 'gpt-6-luna-pro'],
    ], true) . ";\n",
);
\App\Core\Config::load($tmpConfig . DIRECTORY_SEPARATOR . 'config.php', $tmpConfig);

check('the creator is loadable', class_exists(SkillAutoCreator::class));
check('the controller is loadable', class_exists(SkillDraftsController::class));

// The registry reads a directory. An empty one means no connectors, which is
// the documented fallback path - and it keeps this test off the network.
$emptyConnectors = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-connectors-empty';
if (!is_dir($emptyConnectors)) { @mkdir($emptyConnectors, 0775, true); }
$repo = new SkillRepository(null);
$creator = new SkillAutoCreator($repo, new ConnectorRegistry($emptyConnectors));

// the shape a run can actually use
check('a definition with a name and one usable role is valid',
    $creator->isValidDraft(['name' => 'X', 'roles' => [['key' => 'a', 'system' => 'You are A.']]]));
check('a definition without a name is refused', !$creator->isValidDraft(['roles' => [['key' => 'a', 'system' => 'x']]]));
check('a definition without roles is refused', !$creator->isValidDraft(['name' => 'X']));
check('empty roles are refused', !$creator->isValidDraft(['name' => 'X', 'roles' => []]));
check('a role without a key is refused', !$creator->isValidDraft(['name' => 'X', 'roles' => [['system' => 'x']]]));
check('a role without a system prompt is refused', !$creator->isValidDraft(['name' => 'X', 'roles' => [['key' => 'a']]]));

// no connectors: the documented fallback, and it still produces a valid shape
$draft = $creator->draft('Estimate the cost of a rail link between two cities.');
check('without a connector the draft falls back', ($draft['source'] ?? '') === 'fallback', json_encode($draft['source'] ?? null));
// ``??`` treats a null value as absent, so ask for the key explicitly.
check('and says so instead of pretending a model answered',
    array_key_exists('model', $draft) && $draft['model'] === null);
$def = $draft['definition'] ?? [];
check('the fallback definition is one a run could use', $creator->isValidDraft($def));
check('it carries several roles', count($def['roles'] ?? []) >= 3);
check('and a synthesis prompt', is_string($def['synthesis_prompt'] ?? null) && $def['synthesis_prompt'] !== '');

// keeping a reviewed draft
$kept = $creator->persist($def);
check('a reviewed draft can be kept', $kept->slug !== '', $kept->slug);
check('it is not a system skill', $kept->isSystem === false);
check('the roles survive the round trip', count($kept->roles()) === count($def['roles']));
$again = $creator->persist($def);
check('keeping it twice does not produce a second skill', $again->slug === $kept->slug);

$threw = false;
try { $creator->persist(['name' => 'Broken']); } catch (\InvalidArgumentException) { $threw = true; }
check('keeping something a run could not use throws', $threw);

// the controller: the draft route writes nothing
require_once $root . '/src/Http/Response.php';
$body = json_decode($creator instanceof SkillAutoCreator ? '{}' : '{}', true);
$_POST = [];
$ctrl = new SkillDraftsController($creator);
$empty = $ctrl->draft();
check('a draft without a query is refused', str_contains($empty, 'query_required'), substr($empty, 0, 120));

$src = (string)file_get_contents($root . '/src/Http/Controllers/SkillDraftsController.php');
check('the draft answer says nothing was saved', str_contains($src, "'saved'      => false,"));
check('the confirm route validates before writing', str_contains($src, 'isValidDraft($definition)'));
check('and maps an invalid definition to 400', str_contains($src, "'definition_invalid'"));
check('the route pair is registered', str_contains((string)file_get_contents($root . '/index.php'), "'/api/skills/draft/confirm'"));
check('external API keys are kept off it', str_contains((string)file_get_contents($root . '/src/Api/ApiAuth.php'), "'/api/skills/draft',"));
// The role generator can have a model of its own; unset means the synthesis
// model, the same inheritance the actualize section uses for its analyzer.
$creatorSrc = (string)file_get_contents($root . '/src/Skills/SkillAutoCreator.php');
check('the creator has its own target', str_contains($creatorSrc, 'public function generatorTarget(): array'));
check('it prefers the generator slot', str_contains($creatorSrc, "Config::get('skills.generator.provider'"));
check('and falls back to the synthesis model', str_contains($creatorSrc, "Config::get('pipeline.synthesis_model', 'gpt-6-luna-pro')"));
check('the draft reports the generator that ran', str_contains($creatorSrc, "implode('/', \$this->generatorTarget())"));
$ctrlSrc = (string)file_get_contents($root . '/src/Http/Controllers/SkillDraftsController.php');
check('the settings pair exists', str_contains($ctrlSrc, 'public function generatorSettings(): string')
    && str_contains($ctrlSrc, 'public function saveGenerator(): string'));
check('a batch-only model is refused with its reason', str_contains($ctrlSrc, 'model_not_synchronous'));
check('an uncatalogued model is refused', str_contains($ctrlSrc, 'model_not_in_catalogue'));
check('resetting means inheriting again', str_contains($ctrlSrc, "'skills.generator.provider' => ''"));
check('both settings routes are registered',
    str_contains((string)file_get_contents($root . '/index.php'), "'/api/skills/generator'"));
$js = (string)file_get_contents($root . '/assets/js/models.js');
check('the Models tab carries the Skills card', str_contains($js, "title: 'Skills'"));
check('with the generator picker', str_contains($js, "'oc-skill-generator-picker'"));
check('and the two-step draft flow', str_contains($js, "'oc-skill-draft-run'") && str_contains($js, "'oc-skill-draft-keep'"));
check('keeping is the only step that writes',
    str_contains($js, "'/api/skills/draft/confirm'"));

// Step 2: a kept skill must reach a run. The catalogue reads the bundled
// files, so without the merge a drafted skill was invisible to the composer.
$catalogSrc = (string)file_get_contents($root . '/src/Skills/ConsensusSkillCatalog.php');
check('the catalogue can merge user skills', str_contains($catalogSrc, 'public static function mergeUserSkills('));
$keptSkill = new \App\Skills\Skill(
    id: 1, slug: 'my-kept', name: 'My kept skill', description: 'd',
    definition: ['roles' => [['key' => 'a', 'system' => 'x']], 'tags' => ['consensus']],
    isSystem: false,
);
$merged = \App\Skills\ConsensusSkillCatalog::mergeUserSkills([], [$keptSkill]);
check('a kept skill appears in the catalogue list', ($merged[0]['slug'] ?? '') === 'my-kept');
check('marked as the operator own', ($merged[0]['source'] ?? '') === 'user' && ($merged[0]['is_system'] ?? true) === false);
check('using the user pool by default', ($merged[0]['uses_user_pool'] ?? false) === true);
// ``??`` treats a null value as absent - ask for the key.
check('with no preset of its own',
    array_key_exists('preset', $merged[0]) && $merged[0]['preset'] === null
    && array_key_exists('models', $merged[0]) && $merged[0]['models'] === []);
check('the catalogue scoring default applies',
    ($merged[0]['consensus_scoring'] ?? '') === \App\Skills\ConsensusSkillCatalog::DEFAULT_SCORING);
$ownScoring = new \App\Skills\Skill(
    id: 2, slug: 'own', name: 'Own', description: '',
    definition: ['roles' => [], 'consensus_scoring' => 'semantic_components', 'tags' => []],
    isSystem: false,
);
$m2 = \App\Skills\ConsensusSkillCatalog::mergeUserSkills([], [$ownScoring]);
check('a definition own scoring is carried', ($m2[0]['consensus_scoring'] ?? '') === 'semantic_components');
check('a slug already listed is not shadowed',
    count(\App\Skills\ConsensusSkillCatalog::mergeUserSkills([['slug' => 'my-kept']], [$keptSkill])) === 1);
check('the route merges user skills into its answer',
    str_contains((string)file_get_contents($root . '/index.php'), 'mergeUserSkills('));

check('the auto-creator no longer names a dead default',
    !str_contains((string)file_get_contents($root . '/src/Skills/SkillAutoCreator.php'), "'openai/gpt-4o'"));

echo "----------------------------------------\n";
echo $failed === 0 ? "ALL OK: {$passes} passed, 0 failed\n" : "FAILED: {$passes} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
