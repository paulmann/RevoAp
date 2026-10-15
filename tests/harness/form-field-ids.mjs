/**
 * form-field-ids harness: no form field in the client may be nameless.
 *
 * The browser reports every <input>/<select>/<textarea> that has neither an id
 * nor a name: "A form field element should have an id or name attribute", one
 * issue per field, per tab. On a generated table that is one issue per row, so
 * the count grows with the data.
 *
 * This scans the client sources for field construction without an id and
 * compares the result against the list of known offenders. The list may shrink
 * (and then it must be updated here, which is the point), but it must never
 * grow: new code has to name its fields.
 *
 *   node tests/harness/form-field-ids.mjs
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.RV_REPO
    || (existsSync(resolve(here, '..', '..', 'assets', 'js', 'history.js'))
        ? resolve(here, '..', '..')
        : 'C:/AutoClaw/revoap.com');

let passes = 0;
let failures = 0;

function check(label, cond, detail = '') {
    if (cond) {
        process.stdout.write('  ok  ' + label + '\n');
        passes++;
    } else {
        process.stdout.write('  FAIL ' + label + (detail !== '' ? ' - ' + detail : '') + '\n');
        failures++;
    }
}

/**
 * Fields still without an id, by file.
 *
 * These are real and known: the Security tab's allow-list editors (8) and the
 * API-key form (3) plus the log tail field (1), the models monitor row helper
 * and one datalist-backed select. They are named rather than fixed here so the
 * list cannot quietly grow, and each one is a one-line change when its tab is
 * next opened.
 */
// Empty on purpose: every form field in the client is named. The list stays
// so a future offender has to be declared here rather than accepted silently.
const KNOWN_OFFENDERS = {};

const FIELD_START = /el\(\s*'(input|select|textarea)'/;
const LOOKAHEAD = 16;

// One label cannot be associated with anything: it names a custom model
// picker (a button + popup), not a native input, so there is no id to point
// `for` at. Making it a <div> would drop the form styling it inherits.
const LABEL_OFFENDERS = {
    'models.js': 1,
};

const offenders = {};
const dir = resolve(root, 'assets/js');
for (const name of readdirSync(dir)) {
    if (!name.endsWith('.js')) continue;
    const lines = readFileSync(resolve(dir, name), 'utf8').split('\n');
    for (let i = 0; i < lines.length; i++) {
        if (!FIELD_START.test(lines[i])) continue;
        const window = lines.slice(i, i + LOOKAHEAD).join(' ');
        if (/\bid:\s|\bid\s*=/.test(window)) continue;
        offenders[name] = (offenders[name] || 0) + 1;
    }
}

// Labels that render their own text next to a field must point at it: a bare
// `<label>Provider</label>` beside an input is not associated with anything,
// which the browser reports as "No label associated with a form field".
const labelOffenders = {};
for (const name of readdirSync(dir)) {
    if (!name.endsWith('.js')) continue;
    const lines = readFileSync(resolve(dir, name), 'utf8').split('\n');
    for (let i = 0; i < lines.length; i++) {
        if (!/el\(\s*'label'/.test(lines[i])) continue;
        const window = lines.slice(i, i + 6).join(' ');
        if (!/\btext:/.test(window)) continue;      // a wrapping label owns its field
        if (/\bfor:/.test(window)) continue;
        labelOffenders[name] = (labelOffenders[name] || 0) + 1;
    }
}
const labelNames = Object.keys(labelOffenders).sort();
process.stdout.write('  labels without for: ' + (labelNames.length === 0
    ? 'none\n'
    : labelNames.map((n) => n + '=' + labelOffenders[n]).join(', ') + '\n'));
check('every text label is associated with its field',
    labelNames.every((n) => !(LABEL_OFFENDERS[n] === undefined)),
    labelNames.filter((n) => LABEL_OFFENDERS[n] === undefined).join(', '));
check('the label-offender list did not grow',
    labelNames.every((n) => (labelOffenders[n] || 0) <= (LABEL_OFFENDERS[n] || 0)),
    labelNames.map((n) => n + ': ' + labelOffenders[n] + ' > ' + (LABEL_OFFENDERS[n] || 0)).join(', '));

const names = Object.keys(offenders).sort();
process.stdout.write('  found: ' + (names.length === 0
    ? 'no unnamed form fields\n'
    : names.map((n) => n + '=' + offenders[n]).join(', ') + '\n'));

check('every newly written form field carries an id',
    names.every((n) => !(KNOWN_OFFENDERS[n] === undefined)),
    names.filter((n) => KNOWN_OFFENDERS[n] === undefined).join(', '));

check('the known-offender list did not grow',
    names.every((n) => (offenders[n] || 0) <= (KNOWN_OFFENDERS[n] || 0)),
    names.map((n) => n + ': ' + offenders[n] + ' > ' + (KNOWN_OFFENDERS[n] || 0)).join(', '));

// A file that is cleaner than the list says must be removed from the list.
const stale = Object.keys(KNOWN_OFFENDERS).filter((n) => (offenders[n] || 0) < KNOWN_OFFENDERS[n]);
check('the known-offender list has no stale entries',
    stale.length === 0,
    stale.map((n) => n + ': listed ' + KNOWN_OFFENDERS[n] + ', found ' + (offenders[n] || 0)).join(', '));

process.stdout.write('\n=========================\n');
process.stdout.write('Passes: ' + passes + '  Failures: ' + failures + '\n');
process.exit(failures === 0 ? 0 : 1);
