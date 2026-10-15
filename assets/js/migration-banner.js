// migration-banner.js - top banner that warns the operator when the live
// database's schema versions do not match the versions declared in
// db/schema.sql.
//
// Probe: GET /api/settings/version-status (public, no admin secrets).
// When `migration_needed === true`, a banner is injected above the header.
// Clicking it:
//   1. Switches the Settings tab to visible (if hidden).
//   2. Activates the Settings tab.
//   3. If the admin token is not yet unlocked, focuses the unlock input -
//      settings.js renders the lock screen automatically. Once the operator
//      unlocks the tab, settings.js scrolls the Migrations card into view.
//   4. If already unlocked, the Migrations card is scrolled into view
//      immediately.
//
// The banner never exposes admin information or version details - it only says
// "migration needed" and routes the operator to the correct place. The
// destructive endpoints behind it stay admin-gated server-side.
//
// v1.9.1: the banner used to be painted once at load, so a successful "Apply
// Migrations" left it on screen until the operator reloaded the page - a stale
// warning about a problem that no longer existed. settings.js now announces the
// outcome (see MIGRATIONS_APPLIED below) and the banner re-probes: it removes
// itself when the drift is gone, refreshes its numbers when it is not, and
// clears the dismissal flag so a future drift is never hidden by an old
// dismissal.

const BANNER_ID = 'oc-migration-banner';

/** Fired by settings.js after migrations were applied successfully. */
export const MIGRATIONS_APPLIED = 'oc:migrations-applied';

const DISMISSED_KEY = 'oc-migration-banner-dismissed';

async function probe() {
    try {
        const res = await fetch('/api/settings/version-status', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!res.ok) return null;
        return await res.json();
    } catch {
        return null;
    }
}

function bannerText(info) {
    const live = info?.live_schema_version || '(unset)';
    const expected = info?.expected_schema_version || '?';

    // The wording matches what a click actually does: it opens the Migrations
    // card, it does not apply anything by itself.
    return `Database schema is out of date (live ${live}, expected ${expected}). `
        + 'Click to open Settings → Migrations and apply them.';
}

function buildBanner(info) {
    const banner = document.createElement('div');
    banner.id = BANNER_ID;
    banner.className = 'oc-migration-banner';
    banner.setAttribute('role', 'alert');
    const text = document.createElement('span');
    text.className = 'oc-migration-banner-text';
    text.textContent = bannerText(info);
    const action = document.createElement('button');
    action.type = 'button';
    action.className = 'oc-migration-banner-action';
    action.textContent = 'Open Settings → Migrations';
    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'oc-migration-banner-dismiss';
    dismiss.setAttribute('aria-label', 'Dismiss this notice for the current session');
    dismiss.textContent = 'x';
    dismiss.addEventListener('click', (e) => {
        e.stopPropagation();
        try { sessionStorage.setItem(DISMISSED_KEY, '1'); } catch { /* ignore */ }
        banner.remove();
    });
    const onActivate = () => {
        const settingsTab = document.getElementById('oc-tab-settings');
        if (settingsTab) {
            settingsTab.hidden = false;
            settingsTab.click();
        }
        // Defer to settings.js's own rendering. Once it has painted either the
        // lock screen or the Migrations card, scroll the relevant element into
        // view.
        setTimeout(() => {
            const lock = document.querySelector('#oc-settings-root .oc-settings-locked input');
            if (lock instanceof HTMLInputElement) {
                lock.scrollIntoView({ behavior: 'smooth', block: 'center' });
                lock.focus();
                return;
            }
            const headings = Array.from(document.querySelectorAll('#oc-settings-root h2, #oc-settings-root h3'));
            const migrations = headings.find((h) => /Database maintenance|Migrations/i.test(h.textContent || ''));
            if (migrations) {
                migrations.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 80);
    };
    banner.addEventListener('click', onActivate);
    action.addEventListener('click', (e) => { e.stopPropagation(); onActivate(); });
    banner.append(text, action, dismiss);
    return banner;
}

/** Replace the numbers in an existing banner, so a partial apply is visible. */
function refreshText(banner, info) {
    const text = banner.querySelector('.oc-migration-banner-text');
    if (text) {
        text.textContent = bannerText(info);
    }
}

/**
 * Re-read the drift state and let the banner follow it.
 *
 * `show` is true for the initial paint (which honours a session dismissal) and
 * false when called after a successful apply: at that point the operator has
 * just interacted with the very thing the banner is about, so a stale banner
 * must never survive it.
 */
export async function refresh({ show = false } = {}) {
    const existing = document.getElementById(BANNER_ID);
    const info = await probe();

    // A failed probe says nothing about the schema: leave the banner as it is.
    if (!info) {
        return existing !== null;
    }

    if (info.migration_needed !== true) {
        if (existing) {
            existing.remove();
        }
        // The problem is gone: an old dismissal must not hide the next one.
        try { sessionStorage.removeItem(DISMISSED_KEY); } catch { /* ignore */ }
        return false;
    }

    if (existing) {
        refreshText(existing, info);
        return true;
    }

    if (show) {
        try {
            if (sessionStorage.getItem(DISMISSED_KEY) === '1') {
                return false;
            }
        } catch { /* ignore */ }
    }
    if (info.tab_enabled === false) {
        // Settings tab disabled - the banner has nowhere to send the operator.
        return false;
    }
    document.body.prepend(buildBanner(info));

    return true;
}

document.addEventListener(MIGRATIONS_APPLIED, () => {
    void refresh({ show: false });
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { void refresh({ show: true }); });
} else {
    void refresh({ show: true });
}
