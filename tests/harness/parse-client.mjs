/**
 * Parse every client module the way the browser will. This is the check that
 * was missing when a bad patch shipped: a duplicate declaration is invisible
 * to the test suites (they do not load these files) but kills the whole module
 * in the browser, and the tab it powers stops opening.
 *
 *   node tests/harness/parse-client.mjs
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.RV_REPO
    || (existsSync(resolve(here, '..', '..', 'assets', 'js', 'app.js'))
        ? resolve(here, '..', '..')
        : 'C:/AutoClaw/revoap.com');

const dir = resolve(root, 'assets/js');
let passes = 0;
let failures = 0;

for (const name of readdirSync(dir).sort()) {
    if (!name.endsWith('.js')) continue;
    const source = readFileSync(resolve(dir, name), 'utf8');
    try {
        // Parses the module without executing it: duplicate declarations,
        // unbalanced braces and stray identifiers all surface here.
        new vm.SourceTextModule(source, { identifier: name });
        process.stdout.write('  ok    ' + name + '\n');
        passes++;
    } catch (err) {
        process.stdout.write('  FAIL  ' + name + ' - ' + String(err && err.message) + '\n');
        failures++;
    }
}

process.stdout.write('\n=========================\n');
process.stdout.write('Passes: ' + passes + '  Failures: ' + failures + '\n');
process.exit(failures === 0 ? 0 : 1);
