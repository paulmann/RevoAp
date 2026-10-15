/**
 * admin.js — the v2.0.0 operator panels.
 *
 * System · Feature flags · Maintenance · Localization · Audit.
 *
 * All five render from JSON the controllers already return, through the same
 * ui/components.js primitives as the rest of the console. There is no
 * `innerHTML` with interpolated values anywhere in this file: audit entries
 * contain attacker-influenced text (the detail of a denied request, a target id
 * copied from a query string) and a localization value is operator input, so
 * every string goes through `textContent`.
 *
 * A panel that cannot reach its endpoint says so with the remedy, rather than
 * rendering an empty state that looks like "no data". On an operator console the
 * difference between those two is the whole diagnosis.
 *
 * @project RevoAp   @since 2.0.0
 */
import { h, txt, clear, debounce } from './ui/dom.js';
import { icon } from './ui/icons.js';
import {
    Button, Card, Badge, Alert, Stat, Field, Input, Textarea, Select, Switch,
    Dialog, Confirm, Toast, EmptyState, SkeletonBlock, Progress, Separator,
} from './ui/components.js';
import { t, tn, formatNumber, formatPercent, formatBytes, timeAgo, formatDateTime, formatDuration } from './i18n.js';

/**
 * @param {string} path
 * @param {{method?:string, body?:object}} [opts]
 */
async function api(path, opts = {}) {
    const res = await fetch(path, {
        method: opts.method || 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const text = await res.text();
    let body = null;
    try { body = text ? JSON.parse(text) : null; } catch { body = null; }
    if (!res.ok) {
        const err = new Error((body && (body.error || body.message)) || `HTTP ${res.status}`);
        err.status = res.status;
        err.body = body;
        throw err;
    }
    return body || {};
}

/** Render the 403 unlock affordance instead of an empty panel. */
function gated(host) {
    clear(host);
    host.appendChild(Alert({
        tone: 'warning',
        title: t('common.unauthorized'),
        description: t('admin.unlock_help'),
        actions: [Button({
            size: 'sm', iconName: 'unlock', label: t('admin.unlock'),
            onClick: () => window.RevoApShell?.showUnlockDialog(),
        })],
    }));
}

/** One error path for every panel: the message, plus the remedy when known. */
function failed(host, err) {
    clear(host);
    if (err && err.status === 403) { gated(host); return; }
    host.appendChild(Alert({
        tone: 'destructive',
        title: t('common.error'),
        description: String((err && err.message) || err || t('common.unknown_error')),
        actions: [Button({ size: 'sm', variant: 'outline', iconName: 'refresh', label: t('common.retry'), onClick: () => host.__remount?.() })],
    }));
}

/** Register a panel: mount on first show, re-mount on locale change. */
function panel(rootId, loader) {
    const root = document.getElementById(rootId);
    if (!root) return;
    const mount = async () => {
        root.__remount = mount;
        clear(root);
        root.classList.add('stack');
        root.appendChild(SkeletonBlock({ rows: 5 }));
        try {
            clear(root);
            root.classList.add('stack');
            await loader(root);
        } catch (err) {
            failed(root, err);
        }
    };
    mount();
}

/* =====================================================================
 * System
 * =================================================================== */

export function mountSystem(rootId = 'oc-system-root') {
    panel(rootId, async (root) => {
        const r = await api('/api/admin/system');

        root.appendChild(Card({
            eyebrow: t('nav.admin'),
            title: t('system.title'),
            description: t('system.subtitle'),
            actions: [Button({ variant: 'outline', size: 'sm', iconName: 'refresh', label: t('common.refresh'), onClick: () => root.__remount() })],
        }, verdicts(r)));

        // --- headline tiles -------------------------------------------
        const php = r.php || {};
        const ext = r.extensions || {};
        const opcache = r.opcache || {};
        const disk = r.disk || {};
        const mem = r.memory || {};
        const sec = r.security || {};

        const tiles = h('div', { class: 'grid gap-3 grid-cols-2 md:grid-cols-3 xl:grid-cols-6' });
        tiles.appendChild(Stat({
            label: t('system.php_version'), value: php.version || '—', icon: 'cpu',
            hint: php.meets_required ? t('system.php_version_ok') : t('system.php_version_bad', { version: php.required || '^8.5' }),
        }));
        tiles.appendChild(Stat({ label: t('system.sapi'), value: php.sapi || '—', icon: 'server' }));
        tiles.appendChild(Stat({
            label: t('system.opcache'),
            value: opcache.enabled ? t('common.on') : t('common.off'),
            icon: 'zap',
            hint: typeof opcache.hit_rate === 'number' ? t('system.opcache_stats', { rate: formatPercent(opcache.hit_rate, 1), used: formatBytes(opcache.used_bytes || 0), size: formatBytes((opcache.used_bytes || 0) + (opcache.free_bytes || 0)) }) : null,
        }));
        tiles.appendChild(Stat({
            label: t('system.disk'),
            value: disk.available ? formatBytes(disk.free_bytes || 0) : '—',
            icon: 'hardDrive',
            hint: disk.available ? t('system.disk_free', { free: formatBytes(disk.free_bytes || 0), total: formatBytes(disk.total_bytes || 0), mount: '.' }) : null,
        }));
        tiles.appendChild(Stat({
            label: t('system.memory_usage'),
            value: formatBytes(mem.used_bytes || 0),
            icon: 'activity',
            hint: mem.limit_bytes ? `${t('system.memory_limit')}: ${formatBytes(mem.limit_bytes)}` : null,
        }));
        tiles.appendChild(Stat({
            label: t('admin.health'),
            value: String((r.verdicts || []).filter((v) => v.severity === 'critical').length),
            icon: 'shield',
            hint: (r.verdicts || []).length === 0 ? t('admin.health_ok') : t('admin.health_degraded', { count: (r.verdicts || []).length }),
        }));
        root.appendChild(tiles);

        // --- PHP + environment -----------------------------------------
        const iniRows = Object.entries(r.ini || {}).map(([k, v]) => [k, String(v)]);
        root.appendChild(Card({ title: t('system.environment') },
            definitionGrid([
                [t('system.php_version'), `${php.version || '—'} (${php.required || ''}) ${php.meets_required ? '✓' : '✗'}`],
                [t('system.os'), php.uname || php.os || '—'],
                [t('system.timezone'), php.timezone || '—'],
                [t('system.uptime'), (r.load && r.load.available) ? `${r.load['1']} / ${r.load['5']} / ${r.load['15']}${r.load.cores ? ` (${r.load.cores} cores)` : ''}` : '—'],
                [t('system.extensions'), `${(ext.rows || []).filter((e) => e.present).length} / ${(ext.rows || []).length}`],
                [t('system.paths'), t('system.path_root')],
            ]),
            iniRows.length ? h('details', { class: 'stack-sm' },
                h('summary', { class: 'label cursor-pointer', text: t('common.details') }),
                table(['key', 'value'], iniRows)) : null,
        ));

        // --- extensions --------------------------------------------------
        const extRows = (ext.rows || []).map((e) => [
            e.name,
            e.present ? t('common.ok') : (e.tier === 'required' ? t('common.failed') : t('common.warning')),
            e.tier,
            e.present ? '' : e.impact,
        ]);
        root.appendChild(Card({
            title: t('system.extensions'),
            description: (ext.missing_required || []).length
                ? t('system.extension_missing', { name: (ext.missing_required || []).join(', '), impact: '' })
                : null,
        }, table([t('common.name'), t('common.status'), t('common.type'), t('system.extension_missing', { name: '', impact: '' }).replace(/:.*/, '')], extRows,
            (e) => (e[1] === t('common.ok') ? null : (e[2] === 'required' ? 'destructive' : 'warning')))));

        // --- paths -------------------------------------------------------
        const pathRows = Object.entries(r.paths || {}).map(([name, info]) => [
            t(`system.path_${name}`) === `system.path_${name}` ? name : t(`system.path_${name}`),
            info.exists ? t('common.ok') : t('common.failed'),
            info.writable ? t('system.writable') : t('system.not_writable'),
            info.mode || '—',
        ]);
        root.appendChild(Card({ title: t('system.paths') },
            table([t('common.name'), t('common.status'), t('system.writable'), t('common.size')], pathRows)));

        // --- security posture --------------------------------------------
        root.appendChild(Card({ title: t('security.title'), description: t('security.headers_help') },
            definitionGrid([
                [t('admin.unlock'), sec.admin_protection ? (sec.admin_configured ? t('common.enabled') : t('admin.token_missing')) : t('admin.not_protected')],
                ['Content-Security-Policy', t('security.csp_ok')],
                ['Strict-Transport-Security', (sec.hsts_max_age > 0) ? `${sec.hsts_max_age}s` : t('security.hsts_off')],
                [t('system.environment'), sec.debug_mode ? t('system.env_development') : t('system.env_production')],
                [t('security.sessions'), `${sec.session_httponly ? 'HttpOnly ' : ''}${sec.session_secure ? 'Secure ' : ''}${sec.session_samesite || ''}`.trim() || '—'],
            ]),
            sec.debug_mode ? Alert({ tone: 'warning', title: t('system.env_warning') }) : null,
        ));

        // --- build -------------------------------------------------------
        const build = r.build || {};
        root.appendChild(Card({ title: t('system.build_id'), description: t('system.build_id_help') },
            definitionGrid([
                [t('app.version'), build.app_version || '—'],
                ['assets/dist/revoap.css', build.stylesheet_present ? formatBytes(build.styleableheet_bytes || 0) : t('common.failed')],
                [t('system.config_source'), 'config.php + var/config/runtime.json'],
                [t('common.updated'), build.newest_core_at ? formatDateTime(build.newest_core_at) : '—'],
            ])));
    });
}

/** The verdict list: the part of the report an operator actually reads. */
function verdicts(r) {
    const list = r.verdicts || [];
    if (list.length === 0) {
        return Alert({ tone: 'success', title: t('admin.health_ok'), description: t('security.no_recommendations') });
    }
    const wrap = h('div', { class: 'stack-sm' });
    for (const v of list) {
        const tone = v.severity === 'critical' ? 'destructive' : v.severity === 'warning' ? 'warning' : 'info';
        const label = t(v.key) === v.key ? v.key : t(v.key);
        wrap.appendChild(Alert({
            tone,
            title: label,
            description: v.detail || null,
            dismissible: v.severity === 'info',
        }));
    }
    return wrap;
}

/** A two-column definition grid. */
function definitionGrid(pairs) {
    const grid = h('div', { class: 'grid gap-x-6 gap-y-2 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' });
    for (const [label, value] of pairs) {
        if (value === null || value === undefined) continue;
        grid.appendChild(h('div', { class: 'row-between gap-3 py-1 border-b border-border' },
            h('span', { class: 'xs muted shrink-0', text: label }),
            h('span', { class: 'text-sm mono truncate text-end', text: String(value), title: String(value) })));
    }
    return grid;
}

/** @param {Array<[string,string]>} rows */
function table(headers, rows, toneFor = null) {
    if (!rows.length) return EmptyState({ iconName: 'folderOpen', title: t('common.empty'), compact: true });
    const el = h('table', { class: 'table table-compact' });
    el.appendChild(h('thead', {}, h('tr', {}, headers.map((hdr) => h('th', { text: hdr })))));
    const tbody = h('tbody');
    for (const row of rows) {
        const tone = toneFor ? toneFor(row) : null;
        tbody.appendChild(h('tr', {}, row.map((cell, i) => {
            const td = h('td', { class: i === 0 ? 'mono xs' : 'xs' });
            if (i === 1 && tone) td.appendChild(Badge(String(cell), { tone }));
            else td.textContent = String(cell ?? '');
            return td;
        })));
    }
    el.appendChild(tbody);
    return h('div', { class: 'table-wrap max-h-[24rem] overflow-y-auto' }, el);
}

/* =====================================================================
 * Feature flags
 * =================================================================== */

export function mountFlags(rootId = 'oc-flags-root') {
    panel(rootId, async (root) => {
        const r = await api('/api/admin/flags');
        const flags = r.flags || {};
        const keys = Object.keys(flags);

        const body = h('div', { class: 'stack-sm' });
        if (keys.length === 0) {
            body.appendChild(EmptyState({ iconName: 'flag', title: t('common.empty'), description: t('feature_flags.subtitle') }));
        }
        for (const key of keys) {
            const f = flags[key];
            const toggle = Switch({
                checked: !!f.enabled,
                label: key,
                description: (t(f.description_key) === f.description_key ? '' : t(f.description_key)) + (f.overridden ? '' : ` · ${t('common.default')}`),
                onChange: async (checked) => {
                    try {
                        await api('/api/admin/flags', { method: 'POST', body: { flag: key, enabled: checked, rollout: f.rollout, percent: f.percent } });
                        Toast.success(t('feature_flags.saved'), key);
                    } catch (err) {
                        Toast.error(t('common.error'), String(err.message || err));
                        // Revert: leaving the switch on while the write failed is
                        // a lie about the state of the system.
                        toggle.querySelector('input').checked = !checked;
                    }
                },
            });
            const row = h('div', { class: 'row-between gap-3 p-2 rounded-md border border-border bg-card' });
            row.appendChild(h('div', { class: 'min-w-0 flex-1' }, toggle));
            const right = h('div', { class: 'row gap-2 shrink-0' });
            if (f.overridden) right.appendChild(Badge(t('common.current'), { tone: 'info' }));
            right.appendChild(Badge(t(`feature_flags.rollout_${f.rollout}`) === `feature_flags.rollout_${f.rollout}` ? f.rollout : t(`feature_flags.rollout_${f.rollout}`), { tone: 'outline' }));
            row.appendChild(right);
            body.appendChild(row);
        }

        root.appendChild(Card({
            title: t('feature_flags.title'),
            description: t('feature_flags.subtitle'),
            actions: [
                Button({ variant: 'outline', size: 'sm', iconName: 'rotateCcw', label: t('feature_flags.reset'), onClick: async () => {
                    const ok = await Confirm({ title: t('feature_flags.reset'), description: t('common.confirm_action'), confirmLabel: t('common.reset'), danger: true });
                    if (!ok) return;
                    try { await api('/api/admin/flags/reset', { method: 'POST' }); Toast.success(t('common.saved')); root.__remount(); }
                    catch (err) { Toast.error(t('common.error'), String(err.message || err)); }
                } }),
                Button({ variant: 'ghost', size: 'sm', iconName: 'refresh', label: t('common.refresh'), onClick: () => root.__remount() }),
            ],
        }, body));
    });
}

/* =====================================================================
 * Maintenance mode
 * =================================================================== */

export function mountMaintenance(rootId = 'oc-maintenance-root') {
    panel(rootId, async (root) => {
        const s = await api('/api/admin/maintenance');

        const enabled = Switch({ checked: !!s.enabled, label: s.enabled ? t('maintenance.disable') : t('maintenance.enable') });
        const message = Textarea({ value: s.message || '', rows: 3, placeholder: t('maintenance.message') });
        const eta = Input({ value: s.eta || '', placeholder: t('maintenance.eta'), class: 'input-sm' });
        const allowIps = Textarea({ value: (s.allow_ips || []).join('\n'), rows: 4, mono: true, placeholder: '127.0.0.1\n10.0.0.0/8' });

        const save = Button({
            variant: s.enabled ? 'default' : 'destructive', iconName: 'save', label: t('common.save'),
            onClick: async () => {
                const want = enabled.querySelector('input').checked;
                if (want) {
                    const ok = await Confirm({
                        title: t('maintenance.enable'),
                        description: t('maintenance.banner'),
                        confirmLabel: t('maintenance.enable'),
                        danger: true,
                    });
                    if (!ok) return;
                }
                try {
                    const res = await api('/api/admin/maintenance', {
                        method: 'POST',
                        body: {
                            enabled: want,
                            message: message.value,
                            eta: eta.value,
                            allow_ips: allowIps.value.split(/[\r\n,]+/).map((x) => x.trim()).filter(Boolean),
                        },
                    });
                    if (res.ok === false) throw new Error(res.error || 'persist_failed');
                    Toast.success(want ? t('maintenance.enabled_ok') : t('maintenance.disabled_ok'));
                    root.__remount();
                } catch (err) {
                    Toast.error(t('common.error'), String(err.message || err));
                }
            },
        });

        root.appendChild(Card({
            title: t('maintenance.enable'),
            description: t('maintenance.banner'),
            actions: [save],
        },
            s.enabled ? Alert({ tone: 'warning', title: t('maintenance.banner') }) : null,
            h('div', {}, enabled),
            Separator(),
            Field({ label: t('maintenance.message'), control: message, description: t('maintenance.message') }),
            h('div', { class: 'form-row form-row-2' },
                Field({ label: t('maintenance.eta'), control: eta }),
                Field({ label: t('maintenance.allow_ips'), control: allowIps, description: t('security.proxies_help') }),
            ),
        ));
    });
}

/* =====================================================================
 * Localization admin
 * =================================================================== */

export function mountLocalization(rootId = 'oc-localization-root') {
    panel(rootId, async (root) => {
        const r = await api('/api/admin/localization');
        const locales = r.locales || [];

        const rows = locales.map((l) => [
            l.code, l.native_name, formatNumber(l.keys),
            l.coverage >= 1 ? t('localization_admin.complete') : t('localization_admin.incomplete', { count: l.missing_total }),
            l.dir,
            l.writable ? t('system.writable') : t('system.not_writable'),
        ]);

        const defaultSelect = Select(
            locales.map((l) => ({ value: l.code, label: `${l.native_name} (${l.code})` })),
            { value: r.default, class: 'select-sm w-auto' },
        );

        root.appendChild(Card({
            title: t('localization_admin.title'),
            description: t('localization_admin.subtitle'),
            actions: [
                Button({ variant: 'outline', size: 'sm', iconName: 'save', label: t('localization_admin.default_locale'), onClick: async () => {
                    try {
                        const res = await api('/api/admin/localization/default', { method: 'POST', body: { locale: defaultSelect.value } });
                        if (res.ok === false) throw new Error(res.error || 'persist_failed');
                        Toast.success(t('common.saved'), defaultSelect.value);
                    } catch (err) { Toast.error(t('common.error'), String(err.message || err)); }
                } }),
                Button({ variant: 'ghost', size: 'sm', iconName: 'refresh', label: t('localization_admin.reload_catalogs'), onClick: async () => {
                    try { await api('/api/i18n/reload', { method: 'POST' }); Toast.success(t('localization_admin.reloaded')); root.__remount(); }
                    catch (err) { Toast.error(t('common.error'), String(err.message || err)); }
                } }),
            ],
        },
            h('div', { class: 'form-row form-row-2' },
                Field({ label: t('localization_admin.default_locale'), control: defaultSelect, description: t('localization_admin.default_locale_help') }),
                Field({ label: t('locale.label'), control: Input({ value: `${r.active} (${t('localization_admin.detect_from_browser')}: ${r.negotiated})`, readonly: true, class: 'input-sm' }) }),
            ),
            table([t('localization_admin.locale'), t('common.name'), t('localization_admin.keys'), t('localization_admin.coverage'), 'dir', t('system.writable')],
                rows, (row) => (row[3] === t('localization_admin.complete') ? 'success' : 'warning')),
            locales.some((l) => l.missing_total > 0)
                ? Alert({ tone: 'warning', title: t('localization_admin.lint_failed', { count: locales.reduce((a, l) => a + l.missing_total, 0) }), description: 'php bin/revoap-i18n.php lint' })
                : Alert({ tone: 'success', title: t('localization_admin.lint_ok') }),
        ));

        // Per-locale detail, lazy: the missing-key lists can be long.
        for (const l of locales) {
            if (!l.missing_total && !(l.extra || []).length) continue;
            root.appendChild(Card({ title: `${l.native_name} (${l.code})`, description: `${l.missing_total} ${t('localization_admin.missing').toLowerCase()}` },
                h('div', { class: 'code-block', attrs: { 'data-wrap': 'true' } }, txt((l.missing || []).join('\n') || '—'))));
        }
    });
}

/* =====================================================================
 * Audit trail
 * =================================================================== */

export function mountAudit(rootId = 'oc-audit-root') {
    panel(rootId, async (root) => {
        const state = { page: 1, filter: {} };

        const tbody = h('tbody');
        const el = h('table', { class: 'table table-compact' });
        el.appendChild(h('thead', {}, h('tr', {},
            [t('audit.when'), t('audit.actor'), t('audit.action'), t('audit.target'), t('audit.result'), '']
                .map((hdr) => h('th', { text: hdr })))));
        el.appendChild(tbody);

        const search = Input({ type: 'search', class: 'input-sm', placeholder: t('common.search_placeholder') });
        const actionFilter = Select([{ value: '', label: t('common.all') }], { class: 'select-sm w-auto' });
        const resultFilter = Select([
            { value: '', label: t('common.all') },
            { value: 'ok', label: t('audit.result_ok') },
            { value: 'denied', label: t('audit.result_denied') },
            { value: 'error', label: t('audit.result_error') },
        ], { class: 'select-sm w-auto' });
        const counter = h('span', { class: 'xs muted tabular' });

        const paint = async () => {
            const params = new URLSearchParams({ page: String(state.page), per_page: '50' });
            for (const [k, v] of Object.entries(state.filter)) if (v) params.set(k, String(v));
            let r;
            try { r = await api(`/api/audit?${params.toString()}`); }
            catch (err) { failed(root, err); return; }

            clear(tbody);
            const entries = r.entries || [];
            if (entries.length === 0) {
                tbody.appendChild(h('tr', {}, h('td', { colspan: 6, class: 'table-empty', text: t('audit.no_entries') })));
            }
            for (const e of entries) {
                const tone = e.result === 'ok' ? 'success' : e.result === 'denied' ? 'warning' : 'destructive';
                const detail = Button({
                    variant: 'ghost', size: 'sm', iconName: 'eye', label: t('common.details'),
                    onClick: () => showEntry(e),
                });
                tbody.appendChild(h('tr', {},
                    h('td', { class: 'xs tabular num', text: e.created_at ? formatDateTime(e.created_at) : '—', title: e.created_at ? timeAgo(e.created_at) : '' }),
                    h('td', { class: 'xs mono truncate max-w-[10rem]', text: e.actor_label || t('audit.actor_anonymous'), title: e.actor_label || '' }),
                    h('td', { class: 'xs mono', text: e.action }),
                    h('td', { class: 'xs truncate max-w-[12rem]', text: [e.target_type, e.target_id].filter(Boolean).join(' · ') || '—', title: [e.target_type, e.target_id].filter(Boolean).join(' · ') }),
                    h('td', {}, Badge(t(`audit.result_${e.result}`) === `audit.result_${e.result}` ? e.result : t(`audit.result_${e.result}`), { tone })),
                    h('td', {}, detail),
                ));
            }

            // The action filter is populated from what is actually present, so
            // it cannot offer a value that returns nothing.
            const known = r.actions || [];
            if (known.length && actionFilter.options.length <= 1) {
                for (const a of known) actionFilter.appendChild(h('option', { value: a, text: a }));
            }
            counter.textContent = t('common.showing', {
                from: formatNumber(entries.length ? (state.page - 1) * 50 + 1 : 0),
                to: formatNumber((state.page - 1) * 50 + entries.length),
                total: formatNumber(r.total || 0),
            });
        };

        const showEntry = (e) => {
            const content = [
                definitionGrid([
                    [t('audit.when'), e.created_at ? formatDateTime(e.created_at) : '—'],
                    [t('audit.actor'), `${e.actor_label || '—'} (${e.actor_type || '—'})`],
                    [t('audit.action'), e.action],
                    [t('audit.target'), [e.target_type, e.target_id].filter(Boolean).join(' · ') || '—'],
                    [t('audit.result'), e.result],
                    [t('audit.ip'), e.ip || '—'],
                    ['request', e.request_id || '—'],
                    ['prev_hash', (e.prev_hash || '').slice(0, 16) + '…'],
                    ['entry_hash', (e.entry_hash || '').slice(0, 16) + '…'],
                ]),
                e.detail ? Field({ label: t('common.details'), control: Textarea({ value: e.detail, rows: 2, readonly: true, mono: true }) }) : null,
                e.before ? h('div', { class: 'stack-sm' }, h('div', { class: 'label', text: t('audit.before') }), h('div', { class: 'code-block', attrs: { 'data-wrap': 'true' } }, txt(JSON.stringify(e.before, null, 2)))) : null,
                e.after ? h('div', { class: 'stack-sm' }, h('div', { class: 'label', text: t('audit.after') }), h('div', { class: 'code-block', attrs: { 'data-wrap': 'true' } }, txt(JSON.stringify(e.after, null, 2)))) : null,
            ];
            Dialog({ title: t('audit.title'), content, size: 'lg' });
        };

        search.addEventListener('input', debounce(() => { state.filter.q = search.value.trim(); state.page = 1; paint(); }, 250));
        actionFilter.addEventListener('change', () => { state.filter.action = actionFilter.value; state.page = 1; paint(); });
        resultFilter.addEventListener('change', () => { state.filter.result = resultFilter.value; state.page = 1; paint(); });

        root.appendChild(Card({
            title: t('audit.title'),
            description: t('audit.subtitle'),
            actions: [
                counter,
                Button({ variant: 'outline', size: 'sm', iconName: 'shield', label: t('audit.verify'), onClick: async () => {
                    try {
                        const v = await api('/api/audit/verify');
                        if (v.intact) Toast.success(t('audit.integrity_ok', { count: formatNumber(v.checked) }));
                        else Toast.error(t('audit.integrity_broken', { index: formatNumber((v.first_break && v.first_break.id) || 0) }), v.first_break ? v.first_break.reason : (v.error || ''));
                    } catch (err) { Toast.error(t('common.error'), String(err.message || err)); }
                } }),
                Button({ variant: 'ghost', size: 'sm', iconName: 'download', label: t('audit.export_csv'), onClick: () => { window.location.assign('/api/audit/export?format=csv'); } }),
                Button({ variant: 'ghost', size: 'sm', iconName: 'refresh', label: t('common.refresh'), onClick: paint }),
            ],
        },
            Alert({ tone: 'info', title: t('audit.integrity'), description: t('audit.integrity_help') }),
            h('div', { class: 'row gap-2 wrap' }, search, actionFilter, resultFilter),
            h('div', { class: 'table-wrap max-h-[36rem] overflow-y-auto' }, el),
            h('div', { class: 'row-between' },
                Button({ variant: 'outline', size: 'sm', iconName: 'chevronLeft', label: t('common.previous'), onClick: () => { if (state.page > 1) { state.page -= 1; paint(); } } }),
                Button({ variant: 'outline', size: 'sm', iconName: 'chevronRight', label: t('common.next'), onClick: () => { state.page += 1; paint(); } }),
            ),
        ));
        await paint();
    });
}

/* =====================================================================
 * Boot: mount whichever panels are in the document.
 * =================================================================== */

function init() {
    const map = [
        ['oc-system-root', mountSystem],
        ['oc-localization-root', mountLocalization],
        ['oc-audit-root', mountAudit],
    ];
    for (const [id, fn] of map) {
        const root = document.getElementById(id);
        if (!root) continue;
        // Mount lazily on first show: a panel that fetches on load would fire
        // three admin API calls on every page view for tabs most operators
        // never open. The tab machinery fires a click on [data-tab], so watch
        // for the panel becoming visible instead of guessing at ids.
        const observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting && !root.dataset.revoapMounted) {
                    root.dataset.revoapMounted = '1';
                    observer.disconnect();
                    fn(id);
                }
            }
        }, { rootMargin: '200px' });
        // A hidden panel never intersects, so also mount on first reveal by the
        // tab machinery (which removes the `hidden` attribute).
        const mo = new MutationObserver(() => {
            const panel = root.closest('[data-tab-panel]');
            if (panel && !panel.hidden && !root.dataset.revoapMounted) {
                root.dataset.revoapMounted = '1';
                mo.disconnect();
                observer.disconnect();
                fn(id);
            }
        });
        mo.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['hidden'] });
        observer.observe(root);
    }

    // Feature flags and maintenance live inside the System panel on narrow
    // installs and in their own panels where the operator enabled them.
    const flagsRoot = document.getElementById('oc-flags-root');
    if (flagsRoot) mountFlags('oc-flags-root');
    const maintRoot = document.getElementById('oc-maintenance-root');
    if (maintRoot) mountMaintenance('oc-maintenance-root');

    window.RevoApAdmin = Object.freeze({ mountSystem, mountFlags, mountMaintenance, mountLocalization, mountAudit });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();

export default { mountSystem, mountFlags, mountMaintenance, mountLocalization, mountAudit };
