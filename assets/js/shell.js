/**
 * shell.js — the responsive application chrome.
 *
 * One shell, three layouts, chosen by viewport and by an explicit user choice:
 *
 *   < 768px   header (compact) + bottom navigation bar + a drawer for the
 *             long tail of destinations. The bottom bar is thumb-reachable and
 *             respects `env(safe-area-inset-bottom)`; the drawer holds the
 *             admin sections, which are too many for five slots.
 *   ≥ 768px   header + persistent left sidebar. The sidebar collapses to an
 *             icon rail (3.25rem) and the choice is remembered.
 *   ≥ 1536px  same, with the content column allowed to use the full width.
 *             The shell has NO max-width anywhere: an operations console that
 *             centres 1200px of a 3440px display wastes two thirds of it.
 *             Only long-form prose opts into `--measure` via `.prose-measure`.
 *
 * Why the sidebar state lives in localStorage AND a cookie
 * -------------------------------------------------------
 * localStorage is what the JS reads; the cookie is what PHP reads to render
 * `<html data-sidebar="collapsed">` on the first byte. Without the cookie the
 * sidebar paints expanded and then snaps shut on every load — visible as a
 * layout shift, and on a slow device as a flash of the wrong chrome.
 *
 * Command palette (Ctrl/Cmd+K)
 * ----------------------------
 * Every navigation target, every theme/locale control and every primary action
 * is registered as a command. This is not a convenience feature: with 14
 * destinations in the nav, a palette is faster than hunting, and it is the
 * only way to reach them without a mouse — which matters because the console is
 * also the tool an operator uses when the UI is half-broken.
 *
 * @project RevoAp   @since 2.0.0
 */
import { h, txt, clear, append, uid, focusables, lockScroll, debounce, isMobile, copyText, nextFrame } from './ui/dom.js';
import { icon, spinner } from './ui/icons.js';
import { Button, Badge, Sheet, Dialog, Confirm, Toast, DropdownMenu, attachTooltip, Kbd, Separator, EmptyState } from './ui/components.js';
import { t, tn, applyTranslations, LOCALES, switchLocale, locale as currentLocale, isRtl } from './i18n.js';
import * as Theme from './theme.js';

/* =====================================================================
 * Navigation model
 *
 * One list drives the sidebar, the mobile bottom bar, the drawer and the
 * command palette. Adding a destination is a single entry here; all four
 * surfaces pick it up, which is the only way they do not drift apart.
 * =================================================================== */

/**
 * @typedef {{id:string, labelKey:string, icon:string, group:'main'|'admin'|'system', gatedBy?:string, badge?:string, tab?:string, href?:string, external?:boolean}} NavItem
 */

/** @type {NavItem[]} */
export const NAV = [
    { id: 'chat', labelKey: 'nav.chat', icon: 'messageSquare', group: 'main', tab: 'chat' },
    { id: 'dashboard', labelKey: 'nav.dashboard', icon: 'layoutGrid', group: 'main', tab: 'dashboard' },
    { id: 'models', labelKey: 'nav.models', icon: 'cpu', group: 'main', tab: 'models', gatedBy: 'models' },
    { id: 'skills', labelKey: 'nav.skills', icon: 'sparkles', group: 'main', tab: 'skills' },
    { id: 'history', labelKey: 'nav.history', icon: 'history', group: 'admin', tab: 'history', gatedBy: 'history' },
    { id: 'statistic', labelKey: 'nav.statistic', icon: 'barChart', group: 'admin', tab: 'statistic', gatedBy: 'statistic' },
    { id: 'queue', labelKey: 'nav.queue', icon: 'listTree', group: 'admin', tab: 'queue', gatedBy: 'queue' },
    { id: 'users', labelKey: 'nav.users', icon: 'users', group: 'admin', tab: 'users', gatedBy: 'users' },
    { id: 'audit', labelKey: 'nav.audit', icon: 'shield', group: 'admin', tab: 'audit', gatedBy: 'audit' },
    { id: 'budgets', labelKey: 'nav.budgets', icon: 'wallet', group: 'admin', tab: 'budgets', gatedBy: 'budgets' },
    { id: 'scheduler', labelKey: 'nav.scheduler', icon: 'calendarClock', group: 'admin', tab: 'scheduler', gatedBy: 'scheduler' },
    { id: 'notifications', labelKey: 'nav.notifications', icon: 'bell', group: 'admin', tab: 'notifications', gatedBy: 'notifications' },
    { id: 'backup', labelKey: 'nav.backup', icon: 'hardDrive', group: 'admin', tab: 'backup', gatedBy: 'backup' },
    { id: 'localization', labelKey: 'nav.localization', icon: 'languages', group: 'admin', tab: 'localization', gatedBy: 'localization' },
    { id: 'settings', labelKey: 'nav.settings', icon: 'settings', group: 'admin', tab: 'settings', gatedBy: 'settings' },
    { id: 'security', labelKey: 'nav.security', icon: 'lock', group: 'admin', tab: 'security', gatedBy: 'security' },
    { id: 'logs', labelKey: 'nav.logs', icon: 'fileText', group: 'admin', tab: 'logs', gatedBy: 'logs' },
    { id: 'api', labelKey: 'nav.api', icon: 'terminal', group: 'system', tab: 'api', gatedBy: 'api' },
    { id: 'extensions', labelKey: 'nav.extensions', icon: 'package', group: 'system', tab: 'extensions', gatedBy: 'extensions' },
    { id: 'system', labelKey: 'nav.system', icon: 'server', group: 'system', tab: 'system', gatedBy: 'system' },
    // Feature flags and maintenance mode render inside the System panel, so
    // they are palette destinations rather than sidebar rows: giving each its
    // own nav entry would put three items in the sidebar that all show the
    // same page. The command palette reaches them in one keystroke.
    { id: 'flags', labelKey: 'feature_flags.title', icon: 'flag', group: 'system', tab: 'system', paletteOnly: true },
    { id: 'maintenance', labelKey: 'maintenance.enable', icon: 'shieldAlert', group: 'system', tab: 'system', paletteOnly: true },
    { id: 'help', labelKey: 'nav.help', icon: 'lifeBuoy', group: 'system', tab: 'help' },
];

/** The five destinations shown in the mobile bottom bar. */
const MOBILE_PRIMARY = ['chat', 'models', 'queue', 'statistic', 'help'];

/** Keys of `/api/tabs` that gate visibility. Absent = always visible. */
const tabState = { tabs: {}, adminUnlocked: false };

const SIDEBAR_KEY = 'revoap.sidebar';
const SIDEBAR_COOKIE = 'revoap_sidebar';

let shellRoot = null;
let activeTab = 'chat';
const listeners = new Set();

/* =====================================================================
 * Tab activation
 * =================================================================== */

/**
 * Activate a tab.
 *
 * The pre-v2 tab machinery binds to `[data-tab]` buttons and shows
 * `[data-tab-panel]`. This function drives THAT contract rather than
 * replacing it, so every existing panel module (settings.js, models.js,
 * history.js …) keeps working unchanged. The shell only decides which id to
 * ask for and keeps its own nav in sync.
 */
export function setTab(id, { pushState = true } = {}) {
    const item = NAV.find((n) => n.id === id);
    const tab = item?.tab || id;

    // Delegate to an existing [data-tab="<tab>"] trigger when there is one.
    const trigger = document.querySelector(`[data-tab="${tab}"]`);
    if (trigger && !trigger.hidden) {
        trigger.click();
    } else {
        // No trigger (or it is gated off): fall back to the panel contract so a
        // deep link to a disabled tab shows the "not available" state instead
        // of a blank page.
        for (const panel of document.querySelectorAll('[data-tab-panel]')) {
            panel.hidden = panel.dataset.tabPanel !== tab;
        }
    }

    activeTab = id;
    syncNavActive();

    if (pushState) {
        const url = new URL(location.href);
        url.hash = id;
        // `replaceState` on the very first activation, so Back still leaves the
        // app instead of trapping the user in a hash loop.
        history[shellRoot?.dataset.initialHashSeen ? 'pushState' : 'replaceState']({ tab: id }, '', url);
        if (shellRoot) shellRoot.dataset.initialHashSeen = '1';
    }
    for (const fn of listeners) { try { fn(id); } catch (e) { console.error('[shell] tab listener', e); } }
}

export function getTab() { return activeTab; }
export function onTabChange(fn) { listeners.add(fn); return () => listeners.delete(fn); }

/** Reflect the active tab into every nav surface. */
function syncNavActive() {
    for (const el of document.querySelectorAll('[data-nav-id]')) {
        const on = el.dataset.navId === activeTab;
        el.setAttribute('aria-current', on ? 'page' : 'false');
        el.dataset.state = on ? 'active' : 'inactive';
    }
}

/** Apply the `/api/tabs` probe: hide what the operator switched off. */
export function applyTabVisibility(tabs, { adminUnlocked = false } = {}) {
    tabState.tabs = tabs && typeof tabs === 'object' ? tabs : {};
    tabState.adminUnlocked = !!adminUnlocked;
    for (const item of NAV) {
        if (!item.gatedBy) continue;
        const allowed = tabState.tabs[item.gatedBy] === true;
        for (const el of document.querySelectorAll(`[data-nav-id="${item.id}"]`)) {
            el.hidden = !allowed;
            el.closest('li,[data-nav-row]')?.toggleAttribute('hidden', !allowed);
        }
    }
    // Rebuild the command palette's command list; the destinations changed.
    commandRegistry.refresh();
}

/* =====================================================================
 * Header
 * =================================================================== */

function buildHeader() {
    const header = h('header', {
        class: 'app-header',
        attrs: { 'data-slot': 'app-header' },
    });

    // -- leading: sidebar toggle (desktop) / menu (mobile) + brand ----------
    const leading = h('div', { class: 'row gap-2 min-w-0' });

    const menuBtn = Button({
        variant: 'ghost', size: 'icon', iconName: 'menu',
        ariaLabel: t('nav.open_menu'), class: 'md:hidden',
        attrs: { 'data-slot': 'mobile-menu-trigger' },
        onClick: () => openDrawer(),
    });
    leading.appendChild(menuBtn);

    const railBtn = Button({
        variant: 'ghost', size: 'icon', iconName: 'panelLeft',
        ariaLabel: t('nav.collapse_sidebar'), class: 'hidden md:inline-flex',
        attrs: { 'data-slot': 'sidebar-toggle' },
        onClick: () => toggleSidebar(),
    });
    leading.appendChild(railBtn);

    const brand = h('a', {
        class: 'app-brand min-w-0', href: '#chat',
        attrs: { 'data-tab': 'chat', 'aria-label': t('app.name') },
    });
    brand.appendChild(h('span', { class: 'app-brand-mark', attrs: { 'aria-hidden': 'true' } }, icon('layers', { size: 16 })));
    brand.appendChild(h('span', { class: 'app-brand-text truncate', text: t('app.name') }));
    leading.appendChild(brand);

    header.appendChild(leading);

    // -- centre: command palette trigger (desktop only) ---------------------
    const centre = h('div', { class: 'hidden lg:flex flex-1 justify-center px-4 max-w-xl' });
    const paletteBtn = h('button', {
        type: 'button', class: 'app-command-trigger',
        attrs: { 'data-slot': 'command-trigger', 'aria-label': t('nav.command_palette') },
        onClick: () => openCommandPalette(),
    });
    paletteBtn.appendChild(icon('search', { size: 15 }));
    paletteBtn.appendChild(h('span', { class: 'flex-1 text-start muted', text: t('command.placeholder') }));
    paletteBtn.appendChild(Kbd(isApple() ? '⌘K' : 'Ctrl K'));
    centre.appendChild(paletteBtn);
    header.appendChild(centre);

    // -- trailing: worker chip, language, theme, account --------------------
    const trailing = h('div', { class: 'row gap-1' });

    // The worker indicator keeps its v1 id so worker-status.js finds it.
    const worker = h('button', {
        type: 'button', id: 'oc-worker-status', class: 'btn btn-ghost btn-icon app-worker-chip',
        attrs: { 'data-lane': 'unknown', 'aria-label': t('worker.checking'), 'aria-haspopup': 'true', 'aria-expanded': 'false', title: t('worker.tooltip') },
    }, h('span', { class: 'app-worker-dot', attrs: { 'aria-hidden': 'true' } }));
    trailing.appendChild(worker);

    trailing.appendChild(buildLocaleControl());
    trailing.appendChild(buildThemeControl());

    const account = Button({
        variant: 'ghost', size: 'icon', iconName: 'userCheck',
        ariaLabel: t('admin.unlock'), attrs: { 'data-slot': 'account-trigger' },
    });
    const accountMenu = DropdownMenu(account, [
        { label: t('admin.unlock'), icon: 'unlock', onClick: () => showUnlockDialog() },
        { label: t('admin.logout'), icon: 'lock', onClick: () => lockAdmin() },
        { separator: true },
        { label: t('theme.appearance'), icon: 'palette', onClick: () => showAppearanceDialog() },
        { label: t('nav.help'), icon: 'lifeBuoy', onClick: () => setTab('help') },
        { separator: true },
        { label: t('nav.repository'), icon: 'externalLink', onClick: () => window.open('https://github.com/paulmann/RevoAp', '_blank', 'noopener,noreferrer') },
    ], { align: 'end' });
    trailing.appendChild(account);

    header.appendChild(trailing);
    return header;
}

function isApple() {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
}

/**
 * The language control.
 *
 * A dropdown on desktop (all five at once, native names first), a full sheet on
 * mobile where five rows in a popover are a 22px target each.
 */
function buildLocaleControl() {
    const btn = Button({
        variant: 'ghost', size: 'icon', iconName: 'languages',
        ariaLabel: t('locale.label'), title: t('locale.label'),
        attrs: { 'data-slot': 'locale-trigger', 'data-locale-current': currentLocale() },
    });
    const items = LOCALES.map((l) => ({
        label: l.native === l.english ? l.native : `${l.native}`,
        checked: l.code === currentLocale(),
        onClick: async () => {
            await switchLocale(l.code, { reload: false });
            rebuild();
            Toast.success(t('locale.switch'), l.native);
        },
    }));
    const menu = DropdownMenu(btn, [
        { label: t('locale.label'), icon: 'globe' },
        { separator: true },
        ...items,
    ], { align: 'end' });

    btn.addEventListener('click', () => {
        if (isMobile()) {
            Sheet({
                title: t('locale.label'),
                description: t('locale.help'),
                side: 'bottom',
                content: [buildLocaleGrid()],
            });
        }
    });
    return btn;
}

/** A touch-sized grid of language tiles, used in the mobile sheet. */
function buildLocaleGrid() {
    const grid = h('div', { class: 'grid grid-cols-1 gap-2' });
    for (const l of LOCALES) {
        const active = l.code === currentLocale();
        const tile = h('button', {
            type: 'button',
            class: 'row-between w-full rounded-lg border p-3 text-start transition-colors',
            attrs: { 'data-state': active ? 'active' : 'inactive', role: 'radio', 'aria-checked': active ? 'true' : 'false' },
            style: active ? { borderColor: 'var(--primary)', background: 'color-mix(in oklch, var(--primary) 10%, transparent)' } : { borderColor: 'var(--border)' },
            onClick: async () => { await switchLocale(l.code, { reload: false }); rebuild(); },
        });
        const left = h('div', { class: 'stack-sm min-w-0' });
        left.appendChild(h('span', { class: 'font-medium', text: l.native, attrs: { lang: l.code } }));
        if (l.native !== l.english) left.appendChild(h('span', { class: 'xs muted', text: l.english }));
        if (l.dir === 'rtl') left.appendChild(Badge(t('locale.rtl_notice'), { tone: 'info' }));
        tile.appendChild(left);
        if (active) tile.appendChild(icon('check', { size: 18, class: 'text-primary shrink-0' }));
        grid.appendChild(tile);
    }
    grid.setAttribute('role', 'radiogroup');
    grid.setAttribute('aria-label', t('locale.label'));
    return grid;
}

/**
 * The theme control.
 *
 * Cycles light → dark → system on click, and shows the three states plus the
 * accent/density pickers in a dropdown. Cycling is on the primary click
 * because that is the fast path; the dropdown is for the settings most people
 * set once.
 */
function buildThemeControl() {
    const state = Theme.getState();
    const btn = Button({
        variant: 'ghost', size: 'icon',
        ariaLabel: t('theme.toggle'), title: t('theme.toggle'),
        attrs: { 'data-slot': 'theme-trigger', 'data-mode': state.mode, 'data-resolved': state.resolved },
    });
    btn.appendChild(icon({ light: 'sun', dark: 'moon', system: 'monitor' }[state.mode] || 'monitor', { size: 17 }));

    const rebuildIcon = () => {
        const s = Theme.getState();
        clear(btn);
        btn.appendChild(icon({ light: 'sun', dark: 'moon', system: 'monitor' }[s.mode] || 'monitor', { size: 17 }));
        btn.dataset.mode = s.mode;
        btn.dataset.resolved = s.resolved;
    };
    Theme.onChange(rebuildIcon);

    const menuItems = [
        { label: t('theme.mode'), icon: 'contrast' },
        ...['light', 'dark', 'system'].map((mode) => ({
            label: t(`theme.${mode}`),
            icon: { light: 'sun', dark: 'moon', system: 'monitor' }[mode],
            checked: state.mode === mode,
            onClick: () => { Theme.setMode(mode); rebuildIcon(); },
        })),
        { separator: true },
        { label: t('theme.density'), icon: 'rows' },
        ...['cozy', 'comfortable', 'compact'].map((density) => ({
            label: t(`theme.${density}`),
            checked: state.density === density,
            onClick: () => Theme.setDensity(density),
        })),
        { separator: true },
        { label: t('theme.accent'), icon: 'palette', onClick: () => showAppearanceDialog() },
    ];
    DropdownMenu(btn, menuItems, { align: 'end' });
    return btn;
}

/** The full appearance dialog, including the accent swatches. */
export function showAppearanceDialog() {
    const state = Theme.getState();
    const content = [];

    // --- mode ----------------------------------------------------------
    content.push(h('div', { class: 'stack-sm' },
        h('div', { class: 'label mb-0', text: t('theme.mode') }),
        h('p', { class: 'form-description mt-0', text: t('theme.mode_help') }),
        segmented('mode', [
            { value: 'light', label: t('theme.light'), icon: 'sun' },
            { value: 'dark', label: t('theme.dark'), icon: 'moon' },
            { value: 'system', label: t('theme.system'), icon: 'monitor' },
        ], state.mode, (v) => Theme.setMode(v)),
    ));

    // --- density -------------------------------------------------------
    content.push(h('div', { class: 'stack-sm' },
        h('div', { class: 'label mb-0', text: t('theme.density') }),
        h('p', { class: 'form-description mt-0', text: t('theme.density_help') }),
        segmented('density', [
            { value: 'cozy', label: t('theme.cozy') },
            { value: 'comfortable', label: t('theme.comfortable') },
            { value: 'compact', label: t('theme.compact') },
        ], state.density, (v) => Theme.setDensity(v)),
    ));

    // --- accent --------------------------------------------------------
    const swatches = h('div', { class: 'flex flex-wrap gap-2' });
    for (const accent of Theme.ACCENTS) {
        const on = accent === state.accent;
        const sw = h('button', {
            type: 'button',
            class: 'app-swatch',
            attrs: { 'data-accent': accent, 'aria-pressed': on ? 'true' : 'false', 'aria-label': t(`theme.accent_${accent}`), title: t(`theme.accent_${accent}`) },
            onClick: () => {
                Theme.setAccent(accent);
                for (const other of swatches.querySelectorAll('.app-swatch')) {
                    const isIt = other.dataset.accent === accent;
                    other.setAttribute('aria-pressed', isIt ? 'true' : 'false');
                }
            },
        });
        // Colours come from `.app-swatch[data-accent="…"]` in ui/shell.css,
        // NOT from an inline style: the token pair there carries the matching
        // foreground, and setting only the background here would leave the
        // check mark white-on-amber (2.9:1, below WCAG 1.4.11).
        sw.appendChild(on ? icon('check', { size: 14 }) : txt(''));
        swatches.appendChild(sw);
    }
    content.push(h('div', { class: 'stack-sm' },
        h('div', { class: 'label mb-0', text: t('theme.accent') }),
        h('p', { class: 'form-description mt-0', text: t('theme.accent_help') }),
        swatches,
    ));

    // --- locale --------------------------------------------------------
    content.push(h('div', { class: 'stack-sm' },
        h('div', { class: 'label mb-0', text: t('locale.label') }),
        h('p', { class: 'form-description mt-0', text: t('locale.help') }),
        buildLocaleGrid(),
    ));

    Dialog({ title: t('theme.appearance'), content, size: 'md' });
}

/** A segmented control bound to the theme manager. */
function segmented(name, options, value, onChange) {
    const group = h('div', { class: 'tabs-list w-full', role: 'radiogroup', attrs: { 'data-theme-group': name, 'aria-label': t(`theme.${name}`) } });
    for (const option of options) {
        const input = h('input', {
            type: 'radio', name: `revoap-${name}`, value: option.value,
            checked: option.value === value, class: 'sr-only',
            attrs: { 'data-theme-radio': name },
            on: { change: () => { if (input.checked) onChange(option.value); } },
        });
        const label = h('label', {
            class: 'tabs-trigger flex-1', attrs: { 'data-state': option.value === value ? 'active' : 'inactive' },
        });
        if (option.icon) label.appendChild(icon(option.icon, { size: 14 }));
        label.appendChild(txt(option.label));
        // The label wraps the input so clicking it toggles natively; no
        // synthetic .click() and no duplicated change events.
        label.prepend(input);
        group.appendChild(label);
    }
    group.addEventListener('change', () => {
        for (const label of group.querySelectorAll('label')) {
            const input = label.querySelector('input');
            label.dataset.state = input?.checked ? 'active' : 'inactive';
        }
    });
    return group;
}

/**
 * Adopt the server-rendered header instead of replacing it.
 *
 * The PHP template ships a real `<header>` with every `[data-tab]` trigger and
 * the worker/theme/locale controls. Enhancing it (rather than building a new
 * one) is what keeps three things true at once:
 *
 *   - the v1 tab machinery still binds to the same triggers it always did;
 *   - a script-disabled browser still has a navigable console;
 *   - the "admin surface is unprotected" disclosure, which must never depend
 *     on JS running, survives every rebuild.
 *
 * What this adds: the sidebar toggle and the command-palette trigger, which
 * have no server-rendered counterpart because they are pure enhancement.
 */
function enhanceHeader(header) {
    if (header.dataset.revoapEnhanced === '1') { syncNavActive(); return; }
    header.dataset.revoapEnhanced = '1';
    header.classList.add('app-header');

    const registry = header.querySelector('#oc-tab-registry');
    const controls = header.querySelector('#oc-header-controls');

    // -- leading: mobile menu + desktop rail toggle + brand --------------
    const brand = header.querySelector('#oc-brand-link');
    const leading = h('div', { class: 'row gap-2 min-w-0' });
    leading.appendChild(Button({
        variant: 'ghost', size: 'icon', iconName: 'menu',
        ariaLabel: t('nav.open_menu'), class: 'md:hidden',
        attrs: { 'data-slot': 'mobile-menu-trigger' }, onClick: () => openDrawer(),
    }));
    leading.appendChild(Button({
        variant: 'ghost', size: 'icon', iconName: 'panelLeft',
        ariaLabel: t('nav.collapse_sidebar'), class: 'hidden md:inline-flex',
        attrs: { 'data-slot': 'sidebar-toggle' }, onClick: () => toggleSidebar(),
    }));
    if (brand) {
        brand.classList.add('app-brand');
        brand.setAttribute('aria-label', t('app.name'));
        leading.appendChild(brand);
    }
    header.prepend(leading);

    // -- centre: command palette trigger ---------------------------------
    const centre = h('div', { class: 'hidden lg:flex flex-1 justify-center px-4 max-w-xl' });
    const paletteBtn = h('button', {
        type: 'button', class: 'app-command-trigger',
        attrs: { 'data-slot': 'command-trigger', 'aria-label': t('nav.command_palette') },
        onClick: () => openCommandPalette(),
    });
    paletteBtn.appendChild(icon('search', { size: 15 }));
    paletteBtn.appendChild(h('span', { class: 'flex-1 text-start muted', text: t('command.placeholder') }));
    paletteBtn.appendChild(Kbd(isApple() ? '\u2318K' : 'Ctrl K'));
    centre.appendChild(paletteBtn);
    // Inserted after `leading`, before the registry, so the visual order is
    // brand · search · tabs on desktop and brand · tabs on mobile.
    if (registry) header.insertBefore(centre, registry);
    else header.appendChild(centre);

    // -- registry: hide the raw button row, the sidebar carries it --------
    if (registry) {
        // `hidden` rather than `display:none` in CSS: the triggers must stay
        // in the DOM and clickable (the tab machinery dispatches .click() on
        // them) while being invisible. The sidebar and bottom bar hold the
        // visible copies, which carry the same data-tab value.
        registry.setAttribute('aria-hidden', 'true');
        registry.style.position = 'absolute';
        registry.style.width = '1px';
        registry.style.height = '1px';
        registry.style.overflow = 'hidden';
        registry.style.clipPath = 'inset(50%)';
        registry.style.whiteSpace = 'nowrap';
        // A visually-hidden container must not be reachable by Tab, but the
        // buttons inside it ARE the tab machinery's targets, so they stay
        // focusable=false rather than tabindex=-1 (which would break
        // programmatic .click() in some engines).
        registry.tabIndex = -1;
    }

    // -- trailing controls rendered by PHP -------------------------------
    if (controls) {
        controls.classList.add('row', 'gap-1');
        // The PHP-rendered theme toggle gains the cycle behaviour and the
        // locale <select> gains its options; both are wired by the modules
        // themselves (theme.js::bindControls, i18n.js::bindControls), so all
        // this has to do is put the appearance dialog within reach.
        const appearance = Button({
            variant: 'ghost', size: 'icon', iconName: 'palette',
            ariaLabel: t('theme.appearance'), attrs: { 'data-slot': 'appearance-trigger' },
            onClick: () => showAppearanceDialog(),
        });
        controls.appendChild(appearance);

        const account = Button({
            variant: 'ghost', size: 'icon', iconName: 'userCheck',
            ariaLabel: t('admin.unlock'), attrs: { 'data-slot': 'account-trigger' },
        });
        DropdownMenu(account, [
            { label: t('admin.unlock'), icon: 'unlock', onClick: () => showUnlockDialog() },
            { label: t('admin.logout'), icon: 'lock', onClick: () => lockAdmin() },
            { separator: true },
            { label: t('theme.appearance'), icon: 'palette', onClick: () => showAppearanceDialog() },
            { label: t('nav.help'), icon: 'lifeBuoy', onClick: () => setTab('help') },
            { separator: true },
            { label: t('nav.repository'), icon: 'externalLink', onClick: () => window.open('https://github.com/paulmann/RevoAp', '_blank', 'noopener,noreferrer') },
        ], { align: 'end' });
        controls.appendChild(account);
    }

    // The worker chip keeps its v1 id and `data-lane` contract; only the class
    // that paints it changes.
    header.querySelector('#oc-worker-status')?.classList.add('app-worker-chip');
    syncNavActive();
}

/* =====================================================================
 * Sidebar (desktop) + drawer (mobile)
 * =================================================================== */

function sidebarCollapsed() {
    try { return localStorage.getItem(SIDEBAR_KEY) === 'collapsed'; } catch { return false; }
}

export function toggleSidebar(force) {
    const next = force !== undefined ? !!force : !sidebarCollapsed();
    try { localStorage.setItem(SIDEBAR_KEY, next ? 'collapsed' : 'expanded'); } catch { /* blocked */ }
    document.cookie = `${SIDEBAR_COOKIE}=${next ? 'collapsed' : 'expanded'}; Path=/; Max-Age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
    document.documentElement.dataset.sidebar = next ? 'collapsed' : 'expanded';
    for (const el of document.querySelectorAll('[data-slot="sidebar-toggle"]')) {
        el.setAttribute('aria-label', next ? t('nav.expand_sidebar') : t('nav.collapse_sidebar'));
        el.setAttribute('aria-expanded', next ? 'false' : 'true');
    }
}

function buildSidebar() {
    const aside = h('aside', {
        class: 'app-sidebar', id: 'app-sidebar',
        attrs: { 'data-slot': 'app-sidebar', 'aria-label': t('nav.main') },
    });

    const nav = h('nav', { class: 'app-sidebar-nav' });
    for (const group of ['main', 'admin', 'system']) {
        const items = NAV.filter((n) => n.group === group);
        if (items.length === 0) continue;
        const section = h('div', { class: 'app-nav-section', attrs: { 'data-nav-group': group } });
        section.appendChild(h('div', {
            class: 'app-nav-group-label',
            text: t(group === 'main' ? 'nav.main' : group === 'admin' ? 'nav.admin' : 'nav.system'),
            attrs: { 'data-slot': 'nav-group-label' },
        }));
        const list = h('ul', { class: 'app-nav-list', role: 'list' });
        for (const item of items) {
            if (item.paletteOnly) continue;   // reachable from Ctrl/Cmd+K only
            list.appendChild(buildNavLink(item));
        }
        section.appendChild(list);
        nav.appendChild(section);
    }
    aside.appendChild(nav);

    // -- footer: version + build ---------------------------------------
    const meta = document.querySelector('meta[name="oc-app-version"]')?.getAttribute('content') || '';
    const build = document.querySelector('meta[name="oc-build"]')?.getAttribute('content') || '';
    const footer = h('div', { class: 'app-sidebar-footer' });
    footer.appendChild(h('div', { class: 'app-nav-item app-nav-static', title: `${t('app.build', { build })}` },
        icon('gitBranch', { size: 15 }),
        h('span', { class: 'app-nav-label mono xs', text: meta ? `v${meta}` : 'v—' }),
    ));
    aside.appendChild(footer);

    return aside;
}

/** One nav row. Renders as a button because tab panels are not documents. */
function buildNavLink(item) {
    const li = h('li', { attrs: { 'data-nav-row': item.id } });
    const label = t(item.labelKey);
    const el = h('button', {
        type: 'button', class: 'app-nav-item',
        attrs: {
            'data-nav-id': item.id, 'data-tab': item.tab || item.id,
            'aria-current': 'false', title: label,
        },
        onClick: () => { setTab(item.id); closeDrawer(); },
    });
    el.appendChild(icon(item.icon, { size: 17 }));
    el.appendChild(h('span', { class: 'app-nav-label', text: label }));
    if (item.badge) el.appendChild(Badge(item.badge, { tone: 'muted', class: 'app-nav-badge' }));
    attachTooltip(el, () => label);
    li.appendChild(el);
    return li;
}

/* ---- mobile drawer ------------------------------------------------- */

let drawerHandle = null;
function openDrawer() {
    if (drawerHandle) return;
    const content = [];

    // Search first: on a phone the palette trigger is not in the header, and
    // search is the fastest way to a destination that is buried in the list.
    const search = h('input', {
        class: 'input', type: 'search', placeholder: t('command.placeholder'),
        attrs: { 'aria-label': t('common.search'), 'data-autofocus': '' },
    });
    const results = h('div', { class: 'stack-sm max-h-[45dvh] overflow-y-auto' });
    const renderResults = (query = '') => {
        clear(results);
        const q = query.trim().toLowerCase();
        const visible = NAV.filter((n) => isNavVisible(n) && !n.paletteOnly)
            .filter((n) => !q || t(n.labelKey).toLowerCase().includes(q) || n.id.includes(q));
        if (visible.length === 0) {
            results.appendChild(EmptyState({ iconName: 'search', title: t('common.no_results'), description: t('common.no_results_hint'), compact: true }));
            return;
        }
        for (const item of visible) {
            const row = h('button', {
                type: 'button', class: 'app-nav-item w-full',
                attrs: { 'data-nav-id': item.id },
                onClick: () => { setTab(item.id); closeDrawer(); },
            });
            row.appendChild(icon(item.icon, { size: 17 }));
            row.appendChild(h('span', { class: 'app-nav-label', text: t(item.labelKey) }));
            results.appendChild(row);
        }
    };
    search.addEventListener('input', debounce(() => renderResults(search.value), 120));
    content.push(h('div', { class: 'stack-sm' }, search, results));
    renderResults();

    content.push(Separator());

    // Appearance, inline: on a phone this is where the theme control lives,
    // because the header only has room for the cycle button.
    const state = Theme.getState();
    content.push(h('div', { class: 'stack-sm' },
        h('div', { class: 'label mb-0', text: t('theme.mode') }),
        segmented('mode', [
            { value: 'light', label: t('theme.light'), icon: 'sun' },
            { value: 'dark', label: t('theme.dark'), icon: 'moon' },
            { value: 'system', label: t('theme.system'), icon: 'monitor' },
        ], state.mode, (v) => Theme.setMode(v)),
    ));
    content.push(h('div', { class: 'stack-sm' },
        h('div', { class: 'label mb-0', text: t('locale.label') }),
        segmented('locale', LOCALES.map((l) => ({ value: l.code, label: l.native })), currentLocale(),
            async (v) => { await switchLocale(v, { reload: false }); closeDrawer(); rebuild(); }),
    ));

    drawerHandle = Sheet({
        title: t('nav.main'),
        side: 'left',
        content,
        onClose: () => { drawerHandle = null; },
    });
}
function closeDrawer() { drawerHandle?.close(); drawerHandle = null; }

function isNavVisible(item) {
    if (!item.gatedBy) return true;
    return tabState.tabs[item.gatedBy] === true;
}

/* =====================================================================
 * Mobile bottom navigation
 * =================================================================== */

function buildBottomNav() {
    const nav = h('nav', {
        class: 'app-bottomnav', attrs: { 'data-slot': 'app-bottomnav', 'aria-label': t('nav.main') },
    });
    const list = h('div', { class: 'app-bottomnav-row' });
    for (const id of MOBILE_PRIMARY) {
        const item = NAV.find((n) => n.id === id);
        if (!item || !isNavVisible(item)) continue;
        const el = h('button', {
            type: 'button', class: 'app-bottomnav-item',
            attrs: { 'data-nav-id': item.id, 'data-tab': item.tab || item.id, 'aria-current': 'false' },
            onClick: () => setTab(item.id),
        });
        el.appendChild(icon(item.icon, { size: 20 }));
        el.appendChild(h('span', { class: 'app-bottomnav-label', text: t(item.labelKey) }));
        list.appendChild(el);
    }
    // The "everything else" slot opens the drawer.
    list.appendChild(h('button', {
        type: 'button', class: 'app-bottomnav-item',
        attrs: { 'aria-label': t('nav.open_menu') },
        onClick: () => openDrawer(),
    }, icon('moreHorizontal', { size: 20 }), h('span', { class: 'app-bottomnav-label', text: t('common.more') })));

    nav.appendChild(list);
    return nav;
}

/* =====================================================================
 * Command palette
 * =================================================================== */

/**
 * A registry rather than a hardcoded list: panels register their own actions
 * as they mount, so "Purge cache" is searchable from the palette without the
 * shell knowing the Settings panel exists.
 */
export const commandRegistry = (() => {
    /** @type {Array<{id:string,group:string,label:string|Function,hint?:string,icon?:string,run:Function,keywords?:string}>} */
    let commands = [];

    const base = () => {
        const out = [];
        for (const item of NAV) {
            if (!isNavVisible(item)) continue;
            out.push({
                id: `nav:${item.id}`, group: 'navigation', label: () => t(item.labelKey),
                icon: item.icon, keywords: item.id, run: () => setTab(item.id),
            });
        }
        for (const mode of ['light', 'dark', 'system']) {
            out.push({ id: `theme:${mode}`, group: 'theme', label: () => `${t('theme.mode')}: ${t(`theme.${mode}`)}`, icon: { light: 'sun', dark: 'moon', system: 'monitor' }[mode], run: () => Theme.setMode(mode) });
        }
        for (const density of Theme.DENSITIES) {
            out.push({ id: `density:${density}`, group: 'theme', label: () => `${t('theme.density')}: ${t(`theme.${density}`)}`, icon: 'rows', run: () => Theme.setDensity(density) });
        }
        for (const accent of Theme.ACCENTS) {
            out.push({ id: `accent:${accent}`, group: 'theme', label: () => `${t('theme.accent')}: ${t(`theme.accent_${accent}`)}`, icon: 'palette', run: () => Theme.setAccent(accent) });
        }
        for (const l of LOCALES) {
            out.push({ id: `locale:${l.code}`, group: 'theme', label: () => `${t('locale.label')}: ${l.native}`, icon: 'languages', keywords: l.english, run: async () => { await switchLocale(l.code, { reload: false }); rebuild(); } });
        }
        out.push({ id: 'ui:appearance', group: 'theme', label: () => t('theme.appearance'), icon: 'contrast', run: () => showAppearanceDialog() });
        out.push({ id: 'ui:sidebar', group: 'actions', label: () => t('nav.collapse_sidebar'), icon: 'panelLeft', run: () => toggleSidebar() });
        out.push({ id: 'admin:unlock', group: 'actions', label: () => t('admin.unlock'), icon: 'unlock', run: () => showUnlockDialog() });
        return out;
    };

    return {
        /** Register extra commands. Returns an unregister function. */
        register(list) {
            const added = Array.isArray(list) ? list : [list];
            commands.push(...added);
            return () => { commands = commands.filter((c) => !added.includes(c)); };
        },
        unregister(id) { commands = commands.filter((c) => c.id !== id); },
        refresh() { /* base() is recomputed on every open */ },
        all() { return [...base(), ...commands]; },
    };
})();

/** Fuzzy-ish scoring: substring beats subsequence beats nothing. */
function score(haystack, needle) {
    if (!needle) return 1;
    const h = haystack.toLowerCase();
    const n = needle.toLowerCase();
    const at = h.indexOf(n);
    if (at === 0) return 100;
    if (at > 0) return 80 - Math.min(at, 40);
    // Subsequence match, penalised by how spread out the letters are.
    let i = 0;
    let gaps = 0;
    for (const ch of h) {
        if (ch === n[i]) { i += 1; if (i >= n.length) return 40 - Math.min(gaps, 30); }
        else if (i > 0) gaps += 1;
    }
    return -1;
}

let paletteHandle = null;
export function openCommandPalette() {
    if (paletteHandle) return;
    const all = commandRegistry.all();
    const listId = uid('cmd-list');

    const input = h('input', {
        class: 'command-input', type: 'text', role: 'combobox',
        placeholder: t('command.placeholder'),
        attrs: { 'aria-expanded': 'true', 'aria-controls': listId, 'aria-autocomplete': 'list', 'data-autofocus': '', autocomplete: 'off', spellcheck: 'false' },
    });
    const list = h('div', { class: 'command-list', id: listId, role: 'listbox', attrs: { 'aria-label': t('nav.command_palette') } });
    const state = { matches: [], active: -1 };

    const render = () => {
        clear(list);
        if (state.matches.length === 0) {
            list.appendChild(h('div', { class: 'command-empty', text: t('command.empty') }));
            input.removeAttribute('aria-activedescendant');
            return;
        }
        let lastGroup = null;
        state.matches.forEach((m, index) => {
            if (m.group !== lastGroup) {
                lastGroup = m.group;
                list.appendChild(h('div', { class: 'command-group-label', text: t(`command.groups.${m.group}`) || m.group }));
            }
            const id = `${listId}-${index}`;
            const el = h('div', {
                class: 'command-item', id, role: 'option',
                attrs: { 'data-selected': index === state.active ? 'true' : 'false', 'aria-selected': index === state.active ? 'true' : 'false' },
                onClick: () => run(m),
                onPointermove: () => { state.active = index; paintActive(); },
            });
            if (m.icon) el.appendChild(icon(m.icon, { size: 16 }));
            el.appendChild(h('span', { class: 'flex-1 truncate', text: typeof m.label === 'function' ? m.label() : String(m.label) }));
            if (m.hint) el.appendChild(h('span', { class: 'xs muted truncate max-w-[40%]', text: m.hint }));
            list.appendChild(el);
        });
        list.querySelector('[data-selected="true"]')?.scrollIntoView({ block: 'nearest' });
        const activeEl = list.querySelector('[data-selected="true"]');
        if (activeEl) input.setAttribute('aria-activedescendant', activeEl.id);
    };

    // Re-painting the whole list on every arrow key is fine here: the palette
    // is capped at 40 rows, and mutating `data-selected` in place would leave
    // `aria-activedescendant` and the visual highlight able to disagree.
    const paintActive = () => render();

    const filter = () => {
        const q = input.value.trim();
        const scored = all.map((m) => {
            const label = typeof m.label === 'function' ? m.label() : String(m.label);
            const s = Math.max(score(label, q), score(m.keywords || '', q) * 0.8, score(m.id, q) * 0.6);
            return { m, s, label };
        }).filter((e) => e.s >= 0).sort((a, b) => b.s - a.s || a.label.localeCompare(b.label));
        state.matches = scored.slice(0, 40).map((e) => e.m);
        state.active = state.matches.length ? 0 : -1;
        render();
    };

    const run = async (m) => {
        close();
        try { await m.run?.(); } catch (err) { console.error('[command]', m.id, err); Toast.error(t('common.unknown_error'), String(err?.message || err)); }
    };

    input.addEventListener('input', filter);
    input.addEventListener('keydown', (ev) => {
        const n = state.matches.length;
        if (ev.key === 'ArrowDown') { ev.preventDefault(); state.active = (state.active + 1) % n; render(); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); state.active = (state.active - 1 + n) % n; render(); }
        else if (ev.key === 'Home') { ev.preventDefault(); state.active = 0; render(); }
        else if (ev.key === 'End') { ev.preventDefault(); state.active = n - 1; render(); }
        else if (ev.key === 'Enter') { ev.preventDefault(); const m = state.matches[state.active]; if (m) run(m); }
    });

    const close = () => { paletteHandle?.close(); paletteHandle = null; };

    const body = h('div', { class: 'command' },
        h('div', { class: 'row gap-2 px-3 border-b border-border' }, icon('search', { size: 16, class: 'muted shrink-0' }), input),
        list,
        h('div', { class: 'row-between px-3 py-2 border-t border-border xs muted' },
            h('span', { class: 'row gap-1' }, Kbd('↑↓'), txt(t('common.search'))),
            h('span', { class: 'row gap-1' }, Kbd('↵'), txt(t('common.apply'))),
            h('span', { class: 'row gap-1' }, Kbd('Esc'), txt(t('common.close'))),
        ),
    );

    paletteHandle = Dialog({ content: [body], size: 'lg', initialFocus: input, onClose: () => { paletteHandle = null; } });
    // Hide the dialog's own chrome: the command box provides its own.
    paletteHandle.el.classList.add('p-0', 'gap-0', 'overflow-hidden');
    filter();
}

/* =====================================================================
 * Admin unlock
 * =================================================================== */

export function showUnlockDialog() {
    const input = h('input', {
        class: 'input input-mono', type: 'password', autocomplete: 'current-password',
        placeholder: t('admin.token'), attrs: { 'data-autofocus': '', 'aria-label': t('admin.token') },
    });
    const status = h('p', { class: 'form-description', attrs: { role: 'status', 'aria-live': 'polite' } });
    const submit = Button({ variant: 'default', label: t('admin.unlock_button'), type: 'submit' });

    const form = h('form', {
        class: 'stack',
        onSubmit: async (ev) => {
            ev.preventDefault();
            const token = input.value;
            if (!token) { status.textContent = t('admin.unlock_failed'); return; }
            submit.disabled = true;
            status.textContent = t('common.loading');
            try {
                const res = await fetch('/api/settings/unlock', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ token }),
                });
                const body = await res.json().catch(() => ({}));
                if (res.ok && body.ok !== false) {
                    close();
                    Toast.success(t('admin.unlock_ok'));
                    await refreshTabProbe();
                } else {
                    status.textContent = body.error === 'locked'
                        ? t('admin.unlock_locked', { seconds: body.retry_after ?? 30 })
                        : t('admin.unlock_failed');
                    input.select();
                }
            } catch (err) {
                status.textContent = t('common.network_error');
            } finally {
                submit.disabled = false;
            }
        },
    }, input, status, h('div', { class: 'dialog-footer' }, submit));

    const dlg = Dialog({ title: t('admin.unlock'), description: t('admin.unlock_help'), content: [form], size: 'sm', initialFocus: input });
    const close = () => dlg.close();
}

async function lockAdmin() {
    const ok = await Confirm({ title: t('admin.logout'), description: t('common.confirm_action'), confirmLabel: t('admin.logout'), danger: false });
    if (!ok) return;
    try {
        await fetch('/api/settings/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
        Toast.info(t('admin.logout_ok'));
        await refreshTabProbe();
    } catch { Toast.error(t('common.network_error')); }
}

/** Re-read `/api/tabs` and re-apply visibility. Called after unlock/lock. */
export async function refreshTabProbe() {
    try {
        const res = await fetch('/api/tabs', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const body = await res.json();
        applyTabVisibility(body.tabs ?? body, { adminUnlocked: !!(body.admin ?? body.unlocked) });
        syncNavActive();
    } catch (err) {
        console.warn('[shell] tab probe failed', err);
    }
}

/* =====================================================================
 * Assembly
 * =================================================================== */

/**
 * Build (or rebuild after a locale change) the whole chrome.
 *
 * Idempotent: it removes the previous shell before inserting the new one, so
 * `switchLocale()` can call it without producing two headers.
 */
export function rebuild() {
    const host = document.getElementById('revoap-shell') || document.body;
    host.id = 'revoap-shell';

    // The header, the tab registry and the protection notice are rendered by
    // PHP (see layout.html). They are PRESERVED across a rebuild — a locale
    // switch must not destroy the disclosure that says the admin surface is
    // unprotected, and the `[data-tab]` triggers are the contract every v1
    // panel module binds to. Only the shell's own additions are rebuilt.
    const preservedHeader = document.getElementById('oc-app-header');
    const preservedNotice = document.getElementById('oc-protection-notice');
    const preservedMain = document.getElementById('revoap-content');
    const preservedNoScript = host.querySelector('noscript');

    clear(host);
    if (preservedNoScript) host.appendChild(preservedNoScript);

    // Skip link first in DOM order: it must be the first tab stop, and putting
    // it after the header would make it unreachable in practice.
    const skip = h('a', { class: 'sr-only-focusable app-skiplink', href: '#revoap-content', text: t('nav.skip_to_content') });
    host.appendChild(skip);

    // Reuse the server header when it is there; only build one when the
    // template did not ship it (a cached document, or a test fixture).
    const header = preservedHeader || buildHeader();
    enhanceHeader(header);
    host.appendChild(header);
    if (preservedNotice) host.appendChild(preservedNotice);

    const body = h('div', { class: 'app-body' });
    body.appendChild(buildSidebar());

    const main = preservedMain || h('main', { class: 'app-main oc-main', id: 'revoap-content', attrs: { tabindex: '-1' } });
    main.classList.add('app-main');
    main.setAttribute('tabindex', '-1');
    body.appendChild(main);
    host.appendChild(body);

    host.appendChild(buildBottomNav());

    // Screen-reader-only live region for theme/density announcements.
    if (!document.getElementById('revoap-theme-status')) {
        document.body.appendChild(h('div', {
            id: 'revoap-theme-status', class: 'sr-only', role: 'status', attrs: { 'aria-live': 'polite', 'aria-atomic': 'true' },
        }));
    }

    document.documentElement.dataset.sidebar = sidebarCollapsed() ? 'collapsed' : 'expanded';
    syncNavActive();
    applyTranslations(host);
    bindGlobalKeys();
}

/** Global keyboard shortcuts. Bound once, not on every rebuild. */
let keysBound = false;
function bindGlobalKeys() {
    if (keysBound) return;
    keysBound = true;
    let pendingGoto = 0;

    document.addEventListener('keydown', (ev) => {
        const mod = isApple() ? ev.metaKey : ev.ctrlKey;
        // Cmd/Ctrl+K — palette. Prevented so the browser's own search bar does
        // not steal it; that binding is nearly never wanted on a console page.
        if (mod && ev.key.toLowerCase() === 'k' && !ev.altKey) {
            ev.preventDefault();
            openCommandPalette();
            return;
        }
        // Typing in a field must never trigger a navigation shortcut.
        const target = ev.target;
        const typing = target instanceof HTMLElement
            && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
        if (typing || ev.metaKey || ev.ctrlKey || ev.altKey) return;

        if (ev.key === '/') {
            const query = document.getElementById('oc-query') || document.querySelector('[data-slot="chat-input"]');
            if (query) { ev.preventDefault(); query.focus(); }
            return;
        }
        // `g` then a letter: the GitHub-style goto, discoverable and cheap.
        if (ev.key === 'g') { pendingGoto = Date.now(); return; }
        if (pendingGoto && Date.now() - pendingGoto < 1200) {
            const map = { c: 'chat', d: 'dashboard', m: 'models', h: 'history', s: 'settings', q: 'queue', l: 'logs', x: 'security', a: 'audit', '?': null };
            pendingGoto = 0;
            const dest = map[ev.key.toLowerCase()];
            if (ev.key === '?') { ev.preventDefault(); showShortcuts(); return; }
            if (dest) { ev.preventDefault(); setTab(dest); }
        } else {
            pendingGoto = 0;
        }
    });

    // Browser back/forward across hashes.
    window.addEventListener('hashchange', () => {
        const id = (location.hash || '').replace(/^#/, '');
        if (id && id !== activeTab && NAV.some((n) => n.id === id)) setTab(id, { pushState: false });
    });

    // Online/offline is worth surfacing: a console whose fetches all fail with
    // no explanation looks broken rather than disconnected.
    const connectivity = () => {
        if (!navigator.onLine) Toast.warning(t('common.network_error'), t('errors.retry_suggested'), { duration: 0 });
    };
    window.addEventListener('offline', connectivity);
}

/** The keyboard-shortcut reference, from the Help panel. */
export function showShortcuts() {
    const rows = [
        [isApple() ? '⌘K' : 'Ctrl+K', t('help.key_palette')],
        ['/', t('help.key_focus_query')],
        ['g c', t('nav.chat')],
        ['g d', t('nav.dashboard')],
        ['g m', t('nav.models')],
        ['g h', t('nav.history')],
        ['g s', t('nav.settings')],
        ['g q', t('nav.queue')],
        ['g a', t('nav.audit')],
        ['g ?', t('help.keyboard')],
        ['Esc', t('help.key_close')],
    ];
    const table = h('table', { class: 'table table-compact' });
    const tbody = h('tbody');
    for (const [keys, label] of rows) {
        tbody.appendChild(h('tr', {},
            h('td', { class: 'w-32' }, ...keys.split(' ').map((k) => Kbd(k))),
            h('td', { text: label }),
        ));
    }
    table.appendChild(tbody);
    Dialog({ title: t('help.keyboard'), description: t('help.keyboard_help'), content: [table], size: 'md' });
}

/* =====================================================================
 * Boot
 * =================================================================== */

function init() {
    rebuild();
    // Honour a deep link (#models) on first load.
    const hash = (location.hash || '').replace(/^#/, '');
    if (hash && NAV.some((n) => n.id === hash)) setTab(hash, { pushState: false });
    void refreshTabProbe();

    window.RevoApShell = Object.freeze({
        setTab, getTab, onTabChange, applyTabVisibility, refreshTabProbe,
        openCommandPalette, openDrawer, closeDrawer, toggleSidebar,
        showAppearanceDialog, showUnlockDialog, showShortcuts, commandRegistry, rebuild, NAV,
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();

export default { setTab, getTab, rebuild, openCommandPalette, commandRegistry, NAV };
