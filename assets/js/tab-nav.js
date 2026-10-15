// tab-nav.js — shell navigation added with the "Routes & build" block.
//
// Two affordances have no per-tab module of their own, because neither
// owns a panel body:
//
//   1. The header brand (#oc-brand-link) is a link back to the Chat tab.
//      It delegates to the canonical [data-tab="chat"] trigger by
//      dispatching a click — the same idiom app.js already uses for its
//      "Configure these models in Settings →" link — so every listener the
//      tab machinery installed on that button runs unchanged. The element
//      is a real <a href="#chat">: Enter activates it natively, Space is
//      handled below, and middle-click / Ctrl-click keep their browser
//      meaning.
//   2. The Help item (#oc-tab-help) opens the Help panel. The panel body is
//      server-rendered static markup (assets/templates/help.html) — nothing
//      is fetched, nothing is sanitized at runtime, and no admin token is
//      involved.
//
// Both paths are navigation only: no fetch, no state, no innerHTML.

const TAB_BUTTONS = document.querySelectorAll('[data-tab]');
const TAB_PANELS  = document.querySelectorAll('[data-tab-panel]');

const BRAND = document.getElementById('oc-brand-link');

/**
 * Mirror of the activation the per-tab modules perform: exactly one tab
 * carries `oc-tab-active`, exactly one panel is visible. The other modules
 * keep their own copies of this for historical reasons; this one only
 * covers the two triggers above, and their listeners run in addition to
 * settings.js's blanket handler (both are idempotent).
 *
 * @param {string} name Tab id, e.g. 'chat'.
 */
function activateTab(name) {
    TAB_BUTTONS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    TAB_PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
}

/**
 * Open the Chat tab.
 *
 * Delegates to the real Chat button first, so every listener the tab
 * machinery installed on it (settings.js tears its pollers down on any tab
 * change) runs exactly as it does for a click on that button. The switch is
 * then re-applied locally, because the brand must keep working even if that
 * button is ever moved or loses its listeners — both steps are idempotent.
 */
function goToChat() {
    const trigger = document.querySelector('[data-tab="chat"]');
    if (trigger) { trigger.click(); }
    activateTab('chat');
}

if (BRAND) {
    BRAND.addEventListener('click', (event) => {
        // Modified clicks stay native — the href is a real anchor.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
        event.preventDefault();
        goToChat();
    });
    BRAND.addEventListener('keydown', (event) => {
        // An anchor activates on Enter by itself; Space is the other key a
        // user of the tab buttons next to it will try.
        if (event.key !== ' ' && event.key !== 'Spacebar') { return; }
        event.preventDefault();
        goToChat();
    });
}

TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'help') {
        t.addEventListener('click', () => activateTab('help'));
    }
});
