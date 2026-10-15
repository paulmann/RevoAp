<?php
declare(strict_types=1);
/**
 * SkillSelectorTest: unit checks for the Automatic Skill Selector.
 *
 * The selector decides, before a run, which skill carries a task: it asks a
 * configured model to pick from the catalogue (with the role that should
 * lead), or - when nothing fits or the topic is narrow - searches arXiv and
 * has the role generator build a new skill. Every path degrades instead of
 * throwing: router, fallback skill, generic council.
 *
 * The model calls and the arXiv search are injected, so the whole decision
 * tree is exercised without network or connectors.
 *
 * Run: php tests/SkillSelectorTest.php
 *
 * @project  RevoAp
 * @version  1.0.0
 * @since    2026-09-29
 */
require __DIR__ . '/../src/autoload.php';

use App\Connectors\ConnectorRegistry;
use App\Core\Config;
use App\Skills\Skill;
use App\Skills\SkillAutoCreator;
use App\Skills\SkillRepository;
use App\Skills\SkillSelector;

// Config::set marks the tree loaded: the classes read config under the hood.
Config::set(['app' => ['version' => '0.0.0', 'debug' => false, 'log_to_console' => false]]);

$passed = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo 'ok   ', $label, "\n";
        return;
    }
    $failed++;
    echo 'FAIL ', $label, ($detail !== '' ? "  [$detail]" : ''), "\n";
}

$nowhere  = 'C:/nonexistent-connectors-' . bin2hex(random_bytes(4));
$registry = new ConnectorRegistry($nowhere);

// ---- isRequested: every spelling the composer may send ----
check('the automatic entry is recognised by its stem', SkillSelector::isRequested(['consensus_skill' => 'auto_selector']));
check('and by its slug', SkillSelector::isRequested(['consensus_skill' => 'auto-selector']));
check('and via the skill key', SkillSelector::isRequested(['skill' => 'auto_selector']));
check('and in its array form', SkillSelector::isRequested(['consensus_skill' => ['slug' => 'auto-selector']]));
check('an ordinary skill is not the selector', !SkillSelector::isRequested(['consensus_skill' => 'consensus_reasoning']));
check('and neither is an empty option set', !SkillSelector::isRequested([]));

// ---- withPreferredRole: the role pick reorders the participant order ----
$roles = [
    ['key' => 'alpha', 'label' => 'Alpha', 'system' => 'a'],
    ['key' => 'beta',  'label' => 'Beta',  'system' => 'b'],
    ['key' => 'gamma', 'label' => 'Gamma', 'system' => 'g'],
];
$demo  = new Skill(null, 'demo', 'Demo', '', ['roles' => $roles]);
$moved = SkillSelector::withPreferredRole($demo, 'beta');
check('the preferred role moves to the front', ($moved->roles()[0]['key'] ?? '') === 'beta');
check('and the others keep their order', array_column($moved->roles(), 'key') === ['beta', 'alpha', 'gamma']);
check('an unknown key leaves the order alone', array_column(SkillSelector::withPreferredRole($demo, 'nope')->roles(), 'key') === ['alpha', 'beta', 'gamma']);
check('a null key leaves the order alone', SkillSelector::withPreferredRole($demo, null)->roles() === $roles);

// ---- select(): the model picks an existing skill ----
$repo = new SkillRepository(null);
$repo->save(new Skill(null, 'webapp-architect', 'Web Application Architect', 'Designs web applications', [
    'roles' => [
        ['key' => 'lead_architect', 'label' => 'Lead Architect', 'system' => 's'],
        ['key' => 'security',       'label' => 'Security',       'system' => 's'],
    ],
], false));

$captured = [];
$selector = new SkillSelector(
    $repo,
    $registry,
    null,
    static fn(string $q, int $m): array => ['papers' => [], 'error' => null, 'query' => $q],
    static function (string $p, string $m, array $msgs) use (&$captured): ?string {
        $captured = $msgs;
        return '{"action":"use","slug":"webapp-architect","role":"security","reason":"fits"}';
    },
);
$out = $selector->select('design a billing portal');
check('the decision names an existing skill', ($out['skill']->slug ?? '') === 'webapp-architect');
check('with its source marked as the model', ($out['source'] ?? '') === 'model');
check('and the role pick rides along', ($out['role'] ?? '') === 'security');
check('nothing was created', ($out['created'] ?? true) === false);
check(
    'the decision prompt carries the catalogue',
    str_contains((string)($captured[1]['content'] ?? ''), '"webapp-architect"')
        && !str_contains((string)($captured[1]['content'] ?? ''), '"auto-selector"'),
);

// ---- select(): the model asks for research; a new skill is built ----
$creation = '{"slug":"quantum-flux","name":"Quantum Flux Council","description":"d",'
    . '"roles":[{"key":"physicist","label":"Physicist","system":"s"},{"key":"critic","label":"Critic","system":"s"}],'
    . '"synthesis_prompt":"c","preferred_role":"physicist",'
    . '"sources":[{"title":"Paper One","url":"https://arxiv.org/abs/2501.00001"}]}';
$creator = new SkillAutoCreator($repo, $registry, static fn(string $p, string $m, array $msgs): ?string => $creation);
$asked = [];
$selector2 = new SkillSelector(
    $repo,
    $registry,
    $creator,
    static function (string $q, int $m) use (&$asked): array {
        $asked[] = $q;
        return [
            'papers' => [['title' => 'Paper One', 'summary' => 'about flux', 'url' => 'https://arxiv.org/abs/2501.00001']],
            'error'  => null,
            'query'  => $q,
        ];
    },
    static fn(string $p, string $m, array $msgs): ?string => '{"action":"create","arxiv_query":"quantum flux computing","reason":"narrow"}',
);
$out2 = $selector2->select('estimate decoherence in a 3-qubit flux device');
check('a narrow task is researched and built', ($out2['created'] ?? false) === true && ($out2['skill']->slug ?? '') === 'quantum-flux');
check('the arXiv query came from the decision', ($out2['arxiv_query'] ?? '') === 'quantum flux computing');
check('the papers were searched with it', $asked === ['quantum flux computing']);
check('the new skill carries its sources', ($out2['skill']->definition['sources'][0]['url'] ?? '') === 'https://arxiv.org/abs/2501.00001');
check('the preferred role survives validation', ($out2['role'] ?? '') === 'physicist');
check('and the skill was kept in the library', $repo->findBySlug('quantum-flux') !== null);

// ---- building disabled: the fallback skill takes over ----
Config::set(['skills' => ['selector' => ['auto_create' => false, 'fallback_skill' => 'webapp-architect']]]);
$selector3 = new SkillSelector(
    $repo,
    $registry,
    $creator,
    static fn(string $q, int $m): array => ['papers' => [], 'error' => null, 'query' => $q],
    static fn(string $p, string $m, array $msgs): ?string => '{"action":"create","arxiv_query":"x","reason":"narrow"}',
);
$out3 = $selector3->select('another narrow task');
check('with building off the fallback skill runs', ($out3['skill']->slug ?? '') === 'webapp-architect');
check('and the source says fallback', ($out3['source'] ?? '') === 'fallback');

// ---- no decision model at all: the lexical router still resolves ----
Config::set(['skills' => ['selector' => ['auto_create' => false, 'fallback_skill' => '']]]);
$selector4 = new SkillSelector(
    $repo,
    $registry,
    null,
    static fn(string $q, int $m): array => ['papers' => [], 'error' => null, 'query' => $q],
    static fn(string $p, string $m, array $msgs): ?string => null,
);
$out4 = $selector4->select('design a web application architecture');
check('a dead decision model falls back to lexical routing', ($out4['source'] ?? '') === 'router');
check('and still resolves a real skill', ($out4['skill']->slug ?? '') === 'webapp-architect');

// ---- a role the skill does not carry is refused, not invented ----
$selector5 = new SkillSelector(
    $repo,
    $registry,
    null,
    static fn(string $q, int $m): array => ['papers' => [], 'error' => null, 'query' => $q],
    static fn(string $p, string $m, array $msgs): ?string => '{"action":"use","slug":"webapp-architect","role":"ghost","reason":"fits"}',
);
$out5 = $selector5->select('design a portal');
check('an unknown role pick is dropped', array_key_exists('role', $out5) && $out5['role'] === null);

echo "\n== SkillSelectorTest summary ==\n";
echo "passed: {$passed}  failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
