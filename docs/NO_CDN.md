# No CDN

RevoAp fetches **nothing** from any third-party origin at runtime. Every
stylesheet, script, font and diagram engine is served from the same origin as the
application.

This is enforced in four places, because a rule that only exists in a document is
a rule that gets broken by the next convenient patch:

| Layer | What it does |
|---|---|
| `src/Http/Response.php` | `script-src`/`style-src`/`font-src`/`connect-src` are `'self'` (+ a per-request nonce). An external `<script>` is **blocked by the browser**, not merely discouraged. |
| `tools/build-css.mjs` | The build fails if the compiled stylesheet contains an unresolved `@import`, an unprocessed `@tailwind` directive, or any of `cdn.jsdelivr`, `unpkg.com`, `cdnjs`, `fonts.googleapis`, `fonts.gstatic`. |
| `.github/workflows/ci.yml` | Two jobs grep the tree and the compiled CSS for a remote `src=`/`href=`/`url()`/`@import`. |
| `tests/LiveSmoke.php` | Asserts against **real response bytes**: no remote asset in the document, KaTeX and Mermaid resolve to `/assets/vendor/`, and the CSP header contains no `https?://` at all. |

---

## What changed in v2.0.0

| Resource | v1.x | v2.0.0 |
|---|---|---|
| KaTeX runtime + CSS + `auto-render` | `cdn.jsdelivr.net` with SRI | `/assets/vendor/katex/` (604 KB) |
| KaTeX math fonts | jsDelivr | `/assets/vendor/katex/fonts/` (20 woff2) |
| Mermaid | `cdn.jsdelivr.net`, ESM (146 chunks) | `/assets/vendor/mermaid/mermaid.min.js`, UMD (2.7 MB, loaded **on demand**) |
| UI face | system stack | `/assets/fonts/` — Inter Variable (latin, latin-ext, cyrillic + italic) |
| Mono face | system stack | JetBrains Mono Variable (latin, cyrillic) |
| Arabic face | *(nothing — system stack)* | Noto Sans Arabic Variable (arabic subset) |
| CSP | `script-src 'self' cdn.jsdelivr.net` … | `'self'` + nonce only |

Total vendored: **~3.8 MB** of committed binaries. That is a real cost, and it is
the right one — see below.

### Why SRI was not enough

Subresource Integrity proves the bytes were not altered in transit. It does
nothing about:

* **Availability.** An air-gapped or intranet deployment — which is most of the
  interesting deployments for a tool that sends prompts to LLMs — had no math
  rendering and no diagrams at all.
* **Upstream compromise.** The SRI hashes live in *your* HTML. A compromised CDN
  account that publishes a new asset and a new hash is accepted, because nothing
  on your side pins the version.
* **Privacy.** Every page load told jsDelivr the operator's IP, User-Agent,
  referer and reading pattern. For a console whose whole purpose is deliberating
  over confidential prompts, that is an odd thing to leak.
* **The CSP concession.** Allowing an external origin in `script-src` is the one
  weakening that matters most. It was there for a font and a diagram engine.

---

## Chinese is not shipped as a webfont

A CJK subset of a variable Inter-class face is **5–15 MB** — more than the rest of
the front end combined. Every device that can render Simplified Chinese already
has a good system face. So `--font-sans` names the platform fonts explicitly
instead:

```
PingFang SC (Apple) · Hiragino Sans GB · Microsoft YaHei (Windows)
Noto Sans CJK SC (Linux/Android)
```

Arabic **is** shipped, because coverage in system stacks is far less reliable and
shaping quality varies enormously between faces — a bad Arabic face is not merely
ugly, it is hard to read.

---

## Mermaid: UMD, not ESM, and only on demand

The v1 loader used the ESM build. That is 146 chunk files totalling 13 MB, which
is a poor fit for a vendored dependency and an even poorer fit for an on-demand
load. The UMD build is one 2.7 MB file that publishes `window.mermaid`.

It is loaded by `injectClassicScript()` only when an answer actually contains a
```mermaid fence. A 2.7 MB diagram engine must not sit on the critical path of a
page whose answers contain no diagrams — which is most pages.

A dynamic `import()` of a UMD file throws in a strict ESM context, so the loader
injects a classic `<script>` and resolves on `load`. Its `error` path **rejects
with a reason** rather than leaving the caller awaiting forever, which is what
turned a CDN outage into a permanently hung "rendering…" indicator in v1.x.

`securityLevel: 'strict'` and `startOnLoad: false` are unchanged: model output is
untrusted, so only the SVG Mermaid's strict parser produces is ever inserted.

---

## Fonts: subsetted, variable, `font-display: swap`

* All three faces are **variable** (`wght 100..900`), so one file per subset covers
  every weight the UI uses. That is ~180 KB instead of ~700 KB for the equivalent
  static set, and it makes `font-weight: 550` legal.
* `unicode-range` on each `@font-face` means an English-only operator downloads
  ~46 KB, not ~180 KB.
* `font-display: swap` is deliberate. This is an operations tool: text must be
  readable immediately, and a moment of fallback rendering beats invisible text on
  a slow link.

Regenerate with `node tools/vendor-assets.mjs`, which copies from the versions
pinned in `package.json`.

---

## Building

The compiled stylesheet is a **committed artefact**, so a deployment needs no
Node, no `npm install` and no network:

```bash
npm install                    # only to change the styles
node tools/build-css.mjs       # -> assets/dist/revoap.css (minified)
node tools/build-css.mjs --dev # -> also assets/dist/revoap.dev.css (readable)
node tools/build-css.mjs --check   # CI: is the committed file up to date?
```

`--check` rebuilds into a temp file and diffs it against the committed one. A
stale artefact is a silent, confusing bug: the operator edits a token, sees no
change, and concludes the token is unused.

---

## Operator: widening the policy

If your deployment genuinely serves an asset from another origin (a reverse proxy
on a different hostname, an internal mirror), `security.csp.extra_sources` accepts
an explicit list:

```php
'security' => [
    'csp' => [
        'extra_sources' => ['https://static.intranet.example.com'],
    ],
],
```

Each entry is validated against `#^https?://[a-z0-9.\-]+(?::\d+)?$#i` before it
reaches the header, so it cannot introduce a wildcard, a `data:` scheme or a
header injection. There is no way to configure `*`.
