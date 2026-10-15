<?php
declare(strict_types=1);
/**
 * SkillDocsTest: the Skills tab's document and its wiring.
 *
 * The document is what an operator reads when deciding to run a council: the
 * skill's full description, every role with its verbatim prompt, and the
 * arXiv sources the skill carries. The page's promise - "full description in
 * a new window, through MD.Viewer when installed" - rests on two joints this
 * suite pins: the renderer, and the share writer whose publish() both the
 * Share button and the Skills tab must keep using (one write path, one set
 * of limits, one viewer URL).
 *
 *   php tests/SkillDocsTest.php
 */
require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Extensions\MdViewerInstaller;
use App\Http\Controllers\DocumentShareController;
use App\Skills\SkillDoc;

$failures = 0;
$passes   = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failures++; }
}

// ---- the document ------------------------------------------------------------
$doc = SkillDoc::markdown([
    'slug' => 'demo-skill',
    'name' => 'Demo Skill | with pipe',
    'description' => "A demo description.\nSecond line.",
    'source' => 'user',
    'scoring' => 'semantic_components',
    'preset' => null,
    'models' => [],
    'uses_user_pool' => true,
    'roles' => [
        ['key' => 'analyst', 'label' => 'Analyst', 'system' => "Line one\n\nLine two"],
        ['key' => 'critic', 'name' => 'Critic', 'system_prompt' => 'Be strict.'],
    ],
    'sources' => [
        ['title' => 'A paper [draft]', 'url' => 'https://arxiv.org/abs/2411.16189'],
        ['title' => 'bad', 'url' => 'ftp://nope'],
    ],
]);
check('the document names the skill', str_contains($doc, '# Demo Skill \| with pipe'), $doc);
check('the description is kept', str_contains($doc, 'A demo description.'));
check('the table carries the slug', str_contains($doc, '| Slug | `demo-skill` |'));
check('user skills are named as such', str_contains($doc, '| Source | user - kept through the draft flow |'));
check('the pool fallback is stated',
    str_contains($doc, '| Preset | _none - the council runs on the selected pool_ |')
    && str_contains($doc, '(from the selected pool)'));
check('roles are counted and labelled',
    str_contains($doc, '## Roles (2)') && str_contains($doc, '### 1. Analyst - `analyst`'));
check('the second role uses the draft shape',
    str_contains($doc, '### 2. Critic - `critic`') && str_contains($doc, 'Be strict.'));
check('prompts render verbatim in a fence', str_contains($doc, "```text\nLine one\n\nLine two\n```"));
check('sources render in the viewer\'s numbered shape', str_contains($doc, "1. **A paper [draft]**\n   - URL: https://arxiv.org/abs/2411.16189"));
check('and keep the Sources heading the viewer lifts', str_contains($doc, '## Sources'));
check('a non-http source is dropped', !str_contains($doc, 'ftp://nope'));
check('the document ends with one newline', str_ends_with($doc, "\n") && !str_ends_with($doc, "\n\n"));

$bare = SkillDoc::markdown([
    'slug' => 'x', 'name' => '', 'description' => '', 'source' => 'bundled', 'scoring' => '',
    'preset' => null, 'models' => [], 'uses_user_pool' => false, 'roles' => [], 'sources' => [],
]);
check('an empty skill still renders', str_contains($bare, '# (untitled skill)') && str_contains($bare, '## Roles (0)'));
check('without sources it says so', str_contains($bare, '_No sources are recorded for this skill._'));
check('without sources there is no Sources heading', !str_contains($bare, '## Sources'));

$autoDoc = SkillDoc::markdown([
    'slug'          => 'auto-selector',
    'name'          => 'Automatic Skill Selector',
    'description'   => 'Picks or builds the skill.',
    'auto_selector' => true,
    'stages'        => [
        ['title' => 'Skill scout', 'text' => 'Reads the catalogue.'],
        ['title' => 'Council',     'text' => 'Runs the council.'],
    ],
]);
check('the auto selector explains its stages', str_contains($autoDoc, '## How it works') && str_contains($autoDoc, 'Skill scout'));
check('and keeps the empty role list out', !str_contains($autoDoc, '## Roles'));

// Config::set marks the tree loaded: Logger::info() inside publish() reads
// app.debug, and a CLI test has no config file to load.
Config::set(['app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false]]);

// ---- publishing through the share writer -------------------------------------
$tmpDir = sys_get_temp_dir() . '/rp-skilldoc-' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0777, true);
$share = new DocumentShareController($tmpDir, new MdViewerInstaller($tmpDir));
$out = $share->publish('Skill description Demo', "# Demo\n\nBody\n");
check('publish answers with the http hint', ($out['http'] ?? 0) === 200 && ($out['ok'] ?? false) === true);
check('the file lands under share/',
    is_file($tmpDir . '/share/' . (string)($out['file'] ?? '')) && str_ends_with((string)($out['file'] ?? ''), '.md'),
    (string)($out['file'] ?? ''));
check('without MD.Viewer the url is the file url',
    ($out['installed'] ?? true) === false
    && ($out['viewer_url'] ?? 'x') === ''
    && ($out['url'] ?? '') === ($out['file_url'] ?? '?'));
$empty = $share->publish('Skill description Demo', '   ');
check('empty markdown is refused', ($empty['http'] ?? 0) === 400 && ($empty['error'] ?? '') === 'empty_document');
@unlink($tmpDir . '/share/' . (string)($out['file'] ?? ''));
@rmdir($tmpDir . '/share');
@rmdir($tmpDir);

// ---- the wiring --------------------------------------------------------------
$root = dirname(__DIR__);
$layout = (string)file_get_contents($root . '/assets/templates/layout.html');
check('Skills sits right of History in the header',
    strpos($layout, 'id="oc-tab-skills"') !== false
    && strpos($layout, 'id="oc-tab-skills"') > strpos($layout, 'id="oc-tab-history"'));
check('Statistic lives in the sidebar block now',
    (bool)preg_match('/<ul class="oc-about-nav">.*data-tab="statistic"/s', $layout));
check('and appears exactly once', substr_count($layout, 'data-tab="statistic"') === 1);
check('the skills panel exists',
    str_contains($layout, 'data-tab-panel="skills"') && str_contains($layout, 'id="oc-skills-root"'));
check('skills assets are linked',
    str_contains($layout, 'components/skills.css') && str_contains($layout, 'js/skills.js'));

$index = (string)file_get_contents($root . '/index.php');
check('the document route is registered', str_contains($index, "'/api/consensus/skills/{slug}/document'"));
check('the controller is wired with the share writer',
    str_contains($index, 'new SkillDocsController(__DIR__, $documentShareCtrl, $skillRepo, $presetsRepo)'));
$auth = (string)file_get_contents($root . '/src/Api/ApiAuth.php');
check('external api keys stay off it', str_contains($auth, "'/api/consensus/skills'"));

$js = (string)file_get_contents($root . '/assets/js/skills.js');
check('the tab opens a new window', str_contains($js, "window.open(target, '_blank'") && str_contains($js, "'/document'"));
check('and prefers the viewer url', str_contains($js, "(body?.installed === true && viewerUrl !== '')"));

$shareSrc = (string)file_get_contents($root . '/src/Http/Controllers/DocumentShareController.php');
check('the share writer exposes one publish path', str_contains($shareSrc, 'public function publish('));

// The construct-time contract: the types index.php passes must fit. This
// exact mismatch (App\Storage vs App\Skills\SkillRepository) was a live 500.
check('the controller constructs with the real repositories', (function () use ($root): bool {
    new \App\Http\Controllers\SkillDocsController(
        $root,
        new DocumentShareController($root, new MdViewerInstaller($root)),
        new \App\Skills\SkillRepository(null),
        new \App\DomainPresets\DomainPresetRepository(null),
    );
    return true;
})());
foreach (['consensus_reasoning', 'semantic_consensus_reasoning', 'mathematics_reasoning', 'physics_reasoning'] as $definition) {
    check('definition carries sources: ' . $definition,
        str_contains((string)file_get_contents($root . '/src/Skills/definitions/' . $definition . '.json'), '"sources"'));
}

echo "\n----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
