// Asset size budget: what each page downloads, gzipped, against the numbers in scripts/asset-budget.json.
//
// Reads the Vite manifest of a finished build (public/build/manifest.json). For every entry it adds up what a page
// using that entry loads at once (the entry file, its static imports, their CSS); every chunk that is loaded later
// (a dynamic import) is measured with its own static imports, even those the entry already loaded (it errs high). A number may grow up to TOLERANCE over its budget; beyond that, or for an
// entry or chunk the budget does not list yet, the check fails and says how to record the new numbers on purpose:
//   node scripts/asset-budget.mjs            check (CI, after `pnpm run build`)
//   node scripts/asset-budget.mjs --update   write the current numbers as the budget (commit the JSON with the change)
// Keys are source paths from the manifest, so they stay stable across content hashes. Pure Node, no dependencies.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { join } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const BUILD = join(ROOT, 'public/build');
const BUDGET = join(ROOT, 'scripts/asset-budget.json');
const TOLERANCE = 0.03;

const manifestPath = join(BUILD, 'manifest.json');
if (!existsSync(manifestPath)) {
  console.error('No public/build/manifest.json: run `pnpm run build` first.');
  process.exit(1);
}
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

const sizeCache = new Map();
const gz = (file) => {
  if (!sizeCache.has(file)) {
    sizeCache.set(file, gzipSync(readFileSync(join(BUILD, file)), { level: 9 }).length);
  }
  return sizeCache.get(file);
};

/** Every file a page loads at once for this manifest key: the chunk, its static imports, and their CSS. */
const initialFiles = (key, seen = new Set()) => {
  if (seen.has(key)) return { js: new Set(), css: new Set() };
  seen.add(key);
  const chunk = manifest[key];
  const js = new Set(chunk.file.endsWith('.js') ? [chunk.file] : []);
  const css = new Set([...(chunk.css ?? []), ...(chunk.file.endsWith('.css') ? [chunk.file] : [])]);
  for (const dep of chunk.imports ?? []) {
    const sub = initialFiles(dep, seen);
    sub.js.forEach((f) => js.add(f));
    sub.css.forEach((f) => css.add(f));
  }
  return { js, css };
};
const total = (files) => [...files].reduce((sum, f) => sum + gz(f), 0);

// A package chunk is named without its version, and a shared chunk (`_name-<hash>.js`) without its hash, so a
// dependency bump or a rebuild keeps the budget line.
const label = (key) => key
  .replace(/^node_modules\/\.pnpm\/[^/]+\/node_modules\//, 'npm:')
  .replace(/^(_.+)-[\w-]{8}(\.\w+)$/, '$1$2');

const current = { entries: {}, lazy: {} };
const lazyKeys = new Set();
for (const [key, chunk] of Object.entries(manifest)) {
  if (chunk.isEntry) {
    const { js, css } = initialFiles(key);
    current.entries[label(key)] = { js: total(js), css: total(css) };
  }
  (chunk.dynamicImports ?? []).forEach((k) => lazyKeys.add(k));
}
for (const key of lazyKeys) {
  const { js, css } = initialFiles(key);
  current.lazy[label(key)] = { js: total(js), css: total(css) };
}

const sortKeys = (o) => Object.fromEntries(Object.keys(o).sort().map((k) => [k, o[k]]));
if (process.argv.includes('--update')) {
  writeFileSync(BUDGET, JSON.stringify({ entries: sortKeys(current.entries), lazy: sortKeys(current.lazy) }, null, 2) + '\n');
  console.log(`Budget written: ${Object.keys(current.entries).length} entries, ${lazyKeys.size} lazy chunks.`);
  process.exit(0);
}

const budget = existsSync(BUDGET) ? JSON.parse(readFileSync(BUDGET, 'utf8')) : { entries: {}, lazy: {} };
const kb = (n) => (n / 1024).toFixed(1) + ' KB';
const problems = [];
const rows = [];
for (const group of ['entries', 'lazy']) {
  for (const [key, now] of Object.entries(current[group])) {
    const was = budget[group]?.[key];
    for (const kind of ['js', 'css']) {
      if (now[kind] === 0 && !was?.[kind]) continue;
      const limit = was?.[kind];
      rows.push(`${group === 'entries' ? 'page' : 'lazy'}  ${kind.padEnd(3)} ${kb(now[kind]).padStart(9)}  ${limit === undefined ? '(new)' : 'budget ' + kb(limit)}  ${key}`);
      if (limit === undefined) {
        problems.push(`${key} (${kind}) is not in the budget yet: ${kb(now[kind])}.`);
      } else if (now[kind] > limit * (1 + TOLERANCE)) {
        problems.push(`${key} (${kind}) grew to ${kb(now[kind])}, over its budget of ${kb(limit)} (+${Math.round(TOLERANCE * 100)}% allowed).`);
      }
    }
  }
}
for (const group of ['entries', 'lazy']) {
  for (const key of Object.keys(budget[group] ?? {})) {
    if (!(key in current[group])) problems.push(`${key} is in the budget but no longer built: drop its line with --update.`);
  }
}
console.log('Gzipped size per page entry (loaded at once) and per lazy chunk (loaded when a screen needs it):');
rows.forEach((r) => console.log('  ' + r));
if (problems.length > 0) {
  console.error('\nAsset budget exceeded:');
  problems.forEach((p) => console.error('  - ' + p));
  console.error('\nShare what can be shared, load heavy code with a dynamic import() where only some screens need it, then rebuild.');
  console.error('If the growth is intended, record it: `node scripts/asset-budget.mjs --update`, and say why in the PR.');
  process.exit(1);
}
console.log('\nAsset budget: OK.');
