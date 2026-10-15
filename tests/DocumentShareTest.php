<?php
/**
 * DocumentShareTest: the Share endpoint writes the document it promises.
 *
 * The Share button next to every Copy / Copy MD pair posts a block's topic and
 * Markdown to /api/share/document, which writes share/<slug>.md and answers
 * with the link. Four things have to hold, and each is checked here without
 * PHPUnit, a database or a network:
 *
 *   1. a topic of any language or shape becomes a name MD.Viewer can open -
 *      its ?file= pattern is ^[A-Za-z0-9._\-/]+$, so a Cyrillic topic has to
 *      reach the file system transliterated, never with a space in it;
 *   2. a hostile topic cannot steer the write out of share/;
 *   3. the answer says whether MD.Viewer is installed and hands back the link
 *      that fits: the viewer URL when it is, the plain file URL when it is not;
 *   4. the endpoint is wired - route, controller, and the API-key blocklist
 *      that keeps external keys off an endpoint that writes files.
 *
 *   php tests/DocumentShareTest.php
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-09-27
 */
declare(strict_types=1);

use App\Core\Config;
use App\Core\Logger;
use App\Core\RuntimeConfig;
use App\Extensions\MdViewerInstaller;
use App\History\DocumentSlug;
use App\Http\Controllers\DocumentShareController;

$root = dirname(__DIR__);
foreach (['/vendor/autoload.php', '/src/autoload.php'] as $candidate) {
    if (is_file($root . $candidate)) {
        require $root . $candidate;
    }
}

$passes = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passes, $failed;
    if ($cond) {
        echo "  ok  {$label}\n";
        $passes++;
    } else {
        echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
        $failed++;
    }
}

/** The name MD.Viewer will open through ?file=. */
function viewerAccepts(string $name): bool
{
    return preg_match('#^[A-Za-z0-9._\-/]+$#', $name) === 1
        && strlen($name) <= 255
        && !str_contains($name, '..')
        && !str_contains($name, ' ');
}

// =====================================================================
echo "== a topic of any shape becomes a name the viewer can open ==\n";
// =====================================================================
check('the slug helper is loadable', class_exists(DocumentSlug::class));
if (!class_exists(DocumentSlug::class)) {
    echo "FAILED: {$passes} passed, {$failed} failed\n";
    exit(1);
}

$expected = [
    'Final answer'                             => 'final-answer',
    'Отчёт по рынку: итоги 2026!'              => 'otchet-po-rynku-itogi-2026',
    'Как дела?'                                => 'kak-dela',
    '  Your   question  '                      => 'your-question',
    'L1 · anthropic/claude — Step label'       => 'l1-anthropic-claude-step-label',
    'Знание' . "\n" . 'сила'                   => 'znanie-sila',
    'Café Münster'                             => 'cafe-munster',
    'Step (3) of 4 [draft]'                    => 'step-3-of-4-draft',
    'тема.md'                                  => 'tema.md',
];
foreach ($expected as $topic => $want) {
    $got = DocumentSlug::make($topic);
    check('topic ' . json_encode($topic) . ' -> ' . $want, $got === $want, $got);
}

check('a topic with nothing usable yields no name', DocumentSlug::make('...') === '');
check('an empty topic yields no name', DocumentSlug::make('   ') === '');
check('dots only never escape as a name', !str_contains(DocumentSlug::make('..'), '..'));
check('a slash in the topic does not survive', !str_contains(DocumentSlug::make('a/b/c'), '/'));
check('a backslash in the topic does not survive', !str_contains(DocumentSlug::make('a\\b'), '\\'));
check('a control character in the topic does not survive',
    preg_match('/[\x00-\x1F\x7F]/', DocumentSlug::make("a\x00b\x1Fc")) !== 1);
check('a very long topic is capped', strlen(DocumentSlug::make(str_repeat('тема ', 200))) <= DocumentSlug::MAX_LENGTH);
check('every example name is one the viewer accepts',
    array_reduce(array_keys($expected), static fn(bool $c, string $t): bool => $c && viewerAccepts(DocumentSlug::make($t) . '.md'), true));

// =====================================================================
echo "== the endpoint writes the document and answers with a link ==\n";
// =====================================================================
check('the controller is loadable', class_exists(DocumentShareController::class));
if (!class_exists(DocumentShareController::class)) {
    echo "FAILED: {$passes} passed, {$failed} failed\n";
    exit(1);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'revoap-share-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);

// Minimal config so the endpoint logs the way it does under a real request,
// and an empty overlay in this root so the repository's own .config.php is not
// applied on top of the fixture.
Config::reset();
RuntimeConfig::reset();
file_put_contents(
    $tmp . DIRECTORY_SEPARATOR . 'config.php',
    "<?php\ndeclare(strict_types=1);\nreturn " . var_export([
        'app'     => ['name' => 'RevoAp', 'version' => '0.0.0', 'debug' => false],
        'logging' => ['path' => $tmp . DIRECTORY_SEPARATOR . 'logs'],
    ], true) . ";\n",
);
file_put_contents($tmp . DIRECTORY_SEPARATOR . '.config.php', "<?php\ndeclare(strict_types=1);\nreturn [];\n");
Config::load($tmp . DIRECTORY_SEPARATOR . 'config.php', $tmp);
Logger::reset();
$shareDir   = $tmp . DIRECTORY_SEPARATOR . 'share';
$viewerDir  = $tmp . DIRECTORY_SEPARATOR . MdViewerInstaller::DIR;
$controller = new DocumentShareController($tmp, new MdViewerInstaller($tmp));

$topic = 'Отчёт по рынку: итоги 2026!';
$md    = "# Отчёт по рынку\n\nГотово: **итоги** подведены.\n";

$_POST = ['title' => $topic, 'markdown' => $md];
$answer = json_decode($controller->create(), true);
check('the answer is json', is_array($answer), (string)json_last_error_msg());
check('the write succeeded', ($answer['ok'] ?? false) === true, (string)json_encode($answer));
check('the name is the transliterated topic', ($answer['file'] ?? '') === 'otchet-po-rynku-itogi-2026.md', (string)($answer['file'] ?? ''));
check('the byte count is the document size', ($answer['bytes'] ?? -1) === strlen($md));
check('the file exists in share/', is_file($shareDir . DIRECTORY_SEPARATOR . 'otchet-po-rynku-itogi-2026.md'));
check('the file content is byte-identical to the payload',
    @file_get_contents($shareDir . DIRECTORY_SEPARATOR . 'otchet-po-rynku-itogi-2026.md') === $md);
check('nothing was written into the app root', !is_file($tmp . DIRECTORY_SEPARATOR . 'otchet-po-rynku-itogi-2026.md'));

check('without a viewer the answer says so', ($answer['installed'] ?? null) === false);
check('without a viewer the copied link is the file itself',
    str_ends_with((string)($answer['url'] ?? ''), '/share/otchet-po-rynku-itogi-2026.md'), (string)($answer['url'] ?? ''));
check('without a viewer there is no viewer link', ($answer['viewer_url'] ?? null) === '');
check('the file link is always reported',
    str_ends_with((string)($answer['file_url'] ?? ''), '/share/otchet-po-rynku-itogi-2026.md'));

// The viewer appears where its installer looks for it.
mkdir($viewerDir, 0775, true);
file_put_contents($viewerDir . DIRECTORY_SEPARATOR . 'md.php', "<?php // stub\n");
$_POST = ['title' => $topic, 'markdown' => $md];
$withViewer = json_decode($controller->create(), true);
$viewerName = 'otchet-po-rynku-itogi-2026.md';
check('with a viewer the answer says so', ($withViewer['installed'] ?? null) === true);
check('with a viewer the copied link goes through MD.Viewer',
    str_ends_with((string)($withViewer['url'] ?? ''), '/extensions/md-viewer/md.php?file=' . $viewerName), (string)($withViewer['url'] ?? ''));
check('the viewer link is absolute', str_starts_with((string)($withViewer['viewer_url'] ?? ''), 'http'));
check('the file link stays available next to the viewer link',
    str_ends_with((string)($withViewer['file_url'] ?? ''), '/share/' . $viewerName));
check('a second share of the same topic rewrites the same file',
    @file_get_contents($shareDir . DIRECTORY_SEPARATOR . $viewerName) === $md);

// =====================================================================
echo "== a hostile topic cannot leave share/ ==\n";
// =====================================================================
$before = array_values(array_diff(scandir(dirname($tmp)) ?: [], ['.', '..']));
$hostile = ['../../etc/passwd', '..%2f..%2fetc%2fpasswd', 'a/b/c', 'C:\\Windows\\System32', "bad\x00name", '....//....//etc', '/etc/shadow'];
$stayed = true;
foreach ($hostile as $bad) {
    $_POST = ['title' => $bad, 'markdown' => "# probe\n"];
    $res = json_decode($controller->create(), true);
    if (($res['ok'] ?? false) === true) {
        $name = (string)($res['file'] ?? '');
        $stayed = $stayed
            && str_contains($name, '.md')
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && (str_contains($name, '..') === false)
            && is_file($shareDir . DIRECTORY_SEPARATOR . $name);
    } else {
        // Refusing is fine too - what matters is that nothing was written.
        $stayed = $stayed && in_array($res['error'] ?? '', ['invalid_title'], true);
    }
}
check('every hostile topic stayed inside share/', $stayed);
$after = array_values(array_diff(scandir(dirname($tmp)) ?: [], ['.', '..']));
check('nothing new appeared next to the app root', $before === $after, json_encode(array_diff($after, $before)));
check('no traversal target was created',
    !file_exists(dirname($tmp) . '/etc') && !file_exists(dirname($tmp, 2) . '/etc'));

// =====================================================================
echo "== the endpoint refuses what it cannot store ==\n";
// =====================================================================
$_POST = ['title' => 'Final answer', 'markdown' => '   '];
$empty = json_decode($controller->create(), true);
check('an empty document is refused', ($empty['ok'] ?? true) === false && ($empty['error'] ?? '') === 'empty_document');

$_POST = ['title' => 'Final answer', 'markdown' => str_repeat('a', 2097152 + 1)];
$big = json_decode($controller->create(), true);
check('a document over the cap is refused', ($big['ok'] ?? true) === false && ($big['error'] ?? '') === 'document_too_large');
check('the refusals wrote nothing', !is_file($shareDir . DIRECTORY_SEPARATOR . 'final-answer.md'));

$_POST = ['title' => '...', 'markdown' => "# x\n"];
$noTopic = json_decode($controller->create(), true);
check('a topic that names nothing is refused', ($noTopic['ok'] ?? true) === false && ($noTopic['error'] ?? '') === 'invalid_title');

$wrongVerb = json_decode($controller->methodNotAllowed(), true);
check('a GET is answered 405 with an Allow header', ($wrongVerb['error'] ?? '') === 'method_not_allowed' && ($wrongVerb['allow'] ?? '') === 'POST');

$_POST = [];
$nothing = json_decode($controller->create(), true);
check('a body with no fields is refused', ($nothing['ok'] ?? true) === false);

// =====================================================================
echo "== the endpoint is wired into the app ==\n";
// =====================================================================
$indexSrc = (string)@file_get_contents($root . '/index.php');
$authSrc  = (string)@file_get_contents($root . '/src/Api/ApiAuth.php');
check('index.php registers the POST route', str_contains($indexSrc, "\$router->post('/api/share/document'"));
check('index.php registers the 405 route', str_contains($indexSrc, "\$router->get ('/api/share/document'"));
check('index.php builds the controller', str_contains($indexSrc, 'new DocumentShareController('));
check('external API keys are kept off the endpoint',
    str_contains($authSrc, "'" . DocumentShareController::ENDPOINT . "'"));

// --- best effort cleanup of this run's fixtures ---------------------------
foreach (glob($shareDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
    if (is_file($file)) { @unlink($file); }
}
@unlink($viewerDir . DIRECTORY_SEPARATOR . 'md.php');
@rmdir($viewerDir);
@rmdir($viewerDir === '' ? '' : dirname($viewerDir));
@rmdir($shareDir);
@rmdir($tmp);

echo "----------------------------------------\n";
echo $failed === 0
    ? "ALL OK: {$passes} passed, 0 failed\n"
    : "FAILED: {$passes} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
