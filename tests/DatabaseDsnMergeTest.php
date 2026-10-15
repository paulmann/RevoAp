<?php
declare(strict_types=1);
/**
 * DatabaseDsnMergeTest: the settings form meets the configured target.
 *
 * The Database card mirrors config.php / .config.php; the promise of the
 * form is that a blank field means "as configured" (the password placeholder
 * literally says "(unchanged)"). This is the exact spot that produced
 * "Access denied ... (using password: NO)" when the blank was tested as an
 * empty credential, so every fallback is pinned here: blank and masked
 * passwords, blank user, cleared host/dbname/port/socket, and the provenance
 * the UI shows.
 *
 *   php tests/DatabaseDsnMergeTest.php
 */
require __DIR__ . '/../src/autoload.php';

use App\Settings\DatabaseDsn;

$failures = 0;
$passes   = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $failures, $passes;
    if ($cond) { echo "  ok  {$label}\n"; $passes++; }
    else { echo "  FAIL {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $failures++; }
}

$config = [
    'dsn'      => 'mysql:host=localhost;dbname=revoap;charset=utf8mb4',
    'user'     => 'revoap',
    'password' => 'super-secret-14',
];

// ---- the reported failure ----------------------------------------------------
$merged = DatabaseDsn::mergeForm([
    'host' => 'localhost', 'port' => 3306, 'dbname' => 'revoap',
    'charset' => 'utf8mb4', 'unix_socket' => '', 'user' => 'revoap', 'password' => '',
], $config);
check('a blank password falls back to the configured one', $merged['password'] === 'super-secret-14');
check('and the source says so', ($merged['sources']['password'] ?? '') === 'config');
check('the DSN matches the configured target', $merged['dsn'] === 'mysql:host=localhost;port=3306;dbname=revoap;charset=utf8mb4');
check('the user stays as typed', $merged['user'] === 'revoap' && $merged['sources']['user'] === 'form');

// ---- masked and provided passwords -------------------------------------------
$masked = DatabaseDsn::mergeForm(['host' => 'localhost', 'password' => '••••••'], $config);
check('a masked password means "unchanged"', $masked['password'] === 'super-secret-14');
$stars = DatabaseDsn::mergeForm(['host' => 'localhost', 'password' => '****'], $config);
check('so do asterisks', $stars['password'] === 'super-secret-14');
$typed = DatabaseDsn::mergeForm(['host' => 'localhost', 'password' => 'other-pass'], $config);
check('a typed password is used as typed', $typed['password'] === 'other-pass' && $typed['sources']['password'] === 'form');
check('and it never substitutes silently', $typed['password'] !== $config['password']);

// ---- blank user --------------------------------------------------------------
$noUser = DatabaseDsn::mergeForm(['host' => 'localhost', 'user' => '  '], $config);
check('a blank user falls back to the configured one', $noUser['user'] === 'revoap' && $noUser['sources']['user'] === 'config');

// ---- cleared DSN fields ------------------------------------------------------
$cleared = DatabaseDsn::mergeForm(['host' => '', 'dbname' => '', 'unix_socket' => '', 'port' => ''], $config);
check('cleared fields take the configured values',
    $cleared['dsn'] === 'mysql:host=localhost;port=3306;dbname=revoap;charset=utf8mb4', $cleared['dsn']);
check('and the DSN source turns config', ($cleared['sources']['dsn'] ?? '') === 'config');
$partial = DatabaseDsn::mergeForm(['host' => 'db.example.net', 'dbname' => '', 'unix_socket' => ''], $config);
check('a provided field beats the config', str_contains($partial['dsn'], 'host=db.example.net'));
check('while the blank one still falls back', str_contains($partial['dsn'], 'dbname=revoap'));

// ---- sockets and ports -------------------------------------------------------
$socket = DatabaseDsn::mergeForm(['unix_socket' => '/run/mysqld/mysqld.sock', 'host' => 'ignored'], $config);
check('a socket replaces host and port in the DSN', $socket['dsn'] === 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=revoap;charset=utf8mb4', $socket['dsn']);
$weirdPort = DatabaseDsn::mergeForm(['host' => 'localhost', 'port' => '0'], $config);
check('a nonsense port falls back to the configured one', str_contains($weirdPort['dsn'], 'port=3306'));

// ---- no configured password at all -------------------------------------------
$bare = DatabaseDsn::mergeForm(['host' => 'localhost', 'password' => ''], ['dsn' => $config['dsn'], 'user' => 'revoap', 'password' => '']);
check('with nothing configured the blank stays blank', $bare['password'] === '' && $bare['sources']['password'] === 'none');

// ---- the wiring --------------------------------------------------------------
$root = dirname(__DIR__);
$ctrl = (string)file_get_contents($root . '/src/Http/Controllers/SettingsController.php');
check('the controller tests through the merge', str_contains($ctrl, 'DatabaseDsn::mergeForm($body,'));
check('and reports the provenance', str_contains($ctrl, "\$result['sources'] = \$merged['sources'];"));
$js = (string)file_get_contents($root . '/assets/js/settings.js');
check('the UI says when the config supplied the password', str_contains($js, 'пароль из конфига'));
check('and when there is none anywhere', str_contains($js, 'пароль не задан'));

echo "\n----------------------------------------\n";
echo ($failures === 0 ? 'ALL OK' : 'FAILURES') . ": {$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
