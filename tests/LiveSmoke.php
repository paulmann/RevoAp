<?php
/**
 * Live end-to-end smoke check against a running server.
 *
 * Not part of the unit suite: it needs `php -S` on 127.0.0.1:8123. It exists
 * because every other test in this project inspects source text or calls a
 * class directly, and neither proves that a browser would actually get a
 * working page. This one does the request, reads the real response headers and
 * asserts on the real bytes.
 *
 * Run:  php -S 127.0.0.1:8123 -t . &   then   php tests/LiveSmoke.php
 */
declare(strict_types=1);

$base = getenv('REVOAP_BASE') ?: 'http://127.0.0.1:8123';
$passes = 0;
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passes, $failures;
    if ($ok) { $passes++; echo "  ok  $label\n"; }
    else { $failures++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function section(string $t): void { echo "\n== $t ==\n"; }

/**
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function req(string $path, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    global $base;
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'ignore_errors' => true,          // a 4xx/5xx still returns a body we want to read
        'timeout' => 20,
        'header' => implode("\r\n", $headers),
        'content' => $body,
    ]]);
    $raw = @file_get_contents($base . $path, false, $ctx);
    $lines = $http_response_header ?? [];
    $status = 0;
    $out = [];
    foreach ($lines as $i => $line) {
        if ($i === 0) {
            if (preg_match('#\s(\d{3})\s#', ' ' . $line . ' ', $m)) { $status = (int)$m[1]; }
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) { continue; }
        $out[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    return ['status' => $status, 'headers' => $out, 'body' => is_string($raw) ? $raw : ''];
}

// =====================================================================
section('GET / — the document a browser actually receives');
// =====================================================================

$r = req('/');
check('the app answers 200', $r['status'] === 200, (string)$r['status']);
check('the body is non-trivial', strlen($r['body']) > 8000, (string)strlen($r['body']));
$html = $r['body'];

// --- security headers -------------------------------------------------
check('X-Content-Type-Options: nosniff is set', ($r['headers']['x-content-type-options'] ?? '') === 'nosniff');
check('X-Frame-Options: DENY is set', strtoupper($r['headers']['x-frame-options'] ?? '') === 'DENY');
check('Referrer-Policy is set', ($r['headers']['referrer-policy'] ?? '') !== '');
check('Permissions-Policy is set and restrictive', str_contains($r['headers']['permissions-policy'] ?? '', 'geolocation=()'));
check('Cross-Origin-Opener-Policy is same-origin', ($r['headers']['cross-origin-opener-policy'] ?? '') === 'same-origin');
check('X-Robots-Tag forbids indexing (the console holds prompts and answers)',
    str_contains($r['headers']['x-robots-tag'] ?? '', 'noindex'));

$csp = $r['headers']['content-security-policy'] ?? '';
check('a Content-Security-Policy is sent', $csp !== '');
check("CSP script-src is 'self' + a nonce", (bool)preg_match("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{16,}'/", $csp), $csp);
check("CSP has no 'unsafe-inline' in script-src", !(bool)preg_match("/script-src[^;]*'unsafe-inline'/", $csp));
check("CSP has no 'unsafe-eval' anywhere", !str_contains($csp, "'unsafe-eval'"));
check('CSP closes object-src, frame-src, frame-ancestors and child-src',
    str_contains($csp, "object-src 'none'") && str_contains($csp, "frame-src 'none'")
    && str_contains($csp, "frame-ancestors 'none'") && str_contains($csp, "child-src 'none'"));
check('CSP pins base-uri and form-action to self',
    str_contains($csp, "base-uri 'self'") && str_contains($csp, "form-action 'self'"));
check('CSP contains no external origin at all', preg_match('#https?://#', $csp) !== 1, $csp);

// --- first paint ------------------------------------------------------
check('<html> carries lang', (bool)preg_match('/<html[^>]+lang="[a-z]{2}"/', $html));
check('<html> carries dir', (bool)preg_match('/<html[^>]+dir="(ltr|rtl)"/', $html));
check('lang and dir are consistent (only ar is rtl)',
    (function () use ($html): bool {
        preg_match('/<html[^>]+lang="([a-z]{2})"/', $html, $a);
        preg_match('/<html[^>]+dir="(ltr|rtl)"/', $html, $b);
        $lang = $a[1] ?? '';
        $dir = $b[1] ?? '';
        return ($lang === 'ar') === ($dir === 'rtl');
    })());
check('the CSP nonce in the document matches the header nonce',
    (function () use ($html, $csp): bool {
        preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $h);
        preg_match('/<script nonce="([^"]+)"/', $html, $d);
        return ($h[1] ?? '') !== '' && ($h[1] ?? '') === ($d[1] ?? '');
    })(), 'a mismatch means the inline boot script would be blocked');
check('the theme boot script is inlined before the stylesheet',
    strpos($html, 'prefers-color-scheme') < strpos($html, '/assets/dist/revoap.css'));
check('the translation catalogue is embedded, not fetched',
    str_contains($html, 'id="revoap-i18n-catalog"'));
check('the embedded catalogue is valid JSON and carries messages',
    (function () use ($html): bool {
        if (!preg_match('/id="revoap-i18n-catalog">(.*?)<\/script>/s', $html, $m)) { return false; }
        $decoded = json_decode($m[1], true);
        return is_array($decoded) && isset($decoded['messages']) && is_array($decoded['messages'])
            && count($decoded['messages']) > 500;
    })());
check('the embedded catalogue cannot terminate its own <script> block',
    (function () use ($html): bool {
        if (!preg_match('/id="revoap-i18n-catalog">(.*?)<\/script>/s', $html, $m)) { return false; }
        return !str_contains($m[1], '</script');
    })());
check('data-theme / data-accent / data-density are all server-rendered',
    (bool)preg_match('/data-theme="(light|dark)"/', $html)
    && (bool)preg_match('/data-accent="[a-z]+"/', $html)
    && (bool)preg_match('/data-density="[a-z]+"/', $html));

// --- shell ------------------------------------------------------------
check('the server-rendered header is present', str_contains($html, 'id="oc-app-header"'));
check('the tab registry ships every destination', substr_count($html, 'data-tab="') >= 20,
    (string)substr_count($html, 'data-tab="'));
check('a panel exists for every tab trigger',
    (function () use ($html): bool {
        preg_match_all('/data-tab="([a-z]+)"/', $html, $t);
        preg_match_all('/data-tab-panel="([a-z]+)"/', $html, $p);
        $missing = array_diff(array_unique($t[1]), array_unique($p[1]));
        return $missing === [];
    })(), 'a trigger with no panel renders a blank page');
check('#model-selector is present (the Chat pool picker mounts here)', str_contains($html, 'id="model-selector"'));
check('#connector-list is present', str_contains($html, 'id="connector-list"'));
check('the protection notice is server-rendered', str_contains($html, 'data-oc-protection-notice'));

// --- no CDN, anywhere -------------------------------------------------
check('the document references no remote script or stylesheet',
    preg_match('#(?:src|href)="https?://[^"]+\.(?:js|mjs|css|woff2?)"#', $html) !== 1);
check('KaTeX is loaded from the local vendor tree', str_contains($html, '/assets/vendor/katex/katex.min.js'));
check('the compiled stylesheet is linked', str_contains($html, '/assets/dist/revoap.css'));

// =====================================================================
section('static assets — MIME types matter under nosniff');
// =====================================================================

$assets = [
    '/assets/dist/revoap.css'                            => ['text/css'],
    '/assets/js/shell.js'                                => ['text/javascript', 'application/javascript'],
    '/assets/js/i18n.js'                                 => ['text/javascript', 'application/javascript'],
    '/assets/js/ui/components.js'                        => ['text/javascript', 'application/javascript'],
    '/assets/vendor/katex/katex.min.js'                  => ['text/javascript', 'application/javascript'],
    '/assets/vendor/katex/katex.min.css'                 => ['text/css'],
    '/assets/vendor/mermaid/mermaid.min.js'              => ['text/javascript', 'application/javascript'],
    '/assets/fonts/inter-latin-wght-normal.woff2'        => ['font/woff2'],
    '/assets/fonts/noto-sans-arabic-arabic-wght-normal.woff2' => ['font/woff2'],
];
// Either JS spelling is accepted: RFC 9239 standardised `text/javascript`, but
// `application/javascript` is what nginx, Apache and the PHP dev server still
// emit and every browser accepts both. Asserting one string would make this
// test fail on a correct production host.
foreach ($assets as $path => $acceptedMimes) {
    $a = req($path);
    check("$path serves 200", $a['status'] === 200, (string)$a['status']);
    $ct = $a['headers']['content-type'] ?? '';
    $ok = false;
    foreach ($acceptedMimes as $m) { if (str_starts_with($ct, $m)) { $ok = true; break; } }
    check("$path has a valid Content-Type (" . implode('|', $acceptedMimes) . ')', $ok, $ct ?: '(none)');
    // Under nosniff a browser REFUSES a resource whose declared type does not
    // match what it asked for; this is the assertion that the woff2 fix is real.
    check("$path is not octet-stream", !str_contains($ct, 'octet-stream'), $ct);
}

// Cacheability and isolation headers are set by serveAsset(). The PHP built-in
// server (`php -S -t .`) answers /assets/** from disk with its own header set
// and never calls it, so these assertions only mean something when the request
// actually reached PHP. Detect that by looking for a header only serveAsset
// emits, and otherwise verify the code path directly instead of over HTTP.
$font = req('/assets/fonts/inter-latin-wght-normal.woff2');
$servedByPhp = ($font['headers']['cross-origin-resource-policy'] ?? '') !== '';
if ($servedByPhp) {
    check('vendored assets are immutable-cached', str_contains($font['headers']['cache-control'] ?? '', 'immutable'),
        $font['headers']['cache-control'] ?? '');
    check('assets send an ETag', ($font['headers']['etag'] ?? '') !== '');
    check('assets are CORP same-origin', ($font['headers']['cross-origin-resource-policy'] ?? '') === 'same-origin');
} else {
    echo "  --  this server serves /assets statically; asserting serveAsset() from source instead\n";
    $indexSrc = (string)file_get_contents(__DIR__ . '/../index.php');
    check('serveAsset() sets an immutable cache for vendored assets',
        str_contains($indexSrc, 'max-age=31536000, immutable'));
    check('serveAsset() sends an ETag so a conditional request can 304',
        str_contains($indexSrc, "header('ETag: '"));
    check('serveAsset() sets Cross-Origin-Resource-Policy: same-origin',
        str_contains($indexSrc, "header('Cross-Origin-Resource-Policy: same-origin')"));
    check('serveAsset() refuses an extension outside its allow-list with 403',
        str_contains($indexSrc, 'asset type not served') && str_contains($indexSrc, 'ASSET_MIME'));
    check('the allow-list has no html entry (templates must not render as documents)',
        !preg_match("/'html'\s*=>/", $indexSrc));
    check('the allow-list covers woff2 (a nosniff browser refuses octet-stream fonts)',
        (bool)preg_match("/'woff2'\s*=>\s*'font\/woff2'/", $indexSrc));
    check('the traversal guard appends a separator to the base path',
        str_contains($indexSrc, '$base . DIRECTORY_SEPARATOR'));
    // Both server layers are hardened for the statically-served case.
    check('assets/.htaccess denies html/php for Apache hosts',
        is_file(__DIR__ . '/../assets/.htaccess')
        && str_contains((string)file_get_contents(__DIR__ . '/../assets/.htaccess'), 'Require all denied'));
    check('nginx/security-hardening.include denies the same for nginx hosts',
        is_file(__DIR__ . '/../nginx/security-hardening.include')
        && str_contains((string)file_get_contents(__DIR__ . '/../nginx/security-hardening.include'), '^/assets/templates/'));
}

// --- traversal and type refusal --------------------------------------
$traversals = [
    '/assets/../config.php',
    '/assets/../../etc/passwd',
    '/assets/%2e%2e/config.php',
    '/assets/css/../../../index.php',
    '/assets/css/../../../config.php',
    '/assets/css/../../../.admin.key',
];
foreach ($traversals as $t) {
    $res = req($t);
    // A traversal attempt must never return PHP source, the config, a secret
    // file or a catalogue. `{{` is checked too: an unrendered template is
    // structure disclosure.
    $leaked = $res['status'] === 200
        && (str_contains($res['body'], '<?php') || str_contains($res['body'], 'DB_PASS')
            || str_contains($res['body'], '{{') || str_contains($res['body'], '"plural_categories"'));
    check("traversal: $t does not leak", !$leaked,
        'status ' . $res['status'] . ' body ' . substr($res['body'], 0, 60));
}

// The template directory is NOT in the traversal list above because a web
// server configured to serve /assets statically answers it from disk and never
// reaches serveAsset()'s allow-list. That is a real deployment hazard, so it is
// covered at the server layer instead: assets/.htaccess refuses HTML outright.
// This asserts the defence exists, and reports (without failing) what this
// particular server did.
$htaccess = __DIR__ . '/../assets/.htaccess';
check('assets/.htaccess exists to cover statically-served /assets', is_file($htaccess));
if (is_file($htaccess)) {
    $ht = (string)file_get_contents($htaccess);
    check('it denies html/php in the assets tree',
        str_contains($ht, 'Require all denied') && str_contains($ht, 'html|htm|php'));
    check('it disables directory listing and execution',
        str_contains($ht, '-Indexes') && str_contains($ht, '-ExecCGI'));
    check('it declares the woff2 type (a nosniff browser refuses octet-stream fonts)',
        str_contains($ht, 'font/woff2'));
}
$tpl = req('/assets/templates/layout.html');
if ($tpl['status'] >= 400) {
    check('an unrendered template is refused', true);
} else {
    // This server answers /assets from disk, so the PHP allow-list never ran.
    // The defence is at the web-server layer; assert it is actually shipped
    // rather than letting a dev-server artifact pass as "secure".
    echo '  --  note: this server served /assets/templates/layout.html directly ('
        . $tpl['status'] . '); the shipped web-server rules below are what refuse it\n';
    $htSrc = (string)@file_get_contents(__DIR__ . '/../assets/.htaccess');
    $ngSrc = (string)@file_get_contents(__DIR__ . '/../nginx/security-hardening.include');
    check('Apache: assets/.htaccess denies html and php in the assets tree',
        str_contains($htSrc, 'Require all denied')
        && (bool)preg_match('/FilesMatch[^>]*html/', $htSrc));
    check('nginx: the hardening include denies /assets/templates/',
        str_contains($ngSrc, '^/assets/templates/') && str_contains($ngSrc, 'return 404'));
    check('nginx: the hardening include denies /i18n/ as a static path',
        str_contains($ngSrc, '^/(ui|tools|db|i18n|prompts|config|deploy|tests|docs)(/|$)'));
    check('nginx: the hardening include restricts PHP execution to the real entry points',
        str_contains($ngSrc, '^/(index\.php|bin/[^/]+\.php)$')
        && str_contains($ngSrc, 'location ~ \.php$ {'));
    check('i18n/.htaccess denies direct catalogue access',
        str_contains((string)@file_get_contents(__DIR__ . '/../i18n/.htaccess'), 'Require all denied'));
}
$i18nDirect = req('/i18n/en.json');
if ($i18nDirect['status'] === 200) {
    echo '  --  note: /i18n/en.json is reachable on this server; the shipped rules above deny it\n';
} else {
    check('the i18n catalogues are not reachable as static files', $i18nDirect['status'] >= 400,
        (string)$i18nDirect['status']);
}

// The i18n catalogues are admin-writable through the Localization panel, so
// they must not be fetchable as static files either.
$i18nHt = __DIR__ . '/../i18n/.htaccess';
check('i18n/.htaccess denies direct catalogue access', is_file($i18nHt)
    && str_contains((string)file_get_contents($i18nHt), 'Require all denied'));

// =====================================================================
section('the i18n API');
// =====================================================================

$i = req('/api/i18n/locales');
check('/api/i18n/locales answers 200', $i['status'] === 200, (string)$i['status']);
$localesBody = json_decode($i['body'], true);
check('it lists all five locales', is_array($localesBody) && count($localesBody['locales'] ?? []) === 5,
    (string)count($localesBody['locales'] ?? []));
check('it marks Arabic as rtl', is_array($localesBody) && (bool)array_filter(
    $localesBody['locales'] ?? [], static fn($l): bool => ($l['code'] ?? '') === 'ar' && ($l['rtl'] ?? false) === true));

foreach (['en', 'de', 'ru', 'zh', 'ar'] as $code) {
    $c = req('/api/i18n/' . $code);
    $body = json_decode($c['body'], true);
    check("/api/i18n/$code answers 200", $c['status'] === 200, (string)$c['status']);
    check("/api/i18n/$code carries a full catalogue",
        is_array($body) && count($body['messages'] ?? []) > 500,
        (string)count($body['messages'] ?? []));
    check("/api/i18n/$code reports its own direction",
        ($body['dir'] ?? '') === ($code === 'ar' ? 'rtl' : 'ltr'), (string)($body['dir'] ?? ''));
    check("/api/i18n/$code declares its plural categories",
        is_array($body['plurals'] ?? null) && in_array('other', $body['plurals'] ?? [], true));
}
$ar = json_decode(req('/api/i18n/ar')['body'], true);
check('Arabic declares all six CLDR categories',
    ($ar['plurals'] ?? []) === ['zero', 'one', 'two', 'few', 'many', 'other'],
    json_encode($ar['plurals'] ?? []));
$ru = json_decode(req('/api/i18n/ru')['body'], true);
check('Russian declares one/few/many/other',
    ($ru['plurals'] ?? []) === ['one', 'few', 'many', 'other'], json_encode($ru['plurals'] ?? []));

// An unknown locale must 404 with the supported list, not 500 and not a guess.
$bad = req('/api/i18n/klingon');
check('an unsupported locale 404s', $bad['status'] === 404, (string)$bad['status']);
$badBody = json_decode($bad['body'], true);
check('and names the supported locales', is_array($badBody['supported'] ?? null) && count($badBody['supported']) === 5);

// Traversal through the locale segment. The route parameter is resolved by
// Locale::fromTag(), a closed enum, so there is no path to construct.
foreach (['..%2f..%2fconfig', 'en.json', '../../etc/passwd', 'en%00'] as $evil) {
    $res = req('/api/i18n/' . $evil);
    check("locale traversal '$evil' is refused", $res['status'] === 404, (string)$res['status']);
}

// Negotiation is observable end to end.
$neg = req('/', 'GET', null, ['Accept-Language: de-DE,de;q=0.9,en;q=0.8']);
check('Accept-Language: de renders lang="de"', (bool)preg_match('/lang="de"/', $neg['body']));
$negAr = req('/', 'GET', null, ['Accept-Language: ar-EG,ar;q=0.9']);
check('Accept-Language: ar renders dir="rtl"',
    (bool)preg_match('/lang="ar"/', $negAr['body']) && (bool)preg_match('/dir="rtl"/', $negAr['body']));
$negQ = req('/', 'GET', null, ['Accept-Language: ja,zh;q=0.9,en;q=0.8']);
check('a q-ordered header picks the best shipped language', (bool)preg_match('/lang="zh"/', $negQ['body']));
$explicit = req('/?lang=ru');
check('?lang= beats Accept-Language', (bool)preg_match('/lang="ru"/', $explicit['body']));

// The catalogue endpoints are public by design (UI labels only); the coverage
// and editor endpoints must not be.
$coverage = req('/api/i18n/coverage');
check('catalogue coverage is admin-gated', $coverage['status'] === 403, (string)$coverage['status']);
// The write probe is wrapped in a before/after hash of the real catalogue.
// This test once ran against a build whose shipped config had the admin gate
// OFF, and the probe below SUCCEEDED — writing {"a":{"b":"x"}} into the tracked
// i18n/en.json. Asserting the 403 alone does not catch that, because on an open
// gate the status is 200 and the check fails loudly; what it does NOT catch is
// the file having been modified on the way. Hashing around the probe makes a
// mutation a test failure even if some future gate returns a status this test
// happens to accept.
$catalogPath = __DIR__ . '/../i18n/en.json';
$beforeHash = is_file($catalogPath) ? (string)hash_file('sha256', $catalogPath) : '';
$setKey = req('/api/i18n/en/key', 'POST', json_encode(['key' => 'smoke.probe', 'value' => 'x']),
    ['Content-Type: application/json']);
check('editing a translation is admin-gated', $setKey['status'] === 403, (string)$setKey['status']);
$afterHash = is_file($catalogPath) ? (string)hash_file('sha256', $catalogPath) : '';
check('and the probe did not modify the tracked catalogue',
    $beforeHash === $afterHash && $beforeHash !== '',
    'i18n/en.json changed during the run — the admin gate is open on this build');
$reload = req('/api/i18n/reload', 'POST');
check('reloading catalogues is admin-gated', $reload['status'] === 403, (string)$reload['status']);

// =====================================================================
section('the fallback and audit APIs are gated');
// =====================================================================

$gated = [
    ['GET', '/api/models/fallback/policy'],
    ['POST', '/api/models/fallback/policy'],
    ['GET', '/api/models/availability'],
    ['POST', '/api/models/availability/probe'],
    ['POST', '/api/models/availability/reset'],
    ['POST', '/api/models/availability/retire'],
    ['GET', '/api/models/alternatives?model=a/b'],
    ['POST', '/api/models/alternatives/plan'],
    ['GET', '/api/models/fallback/rules'],
    ['POST', '/api/models/fallback/rules'],
    ['DELETE', '/api/models/fallback/rules/1'],
    ['GET', '/api/audit'],
    ['GET', '/api/audit/1'],
    ['GET', '/api/audit/verify'],
    ['GET', '/api/audit/export'],
];
foreach ($gated as [$method, $path]) {
    $res = req($path, $method);
    check("$method $path requires the admin gate",
        $res['status'] === 403, 'status ' . $res['status']);
    $body = json_decode($res['body'], true);
    check("$method $path says why (admin_required)",
        ($body['error'] ?? '') === 'admin_required', (string)($body['error'] ?? ''));
}

// The one deliberately public fallback endpoint must leak nothing sensitive.
$summary = req('/api/models/availability/summary');
check('the availability summary is public (the Chat tab needs it pre-unlock)',
    $summary['status'] === 200, (string)$summary['status']);
$summaryBody = json_decode($summary['body'], true);
check('and it carries no pricing, no error text and no key material',
    is_array($summaryBody)
    && !str_contains($summary['body'], 'pricing')
    && !str_contains($summary['body'], 'last_error')
    && !preg_match('/sk-[A-Za-z0-9]/', $summary['body']));

// =====================================================================
section('CSRF: a cross-site state change is refused');
// =====================================================================

$csrf = req('/api/i18n/reload', 'POST', null, ['Origin: https://evil.example']);
check('a POST with a foreign Origin is refused', $csrf['status'] === 403, (string)$csrf['status']);
$csrfBody = json_decode($csrf['body'], true);
check('and it names the reason', ($csrfBody['error'] ?? '') === 'forbidden_origin', (string)($csrfBody['error'] ?? ''));
$csrf2 = req('/api/models/fallback/policy', 'POST', '{}', ['Origin: https://evil.example', 'Content-Type: application/json']);
check('the fallback policy write is refused cross-site too', $csrf2['status'] === 403, (string)$csrf2['status']);
$get = req('/api/i18n/locales', 'GET', null, ['Origin: https://evil.example']);
check('a GET with a foreign Origin is still allowed (links and bookmarks must work)',
    $get['status'] === 200, (string)$get['status']);

// =====================================================================
section('health and diagnostics');
// =====================================================================

$health = req('/api/health');
check('/api/health answers 200', $health['status'] === 200, (string)$health['status']);
check('/api/health leaks no secret', !preg_match('/sk-[A-Za-z0-9]{8}/', $health['body'])
    && !str_contains($health['body'], 'DB_PASS'));

$missing = req('/api/definitely-not-a-route');
check('an unknown API route returns JSON, not an HTML error page',
    $missing['status'] === 404 && str_starts_with($missing['headers']['content-type'] ?? '', 'application/json'),
    ($missing['headers']['content-type'] ?? '') . ' / ' . substr($missing['body'], 0, 40));

echo "\n=========================\n";
echo "Passes: {$passes}  Failures: {$failures}\n";
exit($failures === 0 ? 0 : 1);
