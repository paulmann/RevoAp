// settings.js — admin Settings tab.
//
// 1. Probes /api/settings/status (no auth) to decide whether to render the
//    nav tab. The backend gates everything on the admin token; this client
//    just hides the tab when the feature is disabled.
// 2. Lock screen prompts for the admin token; on submit, hits
//    /api/settings/unlock (which sets an HttpOnly cookie). Subsequent
//    requests carry the cookie automatically.
// 3. Renders sections: Interface, Profile, Database, Migrations, Worker,
//    Cache, Runtime, Session.
//    Every form sends JSON. Secrets are NEVER fetched in plaintext after
//    save — the GET response only has masked fingerprints.
//
// v1.8.49 — the model-facing sections (Providers, Models, Domain models /
// consensus presets, Analyzer) moved to the Models tab (models.js). What
// is left here is host configuration: UI default, database, migrations,
// worker, cache, runtime flags and the session lock. The DOM/fetch
// helpers, the lock screen and the sticky section strip now come from the
// shared admin shell (admin-ui.js), because the Models tab needs exactly
// the same three things — and, crucially, the same unlock: the token
// lives only in the HttpOnly cookie, so unlocking either tab unlocks both.

import {
    el, clear, api, renderDisabledNotice, renderErrorNotice,
    createLockScreen, createSectionNav, adminProtected, renderProtectionNotice,
} from './admin-ui.js';

const TABS = document.querySelectorAll('[data-tab]');
const PANELS = document.querySelectorAll('[data-tab-panel]');
const ROOT = document.getElementById('oc-settings-root');
const SETTINGS_TAB = document.getElementById('oc-tab-settings');

let state = {
    status: { admin_configured: false, admin_unlocked: false },
    settings: null,
};

function activateTab(name) {
    TABS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    // Leaving the Settings tab tears the Worker card's 5s poller, the
    // Cache card's poller and the section strip's observer down;
    // re-entering re-renders the tab, which restarts them.
    if (name !== 'settings') {
        workerStopAutoRefresh();
        cacheStopAutoRefresh();
        settingsNav.stop();
    }
    if (name === 'settings') void loadAndRender();
}

TABS.forEach(t => {
    t.addEventListener('click', () => activateTab(t.dataset.tab));
});

async function probeStatus() {
    const { body } = await api('/api/settings/status');
    state.status = body || state.status;
    // Tab visibility tracks SettingsTab=true alone. Admin auth gates
    // the tab body (lock screen + "admin not configured" notice) but
    // never the tab button — operators must see the affordance even
    // before configuring REVOAP_ADMIN_TOKEN / .admin.key.
    const tabEnabled = state.status.tab_enabled !== false;
    SETTINGS_TAB.hidden = !tabEnabled;
}

async function loadAndRender() {
    // v1.8.60 — see models.js: with the gate off, no token is not a fault.
    if (adminProtected(state.status) && !state.status.admin_configured) {
        renderDisabled();
        return;
    }
    if (!state.status.admin_unlocked) {
        renderLocked();
        return;
    }
    const { ok, body, status } = await api('/api/settings');
    if (status === 401) {
        state.status.admin_unlocked = false;
        renderLocked();
        return;
    }
    if (!ok) {
        renderError(body?.message || 'Failed to load settings');
        return;
    }
    state.settings = body;
    renderSettings();
    // v1.8.60 — keep the "not protected by an admin key" disclosure on the
    // panel, not only in the server-rendered strip above it.
    renderProtectionNotice(ROOT, state.status);
}

function renderDisabled() {
    settingsNav.stop();
    renderDisabledNotice(ROOT, { flag: 'SettingsTab', feature: 'Settings' });
    // R-7: this screen is reached when SettingsTab is off, and it is also
    // the screen a brand-new install sees. The health checks are read-only
    // and the endpoint is public, so the first-run checklist is reachable
    // before any admin key exists; the actions it offers stay gated and
    // report the server's refusal.
    ROOT.append(sectionSystemHealth());
    void refreshSystemHealth();
}

function renderError(message) {
    settingsNav.stop();
    renderErrorNotice(ROOT, message);
}

function renderLocked() {
    settingsNav.stop();
    // R-7: same reason as renderDisabled() - the checks and the first-run
    // checklist are readable before the token is entered, and a repair
    // attempted here is answered by the gate, not by the panel.
    createLockScreen(ROOT, {
        title: 'Admin authentication required',
        description: 'The Settings panel manages provider keys, the database connection and runtime flags.',
        onState: (unlocked) => { state.status.admin_unlocked = unlocked; },
        onUnlocked: () => loadAndRender(),
    });
    ROOT.append(sectionSystemHealth());
    void refreshSystemHealth();
}

// ===== v1.8.47 — Settings section navigation (sticky strip) ============
//
// The Settings page is one long column of cards. The strip above it stays
// visible while scrolling, highlights the section currently under the
// reader and lets the operator jump between sections without hunting for
// a heading.
//
// The mechanics live in the shared factory (admin-ui.js::createSectionNav)
// because the Models tab carries an identical strip. The order below
// mirrors renderSettings(). Entries whose card is absent (a section
// switched off by config) or hidden are skipped, so the strip never
// advertises something that is not on the page.
const SETTINGS_SECTIONS = [
    { id: 'oc-iface-card',      title: 'Interface' },
    { id: 'oc-profile-card',    title: 'Profile' },
    { id: 'oc-database-card',   title: 'Database' },
    { id: 'oc-migrations-card', title: 'Migrations' },
    { id: 'oc-worker-card',     title: 'Worker' },
    { id: 'oc-system-health-card', title: 'System health' },
    { id: 'oc-cache-card',      title: 'Cache' },
    { id: 'oc-prompt-cache-card', title: 'Prompt caching' },
    { id: 'oc-runtime-card',    title: 'Runtime' },
    { id: 'oc-logout-card',     title: 'Logout' },
];

const settingsNav = createSectionNav({
    nav: document.getElementById('oc-settings-nav'),
    band: 0.45,
    sections: SETTINGS_SECTIONS,
});

/**
 * (Re)build the strip from the sections currently present in the DOM.
 * Called after every renderSettings() and whenever a section is shown or
 * hidden at runtime. A no-op — strip hidden — when no section is
 * available (locked page, all sections switched off by config).
 *
 * The implementation moved to admin-ui.js (the Models tab needs the same
 * strip); this thin wrapper is kept because the tab modules and the
 * migration banner reach for the documented name.
 */
export function refreshSettingsNav() {
    settingsNav.refresh();
}


function renderSettings() {
    if (!ROOT) return;
    workerStopAutoRefresh();
    cacheStopAutoRefresh();
    clear(ROOT);
    ROOT.append(
        el('h1', { text: 'Settings' }),
        el('div', { class: 'oc-alert oc-alert-warn' },
            'Database passwords are stored server-side. They are masked in this UI and never logged. ',
            'Treat the admin token like a root credential: rotate it if leaked.'),
        // v1.8.49 — Providers / Models / Domain models / Analyzer moved to
        // the Models tab (models.js). The card ids they used are unchanged
        // and travel with them, so nothing that anchors to #oc-domain-models
        // breaks — only the tab it is reached through.
        sectionInterface(),
        sectionProfile(),
        sectionDatabase(),
        sectionMigrations(),
        sectionWorker(),
        sectionSystemHealth(),
        sectionCache(),
        sectionPromptCaching(),
        sectionRuntime(),
        sectionLogout(),
    );
    // Lazy-load profile data after the DOM is mounted; failures surface
    // inline so the rest of the Settings UI keeps working.
    void hydrateProfile();
    // v1.8.44 — starts the 5s Worker poller, gated on the card being
    // visible (IntersectionObserver) and the tab not being hidden.
    const workerCard = document.getElementById('oc-worker-card');
    if (workerCard) workerStartAutoRefresh(workerCard);
    // v1.8.47 — the Cache card polls far less often (its numbers change
    // only when a request is cached), same visibility gate.
    const cacheCard = document.getElementById('oc-cache-card');
    if (cacheCard) cacheStartAutoRefresh(cacheCard);
    // R-7 — the System health card is read once when the tab renders, and
    // afterwards only when the operator asks. It is deliberately not
    // polled: its facts change on a deploy or a migration, not per second.
    if (document.getElementById('oc-system-health-card')) void refreshSystemHealth();
    // v1.8.47 — build the section strip once the cards exist.
    refreshSettingsNav();
}

// ===== v1.8.33 — Interface (default theme) =============================
const THEME_OPTIONS = [
    { value: 'system', label: 'Follow system', desc: 'Use the visitor\'s OS preference (default).' },
    { value: 'light',  label: 'Light',         desc: 'Force light theme for first-time visitors.' },
    { value: 'dark',   label: 'Dark',          desc: 'Force dark theme for first-time visitors.' },
];

function sectionInterface() {
    const ui = (state.settings && state.settings.interface) || { theme: 'system' };
    const status = el('span', { class: 'oc-status' });
    const ids = { theme: 'iface-theme' };

    const themeField = el('div', { class: 'oc-field' },
        el('label', { for: ids.theme, text: 'Default theme' }),
        el('select', { id: ids.theme },
            ...THEME_OPTIONS.map(o => {
                const opt = el('option', { value: o.value, text: o.label });
                if (o.value === ui.theme) opt.selected = true;
                return opt;
            }),
        ),
        el('span', { class: 'oc-muted oc-small', text: 'First-time visitors land on this theme. The toggle button still lets each viewer flip the theme locally.' }),
    );

    return el('div', { class: 'oc-card', id: 'oc-iface-card' },
        el('h2', { text: 'Interface' }),
        el('p', { class: 'oc-muted oc-small' },
            'Controls the default theme for both the main UI and the read-only Shared page. ',
            'Persisted in ', el('span', { class: 'oc-mono', text: 'app_config' }), ' under ',
            el('span', { class: 'oc-mono', text: 'ui.theme' }), '. ',
            'Content width is no longer configurable — it follows the browser window.'),
        themeField,
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-primary', type: 'button',
                onClick: async () => {
                    const theme = document.getElementById(ids.theme).value;
                    status.textContent = 'Saving…';
                    const res = await api('/api/settings/interface', { method: 'POST', body: { theme } });
                    // v1.8.50 — `res.ok` alone is not proof the row landed; a
                    // body carrying an error is a failure even on HTTP 200.
                    if (res.ok && !res.body?.error && res.body?.ok !== false) {
                        // Apply immediately to the live document so the
                        // operator sees the change without a reload.
                        const root = document.documentElement;
                        root.setAttribute('data-ui-theme-default', theme);
                        // Force-apply theme only when explicit — keep
                        // localStorage choice winning if the operator
                        // had already toggled.
                        if (theme === 'light' || theme === 'dark') {
                            const stored = (() => { try { return localStorage.getItem('oc-theme'); } catch { return null; } })();
                            if (!stored) root.setAttribute('data-theme', theme);
                        }
                        status.textContent = 'Saved.';
                        await loadAndRender();
                    } else {
                        status.textContent = res.body?.message || `Failed (${res.status})`;
                    }
                },
            }, 'Save interface'),
            status,
        ),
    );
}

// ===== v1.8.32 — Profile ================================================
function sectionProfile() {
    return el('div', { class: 'oc-card', id: 'oc-profile-card' },
        el('h2', { text: 'Profile' }),
        el('p', { class: 'oc-muted oc-small', text: 'Per-user identity surface. The "System prompt addendum" is appended to every council prompt after the base file (prompts/system/default.md). Multi-user ready — currently scoped to the deterministic local user.' }),
        el('div', { id: 'oc-profile-root' },
            el('p', { class: 'oc-muted', text: 'Loading…' })),
    );
}

async function hydrateProfile() {
    const root = document.getElementById('oc-profile-root');
    if (!root) return;
    const { ok, body } = await api('/api/profile');
    clear(root);
    if (!ok) {
        root.append(el('div', { class: 'oc-alert oc-alert-error', text: body?.message || 'Failed to load profile' }));
        return;
    }
    const p = body.profile || {};
    const storage = body.storage || 'memory';
    const status = el('span', { class: 'oc-status' });
    const ids = {
        email: 'pf-email', phone: 'pf-phone', first: 'pf-first', last: 'pf-last',
        job: 'pf-job', bio: 'pf-bio', sys: 'pf-sys',
    };
    root.append(
        storage === 'memory'
            ? el('div', { class: 'oc-alert oc-alert-warn oc-small', text: 'Storage is in-memory (schema not imported). Import db/schema.sql (Settings → Database → ReCreate DB) to persist changes.' })
            : el('div', { class: 'oc-muted oc-small', text: 'Storage: database (table user_profiles)' }),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.first, text: 'First name' }),
                el('input', { type: 'text', id: ids.first, value: p.first_name || '', autocomplete: 'given-name', maxlength: '128' })),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.last, text: 'Last name' }),
                el('input', { type: 'text', id: ids.last, value: p.last_name || '', autocomplete: 'family-name', maxlength: '128' })),
        ),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.email, text: 'Email' }),
                el('input', { type: 'email', id: ids.email, value: p.email || '', autocomplete: 'email', maxlength: '191' })),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.phone, text: 'Phone' }),
                el('input', { type: 'tel', id: ids.phone, value: p.phone || '', autocomplete: 'tel', maxlength: '64' })),
        ),
        el('div', { class: 'oc-field' },
            el('label', { for: ids.job, text: 'Job title' }),
            el('input', { type: 'text', id: ids.job, value: p.job_title || '', maxlength: '191' })),
        el('div', { class: 'oc-field' },
            el('label', { for: ids.bio, text: 'About / bio' }),
            el('textarea', { id: ids.bio, rows: '3', maxlength: '8192' }, p.bio || '')),
        el('div', { class: 'oc-field' },
            el('label', { for: ids.sys, text: 'System prompt addendum (appended to every council prompt)' }),
            el('textarea', { id: ids.sys, rows: '5', maxlength: '8192', placeholder: 'e.g. "Always answer in technical English. Default to SI units."' }, p.system_prompt_addendum || '')),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-primary', type: 'button',
                onClick: async () => {
                    status.textContent = 'Saving…';
                    const payload = {};
                    for (const k of ['email','phone','first','last','job','bio','sys']) {
                        const node = document.getElementById(ids[k]);
                        payload[{ email:'email', phone:'phone', first:'first_name', last:'last_name', job:'job_title', bio:'bio', sys:'system_prompt_addendum' }[k]] = (node?.value ?? '').trim();
                    }
                    const res = await api('/api/profile', { method: 'POST', body: payload });
                    // v1.8.50 — a body carrying an error must render as a
                    // failure even when the HTTP call succeeded.
                    status.textContent = (res.ok && !res.body?.error && res.body?.ok !== false)
                        ? 'Saved.'
                        : (res.body?.message || `Failed (${res.status})`);
                },
            }, 'Save profile'),
            status,
        ),
    );
}

function sectionLogout() {
    return el('div', { class: 'oc-card', id: 'oc-logout-card' },
        el('h2', { text: 'Session' }),
        el('p', { class: 'oc-muted oc-small', text: 'Drop the admin cookie from this browser.' }),
        el('button', {
            class: 'oc-button-danger',
            type: 'button',
            onClick: async () => {
                await api('/api/settings/logout', { method: 'POST' });
                state.status.admin_unlocked = false;
                renderLocked();
            },
        }, 'Lock'),
    );
}

function sectionDatabase() {
    const d = state.settings.database || {};
    const p = d.parsed || {};
    const ids = {
        host: 'db-host', port: 'db-port', dbname: 'db-name',
        charset: 'db-charset', socket: 'db-socket',
        user: 'db-user', password: 'db-password',
    };
    const status = el('span', { class: 'oc-status' });

    return el('div', { class: 'oc-card', id: 'oc-database-card' },
        el('h2', { text: 'Database' }),
        el('p', { class: 'oc-muted oc-small' },
            'Current DSN: ', el('span', { class: 'oc-mono', text: d.dsn || '(empty)' })),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.host, text: 'Host' }),
                el('input', { type: 'text', id: ids.host, value: p.host || 'localhost' }),
            ),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.port, text: 'Port' }),
                el('input', { type: 'number', id: ids.port, value: String(p.port || 3306), min: '1', max: '65535' }),
            ),
        ),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.dbname, text: 'Database name' }),
                el('input', { type: 'text', id: ids.dbname, value: p.dbname || '' }),
            ),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.charset, text: 'Charset' }),
                el('input', { type: 'text', id: ids.charset, value: p.charset || 'utf8mb4' }),
            ),
        ),
        el('div', { class: 'oc-field' },
            el('label', { for: ids.socket, text: 'Unix socket (optional, replaces host/port)' }),
            el('input', { type: 'text', id: ids.socket, value: p.unix_socket || '' }),
        ),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.user, text: 'User' }),
                el('input', { type: 'text', id: ids.user, value: d.user || '' }),
            ),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.password, text: 'Password' }),
                el('input', { type: 'password', id: ids.password, placeholder: d.has_password ? '(unchanged)' : '(empty)', autocomplete: 'off' }),
                el('span', { class: 'oc-field-hint', text: d.has_password ? 'Set. Leave blank to keep.' : 'Not set.' }),
            ),
        ),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-primary',
                type: 'button',
                onClick: async () => {
                    status.textContent = 'Saving…';
                    const body = collectDb(ids);
                    const res = await api('/api/settings/database', { method: 'POST', body });
                    // v1.8.50 — the DSN goes through the verified writer, so a
                    // body with an error (or an explicit ok:false) is a failure
                    // even when the HTTP call succeeded. The detail block is
                    // shown verbatim.
                    const saved = res.ok && !res.body?.error && res.body?.ok !== false;
                    const detail = res.body?.stage
                        ? ` [${res.body.stage}${res.body.target ? ` — ${res.body.target}` : ''}]`
                        : '';
                    status.textContent = saved
                        ? `Saved. DSN: ${res.body?.dsn || ''}`
                        : `Failed: ${res.body?.message || res.status}${detail}`;
                    if (saved) {
                        document.getElementById(ids.password).value = '';
                        await loadAndRender();
                    }
                },
            }, 'Save DSN'),
            el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                onClick: async () => {
                    status.textContent = 'Testing…';
                    const res = await api('/api/settings/database/test', { method: 'POST', body: collectDb(ids) });
                    if (res.body?.ok) {
                        // v1.9.x - say where the credentials came from: an
                        // empty form field means "as configured", and the
                        // operator should see that the config was used.
                        const src = res.body.sources || {};
                        const note = src.password === 'config'
                            ? ' · пароль из конфига'
                            : (src.password === 'none' ? ' · пароль не задан' : '');
                        status.textContent = `OK — ${res.body.message}${res.body.server_version ? ' · MySQL ' + res.body.server_version : ''}${note}`;
                    } else {
                        const msg = res.body?.message || `HTTP ${res.status}`;
                        status.textContent = `Failed — ${msg}`;
                    }
                },
            }, 'Test connection'),
            status,
        ),
    );
}

function collectDb(ids) {
    return {
        host: document.getElementById(ids.host).value.trim(),
        port: parseInt(document.getElementById(ids.port).value, 10) || 3306,
        dbname: document.getElementById(ids.dbname).value.trim(),
        charset: document.getElementById(ids.charset).value.trim() || 'utf8mb4',
        unix_socket: document.getElementById(ids.socket).value.trim(),
        user: document.getElementById(ids.user).value.trim(),
        password: document.getElementById(ids.password).value,
    };
}

function sectionMigrations() {
    const status = el('span', { class: 'oc-status' });
    const checkStatus = el('span', { class: 'oc-status' });
    const tbody = el('tbody');
    const reportBox = el('div', { class: 'oc-schema-report', hidden: true });

    async function refresh() {
        clear(tbody);
        const res = await api('/api/settings/migrations');
        if (!res.ok) {
            tbody.append(el('tr', {}, el('td', { colspan: '5', text: res.body?.message || `HTTP ${res.status}` })));
            return;
        }
        const rows = res.body?.migrations || [];
        if (rows.length === 0) {
            tbody.append(el('tr', {}, el('td', { colspan: '5', text: 'No migration files in db/migrations/' })));
            return;
        }
        for (const r of rows) {
            // v1.8.27: each row renders the @table-version annotations
            // parsed from the file plus a per-file Apply button. The
            // button stays enabled when the file is already applied so
            // the operator can re-run version sync on demand.
            const tableVersions = r.tables && Object.keys(r.tables).length > 0
                ? Object.entries(r.tables).map(([t, v]) => `${t}=${v}`).join(', ')
                : '—';
            const result = el('span', { class: 'oc-status oc-small' });
            const applyBtn = el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                onClick: async () => {
                    result.textContent = 'Applying…';
                    applyBtn.disabled = true;
                    try {
                        const ar = await api('/api/settings/migrations/apply-one', { method: 'POST', body: { name: r.name } });
                        const b = ar.body || {};
                        if (b.ok && b.status === 'applied') {
                            const updates = b.version_updates && Object.keys(b.version_updates).length > 0
                                ? ' (' + Object.entries(b.version_updates).map(([t, v]) => `${t}→${v}`).join(', ') + ')'
                                : '';
                            result.textContent = 'Applied' + updates + '.';
                            announceMigrationsApplied();
                        } else if (b.ok && (b.status === 'already_applied' || b.status === 'skipped_benign')) {
                            result.textContent = b.status === 'already_applied' ? 'Already applied — version synced.' : 'Skipped (already in place).';
                            announceMigrationsApplied();
                        } else if (b.error) {
                            const stmt = b.error.statement ? ` stmt#${b.error.statement}` : '';
                            const sqlst = b.error.sqlstate ? ` [${b.error.sqlstate}]` : '';
                            result.textContent = `Error${stmt}${sqlst}: ${b.error.message || 'unknown error'}`;
                        } else {
                            result.textContent = `Error: ${b.message || ar.body?.message || ar.status}`;
                        }
                    } catch (e) {
                        result.textContent = `Error: ${e?.message || e}`;
                    } finally {
                        applyBtn.disabled = false;
                        await refresh();
                    }
                },
            }, r.applied ? 'Re-sync' : 'Apply');
            tbody.append(el('tr', {},
                el('td', { class: 'oc-mono', text: r.name }),
                el('td', { text: `${r.size} bytes` }),
                el('td', { class: 'oc-mono oc-small', text: tableVersions }),
                el('td', {}, el('span', { class: 'oc-pill ' + (r.applied ? 'oc-pill-ok' : 'oc-pill-warn'), text: r.applied ? 'applied' : 'pending' })),
                el('td', {}, applyBtn, ' ', result),
            ));
        }
    }

    function renderReport(report) {
        clear(reportBox);
        reportBox.hidden = false;
        if (!report) {
            reportBox.append(el('div', { class: 'oc-alert oc-alert-error', text: 'Empty report.' }));
            return;
        }
        const ready = !!report.schema_ready;
        const banner = el('div', {
            class: 'oc-alert ' + (ready ? 'oc-alert-ok' : 'oc-alert-warn'),
            text: ready
                ? 'Live database matches db/schema.sql.'
                : 'Schema drift detected — see details below. Click "Apply Migrations" to bring an existing DB up to date, or "ReCreate DB" to drop & rebuild.',
        });
        reportBox.append(banner);

        const meta = el('div', { class: 'oc-schema-meta' },
            el('div', {}, el('strong', { text: 'Schema file: ' }), el('span', { class: 'oc-mono', text: report.schema_file || '?' })),
            el('div', {}, el('strong', { text: 'Driver: ' }), el('span', { class: 'oc-mono', text: report.driver || '?' })),
            el('div', {}, el('strong', { text: 'Database: ' }), el('span', { class: 'oc-mono', text: report.database || '(unknown)' })),
            el('div', {}, el('strong', { text: 'Schema tables: ' }), String((report.schema_tables || []).length)),
            el('div', {}, el('strong', { text: 'Live tables: ' }), String((report.live_tables || []).length)),
        );
        reportBox.append(meta);

        const missing = report.missing_tables || [];
        if (missing.length > 0) {
            reportBox.append(el('div', { class: 'oc-schema-section' },
                el('h4', { text: `Missing tables (${missing.length})` }),
                el('ul', {}, ...missing.map(n => el('li', { class: 'oc-mono', text: n }))),
            ));
        }
        const extra = report.extra_tables || [];
        if (extra.length > 0) {
            reportBox.append(el('div', { class: 'oc-schema-section' },
                el('h4', { text: `Extra tables not in schema.sql (${extra.length})` }),
                el('ul', {}, ...extra.map(n => el('li', { class: 'oc-mono', text: n }))),
                el('p', { class: 'oc-muted oc-small', text: 'These are preserved by default — ReCreate keeps them untouched unless "Also drop extra tables" is checked.' }),
            ));
        }
        const diffs = report.table_diffs || {};
        const diffNames = Object.keys(diffs);
        if (diffNames.length > 0) {
            const wrap = el('div', { class: 'oc-schema-section' },
                el('h4', { text: `Table diffs (${diffNames.length})` }),
            );
            for (const name of diffNames) {
                const d = diffs[name] || {};
                const sub = el('div', { class: 'oc-schema-diff' }, el('strong', { class: 'oc-mono', text: name }));
                if ((d.missing_columns || []).length) {
                    sub.append(el('div', { text: 'Missing columns: ' + d.missing_columns.map(c => `${c.column} (${c.type || '?'})`).join(', ') }));
                }
                if ((d.incompatible_columns || []).length) {
                    for (const c of d.incompatible_columns) {
                        sub.append(el('div', { text: `Incompatible ${c.column}: ${(c.issues || []).join('; ')}` }));
                    }
                }
                if ((d.extra_columns || []).length) {
                    sub.append(el('div', { text: 'Extra columns: ' + d.extra_columns.join(', ') }));
                }
                wrap.append(sub);
            }
            reportBox.append(wrap);
        }
        // v1.8.27: surface schema-version mismatches separately from
        // structural drift so the operator sees both "table exists at
        // older version" and "table missing" without conflating them.
        const mismatches = report.version_mismatches || [];
        if (mismatches.length > 0 || report.config_table_present === false) {
            const wrap = el('div', { class: 'oc-schema-section' },
                el('h4', { text: `Schema versions (${mismatches.length} mismatch${mismatches.length === 1 ? '' : 'es'})` }),
            );
            if (report.config_table_present === false) {
                wrap.append(el('div', { class: 'oc-alert oc-alert-warn', text: 'The app_config table is missing. Apply migration 007_app_config.sql to enable schema-version tracking.' }));
            }
            if (report.expected_schema_version || report.live_schema_version) {
                wrap.append(el('div', { class: 'oc-mono oc-small' },
                    'Schema version — expected: ',
                    String(report.expected_schema_version || '?'),
                    ', live: ',
                    String(report.live_schema_version || '(unset)'),
                ));
            }
            if (mismatches.length > 0) {
                wrap.append(el('ul', {}, ...mismatches.map(m => el('li', { class: 'oc-mono oc-small',
                    text: `${m.table}: live=${m.current_version || '(none)'} expected=${m.expected_version} (${m.reason})`,
                }))));
            }
            reportBox.append(wrap);
        }
        const errs = report.errors || [];
        if (errs.length > 0) {
            reportBox.append(el('div', { class: 'oc-schema-section' },
                el('h4', { text: 'Errors' }),
                el('ul', {}, ...errs.map(e => el('li', { text: typeof e === 'string' ? e : JSON.stringify(e) }))),
            ));
        }
    }

    void refresh();

    return el('div', { class: 'oc-card', id: 'oc-migrations-card' },
        el('h2', { text: 'Database maintenance' }),
        el('p', { class: 'oc-muted oc-small' },
            'Compare the live database against ',
            el('span', { class: 'oc-mono', text: 'db/schema.sql' }),
            '. Apply Migrations replays files under ',
            el('span', { class: 'oc-mono', text: 'db/migrations/' }),
            ' (idempotent — "already exists" is treated as benign). ',
            el('strong', { text: 'ReCreate DB' }),
            ' is destructive: it drops every table defined in schema.sql and rebuilds them.'),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-primary',
                type: 'button',
                onClick: async () => {
                    checkStatus.textContent = 'Checking…';
                    reportBox.hidden = true;
                    const res = await api('/api/settings/database/check');
                    if (!res.ok) {
                        checkStatus.textContent = `Failed: ${res.body?.message || res.status}`;
                        return;
                    }
                    renderReport(res.body?.report || null);
                    const r = res.body?.report || {};
                    checkStatus.textContent = r.schema_ready
                        ? 'Schema OK.'
                        : `Drift — missing=${(r.missing_tables || []).length}, diffs=${Object.keys(r.table_diffs || {}).length}.`;
                },
            }, 'Check DB'),
            el('button', {
                class: 'oc-button-primary',
                type: 'button',
                onClick: async () => {
                    status.textContent = 'Applying…';
                    const res = await api('/api/settings/migrations/apply', { method: 'POST' });
                    const a = res.body?.applied || [];
                    const f = res.body?.failed || [];
                    if (res.body?.ok === true && f.length === 0) {
                        status.textContent = `Applied: ${a.length}. Skipped: ${(res.body.skipped || []).length}.`;

                        announceMigrationsApplied();
                    } else if (f.length > 0) {
                        const first = f[0] || {};
                        const where = first.name ? `${first.name}` : 'unknown file';
                        const stmt  = first.statement ? ` stmt#${first.statement}` : '';
                        const sqlst = first.sqlstate ? ` [${first.sqlstate}]` : '';
                        const msg   = first.message || res.body?.message || 'unknown error';
                        status.textContent = `Failed in ${where}${stmt}${sqlst}: ${msg}`;
                    } else {
                        status.textContent = `Failed: ${res.body?.message || res.status}`;
                    }
                    await refresh();
                },
            }, 'Apply Migrations'),
            el('button', {
                class: 'oc-button-danger',
                type: 'button',
                onClick: async () => {
                    if (!window.confirm(
                        'DESTRUCTIVE: ReCreate DB will DROP every RevoAp table defined in db/schema.sql '
                        + '(including council_sessions, council_steps, council_model_responses, pipeline_events, '
                        + 'model_response_cache, shared_sessions and schema_migrations) and recreate them from scratch.\n\n'
                        + 'All history, statistics, cached model responses and share tokens will be permanently lost. '
                        + 'Continue?'
                    )) return;
                    const typed = window.prompt('Type RECREATE (uppercase) to confirm the destructive operation:');
                    if (typed !== 'RECREATE') {
                        status.textContent = 'Cancelled.';
                        return;
                    }
                    status.textContent = 'Recreating…';
                    const res = await api('/api/settings/database/recreate', {
                        method: 'POST',
                        body: { confirm: 'RECREATE' },
                    });
                    if (res.body?.ok === true) {
                        const r = res.body.report || {};
                        status.textContent = `Dropped ${(r.dropped_tables || []).length}, created ${(r.created_tables || []).length}.`;
                    } else {
                        const errs = res.body?.report?.errors || [];
                        const first = errs[0] || {};
                        const phase = first.phase ? `[${first.phase}]` : '';
                        const where = first.file ? ` ${first.file}` : (first.table ? ` table=${first.table}` : '');
                        const stmt  = first.statement_index ? ` stmt#${first.statement_index}` : '';
                        const sqlst = first.sqlstate ? ` [${first.sqlstate}]` : '';
                        const msg   = first.message || res.body?.message || `HTTP ${res.status}`;
                        status.textContent = `Failed ${phase}${where}${stmt}${sqlst}: ${msg}`;
                    }
                    await refresh();
                    // Re-run the check so the operator sees the new state.
                    const verify = await api('/api/settings/database/check');
                    if (verify.ok) renderReport(verify.body?.report || null);
                },
            }, 'ReCreate DB'),
            checkStatus,
            status,
        ),
        reportBox,
        el('h3', { text: 'Migrations' }),
        el('table', { class: 'oc-migrations-table' },
            el('thead', {}, el('tr', {},
                el('th', { text: 'File' }),
                el('th', { text: 'Size' }),
                el('th', { text: 'Table versions' }),
                el('th', { text: 'Status' }),
                el('th', { text: 'Actions' }),
            )),
            tbody,
        ),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                onClick: async () => { status.textContent = 'Refreshing…'; await refresh(); status.textContent = ''; },
            }, 'Refresh'),
            el('button', {
                class: 'oc-button-secondary',
                type: 'button',
                onClick: async () => {
                    if (!window.confirm('Mark all current migration files as already-applied without running them?')) return;
                    status.textContent = 'Baselining…';
                    const res = await api('/api/settings/migrations/baseline', { method: 'POST' });
                    status.textContent = res.ok ? `Baselined ${(res.body.baselined || []).length} files.` : 'Failed.';
                    await refresh();
                },
            }, 'Baseline (mark applied)'),
            el('a', {
                class: 'oc-button-secondary',
                href: '/api/settings/export',
                target: '_blank',
                rel: 'noopener',
            }, 'Download schema dump'),
        ),
    );
}

// v1.8.44 — Worker card auto-refresh controller.
//
// The card polls /api/health/worker every 5s, but ONLY while it is
// actually on screen: an IntersectionObserver gates the fetch on the
// card being visible, the interval additionally skips hidden tabs, and
// the whole thing is torn down when the operator leaves the tab or the
// card is re-rendered. No background polling of a page nobody is
// looking at.
const WORKER_REFRESH_MS = 5000;

let workerCardCtl = { timer: null, observer: null, card: null, visible: false };

function workerStopAutoRefresh() {
    if (workerCardCtl.timer !== null) {
        clearInterval(workerCardCtl.timer);
        workerCardCtl.timer = null;
    }
    if (workerCardCtl.observer) {
        workerCardCtl.observer.disconnect();
        workerCardCtl.observer = null;
    }
    workerCardCtl.card = null;
    workerCardCtl.visible = false;
}

function workerStartAutoRefresh(card) {
    workerStopAutoRefresh();
    workerCardCtl.card = card;
    if (typeof IntersectionObserver === 'function') {
        workerCardCtl.observer = new IntersectionObserver((entries) => {
            const visible = entries.some((e) => e.isIntersecting);
            workerCardCtl.visible = visible;
            // Refresh immediately when the card scrolls into view so the
            // operator never reads a stale badge.
            if (visible) void refreshWorkerCard();
        }, { threshold: 0.01 });
        workerCardCtl.observer.observe(card);
    } else {
        workerCardCtl.visible = true;
        void refreshWorkerCard();
    }
    workerCardCtl.timer = setInterval(() => {
        if (document.hidden || !workerCardCtl.visible) return;
        if (!workerCardCtl.card || !document.body.contains(workerCardCtl.card)) {
            workerStopAutoRefresh();
            return;
        }
        void refreshWorkerCard();
    }, WORKER_REFRESH_MS);
}

// Refresh the snapshot as soon as the operator comes back to the tab.
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && workerCardCtl.visible) void refreshWorkerCard();
});

// v1.8.44 — Worker status presentation. `stale` renders as "degraded"
// (the classification predates the badge vocabulary) and the rest map
// 1:1; an unknown status never renders as healthy.
const WORKER_BADGES = {
    healthy:           { label: 'healthy',     cls: 'oc-pill-ok',    hint: 'Worker is running.' },
    idle:              { label: 'idle',        cls: 'oc-pill-ok',    hint: 'Worker process is alive with nothing to do.' },
    stale:             { label: 'degraded',    cls: 'oc-pill-warn',  hint: 'Last heartbeat is older than the stale threshold — the worker may be stuck or dead.' },
    not_running:       { label: 'not running', cls: 'oc-pill-error', hint: 'No worker process is running, so queued jobs will not be processed.' },
    migration_missing: { label: 'unknown',     cls: 'oc-pill-error', hint: 'The job queue table is missing, so worker state cannot be determined.' },
    unknown:           { label: 'unknown',     cls: 'oc-pill-warn',  hint: 'Worker state could not be determined.' },
};

function pad2(n) { return String(n).padStart(2, '0'); }

function formatUptime(totalSeconds) {
    if (totalSeconds == null || !Number.isFinite(Number(totalSeconds)) || Number(totalSeconds) < 0) return '—';
    const s = Math.floor(Number(totalSeconds));
    const days = Math.floor(s / 86400);
    const hms = `${pad2(Math.floor((s % 86400) / 3600))}:${pad2(Math.floor((s % 3600) / 60))}:${pad2(s % 60)}`;
    return days > 0 ? `${days}d ${hms}` : hms;
}

function formatAge(seconds) {
    if (seconds == null || !Number.isFinite(Number(seconds))) return '—';
    const s = Math.max(0, Math.floor(Number(seconds)));
    if (s < 60) return `${s}s`;
    if (s < 3600) return `${Math.floor(s / 60)}m ${pad2(s % 60)}s`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ${pad2(Math.floor((s % 3600) / 60))}m`;
    return `${Math.floor(s / 86400)}d ${Math.floor((s % 86400) / 3600)}h`;
}

function formatTimestamp(isoish) {
    if (!isoish || typeof isoish !== 'string') return '—';
    return isoish.replace('T', ' ').replace(/Z$/, ' UTC');
}

// Clipboard with a fallback for non-secure contexts (plain-http
// deployments where navigator.clipboard is unavailable).
async function copyText(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch { /* fall through to the legacy path */ }
    try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.append(ta);
        ta.select();
        const ok = document.execCommand('copy');
        ta.remove();
        return ok;
    } catch {
        return false;
    }
}

// v1.8.48 — "Last job" block for the Worker card.
//
// One row of everything an operator asks after a run: which models took
// part, how long it took, and whether it worked. Built entirely from the
// snapshot's `last_job` (server-side selection, see
// WorkerHealthService::lastJob) so the panel never reads a second endpoint
// to answer the same question.
//
// `outcome` — not `status` — drives the colour and the wording, because a
// job that ran to completion but whose final synthesis failed is an error
// from the operator's chair even though the queue row says `completed`.
//
// Never renders raw HTML: every value is a text node through el().
function renderLastJob(job) {
    if (!job || typeof job !== 'object') {
        return el('div', { class: 'oc-muted oc-small' },
            'No job has been enqueued yet — the queue is empty, so there is nothing to report. '
            + 'Submit a query to exercise the worker, or press Test above for a token-free self-check.');
    }

    const outcome = String(job.outcome || '');
    const isError = outcome === 'error';
    const pill = isError
        ? { cls: 'oc-pill-error', label: 'error' }
        : (outcome === 'success' ? { cls: 'oc-pill-ok', label: 'success' }
            : { cls: 'oc-pill-warn', label: outcome === 'queued' ? 'queued' : 'running' });

    // Duration: a finished job reports the span it actually took; a job
    // still in flight reports elapsed time and says so, so a live number is
    // never mistaken for a final one.
    let duration = '—';
    if (job.duration_s != null) {
        duration = job.duration_state === 'running'
            ? `running for ${formatAge(job.duration_s)}`
            : formatAge(job.duration_s);
    }

    const models = Array.isArray(job.models) ? job.models : [];
    const modelsCell = el('div', {});
    if (models.length === 0) {
        modelsCell.append(el('span', { class: 'oc-muted', text: 'not recorded' }));
    } else {
        for (const m of models) {
            modelsCell.append(el('div', { class: 'oc-small' },
                m.provider ? el('span', { class: 'oc-muted', text: `${m.provider} / ` }) : '',
                el('span', { class: 'oc-mono', text: String(m.model || '?') }),
            ));
        }
    }

    const lines = [el('div', { class: 'oc-worker-grid' },
        el('div', {}, el('strong', { text: 'Job: ' }),
            el('span', { class: 'oc-mono', text: '#' + String(job.job_id) }),
            job.session_id ? el('span', { class: 'oc-muted', text: ` — session #${job.session_id}` }) : ''),
        el('div', {}, el('strong', { text: 'Result: ' }),
            el('span', { class: `oc-pill ${pill.cls}`, text: pill.label }),
            job.status && job.status !== outcome
                ? el('span', { class: 'oc-muted oc-small', text: ` (queue status: ${job.status})` })
                : ''),
        el('div', {}, el('strong', { text: 'Started: ' }),
            textOr(job.started_at, '—'), job.started_at ? el('span', { class: 'oc-muted oc-small', text: ` (${formatAge(secondsSince(job.started_at))} ago)` }) : ''),
        el('div', {}, el('strong', { text: 'Finished: ' }), textOr(job.finished_at, '—')),
        el('div', {}, el('strong', { text: 'Duration: ' }), duration),
        el('div', {}, el('strong', { text: 'Attempts: ' }),
            `${job.attempts != null ? job.attempts : 0} / ${job.max_attempts != null ? job.max_attempts : '?'}`),
        el('div', {}, el('strong', { text: 'Models: ' }), modelsCell),
    )];

    if (job.provider_code) {
        lines.push(el('div', { class: 'oc-small' },
            el('strong', { text: 'Provider code: ' }),
            el('span', { class: 'oc-mono', text: String(job.provider_code) }),
        ));
    }
    if (job.error) {
        lines.push(el('div', { class: `oc-alert ${isError ? 'oc-alert-error' : 'oc-alert-warn'} oc-small` },
            String(job.error)));
    }
    if (job.kind === 'other') {
        lines.push(el('div', { class: 'oc-muted oc-small' },
            'The newest queue row is not a pipeline run (it has no selected models), so it is shown '
            + 'as-is rather than substituting an older one.'));
    }

    return el('div', {}, ...lines);
}

function textOr(value, fallback) {
    return value ? formatTimestamp(String(value)) : fallback;
}

/** Whole seconds between an ISO-ish stamp and now; null when unparsable. */
function secondsSince(isoish) {
    if (!isoish || typeof isoish !== 'string') return null;
    const t = Date.parse(isoish.replace(' ', 'T') + (isoish.endsWith('Z') ? '' : 'Z'));
    if (!Number.isFinite(t)) return null;
    return Math.max(0, Math.floor((Date.now() - t) / 1000));
}

// Renders one command row: monospace text + Copy button that reports its
// own result without moving the operator away from the card.
function commandRow(label, command) {
    const status = el('span', { class: 'oc-status oc-small' });
    return el('div', { class: 'oc-worker-cmd' },
        el('span', { class: 'oc-muted oc-small', text: `${label}: ` }),
        el('code', { class: 'oc-mono oc-small', text: command }),
        ' ',
        el('button', {
            class: 'oc-button-secondary oc-small', type: 'button',
            onClick: async () => {
                const ok = await copyText(command);
                status.textContent = ok ? 'copied' : 'copy failed — select the text';
                window.setTimeout(() => { status.textContent = ''; }, 2500);
            },
        }, 'Copy'),
        status,
    );
}

function sectionWorker() {
    return el('div', { class: 'oc-card', id: 'oc-worker-card' },
        el('h2', { text: 'Worker' }),
        el('p', { class: 'oc-muted oc-small' },
            'Live state of the durable job worker (',
            el('span', { class: 'oc-mono', text: 'bin/revoap-worker.php' }),
            '). Every worker upserts a row in ',
            el('span', { class: 'oc-mono', text: 'revoap_worker_presence' }),
            ' once a second, so "alive and idle" is distinguishable from "not started": a fresh row means '
            + 'running, a stale row means the process died, no row at all means it exited cleanly. '
            + 'This panel refreshes every 5 seconds while it is visible.',
        ),
        el('div', { id: 'oc-worker-live' },
            el('p', { class: 'oc-muted', text: 'Loading worker state…' })),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-secondary', type: 'button',
                onClick: async (e) => { e.target.disabled = true; await refreshWorkerCard(); e.target.disabled = false; },
            }, 'Refresh'),
            el('button', {
                class: 'oc-button-primary', type: 'button', id: 'oc-worker-test-btn',
                onClick: () => runWorkerSelfTest(),
            }, 'Test'),
            el('button', {
                class: 'oc-button-secondary', type: 'button', id: 'oc-worker-debug-btn',
                onClick: () => runWorkerDebug(),
            }, 'Debug ⚠'),
            el('span', { class: 'oc-status', id: 'oc-worker-action-status' }),
        ),
        el('div', { id: 'oc-worker-test-result' }),
        el('div', { id: 'oc-worker-debug-result' }),
    );
}

// Fetch + render the live block. Never throws: any failure renders an
// inline notice instead of breaking the rest of the Settings tab.
async function refreshWorkerCard() {
    const root = document.getElementById('oc-worker-live');
    if (!root) return;
    const res = await api('/api/health/worker');
    if (!res.ok || !res.body || typeof res.body !== 'object') {
        clear(root);
        root.append(el('div', { class: 'oc-alert oc-alert-error', text: `Failed to load worker state (HTTP ${res.status}).` }));
        return;
    }
    renderWorkerLive(root, res.body);
    void enrichWorkerJobs(res.body);
}

function renderWorkerLive(root, snap) {
    const badge = WORKER_BADGES[snap.worker_status] || WORKER_BADGES.unknown;
    const counts = snap.counts || {};
    const workers = Array.isArray(snap.running_workers) ? snap.running_workers : [];
    const presence = Array.isArray(snap.worker_presence) ? snap.worker_presence : [];
    const alive = Number(snap.workers_alive || 0);
    const appVersion = snap.app_version || '';

    clear(root);

    // ---- Status badge + plain-language summary ----------------------
    const summary = el('div', { class: 'oc-worker-summary' },
        el('span', { class: `oc-pill ${badge.cls}`, text: badge.label }),
        ' ',
        el('span', { class: 'oc-muted', text: badge.hint }),
    );
    if (snap.execution_mode === 'legacy') {
        summary.append(el('div', { class: 'oc-alert oc-alert-warn oc-small' },
            'Pipeline execution mode is "legacy" — jobs are not routed through the durable worker.'));
    }
    if (snap.presence_table_available === false && snap.jobs_table_available === true) {
        summary.append(el('div', { class: 'oc-alert oc-alert-warn oc-small' },
            'The presence table is missing, so uptime / version / "what is it doing" stay unavailable. '
            + 'Apply migration ', el('span', { class: 'oc-mono', text: '017_worker_presence.sql' }),
            ' (Settings → Database maintenance → Apply Migrations) and restart the worker.'));
    }
    const versions = Array.isArray(snap.worker_versions) ? snap.worker_versions : [];
    const mismatched = appVersion ? versions.filter((v) => v !== appVersion) : [];
    if (mismatched.length > 0) {
        summary.append(el('div', { class: 'oc-alert oc-alert-warn oc-small' },
            `Running worker(s) report version ${mismatched.join(', ')} but this deployment is ${appVersion}. `
            + 'Restart the worker (revoap-worker.service) so the deployed code is actually loaded — a stale '
            + 'process can silently keep using the previous payload/queue semantics.'));
    }
    // v1.8.51 — the deployed code versus the code the process is running.
    // A long-lived worker keeps the classes it loaded at start-up, so a
    // deploy can leave "new caller, old callee" inside one process; that
    // mix has no syntax error anywhere and used to surface only as a
    // nonsensical "Call to undefined method" hours into an investigation.
    // Only an explicit restart clears it, which is why this is a banner
    // rather than another line in the worker table.
    if (snap.code_state === 'stale') {
        const where = snap.code_newest_file
            ? el('span', { class: 'oc-mono oc-small', text: String(snap.code_newest_file) })
            : null;
        const banner = el('div', { class: 'oc-alert oc-alert-error', id: 'oc-worker-code-stale' },
            el('strong', { text: 'The worker is running stale code — restart it.' }),
            el('div', { class: 'oc-small' },
                snap.code_stale_reason
                    ? String(snap.code_stale_reason)
                    : 'The worker process was started before the current code was deployed.'),
            el('div', { class: 'oc-small' },
                where ? ['Newest code file: ', where, '. '] : [],
                'PHP loads each class once per process: a worker started before this deploy keeps the old '
                + 'classes, so freshly deployed callers fail with errors like ',
                el('span', { class: 'oc-mono', text: 'Call to undefined method …' }),
                ' while the queue looks healthy. Nothing but a restart clears it.'),
        );
        banner.append(commandRow('restart', 'sudo systemctl restart revoap-worker'));
        summary.append(banner);
    }

    root.append(summary);

    // ---- Workers ----------------------------------------------------
    root.append(el('h3', { text: `Worker processes (${alive} alive)` }));
    if (workers.length === 0) {
        const empty = el('div', { class: 'oc-alert oc-alert-error' },
            el('div', { text: 'Worker is not running — nothing is draining the job queue.' }),
            el('div', { class: 'oc-small', text: 'Start it with one of these commands:' }),
        );
        empty.append(commandRow('persistent', 'sudo systemctl start revoap-worker.service'));
        empty.append(commandRow('foreground', 'php bin/revoap-worker.php --loop --sleep=1'));
        empty.append(commandRow('one-shot', 'php bin/revoap-worker.php --once'));
        root.append(empty);
    } else {
        const table = el('table', { class: 'oc-worker-table' },
            el('thead', {}, el('tr', {},
                el('th', { text: 'Worker' }),
                el('th', { text: 'Status' }),
                el('th', { text: 'Doing now' }),
                el('th', { text: 'Uptime' }),
                el('th', { text: 'Version' }),
                el('th', { text: 'Mode' }),
                el('th', { text: 'Host / PID' }),
                el('th', { text: 'Jobs done' }),
                el('th', { text: 'Last heartbeat' }),
            )),
        );
        const tbody = el('tbody');
        for (const w of workers) {
            const isAlive = w.alive === true;
            const busy = Number(w.running || 0) > 0;
            const jobId = w.current_job_id != null ? w.current_job_id : null;
            const doing = busy
                ? el('span', { id: `oc-worker-doing-${w.worker_id}`, text: `job #${jobId != null ? jobId : '?'} — running` })
                : (jobId != null
                    ? el('span', { id: `oc-worker-doing-${w.worker_id}`, text: `job #${jobId} — starting` })
                    : el('span', { id: `oc-worker-doing-${w.worker_id}`, class: 'oc-muted', text: 'idle' }));
            const version = w.app_version || '—';
            const staleVersion = appVersion && w.app_version && w.app_version !== appVersion;
            tbody.append(el('tr', {},
                el('td', { class: 'oc-mono oc-small', text: w.worker_id || '?' }),
                el('td', {}, el('span', {
                    class: 'oc-pill ' + (isAlive ? 'oc-pill-ok' : 'oc-pill-error'),
                    text: isAlive ? (busy ? 'running' : 'idle') : 'crashed',
                })),
                el('td', {}, doing),
                el('td', { class: 'oc-mono oc-small', text: formatUptime(w.uptime_s) }),
                el('td', { class: 'oc-mono oc-small' + (staleVersion ? ' oc-worker-stale-version' : ''), text: String(version) }),
                el('td', { class: 'oc-mono oc-small', text: w.mode || '—' }),
                el('td', { class: 'oc-mono oc-small', text: `${w.host || '—'} / ${w.pid != null ? w.pid : '—'}` }),
                el('td', { class: 'oc-mono oc-small', text: String(w.jobs_done != null ? w.jobs_done : 0) }),
                el('td', { class: 'oc-mono oc-small', text: w.heartbeat_at ? `${formatTimestamp(w.heartbeat_at)} (${formatAge(w.heartbeat_age_s)} ago)` : '—' }),
            ));
        }
        table.append(tbody);
        root.append(table);
    }

    // Crashed workers: a stale presence row means the process died
    // mid-flight (SIGKILL / OOM / reboot) instead of exiting cleanly.
    // Rendered outside the branch above so a crash is reported even when
    // the worker list itself happens to be empty.
    for (const row of presence) {
        if (row.alive === true) continue;
        root.append(el('div', { class: 'oc-alert oc-alert-warn oc-small' },
            `Worker "${row.worker_id}" stopped reporting ${formatAge(row.last_seen_age_s)} ago without a clean exit `
            + `(last seen ${formatTimestamp(row.last_seen_at)}, mode ${row.mode || '?'}) — it was killed or crashed. `
            + 'Any job it held was reclaimed by the auto-recovery sweep; check the journal.',
        ));
    }

    // ---- Last job ---------------------------------------------------
    root.append(el('h3', { text: 'Last job' }), renderLastJob(snap.last_job));

    // ---- Queue ------------------------------------------------------
    const queue = el('div', { class: 'oc-worker-grid' },
        el('div', {}, el('strong', { text: 'Queued: ' }), String(counts.queued || 0),
            snap.oldest_queued_age_s != null ? ` (oldest ${formatAge(snap.oldest_queued_age_s)})` : ''),
        el('div', {}, el('strong', { text: 'Running: ' }), String(counts.running || 0)),
        el('div', {}, el('strong', { text: 'Stale: ' }), String(counts.stale || 0)),
        el('div', {}, el('strong', { text: 'Paused: ' }), String((counts.paused || 0) + (counts.pause_requested || 0) + (counts.resume_requested || 0))),
        el('div', {}, el('strong', { text: 'Completed: ' }), String(counts.completed || 0)),
        el('div', {}, el('strong', { text: 'Failed: ' }), String(counts.failed || 0)),
        el('div', {}, el('strong', { text: 'Non-terminal: ' }), String(snap.total_non_terminal || 0)),
        el('div', {}, el('strong', { text: 'Last heartbeat: ' }),
            snap.latest_heartbeat_at
                ? `${formatTimestamp(snap.latest_heartbeat_at)} (${formatAge(snap.latest_heartbeat_age_s)} ago)`
                    + (snap.latest_heartbeat_source === 'presence' ? ' — presence beacon' : '')
                : '—'),
        el('div', {}, el('strong', { text: 'Last presence: ' }),
            snap.latest_presence_at
                ? `${formatTimestamp(snap.latest_presence_at)} (${formatAge(snap.latest_presence_age_s)} ago)`
                : '—'),
        el('div', {}, el('strong', { text: 'Last job heartbeat: ' }),
            snap.latest_job_heartbeat_at
                ? `${formatTimestamp(snap.latest_job_heartbeat_at)} (${formatAge(snap.latest_job_heartbeat_age_s)} ago)`
                : '—'),
    );
    root.append(el('h3', { text: 'Queue' }), queue);

    // ---- Retries ----------------------------------------------------
    const retries = el('div', { class: 'oc-worker-grid' },
        el('div', {}, el('strong', { text: 'Awaiting retry: ' }), String(snap.retry_scheduled || 0)),
        el('div', {}, el('strong', { text: 'Retries exhausted: ' }), String(snap.retry_exhausted || 0)),
        el('div', {}, el('strong', { text: 'Next retry: ' }), snap.next_retry_at ? formatTimestamp(snap.next_retry_at) : '—'),
        el('div', {}, el('strong', { text: 'Stale threshold: ' }), `${snap.stale_threshold_sec || '?'}s`),
    );
    const policy = snap.retry_policy || {};
    retries.append(el('div', {},
        el('strong', { text: 'Policy: ' }),
        policy.auto_recovery_enabled === false
            ? 'auto-recovery OFF'
            : `max ${policy.max_attempts || '?'} attempts, backoff ${Array.isArray(policy.backoff_seconds) ? policy.backoff_seconds.join('/') : '?'}s`));
    root.append(el('h3', { text: 'Retries' }), retries);

    // ---- Recommendations + commands ---------------------------------
    const recs = Array.isArray(snap.recommendations) ? snap.recommendations : [];
    if (recs.length > 0) {
        const list = el('ul', { class: 'oc-worker-recs' });
        for (const r of recs) list.append(el('li', { text: String(r) }));
        root.append(el('h3', { text: 'Recommendations' }), list);
    }

    const commands = snap.commands && typeof snap.commands === 'object' ? snap.commands : {};
    const cmdWrap = el('details', { class: 'oc-worker-commands' },
        el('summary', { text: 'Commands' }));
    for (const [label, command] of Object.entries(commands)) {
        cmdWrap.append(commandRow(label, String(command)));
    }
    root.append(cmdWrap);

    root.append(el('div', { class: 'oc-muted oc-small', text: `Snapshot generated at ${formatTimestamp(snap.generated_at)}.` }));
}

// "job #N" alone does not tell the operator which run it belongs to, so
// for each busy worker we resolve the job row (public endpoint) and
// append "session #M, stage …". Best-effort: a failed lookup leaves the
// short form in place.
async function enrichWorkerJobs(snap) {
    const workers = Array.isArray(snap.running_workers) ? snap.running_workers : [];
    const targets = [];
    for (const w of workers) {
        const id = Number(w.current_job_id || 0);
        if (id <= 0 || !w.worker_id) continue;
        if (targets.some((t) => t.jobId === id)) continue;
        targets.push({ jobId: id, workerId: String(w.worker_id) });
    }
    for (const target of targets) {
        const res = await api(`/api/council/jobs/${target.jobId}`);
        const job = res.body?.job;
        if (!res.ok || !job) continue;
        const node = document.getElementById(`oc-worker-doing-${target.workerId}`);
        if (!node) continue;
        node.className = '';
        node.textContent = `job #${job.id} (session #${job.session_id}, stage ${job.status})`;
    }
}

// ---- Test: web → DB → worker → result, without spending tokens -----
async function runWorkerSelfTest() {
    const btn = document.getElementById('oc-worker-test-btn');
    const status = document.getElementById('oc-worker-action-status');
    const out = document.getElementById('oc-worker-test-result');
    if (!btn || !status || !out) return;
    clear(out);
    btn.disabled = true;
    status.textContent = 'Enqueuing self-test…';
    try {
        const res = await api('/api/settings/worker/test', { method: 'POST', body: {} });
        if (!res.ok) {
            status.textContent = '';
            out.append(workerTestError(res));
            return;
        }
        const jobId = Number(res.body?.job_id || 0);
        status.textContent = `Self-test job #${jobId} queued — waiting for the worker (up to 30s)…`;
        const terminal = await pollSelfTest(jobId, status);
        if (terminal == null) {
            status.textContent = 'No worker picked the self-test up within 30s.';
            out.append(el('div', { class: 'oc-alert oc-alert-warn' },
                el('div', { text: 'The job is still queued — the worker is not running, or it is connected to a different database.' }),
                el('div', { class: 'oc-small', text: 'Start it and press Test again:' }),
            ));
            out.append(commandRow('persistent', 'sudo systemctl start revoap-worker.service'));
            out.append(commandRow('foreground', 'php bin/revoap-worker.php --loop --sleep=1'));
            return;
        }
        // A self-test job always terminates as `completed` (its checks
        // are diagnostics, so they must not inflate the queue's Failed
        // counter). The verdict therefore comes from `result.ok`, never
        // from the terminal status.
        const result = terminal.result || {};
        const checks = Array.isArray(result.checks) ? result.checks : [];
        if (result.ok === true) {
            status.textContent = `Self-test passed (job #${jobId}).`;
        } else if (checks.length > 0) {
            status.textContent = `Self-test finished: ${result.passed || 0} passed, ${result.failed || 0} failed `
                + `(job #${jobId}) — diagnostics did NOT pass.`;
        } else {
            status.textContent = `Self-test did not complete (job #${jobId}, status "${terminal.status}").`;
        }
        out.append(renderSelfTestResult(jobId, terminal));
        await refreshWorkerCard();
    } finally {
        btn.disabled = false;
    }
}

function workerTestError(res) {
    const message = res.body?.message || res.body?.error || `HTTP ${res.status}`;
    let hint = '';
    if (res.status === 401) hint = 'Unlock the admin token first (Session card below) and retry.';
    else if (res.status === 403) hint = 'The admin token is required for this action.';
    else if (res.status === 404) hint = 'The Settings tab is disabled (SettingsTab=false in config.php).';
    else if (res.status === 503) hint = 'The durable job queue is not available — import db/schema.sql first.';
    return el('div', { class: 'oc-alert oc-alert-error' },
        el('div', { text: `Could not enqueue the self-test: ${message}` }),
        hint ? el('div', { class: 'oc-small', text: hint }) : null,
    );
}

// Poll the job until it reaches a terminal status or the 30s budget runs
// out. Returns the job object, or null on timeout.
async function pollSelfTest(jobId, status) {
    const deadline = Date.now() + 30000;
    while (Date.now() < deadline) {
        await new Promise((r) => window.setTimeout(r, 1000));
        const res = await api(`/api/council/jobs/${jobId}`);
        const job = res.body?.job;
        if (!res.ok || !job) continue;
        if (['completed', 'failed', 'cancelled'].includes(job.status)) return job;
        status.textContent = `Self-test job #${jobId}: ${job.status}…`;
    }
    return null;
}

function renderSelfTestResult(jobId, job) {
    const result = job.result || {};
    const checks = Array.isArray(result.checks) ? result.checks : [];
    const wrap = el('div', { class: 'oc-worker-test' },
        el('h3', { text: `Self-test result (job #${jobId})` }),
    );
    if (checks.length === 0) {
        wrap.append(el('div', { class: 'oc-alert oc-alert-warn' },
            el('div', { text: 'The worker finished the job but returned no check list.' }),
            el('div', { class: 'oc-small' }, 'Status: ', el('span', { class: 'oc-mono', text: String(job.status) }),
                job.error ? el('span', { text: ` — ${job.error.message || job.error.class || ''}` }) : null),
        ));
        return wrap;
    }
    // Red gate on `result.ok`: the job itself is `completed` by design,
    // so the terminal status must never soften this verdict.
    wrap.append(el('div', { class: `oc-alert ${result.ok === true ? 'oc-alert-ok' : 'oc-alert-error'}` },
        `Self-test finished: ${result.passed || 0} passed, ${result.failed || 0} failed — ${result.ok === true
            ? 'the web → database → worker → result chain is working.'
            : 'at least one check failed; see the details below.'}`));
    const table = el('table', { class: 'oc-worker-table' },
        el('thead', {}, el('tr', {},
            el('th', { text: 'Check' }),
            el('th', { text: 'Result' }),
            el('th', { text: 'Detail' }),
            el('th', { text: 'ms' }),
        )),
    );
    const tbody = el('tbody');
    for (const c of checks) {
        tbody.append(el('tr', {},
            el('td', { class: 'oc-mono oc-small', text: String(c.name || '?') }),
            el('td', {}, el('span', { class: 'oc-pill ' + (c.ok ? 'oc-pill-ok' : 'oc-pill-error'), text: c.ok ? 'ok' : 'FAIL' })),
            el('td', { class: 'oc-small', text: String(c.detail || '') }),
            el('td', { class: 'oc-mono oc-small', text: String(c.ms != null ? c.ms : '') }),
        ));
    }
    table.append(tbody);
    wrap.append(table);
    wrap.append(el('div', { class: 'oc-muted oc-small', text: String(result.note || '') }));
    wrap.append(el('div', { class: 'oc-muted oc-small' },
        `worker ${result.worker_id || '?'} · app ${result.app_version || '?'} · PHP ${result.php_version || '?'} · `
        + `storage ${result.storage || '?'} · ${result.duration_ms != null ? result.duration_ms + ' ms' : ''}`));
    return wrap;
}

// ---- Debug: operator diagnostic bundle ----------------------------
async function runWorkerDebug() {
    const btn = document.getElementById('oc-worker-debug-btn');
    const status = document.getElementById('oc-worker-action-status');
    const out = document.getElementById('oc-worker-debug-result');
    if (!btn || !status || !out) return;
    clear(out);
    btn.disabled = true;
    status.textContent = 'Collecting debug dump…';
    try {
        const res = await api('/api/settings/worker/debug');
        if (res.status === 403) {
            // A permissions problem is NOT a network error — say so and
            // explain both ways to gain access.
            status.textContent = 'Debug dump refused (403).';
            out.append(el('div', { class: 'oc-alert oc-alert-warn' },
                el('div', { text: res.body?.message || 'Not authorised to read the worker debug dump.' }),
                el('div', { class: 'oc-small' },
                    'Your client IP: ', el('span', { class: 'oc-mono', text: String(res.body?.client_ip || 'unknown') }),
                    res.body?.admin_configured === false
                        ? ' — no admin token is configured on this deployment.'
                        : ' — unlock the admin token in the Session card below, or add this IP to the whitelist '
                          + '(Security tab / security.ip_whitelist).'),
            ));
            return;
        }
        if (!res.ok) {
            status.textContent = '';
            out.append(el('div', { class: 'oc-alert oc-alert-error', text: `Debug dump failed: ${res.body?.message || res.body?.error || `HTTP ${res.status}`}` }));
            return;
        }
        status.textContent = 'Debug dump collected.';
        const text = JSON.stringify(res.body, null, 2);
        out.append(el('div', { class: 'oc-alert oc-alert-warn oc-small' },
            'Sensitive: this bundle contains worker ids, host names, log excerpts and job metadata. It never contains '
            + 'API keys or the admin token, but review it before sharing.'));
        out.append(commandRow('debug dump (again, from a shell)',
            `curl -sS -H 'X-Admin-Token: <token>' ${window.location.origin}/api/settings/worker/debug`));
        out.append(el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-secondary', type: 'button',
                onClick: async (e) => {
                    const ok = await copyText(text);
                    e.target.textContent = ok ? 'Copied' : 'Copy failed';
                    window.setTimeout(() => { e.target.textContent = 'Copy JSON'; }, 2500);
                },
            }, 'Copy JSON'),
            el('span', { class: 'oc-status oc-small', text: `${text.length} bytes` }),
        ));
        out.append(el('pre', { class: 'oc-pre oc-worker-debug', text }));
    } finally {
        btn.disabled = false;
    }
}

// ===== v1.8.47 — Settings → Cache =====================================
//
// Read-only statistics for the model response cache plus the bulk
// invalidation button. The numbers come from /api/settings/cache, which
// is fed by the same ModelResponseCache methods the CLI uses, so this
// card and `php bin/cache-purge.php --status` never disagree.
//
// Auto-refresh mirrors the Worker card: a 10s interval that skips hidden
// tabs, gated on the card being visible (IntersectionObserver) and torn
// down when the operator leaves the tab, so a page nobody is looking at
// performs no polling. The interval is longer than the Worker's because
// cache counters only move when a request is actually cached.
const CACHE_REFRESH_MS = 10000;

let cacheCardCtl = { timer: null, observer: null, card: null, visible: false, busy: false };

function cacheStopAutoRefresh() {
    if (cacheCardCtl.timer !== null) {
        clearInterval(cacheCardCtl.timer);
        cacheCardCtl.timer = null;
    }
    if (cacheCardCtl.observer) {
        cacheCardCtl.observer.disconnect();
        cacheCardCtl.observer = null;
    }
    cacheCardCtl.card = null;
    cacheCardCtl.visible = false;
}

function cacheStartAutoRefresh(card) {
    cacheStopAutoRefresh();
    cacheCardCtl.card = card;
    if (typeof IntersectionObserver === 'function') {
        cacheCardCtl.observer = new IntersectionObserver((entries) => {
            const visible = entries.some((e) => e.isIntersecting);
            cacheCardCtl.visible = visible;
            if (visible) void refreshCacheCard();
        }, { threshold: 0.01 });
        cacheCardCtl.observer.observe(card);
    } else {
        cacheCardCtl.visible = true;
        void refreshCacheCard();
    }
    cacheCardCtl.timer = setInterval(() => {
        // Never poll while a purge is in flight: the response would race
        // the refresh that follows it.
        if (document.hidden || !cacheCardCtl.visible || cacheCardCtl.busy) return;
        if (!cacheCardCtl.card || !document.body.contains(cacheCardCtl.card)) {
            cacheStopAutoRefresh();
            return;
        }
        void refreshCacheCard();
    }, CACHE_REFRESH_MS);
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden && cacheCardCtl.visible && !cacheCardCtl.busy) void refreshCacheCard();
});

function sectionCache() {
    const status = el('span', { class: 'oc-status', id: 'oc-cache-action-status' });
    const days = el('input', {
        type: 'number', id: 'oc-cache-days', min: '0', max: '36500', value: '0', size: '6',
    });
    return el('div', { class: 'oc-card', id: 'oc-cache-card' },
        el('h2', { text: 'Cache' }),
        el('p', { class: 'oc-muted oc-small' },
            'Stored model responses keyed by sha256(provider, model, normalized messages, significant options). A hit skips '
            + 'the provider call entirely. This panel only reads statistics; nothing is deleted unless you press ',
            el('strong', { text: 'Purge cache' }), '. It refreshes every 10 seconds while it is visible.'),
        el('div', { id: 'oc-cache-stats' },
            el('p', { class: 'oc-muted', text: 'Loading cache statistics…' })),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-secondary', type: 'button', id: 'oc-cache-refresh',
                onClick: async (e) => {
                    e.target.disabled = true;
                    status.textContent = 'Refreshing…';
                    await refreshCacheCard();
                    status.textContent = '';
                    e.target.disabled = false;
                },
            }, 'Refresh'),
            el('label', { class: 'oc-muted oc-small', for: 'oc-cache-days', text: 'older than' }),
            days,
            el('label', { class: 'oc-muted oc-small', for: 'oc-cache-days', text: 'days (0 = everything)' }),
            el('button', {
                class: 'oc-button-danger', type: 'button', id: 'oc-cache-purge',
                onClick: () => runCachePurge(),
            }, 'Purge cache'),
            status,
        ),
    );
}

// Fetch + render the statistics block. Never throws: any failure renders
// an inline notice instead of breaking the rest of the Settings tab.
async function refreshCacheCard() {
    const root = document.getElementById('oc-cache-stats');
    if (!root) return;
    const res = await api('/api/settings/cache');
    const body = res.body || {};
    if (res.status === 401) {
        state.status.admin_unlocked = false;
        renderLocked();
        return;
    }
    if (!res.ok || body.ok !== true) {
        clear(root);
        root.append(el('div', { class: 'oc-alert oc-alert-error' },
            `Failed to load cache statistics: ${body.message || body.error || `HTTP ${res.status}`}`));
        return;
    }
    renderCacheStats(root, body);
}

function cacheNumber(value, suffix = '') {
    return (value === null || value === undefined) ? '—' : `${value}${suffix}`;
}

/** One label/value pair; `hint` renders as a muted second line. */
function cacheRow(label, value, hint = null) {
    const row = el('div', {},
        el('strong', { text: `${label}: ` }),
        el('span', { text: String(value) }),
    );
    if (hint) row.append(el('div', { class: 'oc-muted oc-small', text: hint }));
    return row;
}

/**
 * Hit-rate block. The counter only exists in the database backend, so an
 * unavailable counter is reported as such (with the reason) instead of a
 * fake zero. When the counter *is* available the three states are
 * distinguished: never hit (amber — the cache stores but nothing is ever
 * served), partly hit, and entries with hits but no data.
 */
function renderCacheHits(stats) {
    const hits = stats.hits || {};
    const entries = (stats.totals || {}).count || 0;
    if (hits.counter_available !== true) {
        return el('div', { class: 'oc-cache-hits' },
            cacheRow('Hits', '—'),
            el('div', { class: 'oc-muted oc-small', text: hits.note || 'No hit counter on this deployment.' }),
        );
    }
    const block = el('div', { class: 'oc-cache-hits' },
        cacheRow('Hit rate (entries ever served)', cacheNumber(hits.hit_rate_percent, '')),
        cacheRow('Recorded hits', cacheNumber(hits.recorded_total)),
        cacheRow('Entries with hits', cacheNumber(hits.entries_with_hits)),
        cacheRow('Entries never hit', cacheNumber(hits.entries_never_hit)),
        cacheRow('Last hit', formatTimestamp(hits.last_hit_at)),
    );
    if (entries > 0 && hits.recorded_total === 0) {
        block.prepend(el('div', { class: 'oc-alert oc-alert-warn oc-small' },
            `The cache holds ${entries} entr${entries === 1 ? 'y' : 'ies'} but has never served one. `
            + 'Stored answers only help if later requests repeat the same provider, model, messages and options — '
            + 'and only while the same cache version is in use.'));
    } else if (stats.enabled === true && entries === 0) {
        block.prepend(el('div', { class: 'oc-muted oc-small' },
            'Nothing is cached yet — the counters start moving with the first repeated request.'));
    }
    if (hits.note) block.append(el('div', { class: 'oc-muted oc-small', text: hits.note }));
    return block;
}

/** One backend block: status, location, counts, bytes, timestamps. */
function renderCacheBackend(title, section, extraRows = []) {
    const available = section.available === true;
    const box = el('div', { class: 'oc-cache-backend' },
        el('h4', {},
            el('span', { text: `${title} ` }),
            el('span', { class: `oc-pill ${available ? 'oc-pill-ok' : 'oc-pill-warn'}`, text: available ? 'available' : 'unavailable' }),
        ),
    );
    if (section.root) {
        box.append(el('div', { class: 'oc-mono oc-small', text: String(section.root) }));
    }
    if (!available) {
        box.append(el('div', { class: 'oc-muted oc-small', text: section.note || 'Not available.' }));
        return box;
    }
    const grid = el('div', { class: 'oc-cache-grid' },
        cacheRow('Entries', cacheNumber(section.count)),
        cacheRow('Size', cacheNumber(section.size_human)),
        cacheRow('Oldest', formatTimestamp(section.oldest)),
        cacheRow('Newest', formatTimestamp(section.newest)),
    );
    for (const row of extraRows) grid.append(row);
    box.append(grid);
    if (section.size_source) {
        box.append(el('div', { class: 'oc-muted oc-small', text: `Size source: ${section.size_source}` }));
    }
    if (section.note) {
        box.append(el('div', { class: 'oc-muted oc-small', text: String(section.note) }));
    }
    return box;
}

/** Provider/model table, sorted by the backend (entries desc). */
function renderCacheDistribution(backends) {
    const rows = [];
    for (const key of ['database', 'filesystem']) {
        const section = backends[key] || {};
        for (const row of (section.distribution || [])) rows.push(row);
    }
    const box = el('div', { class: 'oc-cache-distribution' }, el('h4', { text: 'Top provider / model' }));
    if (rows.length === 0) {
        box.append(el('div', { class: 'oc-muted oc-small', text: 'No entries to summarise.' }));
        return box;
    }
    const tbody = el('tbody');
    for (const row of rows) {
        tbody.append(el('tr', {},
            el('td', { class: 'oc-mono oc-small', text: `${row.provider || '?'} / ${row.model || '?'}` }),
            el('td', { text: cacheNumber(row.entries) }),
            el('td', { text: cacheNumber(row.hits) }),
            el('td', { text: cacheNumber(row.bytes_human) }),
            el('td', { class: 'oc-muted oc-small', text: row.backend || '' }),
        ));
    }
    box.append(el('table', { class: 'oc-cache-table' },
        el('thead', {}, el('tr', {},
            el('th', { text: 'Provider / model' }),
            el('th', { text: 'Entries' }),
            el('th', { text: 'Hits' }),
            el('th', { text: 'Size' }),
            el('th', { text: 'Backend' }),
        )),
        tbody,
    ));
    return box;
}

function renderCacheStats(root, stats) {
    clear(root);
    const totals = stats.totals || {};
    const backends = stats.backends || {};
    const dbBackend = backends.database || {};
    const fsBackend = backends.filesystem || {};

    // --- state banner -------------------------------------------------
    if (stats.enabled === false) {
        root.append(el('div', { class: 'oc-alert oc-alert-warn' },
            'The model response cache is switched off (',
            el('span', { class: 'oc-mono', text: 'cache.model_responses.enabled = false' }),
            '): new answers are not stored and existing entries are never served. The counters below describe what is '
            + 'on disk or in the table right now.'));
    } else if ((totals.count || 0) === 0) {
        root.append(el('div', { class: 'oc-muted oc-small' },
            'The cache is empty — no response has been stored yet.'));
    }

    // --- summary grid -------------------------------------------------
    root.append(el('div', { class: 'oc-cache-grid' },
        cacheRow('State', stats.enabled === false ? 'disabled' : 'enabled'),
        cacheRow('Cache format version', cacheNumber(stats.cache_version)),
        cacheRow('TTL', String(stats.ttl_human || '—')),
        cacheRow('Entries (both backends)', cacheNumber(totals.count)),
        cacheRow('Size (both backends)', cacheNumber(totals.size_human)),
        cacheRow('Oldest entry', formatTimestamp(totals.oldest)),
        cacheRow('Newest entry', formatTimestamp(totals.newest)),
    ));

    root.append(renderCacheHits(stats));

    // --- per-backend detail -------------------------------------------
    root.append(renderCacheBackend('Database backend', dbBackend, [
        cacheRow('Recorded hits', cacheNumber(dbBackend.hits)),
        cacheRow('Table footprint', cacheNumber(dbBackend.table_human)),
        cacheRow('Last hit', formatTimestamp(dbBackend.last_hit_at)),
        cacheRow('Payload bytes', cacheNumber(dbBackend.size_bytes, ' B')),
    ]));
    root.append(renderCacheBackend('Filesystem backend', fsBackend, [
        cacheRow('Layout', String(fsBackend.layout || '—')),
        cacheRow('Legacy flat layout', cacheNumber(fsBackend.legacy_flat_count, ' entries'),
            fsBackend.legacy_flat_display
                ? `leftovers from before v1.8.21 in ${fsBackend.legacy_flat_display}; a purge removes them too`
                : null),
    ]));

    root.append(renderCacheDistribution(backends));

    root.append(el('div', { class: 'oc-muted oc-small' },
        'Key identity: ', String(stats.key_identity || '—'), '. ',
        'Normalization: ', String(stats.normalization || '—'), '. ',
        String(stats.error_responses || ''),
        ` Snapshot taken ${formatTimestamp(stats.generated_at)}.`));
}

/**
 * Purge flow. Irreversible, so it uses the same two-step guard as
 * Database → ReCreate DB: a warning dialog, then the control word
 * typed by hand. A stray click therefore deletes nothing.
 */
async function runCachePurge() {
    const status = document.getElementById('oc-cache-action-status');
    const daysInput = document.getElementById('oc-cache-days');
    const button = document.getElementById('oc-cache-purge');
    const raw = daysInput ? String(daysInput.value).trim() : '0';
    const days = raw === '' ? 0 : Number.parseInt(raw, 10);
    if (!Number.isFinite(days) || days < 0) {
        if (status) status.textContent = 'Enter 0 (everything) or a positive number of days.';
        return;
    }
    const windowText = days > 0
        ? `every entry older than ${days} day${days === 1 ? '' : 's'}`
        : 'EVERY cached response in all three stores (database, hierarchical cache and legacy flat layout)';
    if (!window.confirm(
        'DESTRUCTIVE: purging the cache permanently deletes ' + windowText + '.\n\n'
        + 'Cached model responses cannot be recovered — the next request for the same prompt will call the provider '
        + 'again (and be billed again). Statistics, history and sessions are not affected.\n\nContinue?'
    )) return;
    const typed = window.prompt('Type PURGE (uppercase) to confirm invalidating the model response cache:');
    if (typed !== 'PURGE') {
        if (status) status.textContent = 'Cancelled.';
        return;
    }
    if (status) status.textContent = 'Purging…';
    if (button) button.disabled = true;
    cacheCardCtl.busy = true;
    try {
        const res = await api('/api/settings/cache/purge', {
            method: 'POST',
            body: { confirm: 'PURGE', older_than_days: days },
        });
        const body = res.body || {};
        if (body.ok === true) {
            const deleted = body.deleted || {};
            const beforeBytes = (body.before || {}).size_bytes;
            const afterBytes = (body.after || {}).size_bytes;
            const freed = (Number.isFinite(beforeBytes) && Number.isFinite(afterBytes))
                ? beforeBytes - afterBytes
                : null;
            const legacy = deleted.filesystem_legacy || 0;
            const total = (deleted.database || 0) + (deleted.filesystem || 0) + legacy;
            if (status) {
                status.textContent = `Purged ${deleted.database || 0} database + ${deleted.filesystem || 0} filesystem `
                    + `entr${total === 1 ? 'y' : 'ies'}, freed ${deleted.bytes_human || '0 B'}`
                    + (freed === null || freed < 0
                        ? '.'
                        : ` (${body.before.size_human} → ${body.after.size_human}).`)
                    // bytes_human covers the hierarchical tree only, so the
                    // legacy tree is named separately instead of being folded
                    // into a figure it was never part of.
                    + (legacy > 0
                        ? ` Legacy flat layout: ${legacy} entr${legacy === 1 ? 'y' : 'ies'}`
                            + `, ${deleted.bytes_legacy_human || '0 B'}.`
                        : '');
            }
        } else if (status) {
            status.textContent = `Purge failed: ${body.message || body.error || `HTTP ${res.status}`}`;
        }
    } finally {
        cacheCardCtl.busy = false;
        if (button) button.disabled = false;
        await refreshCacheCard();
    }
}

function sectionRuntime() {
    const a = state.settings.app || {};
    const r = state.settings.runtime || {};
    const cache = r.cache || {};
    const pipeline = r.pipeline || {};
    const status = el('span', { class: 'oc-status' });
    const ids = {
        debug: 'rt-debug',
        url: 'rt-url',
        tz: 'rt-tz',
        cacheEnabled: 'rt-cache-enabled',
        cacheTtl: 'rt-cache-ttl',
        parallelL1: 'rt-par-l1',
        maxRoles: 'rt-max-roles',
    };
    return el('div', { class: 'oc-card', id: 'oc-runtime-card' },
        el('h2', { text: 'Runtime' }),
        el('p', { class: 'oc-muted oc-small' },
            `App ${a.name || 'RevoAp'} v${a.version || '?'} — `,
            'changes apply to the next request after save (no restart needed).'),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.url, text: 'App URL' }),
                el('input', { type: 'url', id: ids.url, value: a.url || '' }),
            ),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.tz, text: 'Timezone' }),
                el('input', { type: 'text', id: ids.tz, value: a.timezone || 'UTC' }),
            ),
        ),
        el('div', { class: 'oc-checkbox' },
            el('input', { type: 'checkbox', id: ids.debug, checked: !!a.debug }),
            el('label', { for: ids.debug, text: 'Debug mode (enables /api/debug detail + Logger writes to logs/)' }),
        ),
        el('h3', { text: 'Cache' }),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-checkbox' },
                el('input', { type: 'checkbox', id: ids.cacheEnabled, checked: cache.enabled !== false }),
                el('label', { for: ids.cacheEnabled, text: 'Enable model response cache' }),
            ),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.cacheTtl, text: 'TTL seconds (0 = no expiry)' }),
                el('input', { type: 'number', id: ids.cacheTtl, value: String(cache.ttl_seconds ?? 0), min: '0' }),
            ),
        ),
        el('h3', { text: 'Pipeline' }),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-checkbox' },
                el('input', { type: 'checkbox', id: ids.parallelL1, checked: pipeline.parallel_l1 !== false }),
                el('label', { for: ids.parallelL1, text: 'Parallel L1 dispatch' }),
            ),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.maxRoles, text: 'Max roles per skill' }),
                el('input', { type: 'number', id: ids.maxRoles, value: String(pipeline.max_roles_per_skill || 12), min: '1', max: '64' }),
            ),
        ),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-primary',
                type: 'button',
                onClick: async () => {
                    status.textContent = 'Saving…';
                    const body = {
                        debug: document.getElementById(ids.debug).checked,
                        app_url: document.getElementById(ids.url).value.trim(),
                        timezone: document.getElementById(ids.tz).value.trim(),
                        cache: {
                            enabled: document.getElementById(ids.cacheEnabled).checked,
                            ttl_seconds: parseInt(document.getElementById(ids.cacheTtl).value, 10) || 0,
                        },
                        pipeline: {
                            parallel_l1: document.getElementById(ids.parallelL1).checked,
                            max_roles_per_skill: parseInt(document.getElementById(ids.maxRoles).value, 10) || 12,
                        },
                    };
                    const res = await api('/api/settings/runtime', { method: 'POST', body });
                    status.textContent = res.ok ? `Saved (${(res.body.updated || []).length} fields).` : `Failed: ${res.body?.message || res.status}`;
                    if (res.ok) await loadAndRender();
                },
            }, 'Save runtime'),
            status,
        ),
    );
}

// v1.8.45 — Settings → Prompt caching.
//
// Provider-side prefix caching: the provider charges less for a prompt
// prefix it has already seen. Distinct from the Cache card, which is the
// LOCAL model-response cache (a hit there skips the network call entirely).
//
// The card has two halves on purpose:
//
//   1. the knobs — the only four things an operator can decide;
//   2. the documented capability table, read back from the server
//      (`runtime.prompt_cache.capabilities`, built by
//      App\Connectors\PromptCache\Capabilities — the same class the request
//      builder reads). Table and behaviour therefore cannot drift: if a row
//      says "нужны точки останова", the planner is placing them.
//
// Nothing is transcribed a second time here: a family whose caching rules
// Bothub does not document renders as "неизвестно", and for it the
// application adds no breakpoint and promises no saving.
function sectionPromptCaching() {
    const runtime = (state.settings && state.settings.runtime) || {};
    const block   = runtime.prompt_cache || {};
    const policy  = block.policy || {};
    const caps    = Array.isArray(block.capabilities) ? block.capabilities : [];
    const status  = el('span', { class: 'oc-status' });
    const ids = {
        enabled:     'pc-enabled',
        ttl:         'pc-ttl',
        breakpoints: 'pc-breakpoints',
        minTokens:   'pc-min-tokens',
        usage:       'pc-usage',
    };
    const ceiling = policy.max_breakpoints_ceiling || 4;

    const rows = caps.map((c) => el('tr', {},
        el('td', {},
            el('div', { text: c.label || c.family || '—' }),
            // The documented caveats (TTL behaviour, which models a minimum
            // applies to, what Bothub does with extra breakpoints) are shown
            // rather than hidden in a tooltip: they are the difference
            // between "the table explains the prices" and "the table is a
            // list of numbers".
            el('div', { class: 'oc-muted oc-small', text: c.notes || '' }),
        ),
        el('td', { text: c.mode_label || '—' }),
        el('td', { text: c.min_prompt_label || '—' }),
        el('td', { text: c.write_label || '—' }),
        el('td', { text: c.read_label || '—' }),
        el('td', { text: c.max_breakpoints_label || '—' }),
    ));

    return el('div', { class: 'oc-card', id: 'oc-prompt-cache-card' },
        el('h2', { text: 'Prompt caching' }),
        el('p', { class: 'oc-muted oc-small' },
            'Provider-side prompt caching (Bothub). Most providers cache automatically; a few need ',
            'explicit breakpoints in the request, which is what the switch below controls. This is a ',
            'different mechanism from the local response cache in the Cache card — that one avoids the ',
            'network call, this one makes the provider charge less for a repeated prefix.'),
        el('div', { class: 'oc-checkbox' },
            el('input', { type: 'checkbox', id: ids.enabled, checked: policy.enabled !== false }),
            el('label', { for: ids.enabled, text: 'Enable prompt caching' }),
        ),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.ttl, text: 'Cache TTL' }),
                el('select', { id: ids.ttl },
                    el('option', { value: '5m', selected: (policy.ttl || '5m') === '5m' }, '5 minutes (documented default)'),
                    el('option', { value: '1h', selected: policy.ttl === '1h' }, '1 hour (long sessions)'),
                ),
            ),
            el('div', { class: 'oc-field' },
                el('label', { for: ids.breakpoints, text: 'Breakpoints (1–' + ceiling + ')' }),
                el('input', {
                    type: 'number', id: ids.breakpoints,
                    value: String(policy.max_breakpoints || 1),
                    min: '1', max: String(ceiling),
                }),
            ),
        ),
        el('div', { class: 'oc-field-row' },
            el('div', { class: 'oc-field' },
                el('label', { for: ids.minTokens, text: 'Minimum prompt size (tokens, 0 = no guard)' }),
                el('input', {
                    type: 'number', id: ids.minTokens,
                    value: String(policy.min_prompt_tokens ?? 1024),
                    min: '0',
                }),
            ),
            el('div', { class: 'oc-checkbox' },
                el('input', { type: 'checkbox', id: ids.usage, checked: policy.include_usage !== false }),
                el('label', { for: ids.usage, text: 'Request cache token usage (usage.include)' }),
            ),
        ),
        el('p', { class: 'oc-muted oc-small' },
            'A breakpoint is placed on the last stable part of the prompt prefix — the trailing ',
            'system message — so the prefix stays byte-identical between runs and only the query ',
            'travels after it. Prompts below the size guard get no breakpoint: caching there would ',
            'cost a round trip and save nothing. The guard is raised per family by that family\'s ',
            'documented minimum, never lowered.'),
        el('h3', { text: 'Documented capabilities' }),
        el('p', { class: 'oc-muted oc-small' },
            'Transcribed from the Bothub prompt-caching documentation. "Неизвестно" means the ',
            'documentation says nothing about that family: no breakpoint is added and no saving is ',
            'promised, though reported statistics are still shown if the provider returns them.'),
        el('table', { class: 'oc-migrations-table' },
            el('thead', {}, el('tr', {},
                el('th', { text: 'Provider family' }),
                el('th', { text: 'Caching' }),
                el('th', { text: 'Min prompt' }),
                el('th', { text: 'Write price' }),
                el('th', { text: 'Read price' }),
                el('th', { text: 'Max breakpoints' }),
            )),
            el('tbody', {}, ...rows),
        ),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button-primary',
                type: 'button',
                onClick: async () => {
                    status.textContent = 'Saving…';
                    const body = {
                        enabled:           document.getElementById(ids.enabled).checked,
                        ttl:               document.getElementById(ids.ttl).value,
                        max_breakpoints:   parseInt(document.getElementById(ids.breakpoints).value, 10) || 1,
                        min_prompt_tokens: parseInt(document.getElementById(ids.minTokens).value, 10) || 0,
                        include_usage:     document.getElementById(ids.usage).checked,
                    };
                    const res = await api('/api/settings/prompt-cache', { method: 'POST', body });
                    status.textContent = res.ok
                        ? `Saved (${(res.body.updated || []).length} fields).`
                        : `Failed: ${res.body?.message || res.status}`;
                    if (res.ok) await loadAndRender();
                },
            }, 'Save prompt caching'),
            status,
        ),
    );
}

void probeStatus();

/**
 * Tell the migration banner that migrations were just applied.
 *
 * v1.9.1: the banner used to be painted once at load and then stayed on
 * screen, warning about drift that no longer existed. It re-probes on this
 * event and removes itself when the schema is up to date again.
 */
function announceMigrationsApplied() {
    document.dispatchEvent(new CustomEvent('oc:migrations-applied'));
}

// ===== R-7 — System health panel ======================================
//
// One screen for the failure classes that have cost the most time in this
// project: the worker running older code than what is deployed, a migration
// that was never applied, write permissions that broke, a stale model
// catalogue, a pool that drifted out of the active catalogue, a lost
// database connection. Every check says what is wrong and offers the button
// that answers it; after an action the state is read AGAIN and the fresh
// verdict for that check is printed next to it.
//
// The read side is public (`GET /api/health/system`), because a brand-new
// install has to be able to read the first-run checklist before any admin
// key exists. The write side is deliberately absent here: every action posts
// one of the endpoints the Settings cards already use
// (`/api/settings/migrations/apply`, `/api/worker/restart`,
// `/api/models/actualize`), so the panel cannot drift from the cards and
// there is no second place to keep the safety rules. A closed admin gate is
// answered by the server and shown verbatim - it is never papered over as
// success.
//
// Nothing here executes a shell command: host-level facts (file ownership, a
// service restart) arrive as `command` actions marked `external`, and the
// disclosure they carry says so.

/** The last envelope `refreshSystemHealth()` rendered (for re-read lines). */
let systemHealthReport = null;

const HEALTH_PILL = { ok: 'oc-pill-ok', warn: 'oc-pill-warn', error: 'oc-pill-error', unknown: 'oc-pill-warn' };
const HEALTH_ALERT = { ok: 'oc-alert-ok', warn: 'oc-alert-warn', error: 'oc-alert-error', unknown: 'oc-alert-warn' };

function sectionSystemHealth() {
    return el('div', { class: 'oc-card', id: 'oc-system-health-card' },
        el('h2', { text: 'System health' }),
        el('p', { class: 'oc-muted oc-small' },
            'Every failure class that has cost the most time here, on one screen: the code the worker is ',
            'actually running, the migrations and the schema version, the directories the application writes ',
            'to, how fresh the model catalogue is, whether the pool and the linked roles sit inside the active ',
            'catalogue, and the database connections. Each check says what is wrong and what to press; after ',
            'an action the state is read again and the new verdict is shown. Repairs ask for confirmation, run ',
            'behind the admin gate and are written to the log. Anything that lives outside the application — ',
            'file ownership, a service restart — is reported with the exact command and is never run from here.'),
        el('div', { id: 'oc-system-health-live' },
            el('p', { class: 'oc-muted', text: 'Loading system health…' })),
        el('div', { class: 'oc-actions' },
            el('button', {
                class: 'oc-button oc-button-secondary', type: 'button', id: 'oc-system-health-refresh',
                onClick: async (e) => {
                    e.target.disabled = true;
                    await refreshSystemHealth();
                    e.target.disabled = false;
                },
            }, 'Re-check now'),
            el('span', { class: 'oc-status', id: 'oc-system-health-status' }),
        ),
        el('div', { id: 'oc-system-health-checklist' }),
    );
}

// Fetch + render. Never throws: a failed probe renders an inline notice
// instead of breaking the rest of the Settings tab.
async function refreshSystemHealth() {
    const live = document.getElementById('oc-system-health-live');
    const status = document.getElementById('oc-system-health-status');
    const res = await api('/api/health/system');
    if (!res.ok || !res.body || typeof res.body !== 'object' || res.body.ok !== true) {
        systemHealthReport = null;
        if (status) status.textContent = '';
        if (live) {
            clear(live);
            live.append(el('div', { class: 'oc-alert oc-alert-error' },
                el('div', { text: `Failed to read the system health report (HTTP ${res.status}).` }),
                el('div', { class: 'oc-small', text: String(res.body?.message || res.body?.error
                    || 'The endpoint returned no usable body.') })));
        }
        return null;
    }
    systemHealthReport = res.body;
    if (live) renderSystemHealth(live, res.body);
    if (status) status.textContent = `Checked at ${formatTimestamp(res.body.generated_at)}.`;
    return res.body;
}

function renderSystemHealth(root, report) {
    clear(root);
    const checks = Array.isArray(report?.checks) ? report.checks : [];
    const summary = (report && typeof report.summary === 'object' && report.summary) || {};
    const overall = HEALTH_ALERT[report?.overall] ? String(report.overall) : 'unknown';

    root.append(el('div', { class: `oc-alert ${HEALTH_ALERT[overall]}` },
        el('strong', { text: `System health: ${summary.ok || 0} ok, ${summary.warn || 0} warning(s), `
            + `${summary.error || 0} error(s)`
            + (summary.unknown ? `, ${summary.unknown} unknown` : '') + '.' }),
        ' ',
        overall === 'ok'
            ? 'Every check passed.'
            : `The worst verdict is "${overall}" — the checks below say what to press.`,
        el('div', { class: 'oc-small', text: `Checked at ${formatTimestamp(report?.generated_at)}.` })));

    if (checks.length === 0) {
        root.append(el('div', { class: 'oc-alert oc-alert-warn oc-small' },
            'The endpoint returned no checks. That is a statement about the probe, not a clean bill of '
            + 'health for the installation.'));
        return;
    }

    for (const check of checks) {
        root.append(renderHealthCheck(check));
    }

    const checklistRoot = document.getElementById('oc-system-health-checklist') || root;
    renderSetupChecklist(checklistRoot, Array.isArray(report?.setup_checklist) ? report.setup_checklist : []);
}

// One check: severity pill, the check's name, what is wrong, why it matters,
// and the actions that answer it.
function renderHealthCheck(check) {
    const id = String(check?.id || '?');
    const severity = HEALTH_PILL[check?.severity] ? String(check.severity) : 'unknown';

    const block = el('div', { class: 'oc-worker-grid', id: `oc-health-check-${id}` },
        el('div', {},
            el('span', { class: `oc-pill ${HEALTH_PILL[severity]}`, text: severity }),
            ' ',
            el('strong', { text: String(check?.label || id) })),
        el('div', { class: severity === 'ok' ? 'oc-muted oc-small' : '', text: String(check?.headline || '') }),
    );
    if (check?.detail) {
        block.append(el('div', { class: 'oc-muted oc-small', text: String(check.detail) }));
    }

    const actions = Array.isArray(check?.actions) ? check.actions : [];
    for (const action of actions) {
        block.append(renderHealthAction(id, action));
    }
    // A stable slot for the outcome line. An action's own row disappears
    // when the re-read clears the failure, so "State re-read at …" would
    // have nowhere to land; this node survives the re-render.
    block.append(el('div', { class: 'oc-muted oc-small', id: `oc-health-check-note-${id}` }));

    return block;
}

// One action. `endpoint` becomes a button that posts and then re-reads;
// `command` becomes a copyable command with the explicit disclosure that the
// panel will not run it; `navigate` switches to the card that owns the fix.
function renderHealthAction(checkId, action) {
    const aid = String(action?.id || '?');
    const label = String(action?.label || aid);
    const kind = String(action?.kind || '');
    const status = el('span', { class: 'oc-status oc-small', id: `oc-health-action-status-${checkId}-${aid}` });

    if (kind === 'command') {
        const row = el('div', { class: 'oc-worker-cmd', id: `oc-health-command-${checkId}-${aid}` },
            el('span', { class: 'oc-muted oc-small', text: `${label}: ` }),
            el('code', { class: 'oc-mono oc-small', text: String(action?.target || '') }));
        if (action?.external === true) {
            row.append(el('div', { class: 'oc-muted oc-small' },
                'This lives outside the application: the panel reports the command and does not run it.'
                + (action?.note ? ` ${String(action.note)}` : '')));
        }
        row.append(status);
        return row;
    }

    const row = el('div', { class: 'oc-actions' },
        el('button', {
            class: kind === 'endpoint' ? 'oc-button oc-button-primary' : 'oc-button oc-button-ghost',
            type: 'button',
            id: `oc-health-action-${checkId}-${aid}`,
            onClick: () => void runHealthAction(checkId, action),
        }, label),
        status);
    if (action?.needs_confirmation === true) {
        row.append(el('span', { class: 'oc-muted oc-small', text: 'Asks for confirmation before it sends anything.' }));
    }
    if (action?.note) {
        row.append(el('div', { class: 'oc-muted oc-small', text: String(action.note) }));
    }

    return row;
}

// Run one action, then re-read the whole report and print the verdict the
// check has NOW. The status line is written into the freshly rendered node
// (the re-render replaced the old one), which is what makes "state was
// re-read" visible rather than claimed.
async function runHealthAction(checkId, action) {
    const aid = String(action?.id || '?');
    const setStatus = (text) => {
        const node = document.getElementById(`oc-health-action-status-${checkId}-${aid}`);
        if (node) node.textContent = text;
    };

    if (String(action?.kind || '') === 'navigate') {
        healthNavigateTo(action?.target);
        return;
    }

    const method = String(action?.method || 'POST').toUpperCase();
    const target = String(action?.target || '');
    if (target === '') {
        setStatus('This action has no target, so nothing was sent.');
        return;
    }

    if (action?.needs_confirmation === true) {
        const question = `${action.label || aid}\n\n`
            + (action.note ? `${action.note}\n\n` : '')
            + `This sends ${method} ${target} and then reads the state again. Continue?`;
        const approved = typeof window.confirm === 'function' ? window.confirm(question) : false;
        if (!approved) {
            setStatus('Cancelled — nothing was sent.');
            return;
        }
    }

    setStatus(`Sending ${method} ${target}…`);
    // The read-only re-check is a GET: fetch refuses a body on GET/HEAD
    // outright, and the endpoint ignores one anyway.
    const init = { method };
    if (method !== 'GET' && method !== 'HEAD') init.body = {};
    let res;
    try {
        res = await api(target, init);
    } catch (e) {
        setStatus(`Request failed: ${e && e.message ? e.message : 'unknown error'}`);
        return;
    }

    const refused = !res.ok;
    const serverText = String(res.body?.message || res.body?.error || `HTTP ${res.status}`);
    if (refused) {
        // 401 unauthorized / 503 admin_not_configured / 404 settings_disabled
        // land here. The server's own words are the honest report.
        setStatus(`Refused (HTTP ${res.status}): ${serverText}`);
    } else {
        setStatus(`Done (HTTP ${res.status}): ${serverText}`);
    }

    if (!refused && target === '/api/settings/migrations/apply') {
        announceMigrationsApplied();
    }

    const fresh = await refreshSystemHealth();
    const check = (Array.isArray(fresh?.checks) ? fresh.checks : []).find((c) => String(c?.id) === checkId);
    const verdict = check
        ? `State re-read at ${formatTimestamp(fresh.generated_at)}: "${String(check.severity)}" — ${String(check.headline)}`
        : `State re-read at ${formatTimestamp(fresh?.generated_at)} (this check is gone from the report).`;
    const outcome = refused
        ? `Refused (HTTP ${res.status}): ${serverText}. ${verdict}`
        : `Done (HTTP ${res.status}). ${verdict}`;
    setStatus(outcome);
    const note = document.getElementById(`oc-health-check-note-${checkId}`);
    if (note) note.textContent = outcome;
}

// Switch to the tab that owns the card, then scroll it into view. The Models
// tab owns Providers / Models / Actualize, so a fix that belongs there is
// one click away instead of a hunt for the tab. Guarded for the stub DOM,
// which has no layout.
function healthNavigateTo(target) {
    const id = String(target || '');
    if (id === '') return;
    const node = document.getElementById(id);
    if (!node) return;
    let panel = null;
    if (typeof node.closest === 'function') {
        panel = node.closest('[data-tab-panel]');
    }
    if (panel && panel.hidden === true && panel.dataset) {
        const tab = document.querySelector(`[data-tab="${panel.dataset.tabPanel}"]`);
        if (tab && typeof tab.click === 'function') tab.click();
    }
    if (typeof node.scrollIntoView === 'function') node.scrollIntoView();
}

// The compact first-run checklist for a new install: what "done" means, what
// is still open, and the single action that closes each item.
function renderSetupChecklist(root, items) {
    if (!root) return;
    clear(root);
    if (!Array.isArray(items) || items.length === 0) return;

    let done = 0;
    for (const item of items) {
        if (item && item.done === true) done++;
    }

    const details = el('details', { class: 'oc-worker-commands', id: 'oc-system-health-checklist-block' },
        el('summary', { text: `First-run checklist (${done} of ${items.length} done)` }));

    for (const item of items) {
        const id = String(item?.id || '?');
        const row = el('div', { class: 'oc-small', id: `oc-health-setup-${id}` },
            el('span', {
                class: `oc-pill ${item?.done === true ? 'oc-pill-ok' : 'oc-pill-warn'}`,
                text: item?.done === true ? 'done' : 'to do',
            }),
            ' ',
            el('strong', { text: String(item?.label || id) }),
            el('div', { class: 'oc-muted oc-small', text: String(item?.detail || '') }));
        const actions = Array.isArray(item?.actions) ? item.actions : [];
        for (const action of actions) {
            row.append(renderHealthAction(`setup-${id}`, action));
        }
        details.append(row);
    }

    root.append(details);
}
