<?php
/**
 * LocaleNegotiator: decide which language a request is served in.
 *
 * Precedence is the one users expect, and the order matters — each step is
 * a stronger signal of *intent* than the one below it:
 *
 *   1. `?lang=de`      an explicit switch the user just clicked. Highest,
 *                      because it is the only signal that is unambiguous and
 *                      immediate; it also wins over the cookie so a shared
 *                      link ("look at this in Russian") works.
 *   2. `revoap_locale` cookie  the last choice this browser made. Survives
 *                      reloads and is readable by the inline theme/locale
 *                      bootstrap script, so the first paint is already in
 *                      the right language and the right direction.
 *   3. `X-Locale` / `Accept-Language` header  what the browser says the
 *                      human prefers. Only consulted when the user has
 *                      never chosen, i.e. exactly the "first visit" case the
 *                      requirement asks for ("автоопределение при первом
 *                      заходе").
 *   4. `ui.locale` in config.php / runtime.json  the operator's site default.
 *   5. English          the last resort; also the catalog fallback root.
 *
 * A separate `remember` flag tells the caller whether the resolved locale
 * came from an explicit user action (and should therefore be persisted as a
 * cookie) or from a passive signal (and must not be — persisting a header
 * guess would pin the user to a language they never asked for and make the
 * browser's own preference permanently inert).
 *
 * `Accept-Language` parsing implements RFC 9110 §12.5.4 including q-values,
 * wildcards and malformed entries, because that header is attacker-controlled
 * and a naive `explode` on it is both wrong and a mild DoS surface.
 *
 * @project  RevoAp
 * @version  2.0.0
 * @since    2.0.0
 */
declare(strict_types=1);

namespace App\I18n;

use App\Core\Config;

final class LocaleNegotiator
{
    public const COOKIE_NAME = 'revoap_locale';
    public const QUERY_PARAM = 'lang';
    public const HEADER_NAME = 'x-locale';

    /** Cookie lifetime: one year. Long enough to be "sticky", short enough
     *  that an abandoned browser does not carry a stale choice forever. */
    public const COOKIE_TTL = 31_536_000;

    /** Hard cap on the Accept-Language entries inspected. A client may send
     *  hundreds; only the first few can ever win. */
    private const MAX_HEADER_TAGS = 32;

    /**
     * @param array<string,mixed> $server  Normally `$_SERVER`.
     * @param array<string,mixed> $query   Normally `$_GET`.
     * @param array<string,mixed> $cookies Normally `$_COOKIE`.
     */
    public function __construct(
        private readonly array $server = [],
        private readonly array $query = [],
        private readonly array $cookies = [],
    ) {
    }

    /**
     * @return array{locale:Locale,source:string,remember:bool,considered:array<int,string>}
     */
    public function negotiate(): array
    {
        $considered = [];

        // 1. Explicit query switch.
        $q = $this->query[self::QUERY_PARAM] ?? null;
        if (is_string($q) && $q !== '') {
            $considered[] = 'query:' . $q;
            $loc = Locale::fromTag($q);
            if ($loc !== null) {
                return $this->result($loc, 'query', true, $considered);
            }
        }

        // 2. Cookie from a previous explicit choice.
        $c = $this->cookies[self::COOKIE_NAME] ?? null;
        if (is_string($c) && $c !== '') {
            $considered[] = 'cookie:' . $c;
            $loc = Locale::fromTag($c);
            if ($loc !== null) {
                return $this->result($loc, 'cookie', false, $considered);
            }
        }

        // 3. Explicit per-request header (API clients, the SPA's fetch layer).
        $h = $this->server['HTTP_X_LOCALE'] ?? $this->header(self::HEADER_NAME);
        if (is_string($h) && $h !== '') {
            $considered[] = 'header:' . $h;
            $loc = Locale::fromTag($h);
            if ($loc !== null) {
                return $this->result($loc, 'header', false, $considered);
            }
        }

        // 4. Browser preference — only on a genuinely first visit.
        $al = $this->server['HTTP_ACCEPT_LANGUAGE'] ?? null;
        if (is_string($al) && $al !== '') {
            foreach (self::parseAcceptLanguage($al) as $tag => $qValue) {
                $considered[] = 'accept-language:' . $tag . ';q=' . $qValue;
                if ($tag === '*') {
                    continue;
                }
                $loc = Locale::fromTag($tag);
                if ($loc !== null) {
                    return $this->result($loc, 'accept-language', false, $considered);
                }
            }
        }

        // 5. Operator default, then English.
        //
        // tryGet rather than get: this class is constructed by CLI tooling and
        // by unit tests that never boot an Application, and a locale
        // negotiation that fatals because an ambient singleton is unpopulated
        // is not a useful failure. "No configured default" and "no config at
        // all" both correctly fall through to English.
        $configured = Config::tryGet('ui.locale', Config::tryGet('app.locale', null));
        if (is_string($configured) && $configured !== '') {
            $considered[] = 'config:' . $configured;
            $loc = Locale::fromTag($configured);
            if ($loc !== null) {
                return $this->result($loc, 'config', false, $considered);
            }
        }

        $considered[] = 'default:en';
        return $this->result(Locale::English, 'default', false, $considered);
    }

    /**
     * The cookie attributes to persist an explicit choice.
     *
     * `SameSite=Lax` (not Strict): the console is linked from external
     * dashboards and a Strict cookie would be dropped on the inbound click,
     * resetting the language on every navigation from outside. `Secure` is
     * set whenever the request arrived over TLS. `HttpOnly` is deliberately
     * **false** — the inline bootstrap script in `layout.html` must read the
     * locale before any module executes, otherwise the first paint is in the
     * wrong language and then jumps. The value is a two-letter tag from a
     * closed enum, so exposing it carries no risk.
     *
     * @return array<string,mixed>
     */
    public static function cookieOptions(Locale $locale, bool $secure): array
    {
        return [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
            'value'    => $locale->value,
        ];
    }

    /**
     * Parse an `Accept-Language` header into `tag => q`, best first.
     *
     * Tolerates the malformed input real clients produce (empty entries,
     * missing q, q out of range, stray whitespace, duplicated tags). Ties are
     * broken by declaration order, which is what RFC 9110 recommends and what
     * makes `de,en;q=0.9,en-GB;q=0.9` resolve to `de`.
     *
     * @return array<string,float> ordered best-first
     */
    public static function parseAcceptLanguage(string $header): array
    {
        $out = [];
        $order = 0;
        foreach (explode(',', $header) as $part) {
            if (++$order > self::MAX_HEADER_TAGS) {
                break;
            }
            $segments = explode(';', trim($part));
            $tag = strtolower(trim((string)array_shift($segments)));
            // `*` is a valid language-range (RFC 9110 §12.5.4) and means "any
            // language"; the 1-8 / 1-8 subtag shape covers every real tag.
            if ($tag !== '*' && ($tag === '' || preg_match('/^[a-z]{1,8}(?:-[a-z0-9]{1,8})*$/i', $tag) !== 1)) {
                continue;
            }
            $q = 1.0;
            $sawQ = false;
            foreach ($segments as $seg) {
                if (preg_match('/^\s*q\s*=\s*(.*)$/i', $seg, $m) !== 1) {
                    continue;
                }
                $sawQ = true;
                // A qvalue is 0-1 with at most three decimals. Anything else —
                // `-1`, `abc`, `1.2.3` — is a malformed parameter, and the safe
                // reading is to DROP the tag rather than default it to 1.0.
                // Defaulting would let a client put an invalid value at the top
                // of its own preference list.
                if (preg_match('/^(0(\.\d{0,3})?|1(\.0{0,3})?)$/', trim($m[1])) !== 1) {
                    $q = 0.0;
                    break;
                }
                $q = (float)trim($m[1]);
                break;
            }
            if ($q <= 0.0) {
                continue; // explicitly rejected, or malformed enough to distrust
            }
            unset($sawQ);
            // Later duplicates must not outrank an earlier, higher-q entry.
            if (!isset($out[$tag])) {
                $out[$tag] = $q;
            }
        }
        // Stable sort by q descending; PHP's sort is stable since 8.0.
        arsort($out);
        return $out;
    }

    /**
     * Every shipped locale as the `/api/i18n/locales` payload.
     *
     * @return array{active:string,dir:string,rtl:bool,locales:array<int,array<string,mixed>>,persisted_in:string,query_param:string}
     */
    public static function describeAll(Locale $active): array
    {
        $list = [];
        foreach (Locale::all() as $loc) {
            $row = $loc->describe();
            $row['active'] = $loc === $active;
            $list[] = $row;
        }
        return [
            'active'   => $active->value,
            'dir'      => $active->dir(),
            'rtl'      => $active->isRtl(),
            'locales'  => $list,
            'persisted_in' => self::COOKIE_NAME,
            'query_param'  => self::QUERY_PARAM,
        ];
    }

    private function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * @param array<int,string> $considered
     * @return array{locale:Locale,source:string,remember:bool,considered:array<int,string>}
     */
    private function result(Locale $locale, string $source, bool $remember, array $considered): array
    {
        Translator::setLocale($locale);
        return [
            'locale'     => $locale,
            'source'     => $source,
            'remember'   => $remember,
            'considered' => $considered,
        ];
    }
}
