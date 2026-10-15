# UI guide — Tailwind + shadcn/ui design system

The v2 console is styled by a **Tailwind CSS** build and a **shadcn/ui-compatible
component layer**. shadcn/ui is a React registry, and this is a vanilla ES-module
SPA, so what was ported is its *contract* rather than its code: the same token
names, the same class names, the same `data-slot` / `data-state` / `data-variant`
attributes and the same ARIA wiring. Swapping `assets/js/ui/` for the React
registry would change the implementation and not the markup.

| | |
|---|---|
| Tokens | `ui/tokens.css` — the single source of truth |
| Components | `ui/tailwind.css` (`@layer components`), `ui/shell.css` (layout) |
| Config | `ui/tailwind.config.cjs` |
| Build | `node tools/build-css.mjs` → `assets/dist/revoap.css` (**committed**) |
| JS primitives | `assets/js/ui/dom.js`, `ui/icons.js`, `ui/components.js` |
| Shell | `assets/js/shell.js` |
| Appearance | `assets/js/theme.js` + the inline `assets/js/theme-boot.js` |

Compiled output: **93 KB raw / 16.6 KB gzipped**, one file, no CDN.

---

## 1. Tokens

Everything downstream — Tailwind utilities, the component layer, the vanilla JS
primitives and the legacy `oc-*` stylesheets — reads `ui/tokens.css` and nothing
else.

Four rules make the system hold together:

1. **Colour space is OKLCH.** It is perceptually uniform, so a token derived by
   changing only `L` keeps its hue and chroma. That is what makes the derived
   tints (`color-mix(in oklch, …)`) predictable across light and dark *without a
   second hand-tuned value*.
2. **`:root` is light; `.dark` and `[data-theme="dark"]` override it.** Both
   selectors exist because Tailwind's `darkMode: ['class', '.dark']` uses the class
   and the pre-v2 stylesheets use the attribute. `theme.js` writes both, so the two
   layers cannot disagree.
3. **Every token is declared in both themes with an identical name set**, enforced
   by `tests/CssTokensTest.php`. A component must never need to know which theme is
   active.
4. **No token references another token across themes.** Derivations use
   `color-mix()` inside the same block, so a rebrand is a one-line change.

Token groups: surfaces, brand, destructive/status (with `-soft` plaque and `-edge`
border derived from the base hue), lines and controls, sidebar, eight chart hues,
code and syntax, elevation (three steps — a fourth is indistinguishable on a light
page), typography, layout, motion, and a documented z-index ladder so two
components can never fight.

### Three independent axes

| Axis | Values | Set by |
|---|---|---|
| `data-color-mode` | `light` · `dark` · `system` | `Theme.setMode()` |
| `data-accent` | `indigo` · `violet` · `blue` · `cyan` · `emerald` · `amber` · `rose` · `slate` | `Theme.setAccent()` |
| `data-density` | `cozy` · `comfortable` · `compact` | `Theme.setDensity()` |

**`system` is a first-class value, not "unset".** That distinction is the whole
point of `theme.js`: a user who explicitly picks *System* and a user who has never
been asked must behave differently. Both follow the OS live, but the first choice
is persisted the moment they toggle, so the operator default can never overwrite
it again.

An **accent preset** changes only `--primary*` and `--ring`; every derived tint
follows through `color-mix()`. An operator can rebrand the whole console without
touching a component. Values are tuned per theme so `primary-foreground` on
`primary` holds AA in both.

**Density** halves the vertical rhythm. Data-heavy admin tables are unreadable at
comfortable density on a laptop; `compact` fits roughly 30 % more rows.

### Accessibility

* `@media (forced-colors: active)` switches every token to the OS system colours,
  so Windows High Contrast works instead of rendering an invisible UI.
* `@media (prefers-reduced-motion: reduce)` collapses every `--duration-*` to
  ~0 ms globally, so nothing slides, spins or fades. The spinner keeps a single-step
  opacity pulse, because a frozen spinner reads as a hung UI.
* `@media print` forces light, drops shadows and chrome, and honours
  `[data-print-hide]`.
* Accent swatches read a per-preset foreground token. White on amber measures
  2.9:1 and on cyan 3.9:1; WCAG 1.4.11 requires 3:1 for a non-text graphic, so
  those two get dark ink.
* Focus is a `box-shadow` ring, not an `outline`, so it survives `overflow: hidden`
  on pill buttons and does not shift layout.

---

## 2. Layout: three breakpoints, full width everywhere

```
< 768px    header (sticky) + main (scrolls) + bottom nav (fixed)
≥ 768px    header + [sidebar | main], sidebar sticky and independently scrollable
≥ 1536px   identical — no max-width at any breakpoint
```

**The shell has no `max-inline-size` anywhere.** An operations console that
centres 1200 px on a 3440 px display wastes two thirds of the screen the operator
bought. The one place a measure is applied is `.prose-measure` (78 ch), which
long-form answer text opts into — 78 ch is the readable line length for Latin and
Cyrillic prose, while a 40-column admin table across the same width is exactly
what the monitor is for. Padding is fluid: 0.75 rem on a phone, 1.5 rem on a
desktop, 2.5 rem on an ultrawide.

Mobile-first specifics:

* **Bottom navigation** with five thumb-reachable destinations plus an
  "everything else" slot that opens a drawer. `env(safe-area-inset-bottom)` is not
  cosmetic — without it the last row of a table is unreachable behind an iPhone's
  home indicator.
* **Every sheet becomes a bottom sheet under 768 px.** A 24 rem side drawer on a
  360 px phone leaves 40 px of usable width.
* `100dvh` with a `--vvh` fallback (written by `theme-boot.js`). `100vh` on mobile
  includes the URL bar, so a shell sized to it overflows and pushes the bottom nav
  off-screen.
* `overflow-wrap: anywhere` on `body`, because a single
  `anthropic/claude-opus-4.5-20260101` can otherwise force horizontal scroll on a
  320 px viewport.

### RTL

`dir="rtl"` on `<html>` mirrors the **entire shell**, because every directional
rule uses logical properties (`inset-inline-start`, `padding-inline`,
`border-inline-end`, `margin-inline`). There is no `[dir="rtl"]` override
stylesheet and no second copy of any layout rule. The few things that genuinely
need flipping are handled explicitly:

* `.flip-rtl` for directional icons;
* `.switch:checked::after` — `translateX` is not logical, so RTL negates it;
* `.num`, `time`, `.tabular` get `unicode-bidi: isolate; direction: ltr`, so
  "1 234 токенов" cannot render as "токенов 234 1";
* tab arrow-key navigation maps `ArrowRight` to *previous* in RTL, because the tabs
  are laid out right-to-left.

---

## 3. Progressive enhancement

The header, the tab registry, the sidebar mount points and the
"admin surface is unprotected" disclosure are **rendered by PHP**, not built by
JavaScript. `assets/js/shell.js` *enhances* them.

This is load-bearing for three reasons:

1. **No JS.** The panels are still reachable through the server-rendered
   `[data-tab]` triggers, and the unprotected-admin disclosure — the one message
   that must never depend on a script running — is visible.
2. **First paint.** A JS-built header means the page renders empty and then grows a
   header, shifting every panel down by 56 px after load.
3. **One tab contract.** Every pre-v2 panel module binds to a `[data-tab="…"]`
   trigger and shows the matching `[data-tab-panel]`. The shell *adopts* those
   triggers instead of inventing parallel ones, so there is exactly one set of ids
   and one active-state mechanism. `#model-selector` and `#connector-list` keep the
   homes their modules expect.

The registry is visually hidden (not `display: none`) after the shell mounts, so
the triggers stay clickable — the tab machinery dispatches `.click()` on them.

---

## 4. Component layer

`assets/js/ui/components.js` exports factories that return real DOM nodes:

`Button` `Card` `Badge` `Alert` `Stat` `Field` `Input` `Textarea` `Select`
`Switch` `Checkbox` `Combobox` `Dialog` `Confirm` `Sheet` `DropdownMenu`
`attachTooltip` `toast`/`Toast` `Tabs` `Accordion` `Progress` `Skeleton`
`Separator` `EmptyState` `Kbd`

### No `innerHTML` with interpolated values

This is the single most important property of a UI kit that renders LLM output,
user-entered text and operator-editable translations from the same code path.
`ui/dom.js` builds everything with `createElement` + property assignment; strings
become text nodes. The one `html` option is an explicit, greppable escape hatch for
caller-supplied static markup — **no shipped call site uses it**.

### The overlay stack

Dialog, Sheet, Dropdown, Tooltip and the command palette all render into one
portal (`#revoap-overlay-root`) and register on a single stack. That one mechanism
is what makes four things work which would otherwise be four separate bugs:

* Escape closes only the **topmost** layer;
* the scroll lock is **reference-counted**, so closing an inner dialog does not
  unblock a page still behind an outer one;
* `inert` is applied to the rest of the document, which is what actually removes
  the background from the accessibility tree (`aria-hidden` alone does not, and a
  screen-reader user could otherwise tab into a page that is visually gone);
* focus returns to the control that opened the layer.

### Accessibility contracts that are easy to skip

* **Combobox** implements the ARIA 1.2 pattern: the input owns
  `aria-expanded`/`aria-controls`/`aria-activedescendant`, options have stable
  ids, and Arrow/Home/End/Enter/Escape/Tab all behave as specified.
* **Select is a native `<select>`** on purpose. It gives correct keyboard
  handling, form submission, autofill, mobile wheel pickers and screen-reader
  semantics for free. `Combobox` exists only for the case a native select cannot
  serve — a filterable list of 300 model ids.
* **Switch** is `<input type="checkbox" role="switch">`, so a screen reader says
  "on/off" rather than "checked", matching the visual vocabulary. Clicking the row
  toggles it, because a 40×22 px target is below the 44 px touch minimum.
* **Tabs** use roving tabindex and automatic activation; **Accordion** wires
  `aria-expanded`/`aria-controls`/`role="region"`.
* **Toasts** live in a `role="region"` viewport, not an `aria-live` region. An
  aria-live region announces every mutation, and a console streaming pipeline
  events would produce a continuous spoken commentary. Errors additionally get
  `role="alert"` on the toast itself, which does interrupt.
* **A toast's timer pauses on hover and on focus**, so a user reading it does not
  have it disappear mid-sentence.

---

## 5. Command palette

`Ctrl+K` / `⌘K`. Every navigation target, every theme/locale/density/accent
control and every primary action is a command.

This is not a convenience feature: with 21 destinations in the nav a palette is
faster than hunting, and it is the only way to reach them without a mouse — which
matters because the console is also the tool an operator uses *when the UI is
half-broken*.

`commandRegistry.register()` lets a panel add its own actions as it mounts, so
"Purge cache" is searchable without the shell knowing the Settings panel exists.
Scoring is substring-beats-subsequence with a spread penalty, and the `g`-prefix
goto (`g c` → Chat, `g a` → Audit, `g ?` → shortcuts) works alongside it.

---

## 6. Build

```bash
npm install
node tools/build-css.mjs           # -> assets/dist/revoap.css (minified, committed)
node tools/build-css.mjs --dev     # -> also revoap.dev.css (readable, gitignored)
node tools/build-css.mjs --check   # CI: is the committed file current?
```

The artefact is **committed** so a deployment needs no Node, no `npm install` and
no network. `--check` rebuilds into a temp file and diffs, because a stale
artefact is a silent bug: the operator edits a token, sees no change, and
concludes the token is unused.

The build fails on an unresolved `@import`, an unprocessed `@tailwind` directive,
or any external origin in the output. See [`NO_CDN.md`](NO_CDN.md).

Two authoring gotchas worth knowing:

* A `*/` inside a CSS comment terminates it. `/* Only --primary*/--ring change */`
  is a syntax error that reads like prose.
* Tailwind cannot generate a class it never sees literally. `w-${n}%` in JS
  produces nothing; set `el.style.width` instead (see `SkeletonBlock`).

---

## 7. Migrating the legacy panels

`assets/css/components/*.css` (~4 700 lines, `oc-*` classes) still styles the
pre-v2 panels. `ui/tokens.css` §5 aliases every `--color-*` name onto the new
tokens, so those stylesheets render correctly against the new palette with no
edits — that bridge is what makes the migration incremental rather than a
big-bang rewrite.

Panels are moved into the new shell (see `#revoap-legacy-panels` in
`layout.html`) rather than recreated, so their module state survives a language
switch. Migrate one at a time: replace the `oc-*` markup with `ui/components.js`
factories, then delete the corresponding CSS file once nothing references it.
