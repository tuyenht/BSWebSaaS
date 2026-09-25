#!/usr/bin/env node
/*
 * Structural WCAG 2A/2AA gate for the admin screens — labels, roles, names, alt text, headings,
 * landmarks, aria. Runs axe-core over the HTML the render tool dumps (tests/Tools/
 * RenderAdminPreviewTest.php → storage/app/admin-preview/*.html), in a jsdom DOM: no browser, no
 * chromedriver, so it is stable in CI.
 *
 * COLOR-CONTRAST is deliberately OFF here — scripts/contrast-check.mjs owns it (token values, both
 * schemes, gradient/topbar/sidebar variants), which is more precise than axe's pixel sampling and
 * needs no layout engine. This gate is the structural half; contrast-check is the color half.
 *
 * Hidden Alpine content (the modal, dropdowns) is revealed before the scan so axe evaluates it too
 * (the owner wants the gallery scanned "with the modal open"). Known, out-of-scope violations live in
 * axe-allowlist.json (rule + selector + reason + debt id) — never a whole-rule disable.
 *
 * Exit 1 on any violation not in the allowlist.
 */
import { readFileSync, existsSync } from 'node:fs';
import { JSDOM } from 'jsdom';

// axe-core binds to window/document when it loads, so hand it a DOM global before importing it.
const boot = new JSDOM('<!doctype html>', { pretendToBeVisual: true });
global.window = boot.window;
global.document = boot.window.document;
const { default: axe } = await import('axe-core');
const doc = boot.window.document; // axe validates the context against THIS window's Node class

const DIR = process.env.ADMIN_PREVIEW_DIR ?? 'storage/app/admin-preview';
const SCREENS = ['dashboard', 'list', 'form', 'gallery'];
const THEMES = ['light', 'dark'];
const ALLOWLIST = JSON.parse(readFileSync(new URL('./axe-allowlist.json', import.meta.url), 'utf8'));

const isAllowed = (screen, rule, node) => ALLOWLIST.some((entry) =>
    entry.rule === rule
    && (!entry.screen || entry.screen === screen)
    && node.target.some((selector) => String(selector).includes(entry.selector)));

async function scan(screen, theme, html) {
    // Parse the screen, then move its head + body into axe's own document (same realm).
    const src = new JSDOM(html).window.document;
    doc.documentElement.setAttribute('lang', src.documentElement.getAttribute('lang') ?? 'vi');
    doc.documentElement.setAttribute('data-bs-theme', theme);
    doc.head.innerHTML = src.head.innerHTML;
    doc.body.innerHTML = src.body.innerHTML;

    // Reveal Alpine-cloaked / display:none content so the modal + menus are in scope.
    doc.querySelectorAll('[x-cloak]').forEach((el) => el.removeAttribute('x-cloak'));
    doc.querySelectorAll('[style]').forEach((el) => {
        if (/display\s*:\s*none/.test(el.getAttribute('style') ?? '')) {
            el.style.display = 'block';
        }
    });

    const results = await axe.run(doc.documentElement, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa'] },
        rules: { 'color-contrast': { enabled: false } },
    });
    return results.violations;
}

const offenders = [];
let allowlisted = 0;

for (const screen of SCREENS) {
    const file = `${DIR}/${screen}.html`;
    if (!existsSync(file)) {
        console.error(`✗ missing ${file} — render first: pest tests/Tools/RenderAdminPreviewTest.php`);
        process.exit(2);
    }
    const html = readFileSync(file, 'utf8');
    for (const theme of THEMES) {
        for (const violation of await scan(screen, theme, html)) {
            for (const node of violation.nodes) {
                if (isAllowed(screen, violation.id, node)) {
                    allowlisted++;
                    continue;
                }
                offenders.push(`❌ ${screen}/${theme} · ${violation.id} (${violation.impact}) · ${node.target.join(' ')}`);
            }
        }
    }
}

console.log('### Admin axe — WCAG 2A/2AA (structural; color-contrast owned by contrast-check.mjs)');
console.log(offenders.length ? [...new Set(offenders)].join('\n') : '(không có vi phạm mới)');
console.log(`\nĐã cho phép (nợ đã biết trong allowlist): ${allowlisted} · Vi phạm MỚI: ${offenders.length}`);
process.exitCode = offenders.length === 0 ? 0 : 1;
