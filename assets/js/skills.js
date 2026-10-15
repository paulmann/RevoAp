// skills.js - the Skills tab: the consensus skills, with their full
// descriptions opening in a new window.
//
// The list comes from /api/consensus/skills - the same payload the composer
// uses - so the page can never disagree with the dropdown. Opening one posts
// to /api/consensus/skills/{slug}/document, which renders the full
// description (roles verbatim, arXiv sources included), writes it as a share
// document and answers with the file URL plus, when MD.Viewer is installed,
// the viewer URL. The tab opens whichever URL the server says is right: the
// viewer choice lives in one place, exactly like the Share button.
//
// Tab contract: same as every other module - `data-tab` buttons toggling the
// shared `oc-tab-active` / `[data-tab-panel]` machinery, the button revealed
// once the catalogue is available; the body renders with textContent only.

const SKILLS_TAB  = document.getElementById('oc-tab-skills');
const SKILLS_ROOT = document.getElementById('oc-skills-root');
const SKILLS_TAB_BUTTONS = document.querySelectorAll('[data-tab]');
const SKILLS_TAB_PANELS  = document.querySelectorAll('[data-tab-panel]');

const skillsState = { list: [], loaded: false, error: null, opening: '' };

function el(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null) continue;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = String(v);
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2).toLowerCase(), v);
        else if (typeof v === 'boolean') { if (v) node.setAttribute(k, ''); }
        else node.setAttribute(k, String(v));
    }
    for (const child of children.flat()) {
        if (child == null) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }

async function api(path, opts = {}) {
    const init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, ...opts };
    const res = await fetch(path, init);
    let body = null;
    try { body = await res.json(); } catch { body = null; }
    return { status: res.status, ok: res.ok, body: body ?? {} };
}

function activateTab(name) {
    SKILLS_TAB_BUTTONS.forEach(t => t.classList.toggle('oc-tab-active', t.dataset.tab === name));
    SKILLS_TAB_PANELS.forEach(p => { p.hidden = p.dataset.tabPanel !== name; });
    if (name === 'skills') void loadAndRender();
}

SKILLS_TAB_BUTTONS.forEach(t => {
    if (t.dataset.tab === 'skills') {
        t.addEventListener('click', () => activateTab('skills'));
    }
});

function shortDescription(text) {
    const flat = String(text || '').replace(/\s+/g, ' ').trim();
    if (flat.length <= 240) return flat;
    return flat.slice(0, 237).trimEnd() + '...';
}

function bindingLine(skill) {
    const bits = [];
    bits.push(skill.source === 'user' ? 'user skill' : 'bundled');
    if (Number(skill.sources_count) > 0) bits.push('sources: ' + Number(skill.sources_count));
    if (skill.consensus_scoring) bits.push('scoring: ' + String(skill.consensus_scoring));
    const preset = (skill.preset && typeof skill.preset === 'object') ? skill.preset : null;
    bits.push(preset
        ? 'preset: ' + String(preset.slug || preset.name || '?')
        : 'runs on the selected pool');
    return bits.join(' · ');
}

async function loadAndRender() {
    if (!SKILLS_ROOT) return;
    if (skillsState.loaded) { renderSkillList(); return; }
    clear(SKILLS_ROOT);
    SKILLS_ROOT.append(el('p', { class: 'oc-muted', text: 'Loading the consensus skills...' }));
    const { ok, status, body } = await api('/api/consensus/skills');
    if (!ok || !Array.isArray(body?.consensus_skills)) {
        skillsState.error = 'Failed to load the skills list (HTTP ' + status + ').';
        clear(SKILLS_ROOT);
        SKILLS_ROOT.append(el('div', { class: 'oc-alert oc-alert-error', text: skillsState.error }));
        return;
    }
    skillsState.list = body.consensus_skills;
    skillsState.loaded = true;
    // The tab is revealed by data, not by guesswork: an empty or broken
    // catalogue leaves it hidden rather than opening on an error.
    if (SKILLS_TAB) SKILLS_TAB.hidden = false;
    renderSkillList();
}

function renderSkillList() {
    if (!SKILLS_ROOT) return;
    clear(SKILLS_ROOT);
    SKILLS_ROOT.append(el('div', { class: 'oc-skills-head' },
        el('h2', { text: 'Consensus skills' }),
        el('p', { class: 'oc-muted oc-small' },
            'Every skill the council can run on. Opening one writes its full description - roles and arXiv sources included - ',
            'and shows it in a new window, through MD.Viewer when that extension is installed.')));

    const grid = el('div', { class: 'oc-skills-grid' });
    for (const skill of skillsState.list) {
        const slug = String(skill.slug || '');
        const status = el('span', { class: 'oc-status oc-skills-status' });
        grid.append(el('article', { class: 'oc-skills-card' },
            el('h3', { text: String(skill.name || slug) }),
            el('div', { class: 'oc-skills-meta' },
                el('span', { class: 'oc-skills-slug oc-mono', text: slug }),
                el('span', { text: bindingLine(skill) })),
            el('p', { class: 'oc-skills-desc', text: shortDescription(skill.description) }),
            el('div', { class: 'oc-skills-actions' },
                el('button', {
                    class: 'oc-button-secondary',
                    type: 'button',
                    onClick: () => void openSkillDocument(slug, status),
                }, 'Open description (.md)'),
                status)));
    }
    SKILLS_ROOT.append(grid);
}

async function openSkillDocument(slug, statusEl) {
    if (skillsState.opening !== '') return; // one at a time: two viewer tabs are worse than a wait
    skillsState.opening = slug;
    if (statusEl) { clear(statusEl); statusEl.textContent = 'Preparing...'; }
    try {
        const { ok, body } = await api('/api/consensus/skills/' + encodeURIComponent(slug) + '/document', { method: 'POST' });
        if (!ok || body?.ok === false) {
            if (statusEl) statusEl.textContent = 'Failed: ' + String(body?.message || 'unknown error');
            return;
        }
        const viewerUrl = typeof body?.viewer_url === 'string' ? body.viewer_url : '';
        const fileUrl = typeof body?.file_url === 'string' ? body.file_url
            : (typeof body?.url === 'string' ? body.url : '');
        const target = (body?.installed === true && viewerUrl !== '') ? viewerUrl : fileUrl;
        if (target === '') {
            if (statusEl) statusEl.textContent = 'The server answered without a URL.';
            return;
        }
        const opened = window.open(target, '_blank', 'noopener');
        if (statusEl) {
            clear(statusEl);
            statusEl.append(el('span', {
                text: opened
                    ? (body?.installed === true ? 'Opened in MD.Viewer.' : 'Opened the .md file.')
                    : 'The window was blocked - ',
            }));
            if (!opened) {
                statusEl.append(el('a', { href: target, target: '_blank', rel: 'noopener noreferrer', text: 'open it here' }));
                statusEl.append(el('span', { text: '.' }));
            }
        }
    } finally {
        skillsState.opening = '';
    }
}

void loadAndRender();
