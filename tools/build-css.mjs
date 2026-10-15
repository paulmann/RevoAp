#!/usr/bin/env node
/**
 * build-css.mjs — compile the RevoAp stylesheet.
 *
 *     node tools/build-css.mjs [--dev] [--check]
 *
 * Input   ui/tailwind.css  (+ ui/tokens.css, ui/fonts.css via @import)
 * Output  assets/dist/revoap.css        minified, shipped, committed
 *         assets/dist/revoap.dev.css    readable, --dev only, gitignored
 *
 * Why a committed build artefact
 * ------------------------------
 * A deployment of RevoAp must not need Node. The operator clones the repo,
 * points nginx at it and the console works. That is only true if the
 * compiled CSS is in the tree, so it is — and `--check` exists so CI can
 * prove the committed file is still what the sources produce. A stale
 * artefact is a silent, confusing bug: the operator edits a token, sees no
 * change, and concludes the token is unused.
 *
 * `--check` rebuilds into a temp file and diffs it against the committed
 * one, exiting non-zero on any difference.
 *
 * No network access is performed. Tailwind, its plugins and the vendored
 * fonts all resolve from the local node_modules / assets tree.
 */
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync, rmSync, mkdirSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');

const argv = process.argv.slice(2);
const isDev = argv.includes('--dev');
const isCheck = argv.includes('--check');

const input = join(root, 'ui', 'tailwind.css');
const config = join(root, 'ui', 'tailwind.config.cjs');
const distDir = join(root, 'assets', 'dist');
const output = join(distDir, 'revoap.css');
const devOutput = join(distDir, 'revoap.dev.css');

if (!existsSync(input)) {
    console.error(`build-css: missing input ${input}`);
    process.exit(1);
}
if (!existsSync(config)) {
    console.error(`build-css: missing config ${config}`);
    process.exit(1);
}

const tailwindCli = resolve(root, '..', 'build', 'node_modules', 'tailwindcss', 'lib', 'cli.js');
const localCli = join(root, 'node_modules', 'tailwindcss', 'lib', 'cli.js');
const cli = existsSync(localCli) ? localCli : tailwindCli;
if (!existsSync(cli)) {
    console.error(
        'build-css: the Tailwind CLI was not found.\n' +
        '  Run `npm install` in the project root (package.json declares tailwindcss\n' +
        '  as a devDependency) or in ../build. The compiled assets/dist/revoap.css\n' +
        '  is committed, so you only need this to change the styles.'
    );
    process.exit(1);
}

mkdirSync(distDir, { recursive: true });

/**
 * Run the Tailwind CLI. `--no-autoprefixer` is not passed: autoprefixer is
 * still wanted for the small number of properties Safari 16 needs
 * (`backdrop-filter`, `inset-inline-*` in flex contexts).
 */
function build(target, minify) {
    const args = [cli, '-c', config, '-i', input, '-o', target];
    if (minify) args.push('--minify');
    const res = spawnSync(process.execPath, args, {
        cwd: root,
        encoding: 'utf8',
        env: { ...process.env, NODE_ENV: minify ? 'production' : 'development' },
    });
    if (res.status !== 0) {
        process.stderr.write(res.stderr || '');
        process.stdout.write(res.stdout || '');
        throw new Error(`Tailwind CLI exited with ${res.status}`);
    }
    process.stderr.write(res.stderr || '');
    return target;
}

if (isCheck) {
    const tmp = mkdtempSync(join(tmpdir(), 'revoap-css-'));
    const target = join(tmp, 'revoap.css');
    try {
        build(target, true);
        const produced = readFileSync(target, 'utf8');
        if (!existsSync(output)) {
            console.error('build-css --check: assets/dist/revoap.css is missing.');
            process.exit(1);
        }
        const committed = readFileSync(output, 'utf8');
        if (produced.trim() !== committed.trim()) {
            console.error(
                'build-css --check: assets/dist/revoap.css is out of date.\n' +
                '  Run `node tools/build-css.mjs` and commit the result.'
            );
            process.exit(1);
        }
        console.log(`build-css --check: OK (${(statSync(output).size / 1024).toFixed(1)} KB)`);
    } finally {
        rmSync(tmp, { recursive: true, force: true });
    }
    process.exit(0);
}

build(output, !isDev);
const size = statSync(output).size;
console.log(`build-css: wrote assets/dist/revoap.css (${(size / 1024).toFixed(1)} KB)`);

if (isDev) {
    build(devOutput, false);
    console.log(`build-css: wrote assets/dist/revoap.dev.css (${(statSync(devOutput).size / 1024).toFixed(1)} KB, readable)`);
}

// Guard against the two failure modes that are invisible until a browser
// loads the file: an @import that never resolved (leaves a literal
// `@import` in the output, which the browser then tries to fetch) and a
// leftover Tailwind directive (which means the build silently no-oped).
const css = readFileSync(output, 'utf8');
const problems = [];
if (/@import\s/.test(css)) problems.push('unresolved @import');
if (/@tailwind\s/.test(css)) problems.push('unprocessed @tailwind directive');
if (/cdn\.jsdelivr|unpkg\.com|cdnjs|fonts\.googleapis|fonts\.gstatic/.test(css)) {
    problems.push('an external CDN origin appears in the stylesheet');
}
if (problems.length) {
    console.error('build-css: ' + problems.join('; '));
    process.exit(1);
}
console.log('build-css: verified — no unresolved imports, no CDN origins.');
