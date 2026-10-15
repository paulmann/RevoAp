/**
 * katex-gate harness: the guard that keeps code out of KaTeX.
 *
 * A real History entry froze a tab after its pane had painted. The cause was
 * this gate letting a Perl answer through: with the single-`$` delimiter
 * enabled, KaTeX was asked to parse 128 `$...$` pairs of code as LaTeX.
 *
 * This drives the real `looksLikeMath` export and the real delimiter
 * configuration, so the regression cannot come back quietly.
 *
 *   node --experimental-vm-modules tests/harness/katex-gate.mjs
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.RV_REPO
    || (existsSync(resolve(here, '..', '..', 'assets', 'js', 'katex-render.js'))
        ? resolve(here, '..', '..')
        : 'C:/AutoClaw/revoap.com');

let passes = 0;
let failures = 0;

function check(label, cond, detail = '') {
    // process.stdout so a console shim in the module graph cannot swallow it.
    if (cond) {
        process.stdout.write('  ok  ' + label + '\n');
        passes++;
    } else {
        process.stdout.write('  FAIL ' + label + (detail !== '' ? ' - ' + detail : '') + '\n');
        failures++;
    }
}

const stub = await import(pathToFileURL(resolve(root, 'tests/harness/dom-stub.mjs')).href);
const parsed = stub.parseHtml('<!doctype html><html><body>'
    + '<div class="oc-final-answer-text">placeholder</div></body></html>');
stub.installGlobals(parsed, { fetch: async () => ({ ok: true, status: 200, json: async () => ({}) }) });

const mod = await import(pathToFileURL(resolve(root, 'assets/js/katex-render.js')).href + '?case=gate');
const looksLikeMath = mod.looksLikeMath;

check('the gate is exported for offline checks', typeof looksLikeMath === 'function');

// --- the shape that froze production: Perl, where `$` is a sigil -------------
const perl = [
    'sub refactor {',
    '    my ($self, %$opts) = @_;',
    '    my $total = $opts{count} + $self->{base};',
    '    print "total: $total\\n";',
    '    return $total;',
    '}',
].join('\n');
check('Perl code is not treated as maths', looksLikeMath(perl) === false);
check('a $-dense block is not treated as maths',
    looksLikeMath(Array.from({ length: 60 }, (_, i) => 'my $v' + i + ' = $arr[' + i + '];').join('\n')) === false);
check('a shell snippet is not treated as maths',
    looksLikeMath('for f in $FILES; do echo "$f"; done') === false);
check('plain prose is not treated as maths',
    looksLikeMath('A short answer with no maths at all.') === false);
check('an empty string is not maths', looksLikeMath('') === false);
check('a non-string is not maths', looksLikeMath(null) === false && looksLikeMath(42) === false);

// --- the delimiters that must still render -----------------------------------
check('display maths still passes the gate', looksLikeMath('And so $$E = mc^2$$ holds.') === true);
check('bracket display maths still passes', looksLikeMath('See \\[ x^2 + y^2 = z^2 \\] here.') === true);
check('inline paren maths still passes', looksLikeMath('Where \\(a + b\\) is the sum.') === true);
check('a LaTeX environment still passes', looksLikeMath('\\begin{align} a &= b \\end{align}') === true);

// --- the configuration itself: single-$ must not come back -------------------
const source = readFileSync(resolve(root, 'assets/js/katex-render.js'), 'utf8');
check('the single-$ delimiter is not configured', !/left:\s*'\$'/.test(source));
check('the gate has no single-$ alternative', !/\$\[\^\$/.test(source));
check('a size ceiling protects against document-sized nodes',
    /const MAX_NODE_CHARS = \d+;/.test(source));
check('the KaTeX pass is traced for ?debug=1', source.includes("enter('katex:renderOne'"));

process.stdout.write('\n=========================\n');
process.stdout.write('Passes: ' + passes + '  Failures: ' + failures + '\n');
process.exit(failures === 0 ? 0 : 1);
