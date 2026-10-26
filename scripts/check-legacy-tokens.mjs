#!/usr/bin/env node
/**
 * Keeps the old design-token names out of NEW code (docs/notes/design-tokens.md §6–7).
 *
 * Reads the lines this branch ADDS versus its merge base — in resources/ and in the module Blade
 * views — and fails when one uses an old name: a custom property (`--color-accent`, `--vz-…`,
 * `--primary`…, whole-token match, so `--ink` never catches `--ink-x`), an old theme attribute
 * (`data-bs-theme`), or a Tailwind class minted from an old name (`text-body-muted`, `text-accent`,
 * `bg-admin-primary`, `w-[var(--spacing-sidebar)]`…). Lines already on the base are never flagged:
 * old files move over as they are touched.
 *
 * Allowed on purpose:
 *   - the alias blocks themselves: every line from a "Bí danh — sẽ gỡ" comment to the closing `}`
 *     of the rule it sits in (or opens next);
 *   - a line carrying `legacy-token-ok` with the reason (for a place that has no new name yet).
 *
 * Usage: node scripts/check-legacy-tokens.mjs [base-ref]   (default: origin/main)
 *   CI passes HEAD^1 on a pull request (the checked-out merge commit's base parent).
 * Exit code 1 when an added line uses an old name.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });

// ── Old names (§7) ──────────────────────────────────────────────────────────────────────────────
// Custom properties, without the leading `--`. Each entry is a regex source for the whole name.
const OLD_PROPERTIES = [
    'color-admin-(?:primary|secondary|success|info|warning|danger|light|dark)',
    'color-admin-(?:success|info|danger|secondary)-solid',
    'color-admin-(?:success|info|warning|danger|secondary|dark)-text',
    'color-(?:accent|body|body-text|body-muted|body-faint|ink-ghost|heading|sidebar|topbar|footer|on-solid)',
    'focus-ring-color',
    'spacing-(?:sidebar|sidebar-collapsed|header)',
    'topbar-h',
    'fsp',
    's-muted',
    'brand(?:-solid|-ink|-\\d+)?',
    'primary(?:-text|-on-ink)?',
    'ink',
    'surface',
    'body',
    'font',
    'tone-[a-z]+-fg',
    'tx-[a-z0-9-]+',
    'vz-[a-z0-9-]+',
];
const OLD_ATTRIBUTES = ['data-bs-theme', 'data-scheme'];

// Tailwind classes minted from the old --color-* and --spacing-* names.
const OLD_COLOR_KEYS = [
    'admin-(?:primary|secondary|success|info|warning|danger|light|dark)',
    'admin-(?:success|info|danger|secondary)-solid',
    'admin-(?:success|info|warning|danger|secondary|dark)-text',
    'accent', 'body', 'body-text', 'body-muted', 'body-faint', 'ink-ghost', 'heading', 'sidebar', 'topbar', 'footer', 'on-solid',
];
const COLOR_UTILITIES = 'bg|text|border(?:-[xytrblse])?|ring|ring-offset|outline|fill|stroke|decoration|divide|accent|caret|from|via|to|shadow|inset-shadow|inset-ring';
const OLD_SPACING_KEYS = ['sidebar', 'sidebar-collapsed', 'header'];
const SPACING_UTILITIES = 'p[xytrblse]?|m[xytrblse]?|w|h|size|min-w|min-h|max-w|max-h|gap(?:-[xy])?|inset(?:-[xy])?|top|right|bottom|left|start|end|translate-[xy]|scroll-[mp][xytrblse]?|space-[xy]|basis|indent';

const edge = (body) => new RegExp(`(?<![\\w-])(?:${body})(?![\\w-])`, 'g');
const PATTERNS = [
    { kind: 'biến', regex: edge(`--(?:${OLD_PROPERTIES.join('|')})`) },
    { kind: 'thuộc tính', regex: edge(OLD_ATTRIBUTES.join('|')) },
    // `text-body` is the body text-size role once the --color-body alias is gone; `bg-body` stays old.
    { kind: 'class', regex: edge(`(?!text-body(?![\\w-]))-?(?:${COLOR_UTILITIES})-(?:${OLD_COLOR_KEYS.join('|')})(?:\\/\\d+)?`) },
    { kind: 'class', regex: edge(`-?(?:${SPACING_UTILITIES})-(?:${OLD_SPACING_KEYS.join('|')})`) },
];

// First match wins; `$1` takes the pattern's first group.
const HINTS = [
    [/^--vz-/, 'tên --color-sidebar-* / --color-topbar-* / --text-* tương ứng'],
    [/^data-(?:bs-theme|scheme)$/, 'data-theme'],
    [/^--body$|body-text$/, '--color-text'],
    [/body-muted$|^--s-muted$/, '--color-text-muted'],
    [/body-faint$/, '--color-text-faint'],
    [/^--color-body$|-body$/, '--color-bg'],
    [/heading$/, '--color-text-strong'],
    [/ink-ghost$/, '--color-icon-ghost'],
    [/^--primary-on-ink$/, '--color-primary-on-dark'],
    [/^--color-accent$|^--primary-text$|^--brand-ink$|-accent$/, '--color-primary-text'],
    [/admin-primary|^--primary$|^--brand-solid$/, '--color-primary'],
    [/admin-(success|info|danger|secondary)-solid$/, '--color-$1'],
    [/admin-dark-text$/, '--color-text-strong (giá trị khác — xem app.css)'],
    [/admin-(success|info|warning|danger|secondary)-text$/, '--color-$1-text'],
    [/admin-(success|info|warning|danger|secondary)$/, '--color-$1-base'],
    [/admin-light$/, '--color-gray-100'],
    [/admin-dark$/, '--color-gray-900'],
    [/on-solid$/, '--color-on-primary'],
    [/^--focus-ring-color$/, '--color-focus'],
    [/sidebar-collapsed$/, '--sidebar-width-collapsed'],
    [/^--spacing-sidebar$|^-?(?:p|m|w|min-w|max-w|inset|left|start|translate-x)[a-z-]*-sidebar$/, '--sidebar-width'],
    [/sidebar$/, '--color-sidebar-bg'],
    [/header$|^--topbar-h$/, '--topbar-height'],
    [/topbar$/, '--color-topbar-bg'],
    [/footer$/, '--color-footer-fg'],
    [/^--ink$/, '--color-text-strong / --color-dark / --color-on-primary (theo vai trò)'],
    [/^--surface$/, '--color-surface'],
    [/^--font$/, '--font-body'],
    [/^--brand-(\d+)$/, '--color-brand-$1'],
    [/^--brand$/, '--color-primary-text (chữ, viền) / --color-primary (nền)'],
    [/^--fsp$/, '--user-text-boost'],
    [/^--(?:tone-[a-z]+-fg|tx-.*)$/, '--color-<trạng thái>-text'],
];
const hint = (token) => {
    for (const [pattern, replacement] of HINTS) {
        const match = token.match(pattern);
        if (match) return replacement.replace(/\$(\d)/g, (_, i) => match[Number(i)] ?? '');
    }
    return 'xem docs/notes/design-tokens.md §7';
};

// ── Added lines ─────────────────────────────────────────────────────────────────────────────────
const baseRef = process.argv[2] ?? 'origin/main';
let base;
try {
    base = git('merge-base', baseRef, 'HEAD').trim();
} catch {
    console.error(`Không tìm được merge base với ${baseRef} — cần lịch sử git của nhánh đó (fetch-depth).`);
    process.exit(2);
}

// The working tree, not just HEAD: uncommitted edits are checked too when run locally.
const diff = git('diff', '--no-color', '--no-ext-diff', '-U0', '--diff-filter=AMR', base, '--', 'resources', ':(glob)app/**/*.blade.php');

const added = [];
let file = null;
let lineNo = 0;
for (const line of diff.split('\n')) {
    if (line.startsWith('+++ ')) {
        file = line.startsWith('+++ b/') ? line.slice(6) : null;
    } else if (line.startsWith('@@')) {
        lineNo = Number(line.match(/\+(\d+)/)[1]);
    } else if (file && line.startsWith('+')) {
        added.push({ file, line: lineNo, text: line.slice(1) });
        lineNo++;
    }
}

// Lines inside an alias block: from the marker comment to the `}` that closes the rule it is in or
// opens next (a marker before `:root {` covers that whole rule).
const aliasLines = new Map();
const aliasRegion = (path) => {
    if (aliasLines.has(path)) return aliasLines.get(path);
    const lines = existsSync(join(root, path)) ? readFileSync(join(root, path), 'utf8').split('\n') : [];
    const inside = new Set();
    for (let i = 0; i < lines.length; i++) {
        if (!lines[i].includes('Bí danh — sẽ gỡ')) continue;
        let depth = 0;
        for (let j = i; j < lines.length; j++) {
            inside.add(j + 1);
            depth += (lines[j].match(/\{/g) ?? []).length;
            const closes = (lines[j].match(/\}/g) ?? []).length;
            if (closes > depth) break; // the rule the marker sits in ends here
            depth -= closes;
            if (closes > 0 && depth === 0) break; // the rule the marker opened ends here
        }
    }
    aliasLines.set(path, inside);
    return inside;
};

const findings = [];
for (const { file: path, line, text } of added) {
    if (text.includes('legacy-token-ok') || aliasRegion(path).has(line)) continue;
    const seen = new Set();
    for (const { kind, regex } of PATTERNS) {
        for (const [name] of text.matchAll(regex)) {
            if (seen.has(name)) continue;
            seen.add(name);
            findings.push(`${path}:${line}  ${kind} ${name} → ${hint(name)}`);
        }
    }
}

if (findings.length === 0) {
    console.log(`Tên token cũ: không dòng mới nào dùng (${added.length} dòng thêm so với ${base.slice(0, 7)}).`);
} else {
    console.log(`Tên token cũ trong dòng mới thêm (${findings.length}) — dùng tên mới ở docs/notes/design-tokens.md §7:`);
    for (const finding of findings) console.log(`  ${finding}`);
    console.log('Chỗ chưa có tên mới: thêm "legacy-token-ok: <lý do>" vào cuối dòng.');
    process.exitCode = 1;
}
