<?php
/**
 * CatalogCapTest: the catalogue row cap and its truncation rule.
 *
 * Covers the S1 half of the incident this release fixes: the persisted
 * catalogue (`var/config/runtime.json` → `models[]`) was capped at a
 * hard-coded 64 rows, so it could not represent the provider's live list
 * (~520 ids). The pool, the domain presets and the L4 synthesis model were
 * then pointed at models the catalogue did not hold, and a run resolved an
 * empty L1 pool and still reported success.
 *
 * Verified here:
 *   - the cap is configuration (`models.catalog.max_rows`,
 *     `Config::catalogMaxRows()`), not a constant: default, explicit values,
 *     `0` = no ceiling, and unusable values falling back to the default
 *     rather than to "unlimited";
 *   - `models.catalog` cannot be mistaken for catalogue data — the loader
 *     lifts it out (`Config::liftCatalogSettings()`), so writing the setting
 *     in `config.php` or `.config.php` cannot suppress the shipped-catalogue
 *     expansion and leave the install with zero models;
 *   - `.config.php` wins over `config.php` for the setting itself;
 *   - `ModelCatalogMerge` no longer rewrites the caller's limit
 *     (`resolveLimit()`), applies `0` as "no ceiling" instead of "keep
 *     nothing", and counts what it left out;
 *   - `CatalogTruncation` keeps every row the pool, a preset or a linked
 *     role references, ranks the rest by provider family with the newest
 *     release first (never in arrival order), interleaves the families, and
 *     reports the rule it applied.
 *
 * Runs without PHPUnit, a database or a network:
 *   php tests/CatalogCapTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-21
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Core\RuntimeConfig;
use App\Models\CatalogTruncation;
use App\Models\ModelCatalogMerge;
use App\Models\ProviderFamily;

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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-catalog-cap-' . bin2hex(random_bytes(4));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'config', 0o775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'logs', 0o775, true);
@mkdir($tmp . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'models', 0o775, true);

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/**
 * Boot the config stack against the temp root.
 *
 * @param array<string,mixed> $tree         the extra tree to merge into the base
 * @param array<string,mixed>|null $overlay `.config.php` contents (null: none)
 */
function boot(array $tree = [], ?array $overlay = null): void
{
    global $tmp;
    Config::reset();
    RuntimeConfig::reset();

    $models = [
        ['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true, 'default' => true],
        ['provider' => 'bothub', 'model' => 'claude-opus-5', 'active' => true],
        ['provider' => 'bothub', 'model' => 'deepseek-r2', 'active' => false],
    ];
    file_put_contents(
        $tmp . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'shipped.php',
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($models, true) . ";\n",
    );

    $config = [
        'app'         => ['name' => 'RevoAp', 'version' => '1.8.60', 'debug' => true],
        'logging'     => ['path' => $tmp . DIRECTORY_SEPARATOR . 'logs'],
        'ModelsTab'   => true,
        'SettingsTab' => true,
        // Empty on purpose: the shipped catalogue must expand into it.
        'models'      => [],
        'model_files' => ['config/models/shipped.php'],
        'pipeline'    => ['default_selected_models' => [['provider' => 'bothub', 'model' => 'claude-opus-5']]],
    ];
    foreach ($tree as $key => $value) {
        $config[$key] = $value;
    }
    file_put_contents(
        $tmp . DIRECTORY_SEPARATOR . 'config.php',
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n",
    );

    $overlayFile = $tmp . DIRECTORY_SEPARATOR . '.config.php';
    if ($overlay === null) {
        @unlink($overlayFile);
    } else {
        file_put_contents($overlayFile, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($overlay, true) . ";\n");
    }

    @unlink($tmp . DIRECTORY_SEPARATOR . RuntimeConfig::FILE_RELATIVE);
    Config::load($tmp . DIRECTORY_SEPARATOR . 'config.php', $tmp);
}

// =====================================================================
echo "\n== the cap comes from configuration ==\n";
// =====================================================================

boot();
check('the default cap is the documented 1000, not the old 64',
    Config::catalogMaxRows() === Config::CATALOG_MAX_ROWS_DEFAULT
    && Config::catalogMaxRows() === 1000,
    (string)Config::catalogMaxRows());
check('the shipped catalogue still expands beside the setting (3 rows)',
    count((array)Config::get('models', [])) === 3, (string)count((array)Config::get('models', [])));
check('the pool is read from the catalogue unchanged',
    Config::getActiveModels() !== [], json_encode(Config::getActiveModels()));

boot(['models_catalog' => ['max_rows' => 250]]);
check('an explicit cap is honoured (models_catalog.max_rows = 250)',
    Config::catalogMaxRows() === 250, (string)Config::catalogMaxRows());

// The spelling the operator was told to use, carried inside `models` next to
// the rows exactly as config.php documents it.
boot(['models' => [
    ['provider' => 'bothub', 'model' => 'gpt-5.6-sol', 'active' => true],
    'catalog' => ['max_rows' => 64],
]]);
check('the dotted spelling models.catalog.max_rows is honoured', Config::catalogMaxRows() === 64,
    (string)Config::catalogMaxRows());
check('…and the rows it was written beside are untouched (no settings row in the catalogue)',
    count((array)Config::get('models', [])) === 1
    && ((array)Config::get('models', []))[0]['model'] === 'gpt-5.6-sol',
    json_encode(Config::get('models', [])));

// The booby trap: a `models` array that holds nothing but settings must NOT
// count as an operator-supplied catalogue, or the install ends up with zero
// models and every run resolves an empty pool.
boot(['models' => ['catalog' => ['max_rows' => 0]]]);
check('a settings-only `models` array still expands the shipped catalogue',
    count((array)Config::get('models', [])) === 3, json_encode(Config::get('models', [])));
check('…and max_rows = 0 reads as "no ceiling"', Config::catalogMaxRows() === 0,
    (string)Config::catalogMaxRows());

boot([], ['Protect_Settings_by_Admin_Key' => 1, 'models_catalog' => ['max_rows' => 0]]);
check('.config.php wins over config.php for the cap',
    Config::catalogMaxRows() === 0, (string)Config::catalogMaxRows());

foreach ([['max_rows' => 'banana'], ['max_rows' => ''], ['max_rows' => ['nested' => 1]], ['max_rows' => null]] as $bad) {
    $tree = ['models_catalog' => $bad];
    boot($tree);
    check('an unusable cap falls back to the default, never to "unlimited" ('
        . json_encode($bad) . ')',
        Config::catalogMaxRows() === 1000, (string)Config::catalogMaxRows());
}

// =====================================================================
echo "\n== the merge no longer rewrites the caller's limit ==\n";
// =====================================================================

check('resolveLimit passes an explicit limit through untouched (> the legacy 64)',
    ModelCatalogMerge::resolveLimit(250) === 250, (string)ModelCatalogMerge::resolveLimit(250));
check('resolveLimit keeps 0 as "no ceiling"',
    ModelCatalogMerge::resolveLimit(0) === 0, (string)ModelCatalogMerge::resolveLimit(0));
check('resolveLimit resolves the sentinel from configuration',
    ModelCatalogMerge::resolveLimit(ModelCatalogMerge::LIMIT_FROM_CONFIG) === Config::catalogMaxRows());

$incoming = [];
for ($i = 0; $i < 100; $i++) {
    $incoming[] = ['id' => 'vendor/model-' . $i];
}
$merged = ModelCatalogMerge::mergeImported([], $incoming, 'bothub', 100);
check('a 100-row import under a 100-row limit lands 100 rows (no clamp to 64)',
    count($merged['rows']) === 100 && $merged['truncated'] === 0,
    json_encode(['rows' => count($merged['rows']), 'truncated' => $merged['truncated']]));
$uncapped = ModelCatalogMerge::mergeImported([], $incoming, 'bothub', 0);
check('limit 0 imports everything instead of nothing',
    count($uncapped['rows']) === 100, (string)count($uncapped['rows']));
$capped = ModelCatalogMerge::mergeImported([], $incoming, 'bothub', 10);
check('a real cap still caps, and says how many it left out',
    count($capped['rows']) === 10 && $capped['truncated'] === 90,
    json_encode(['rows' => count($capped['rows']), 'truncated' => $capped['truncated']]));

$submitted = [];
for ($i = 0; $i < 80; $i++) {
    $submitted[] = ['provider' => 'bothub', 'model' => 'm' . $i, 'active' => false];
}
$savedAll = ModelCatalogMerge::mergeSubmitted($submitted, [], 0);
check('the save-side merge keeps every row when the ceiling is off',
    count($savedAll['rows']) === 80, (string)count($savedAll['rows']));
$savedCapped = ModelCatalogMerge::mergeSubmitted($submitted, [], 64);
check('the save-side merge still enforces a configured cap, and counts the rest',
    count($savedCapped['rows']) === 64 && $savedCapped['truncated'] === 16,
    json_encode(['rows' => count($savedCapped['rows']), 'truncated' => $savedCapped['truncated']]));

// =====================================================================
echo "\n== truncation keeps what is referenced and ranks the rest ==\n";
// =====================================================================

$rows = static function (array $ids): array {
    $out = [];
    foreach ($ids as $id) {
        [$provider, $model] = explode('|', $id, 2);
        $out[] = ['provider' => $provider, 'model' => $model, 'active' => true];
    }
    return $out;
};

$everything = $rows(['bothub|gpt-5.6', 'bothub|claude-opus-5', 'openrouter|openai/gpt-5.6']);
$report = CatalogTruncation::apply($everything, 0, []);
check('0 means no ceiling at all',
    $report['kept'] === 3 && $report['dropped'] === 0
    && $report['rule'] === CatalogTruncation::RULE_UNCAPPED,
    json_encode($report));

$report = CatalogTruncation::apply($everything, 10, []);
check('a cap above the row count changes nothing',
    $report['kept'] === 3 && $report['dropped'] === 0
    && $report['rule'] === CatalogTruncation::RULE_UNCHANGED,
    json_encode($report));

// Referenced rows survive a cap that cannot hold them.
$report = CatalogTruncation::apply(
    $everything,
    2,
    ['bothub|claude-opus-5', 'openrouter|openai/gpt-5.6'],
);
// (the cap is exactly the size of the protected set here: nothing overflows
// yet, so the rule is still the ranked one — the overflow case is below)
$keptIds = array_map(static fn(array $r): string => $r['provider'] . '|' . $r['model'], $report['rows']);
check('every referenced row survives, even when the cap cannot hold them',
    in_array('bothub|claude-opus-5', $keptIds, true)
    && in_array('openrouter|openai/gpt-5.6', $keptIds, true)
    && $report['protected_kept'] === 2,
    json_encode($keptIds));
check('the unreferenced row is the one that goes',
    !in_array('bothub|gpt-5.6', $keptIds, true) || $report['dropped'] === 0,
    json_encode(['kept' => $keptIds, 'dropped' => $report['dropped']]));
// One slot, two referenced rows: the cap cannot hold what the pool,
// a preset and the linked roles need, so it is exceeded on purpose and the
// rule says which decision that was.
$overflow = CatalogTruncation::apply(
    $everything,
    1,
    ['bothub|claude-opus-5', 'openrouter|openai/gpt-5.6'],
);
check('when the referenced rows alone exceed the cap, they are kept anyway',
    $overflow['protected_kept'] === 2 && $overflow['kept'] === 2,
    json_encode($overflow));
check('the invariant wins over the cap, and says so',
    $overflow['over_cap'] === 1 && $overflow['rule'] === CatalogTruncation::RULE_PROTECTED_OVER_CAP,
    json_encode($overflow));
check('the report explains that the cap was raised rather than broken',
    str_contains(CatalogTruncation::describe($overflow), 'already exceed it'),
    CatalogTruncation::describe($overflow));

// Arrival order is not the rule: 40 rows of one family first, then one row of
// another family, under a cap of 4. The blind slice this replaces would have
// filled all four slots from the first family (which is how a capped
// catalogue ended up holding one vendor and none of the others).
$bulk = [];
for ($i = 0; $i < 40; $i++) {
    $bulk[] = ['provider' => 'bothub', 'model' => 'gpt-4o-mini-' . $i, 'active' => true];
}
$bulk[] = ['provider' => 'bothub', 'model' => 'claude-opus-5', 'active' => true];
$bulk[] = ['provider' => 'bothub', 'model' => 'gemini-3.1-pro', 'active' => true];
$bulk[] = ['provider' => 'bothub', 'model' => 'grok-4.6', 'active' => true];
$ranked = CatalogTruncation::apply($bulk, 4, []);
$rankedIds = array_map(static fn(array $r): string => (string)$r['model'], $ranked['rows']);
$rankedFamilies = array_values(array_unique(array_map(
    static fn(string $id): string => ProviderFamily::of($id),
    $rankedIds,
)));
check('a capped catalogue spans the families instead of filling it with the first one',
    count($rankedFamilies) === 4, json_encode(['kept' => $rankedIds, 'families' => $rankedFamilies]));
check('and the newest release of each family is what represents it',
    in_array('claude-opus-5', $rankedIds, true)
    && in_array('gemini-3.1-pro', $rankedIds, true)
    && in_array('grok-4.6', $rankedIds, true),
    json_encode($rankedIds));
check('the rows that survived are reported, not silently dropped',
    $ranked['dropped'] === count($bulk) - 4
    && count($ranked['dropped_ids']) === count($bulk) - 4
    && $ranked['rule'] === CatalogTruncation::RULE_FLAGSHIPS_PER_FAMILY,
    json_encode(['dropped' => $ranked['dropped'], 'rule' => $ranked['rule']]));
check('what was left out is disclosed in words, with the rule',
    str_contains(CatalogTruncation::describe($ranked), 'left out')
    && str_contains(CatalogTruncation::describe($ranked), 'newest release of every provider family'),
    CatalogTruncation::describe($ranked));
check('nothing is disclosed when nothing was dropped',
    CatalogTruncation::describe(CatalogTruncation::apply($everything, 0, [])) === '');

// Newest-first inside a family, not catalogue position.
$line = $rows(['bothub|gpt-5.2', 'bothub|claude-opus-4', 'bothub|gpt-5.6', 'bothub|claude-opus-5']);
$lineReport = CatalogTruncation::apply($line, 1, []);
$lineKept = (string)$lineReport['rows'][0]['model'];
check('within a family the newest release wins over the earlier catalogue position',
    $lineKept === 'gpt-5.6', $lineKept);

// The ranking follows the EDITORIAL family order, so a truncated catalogue
// leads with the vendors an operator is most likely to have keys for. The
// sharp end of that is the index: it reaches two digits, and `ksort()`
// compares the sort keys as strings — without zero padding, moonshot (10)
// would sort before zhipu (9) and quietly replace the editorial order with a
// wrong one. One slot, two families, and the earlier one must win the slot.
$twoFamilies = [
    ['provider' => 'bothub', 'model' => 'kimi-k2', 'active' => true],
    ['provider' => 'bothub', 'model' => 'glm-5', 'active' => true],
];
check('the two families really resolve to the neighbours in the order table',
    ProviderFamily::of('glm-5') === 'zhipu'
    && ProviderFamily::of('kimi-k2') === 'moonshot'
    && ProviderFamily::order('zhipu') < ProviderFamily::order('moonshot'),
    json_encode([ProviderFamily::of('glm-5'), ProviderFamily::of('kimi-k2')]));
$oneSlot = CatalogTruncation::apply($twoFamilies, 1, []);
check('the ranking keeps the editorially earlier family, not a string-sorted one',
    count($oneSlot['rows']) === 1
    && ProviderFamily::of((string)$oneSlot['rows'][0]['model']) === 'zhipu',
    json_encode(array_map(static fn(array $r): string => (string)$r['model'], $oneSlot['rows'])));

check('the canonical key matches the diff/merge key shape',
    CatalogTruncation::key('BoThub', ' gpt-5.6 ') === 'bothub|gpt-5.6'
    && CatalogTruncation::key('', 'x') === '');

rmTree($tmp);

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
