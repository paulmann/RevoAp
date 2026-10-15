<?php
/**
 * I18nController: the locale API.
 *
 * Endpoints
 *   GET  /api/i18n                the active locale + the shipped list
 *   GET  /api/i18n/locales        the picker payload (native names, dir, plurals)
 *   GET  /api/i18n/{locale}       the full flat catalog for one locale
 *   GET  /api/i18n/{locale}/coverage   per-locale completeness, admin only
 *   POST /api/i18n/{locale}/key   admin: set a single translation
 *   POST /api/i18n/reload         admin: drop the in-process memo
 *
 * Design notes
 *
 *  - The catalog endpoint is PUBLIC and requires no admin token. It contains
 *    UI labels only — no configuration, no secrets, no user data. Making it
 *    authenticated would force the SPA to fetch it after the unlock
 *    round-trip, which is exactly the flash-of-English this design avoids.
 *    The first paint gets the catalog inlined in the document instead; this
 *    endpoint exists for switching language without a reload.
 *
 *  - Every response is `no-store`. A shared proxy caching `/api/i18n/ru` and
 *    serving it for `/api/i18n/de` (both 200, both JSON) is a real failure
 *    mode when the cache key omits the path, and these payloads are small
 *    enough that caching buys nothing.
 *
 *  - `{locale}` is resolved through Locale::fromTag(), which returns null for
 *    anything not shipped, so the route cannot be used to read arbitrary
 *    files. MessageCatalog::path() only ever joins a fixed directory with
 *    `<enum value>.json`; there is no user-controlled path component.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\I18n\Locale;
use App\I18n\LocaleNegotiator;
use App\I18n\MessageCatalog;
use App\I18n\PluralRules;
use App\I18n\Translator;
use App\Http\AdminGate;
use App\Http\Response;

final class I18nController
{
    /** Maximum accepted length for a single admin-supplied translation. */
    private const MAX_VALUE_LENGTH = 4000;

    /**
     * GET /api/i18n — what the SPA needs on boot when it did not get the
     * inline catalog (a cached document, or a hard reload after a deploy).
     */
    public function index(): string
    {
        $negotiated = $this->negotiate();
        $locale = $negotiated['locale'];

        return Response::json([
            'ok'      => true,
            'locale'  => $locale->value,
            'dir'     => $locale->dir(),
            'rtl'     => $locale->isRtl(),
            'source'  => $negotiated['source'],
            // Sent so the SPA can tell "the server chose this" from "I chose
            // this", which matters for the "detected from your browser" hint.
            'remember' => $negotiated['remember'],
            'locales' => LocaleNegotiator::describeAll($locale),
            'messages' => Translator::export($locale),
            'plural_rules' => PluralRules::categoriesFor($locale),
        ]);
    }

    /** GET /api/i18n/locales — the language picker payload, without messages. */
    public function locales(): string
    {
        $locale = $this->negotiate()['locale'];
        return Response::json(LocaleNegotiator::describeAll($locale) + ['ok' => true]);
    }

    /**
     * GET /api/i18n/{locale}
     *
     * @param array<string,string> $params
     */
    public function catalog(array $params): string
    {
        $locale = Locale::fromTag($params['locale'] ?? '');
        if ($locale === null) {
            return Response::json([
                'ok'      => false,
                'error'   => 'unsupported_locale',
                // Listing the shipped locales turns a 404 into something the
                // caller can act on without reading the docs.
                'supported' => array_map(static fn(Locale $l): string => $l->value, Locale::all()),
            ], 404);
        }

        $catalog = MessageCatalog::load($locale);
        if (!$catalog->ok) {
            // A broken catalog is a deployment problem, not a client error.
            // Report it as 500 with the reason, and keep English available.
            return Response::json([
                'ok'      => false,
                'error'   => 'catalog_unavailable',
                'locale'  => $locale->value,
                'reason'  => $catalog->problem,
                'fallback' => Locale::English->value,
            ], 500);
        }

        // Merge the fallback chain so the client always gets a COMPLETE map.
        // A half-translated catalog must not produce half-translated UI.
        return Response::json([
            'ok'       => true,
            'locale'   => $locale->value,
            'dir'      => $locale->dir(),
            'rtl'      => $locale->isRtl(),
            'plurals'  => PluralRules::categoriesFor($locale),
            'count'    => $catalog->count(),
            'messages' => Translator::export($locale),
        ]);
    }

    /**
     * GET /api/i18n/{locale}/coverage — admin only.
     *
     * @param array<string,string> $params
     */
    public function coverage(array $params): string
    {
        if ($denied = AdminGate::deny('i18n')) {
            return $denied;
        }

        $reference = MessageCatalog::load(Locale::English);
        $rows = [];
        foreach (Locale::all() as $locale) {
            $catalog = MessageCatalog::load($locale);
            $diff = $catalog->diffAgainst($reference);
            $rows[] = [
                'code'         => $locale->value,
                'native_name'  => $locale->nativeName(),
                'english_name' => $locale->englishName(),
                'dir'          => $locale->dir(),
                'ok'           => $catalog->ok,
                'problem'      => $catalog->problem,
                'keys'         => $catalog->count(),
                'reference_keys' => $reference->count(),
                'coverage'     => $reference->count() === 0
                    ? 1.0
                    : min(1.0, ($reference->count() - count($diff['missing'])) / $reference->count()),
                'missing'      => $diff['missing'],
                'extra'        => $diff['extra'],
                'empty'        => $diff['non_string'],
                'plurals'      => PluralRules::categoriesFor($locale),
            ];
        }

        return Response::json([
            'ok'         => true,
            'directory'  => basename(MessageCatalog::directory()),
            'reference'  => Locale::English->value,
            'locales'    => $rows,
            'problems'   => MessageCatalog::problems(),
            'translator' => Translator::stats(),
        ]);
    }

    /**
     * POST /api/i18n/{locale}/key — admin: edit one translation in place.
     *
     * @param array<string,string> $params
     */
    public function setKey(array $params): string
    {
        if ($denied = AdminGate::deny('i18n')) {
            return $denied;
        }
        $locale = Locale::fromTag($params['locale'] ?? '');
        if ($locale === null) {
            return Response::json(['ok' => false, 'error' => 'unsupported_locale'], 404);
        }

        $body = $this->jsonBody();
        $key   = trim((string)($body['key'] ?? ''));
        $value = (string)($body['value'] ?? '');

        if ($key === '' || !preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_\-]+)+$/', $key)) {
            return Response::json([
                'ok'    => false,
                'error' => 'invalid_key',
                'hint'  => 'Keys are dotted identifiers: settings.api_key_saved',
            ], 400);
        }
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return Response::json(['ok' => false, 'error' => 'value_too_long', 'max' => self::MAX_VALUE_LENGTH], 413);
        }
        if ($value === '') {
            return Response::json(['ok' => false, 'error' => 'empty_value'], 400);
        }

        $path = MessageCatalog::path($locale);
        if (!is_file($path) || !is_writable($path)) {
            return Response::json([
                'ok'    => false,
                'error' => 'catalog_not_writable',
                'file'  => basename($path),
                'hint'  => 'The catalog is read-only. Edit it in the repository, or chmod the i18n/ directory.',
            ], 409);
        }

        $written = $this->writeKey($path, $key, $value);
        if (!$written) {
            return Response::json(['ok' => false, 'error' => 'write_failed'], 500);
        }

        MessageCatalog::flush($locale);

        return Response::json([
            'ok'     => true,
            'locale' => $locale->value,
            'key'    => $key,
            'value'  => $value,
        ]);
    }

    /** POST /api/i18n/reload — drop the in-process catalog memo. */
    public function reload(): string
    {
        if ($denied = AdminGate::deny('i18n')) {
            return $denied;
        }
        MessageCatalog::flush();
        return Response::json(['ok' => true, 'reloaded' => count(Locale::all())]);
    }

    /* ------------------------------------------------------------------
     * Internals
     * ---------------------------------------------------------------- */

    /** @return array{locale:Locale,source:string,remember:bool,considered:array<int,string>} */
    private function negotiate(): array
    {
        $negotiator = new LocaleNegotiator($_SERVER, $_GET, $_COOKIE);
        $result = $negotiator->negotiate();
        // Persist only an explicit `?lang=` switch, never a header guess.
        if ($result['remember']) {
            $this->persistCookie($result['locale']);
        }
        return $result;
    }

    private function persistCookie(Locale $locale): void
    {
        if (headers_sent()) {
            return;
        }
        $secure = Response::tlsEnforced();
        $opts = LocaleNegotiator::cookieOptions($locale, $secure);
        setcookie(LocaleNegotiator::COOKIE_NAME, (string)$opts['value'], [
            'expires'  => (int)$opts['expires'],
            'path'     => (string)$opts['path'],
            'secure'   => (bool)$opts['secure'],
            'httponly' => (bool)$opts['httponly'],
            'samesite' => (string)$opts['samesite'],
        ]);
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        // 64 KiB is far more than a single translation needs; the cap keeps a
        // large body from being decoded into memory before it is rejected.
        if (strlen($raw) > 65_536) {
            return [];
        }
        $decoded = json_decode($raw, true, 8);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Insert or replace one dotted key inside a nested JSON catalog.
     *
     * Atomic: the new document is written to a sibling temp file and renamed
     * over the original, so a crash mid-write cannot leave a truncated
     * catalog — which would take the whole locale offline on the next read.
     *
     * @return bool true when the file now contains the key
     */
    private function writeKey(string $path, string $key, string $value): bool
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return false;
        }
        $doc = json_decode($raw, true, 16);
        if (!is_array($doc)) {
            return false;
        }

        $segments = explode('.', $key);
        $node = &$doc;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                // A scalar where a branch is expected (or the reverse) means
                // the key was restructured; overwrite rather than fail, since
                // the caller is an administrator who can see the result.
                $node[$segment] = $value;
                break;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        unset($node);

        $encoded = json_encode(
            $doc,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if (!is_string($encoded)) {
            return false;
        }
        $encoded .= "\n";

        // 0644: readable by the PHP process and by the operator, writable only
        // by the owner. Never 0666 — the catalog is inside the document root.
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
