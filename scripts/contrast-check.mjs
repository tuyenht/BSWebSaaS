#!/usr/bin/env node
/**
 * WCAG 2 contrast table for the admin design tokens (+ the storefront's derived brand text color).
 *
 * Reads the tokens straight from the stylesheets (resources/css/fonts.css, app.css, admin-chrome.css)
 * — no build, no dependency — resolves var() / rgb() / oklch() / color-mix() and alpha layers for each
 * theme state (light and dark scheme, sidebar and topbar variants), and prints one Markdown table:
 * text on its background must reach 4.5:1 (WCAG 1.4.3); borders, focus rings and icons 3:1 (1.4.11).
 * Gradients are sampled at 101 points and the worst point is reported.
 *
 * ROWS below say which token each piece of the UI paints with, by the standard names of
 * docs/notes/design-tokens.md (the old names Blade may still use are one-way aliases of these) —
 * when a component switches tokens, update its row here in the same change.
 *
 * The admin brand comes from ONE table, resources/admin-brands.json (the admin layouts print the
 * platform's row over the app.css @theme default at runtime). Every row below is measured for EVERY
 * colour of that table, in both schemes; the full table is printed for the default colour and a
 * summary (worst ratio per colour) for all of them. The storefront's
 * --color-primary-text / --color-primary-on-dark / --color-on-primary are computed in PHP
 * (ThemeResolver + ReadableColor, pinned by their Pest tests); the storefront section mirrors that.
 *
 * Usage: node scripts/contrast-check.mjs
 * Exit code 1 when a checked pair falls below its minimum; "Theo dõi" (watch-list) rows are
 * reported but never fail the run.
 */
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const read = (path) => readFileSync(join(root, path), 'utf8');

// ── CSS → rules ────────────────────────────────────────────────────────────────────────────────
// Comments go first, skipping quoted strings: an @source glob such as '…/**/*.blade.php' holds a
// "/*" that is not a comment.
function stripComments(css) {
    let out = '';
    let quote = null;
    for (let i = 0; i < css.length; i++) {
        const char = css[i];
        if (quote) {
            out += char;
            if (char === quote) quote = null;
        } else if (char === '"' || char === "'") {
            quote = char;
            out += char;
        } else if (char === '/' && css[i + 1] === '*') {
            const end = css.indexOf('*/', i + 2);
            i = end < 0 ? css.length : end + 1;
        } else {
            out += char;
        }
    }
    return out;
}

// Innermost blocks only (enough for custom-property and background-image declarations); the
// selector is the text after the previous brace/semicolon, so @import/@source preludes drop out.
function parseRules(css) {
    const rules = [];
    const clean = stripComments(css);
    const block = /([^{}]*)\{([^{}]*)\}/g;
    let match;
    while ((match = block.exec(clean)) !== null) {
        const selector = match[1].split(';').pop().trim().replace(/\s+/g, ' ');
        const declarations = {};
        for (const part of match[2].split(';')) {
            const colon = part.indexOf(':');
            if (colon > 0) {
                declarations[part.slice(0, colon).trim()] = part.slice(colon + 1).trim();
            }
        }
        rules.push({ selector, declarations });
    }
    return rules;
}

// Cascade order: Tailwind emits @theme inside a layer, so every unlayered :root (fonts.css, then
// admin-chrome.css — both imported at the top of app.css — then app.css itself) beats it.
const sheets = ['resources/css/fonts.css', 'resources/css/admin-chrome.css', 'resources/css/app.css'].map((path) => parseRules(read(path)));
const allRules = sheets.flat();
const themeRules = allRules.filter((rule) => rule.selector === '@theme');
const rootRules = allRules.filter((rule) => rule.selector === ':root');
// A value switch may name one state twice (the dark scheme answers to data-theme and data-bs-theme).
const selectorList = (selector) => selector.split(',').map((part) => part.trim());
const overrideRules = allRules.filter((rule) => selectorList(rule.selector).every((part) => part.startsWith(':root[') && !part.includes(' ')));

// The admin brand table: each colour's row is what AdminBrandColor prints into :root at runtime.
const BRANDS = JSON.parse(read('resources/admin-brands.json')).brands;
const brandScope = (brand) => ({
    '--color-primary-base': brand.brand,
    '--color-primary': brand.solid,
    '--color-primary-text': brand.ink,
});

const overrideSelectors = (state) => {
    const active = [];
    if (state.scheme === 'dark') active.push(":root[data-theme='dark']");
    if (state.topbar === 'dark') active.push(":root[data-topbar='dark']");
    if (state.sidebar === 'light') active.push(":root[data-sidebar='light']");
    if (state.sidebar?.startsWith('gradient')) active.push(":root[data-sidebar^='gradient']");
    return active;
};

function scopeFor(state) {
    const scope = {};
    const apply = (rule) => Object.entries(rule.declarations).forEach(([name, value]) => {
        if (name.startsWith('--')) scope[name] = value;
    });
    themeRules.forEach(apply);
    rootRules.forEach(apply);
    if (state.brand) Object.assign(scope, brandScope(state.brand)); // the runtime :root block
    const active = overrideSelectors(state);
    overrideRules.filter((rule) => selectorList(rule.selector).some((part) => active.includes(part))).forEach(apply); // source order
    if (state.zone) {
        allRules.filter((rule) => rule.selector === state.zone).forEach(apply);
    }
    return scope;
}

function gradientStops(variant) {
    const rule = allRules.find((r) => r.selector === `:root[data-sidebar='${variant}'] .admin-sidebar`);
    const value = rule?.declarations['background-image'] ?? '';
    const inner = value.match(/linear-gradient\((.*)\)$/)?.[1];
    if (!inner) throw new Error(`No gradient declared for data-sidebar="${variant}"`);
    return splitArgs(inner).filter((part) => !part.startsWith('to '));
}

// ── Colors ──────────────────────────────────────────────────────────────────────────────────────
// A color is { r, g, b, a } — channels 0–255 in gamma-encoded sRGB (the space browsers composite in).
const named = { white: [255, 255, 255, 1], black: [0, 0, 0, 1], transparent: [0, 0, 0, 0] };

function splitArgs(text) {
    const parts = [];
    let depth = 0;
    let current = '';
    for (const char of text) {
        if (char === '(') depth++;
        if (char === ')') depth--;
        if (char === ',' && depth === 0) {
            parts.push(current.trim());
            current = '';
        } else {
            current += char;
        }
    }
    parts.push(current.trim());
    return parts;
}

const linear = (channel) => {
    const c = channel / 255;
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};
const gamma = (value) => 255 * (value <= 0.0031308 ? 12.92 * value : 1.055 * value ** (1 / 2.4) - 0.055);

function oklabToRgb(L, A, B) {
    const l = (L + 0.3963377774 * A + 0.2158037573 * B) ** 3;
    const m = (L - 0.1055613458 * A - 0.0638541728 * B) ** 3;
    const s = (L - 0.0894841775 * A - 1.291485548 * B) ** 3;
    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
    ].map((channel) => Math.min(255, Math.max(0, gamma(channel))));
}

function rgbToOklab({ r, g, b }) {
    const [lr, lg, lb] = [r, g, b].map(linear);
    const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb);
    const m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb);
    const s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb);
    return [
        0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
        1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
        0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
    ];
}

const rgba = (r, g, b, a = 1) => ({ r, g, b, a });

function parseColor(expression, scope, depth = 0) {
    if (depth > 20) throw new Error(`var() cycle at ${expression}`);
    const value = expression.trim();
    const call = value.match(/^([a-z-]+)\((.*)\)$/i);

    if (value.startsWith('#')) {
        let hex = value.slice(1);
        if (hex.length === 3) hex = [...hex].map((c) => c + c).join('');
        return rgba(parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16));
    }
    if (named[value]) return rgba(...named[value]);
    if (!call) throw new Error(`Unsupported color "${value}"`);

    const [, fn, body] = call;
    if (fn === 'var') {
        const [name, ...fallback] = splitArgs(body);
        if (scope[name] !== undefined) return parseColor(scope[name], scope, depth + 1);
        if (fallback.length) return parseColor(fallback.join(','), scope, depth + 1);
        throw new Error(`Undefined token ${name}`);
    }
    if (fn === 'rgb' || fn === 'rgba') {
        const [channels, alpha] = body.includes('/') ? body.split('/') : [body, undefined];
        const numbers = channels.split(/[\s,]+/).filter(Boolean).map(Number);
        const a = alpha !== undefined ? Number(alpha) : (numbers[3] ?? 1);
        return rgba(numbers[0], numbers[1], numbers[2], a);
    }
    if (fn === 'oklch') {
        const [l, c, h] = body.split(/\s+/);
        const L = l.endsWith('%') ? parseFloat(l) / 100 : parseFloat(l);
        const radians = (parseFloat(h) * Math.PI) / 180;
        return rgba(...oklabToRgb(L, parseFloat(c) * Math.cos(radians), parseFloat(c) * Math.sin(radians)));
    }
    if (fn === 'color-mix') {
        const [space, first, second] = splitArgs(body);
        const operand = (part) => {
            const weight = part.match(/\s(\d+(?:\.\d+)?)%$/);
            return { color: parseColor(weight ? part.slice(0, weight.index) : part, scope, depth + 1), weight: weight ? Number(weight[1]) / 100 : null };
        };
        const one = operand(first);
        const two = operand(second);
        const p1 = one.weight ?? (two.weight !== null ? 1 - two.weight : 0.5);
        const p2 = two.weight ?? 1 - p1;
        return mix(one.color, two.color, p1, p2, space.replace(/^in\s+/, ''));
    }
    throw new Error(`Unsupported color function ${fn}()`);
}

// CSS color-mix(): premultiplied alpha, interpolated in srgb or oklab.
function mix(one, two, p1, p2, space) {
    const alpha = one.a * p1 + two.a * p2;
    if (alpha === 0) return rgba(0, 0, 0, 0);
    if (space === 'oklab') {
        const [a1, a2] = [rgbToOklab(one), rgbToOklab(two)];
        const lab = a1.map((v, i) => (v * one.a * p1 + a2[i] * two.a * p2) / alpha);
        return rgba(...oklabToRgb(...lab), alpha);
    }
    const channel = (key) => (one[key] * one.a * p1 + two[key] * two.a * p2) / alpha;
    return rgba(channel('r'), channel('g'), channel('b'), alpha);
}

// Paint `top` over an opaque `bottom`, then quantize to 8 bits like the screen does.
const over = (top, bottom) => rgba(
    Math.round(top.r * top.a + bottom.r * (1 - top.a)),
    Math.round(top.g * top.a + bottom.g * (1 - top.a)),
    Math.round(top.b * top.a + bottom.b * (1 - top.a)),
);

const luminance = ({ r, g, b }) => 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
const ratio = (one, two) => {
    const [hi, lo] = [luminance(one), luminance(two)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
};
const hex = ({ r, g, b }) => `#${[r, g, b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('')}`;

// ── Pairs ───────────────────────────────────────────────────────────────────────────────────────
// A layer is a token name, a CSS color literal, or [token, alpha] (a Tailwind `/NN` modifier).
// `bg` is a stack painted bottom → top (the bottom must be opaque); `bgs` lists alternative stacks
// and the worst one is reported. `{ gradient }` samples a sidebar gradient. `fg: null` = the UI has
// no token-driven color there (browser default / element missing).
const layer = (spec, scope) => {
    const [expression, alpha] = Array.isArray(spec) ? spec : [spec, 1];
    const color = parseColor(expression.startsWith('--') ? `var(${expression})` : expression, scope);
    return { ...color, a: color.a * alpha };
};

function paint(stack, scope, gradientColor) {
    let base = null;
    for (const spec of stack) {
        if (spec && typeof spec === 'object' && !Array.isArray(spec) && spec.gradient) {
            base = gradientColor;
            continue;
        }
        const color = layer(spec, scope);
        base = base ? over(color, base) : color;
    }
    if (base.a < 1) throw new Error('A background stack must start opaque');
    return base;
}

function measure(row, state) {
    const scope = scopeFor({ ...state, ...row.state });
    const stacks = row.bgs ?? [row.bg];
    let worst = null;
    for (const stack of stacks) {
        const gradient = stack.find((spec) => spec && typeof spec === 'object' && !Array.isArray(spec) && spec.gradient);
        const samples = gradient
            ? (() => {
                const stops = gradientStops(gradient.gradient).map((stop) => parseColor(stop, scope));
                return Array.from({ length: 101 }, (_, i) => {
                    const t = (i / 100) * (stops.length - 1);
                    const k = Math.min(Math.floor(t), stops.length - 2);
                    return mix(stops[k], stops[k + 1], 1 - (t - k), t - k, 'srgb');
                });
            })()
            : [null];
        for (const sample of samples) {
            const background = paint(stack, scope, sample);
            const foreground = over(layer(row.fg, scope), background);
            const value = ratio(foreground, background);
            if (!worst || value < worst.value) worst = { value, fg: hex(foreground), bg: hex(background) };
        }
    }
    return worst;
}

const WHITE = '#ffffff';
const both = ['light', 'dark'];

// Section → rows. min 4.5 = text; 3 = border / focus ring / icon. `none` explains a row whose UI has
// no token-driven color yet (it counts as failing until one exists).
const ROWS = [
    // The brand navy as text, border or ring in the dark scheme.
    { item: 'Primary làm chữ/viền', role: 'Nút outline/ghost primary — chữ + viền', fg: '--color-primary-text', bg: ['--color-surface'], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Nút soft primary — chữ', fg: '--color-primary-text', bg: ['--color-surface', ['--color-primary', 0.1]], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Tab đang chọn — chữ + gạch chân', fg: '--color-primary-text', bg: ['--color-surface'], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Ô nhập — viền khi focus', fg: '--color-primary-text', bg: ['--color-surface'], min: 3 },

    // The brand fill and the brand ink where the approved brand table measures them.
    { item: 'Primary', role: 'Nút chính / badge — chữ trên nền --color-primary', fg: '--color-on-primary', bg: ['--color-primary'], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Link — trên thẻ và nền trang', fg: '--color-primary-text', bgs: [['--color-surface'], ['--color-bg']], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Mục đang chọn (nền brand 10%) — trên thẻ và nền trang', fg: '--color-primary-text', bgs: [['--color-surface', '--color-primary-soft'], ['--color-bg', '--color-primary-soft']], min: 4.5 },

    // Secondary text.
    { item: 'Chữ phụ', role: 'Chữ phụ (muted) trên thẻ', fg: '--color-text-muted', bg: ['--color-surface'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Chữ phụ (muted) trên nền trang', fg: '--color-text-muted', bg: ['--color-bg'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Footer', fg: '--color-footer-fg', bg: ['--color-bg'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Breadcrumb + phụ đề (page-header, trang Nội dung)', fg: '--color-text-muted', bg: ['--color-bg'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Tab chưa chọn (x-bs-admin::tabs, trang Nội dung)', fg: '--color-text-muted', bg: ['--color-surface'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Đầu cột bảng — x-bs-admin::table', fg: '--color-text-muted', bg: ['--color-surface'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Đầu cột bảng — thead nền surface-muted (7 trang danh sách) · bộ đếm Jodit', fg: '--color-text-muted', bg: ['--color-surface-muted'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Tiêu đề nhóm Customizer', fg: '--color-text-muted', bg: ['--color-surface'], min: 4.5 },

    // Status colors: -text on the page / card and on the 10% soft tint of the base hue.
    ...['success', 'info', 'warning', 'danger'].flatMap((hue) => [
        { item: 'Màu ngữ nghĩa', role: `${hue} — chữ (thẻ / nền trang)`, fg: `--color-${hue}-text`, bgs: [['--color-surface'], ['--color-bg']], min: 4.5 },
        { item: 'Màu ngữ nghĩa', role: `${hue} — kiểu soft (badge / alert / nút; chữ + viền outline)`, fg: `--color-${hue}-text`, bgs: [['--color-surface', [`--color-${hue}-base`, 0.1]], ['--color-bg', [`--color-${hue}-base`, 0.1]]], min: 4.5 },
        { item: 'Màu ngữ nghĩa', role: `${hue} — alert: nút đóng (icon)`, fg: `--color-${hue}-text`, bgs: [['--color-surface', [`--color-${hue}-base`, 0.1]], ['--color-bg', [`--color-${hue}-base`, 0.1]]], min: 3 },
    ]),
    ...['success', 'info', 'danger'].map((hue) => (
        { item: 'Màu ngữ nghĩa', role: `${hue} solid — chữ trắng (nút, badge${hue === 'danger' ? ', số đếm sidebar' : ''})`, fg: WHITE, bg: [`--color-${hue}`], min: 4.5 }
    )),
    { item: 'Màu ngữ nghĩa', role: 'warning solid — chữ (nút, badge)', fg: '--color-on-warning', bg: ['--color-warning'], min: 4.5 },

    // The input's own boundary, against its fill and against the page around it.
    { item: 'Viền ô nhập', role: 'Viền ô nhập (input / select / textarea)', fg: '--color-border-input', bgs: [['--color-surface'], ['--color-bg']], min: 3 },

    // Focus indicators.
    { item: 'Focus', role: 'Vòng focus — nút', fg: '--color-focus', bgs: [['--color-surface'], ['--color-bg']], min: 3 },
    { item: 'Focus', role: 'Vòng focus — tab', fg: '--color-focus', bg: ['--color-surface'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — ô nhập', fg: '--color-focus', bg: ['--color-surface'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — link / mục sidebar', state: { zone: '.admin-sidebar' }, fg: '--color-focus', bg: ['--color-sidebar-bg'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — mục sidebar sáng', state: { zone: '.admin-sidebar', sidebar: 'light' }, fg: '--color-focus', bg: ['--color-sidebar-bg'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — header Customizer (nền navy, theo chữ trắng)', fg: WHITE, bg: ['--color-primary'], min: 3 },

    // Sidebar ink (default dark sidebar; the dark scheme swaps in its own sidebar values).
    { item: 'Sidebar', role: 'Sidebar — mục thường', fg: '--color-sidebar-fg', bg: ['--color-sidebar-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar — mục đang chọn (chữ trên nền active)', fg: '--color-sidebar-fg-active', bg: ['--color-sidebar-bg', '--color-sidebar-active'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar — vạch chỉ báo mục đang chọn', fg: '--color-sidebar-fg-active', bgs: [['--color-sidebar-bg'], ['--color-sidebar-bg', '--color-sidebar-active']], min: 3 },
    { item: 'Sidebar', role: 'Sidebar sáng — mục thường', state: { sidebar: 'light' }, fg: '--color-sidebar-fg', bg: ['--color-sidebar-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar sáng — tiêu đề nhóm', state: { sidebar: 'light' }, fg: '--color-sidebar-title', bg: ['--color-sidebar-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar sáng — mục đang chọn (chữ trên nền active)', state: { sidebar: 'light' }, fg: '--color-sidebar-fg-active', bg: ['--color-sidebar-bg', '--color-sidebar-active'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar sáng — vạch chỉ báo mục đang chọn', state: { sidebar: 'light' }, fg: '--color-sidebar-fg-active', bgs: [['--color-sidebar-bg'], ['--color-sidebar-bg', '--color-sidebar-active']], min: 3 },

    // Gradient sidebars, every point of the band.
    ...['gradient', 'gradient-2', 'gradient-3', 'gradient-4'].flatMap((variant) => [
        { item: 'Sidebar gradient', role: `${variant} — mục thường (mọi điểm của dải)`, state: { sidebar: variant }, fg: '--color-sidebar-fg', bg: [{ gradient: variant }], min: 4.5 },
        { item: 'Sidebar gradient', role: `${variant} — mục đang chọn, chữ trên nền active (mọi điểm của dải)`, state: { sidebar: variant }, fg: '--color-sidebar-fg-active', bg: [{ gradient: variant }, '--color-sidebar-active'], min: 4.5 },
    ]),

    // Modal close button (an icon: 3:1).
    { item: 'Modal', role: 'Modal — nút đóng (icon)', fg: '--color-text-muted', bg: ['--color-surface'], min: 3 },

    // Secondary role (button + badge): the fill under white, the -text as ink / soft / outline.
    { item: 'Secondary', role: 'Secondary solid — chữ trắng (nút, badge)', fg: WHITE, bg: ['--color-secondary'], min: 4.5 },
    { item: 'Secondary', role: 'Secondary — chữ / soft / outline (nút, badge)', fg: '--color-secondary-text', bgs: [['--color-surface'], ['--color-surface', ['--color-secondary-base', 0.1]]], min: 4.5 },

    // Topbar text (role line under the name + platform nav links) and the icon focus rings kept in
    // item 1 (ring-topbar-fg) — measured on the light topbar AND the dark (navy) topbar.
    { item: 'Topbar', role: 'Dòng vai trò + link platform — topbar sáng', fg: '--color-topbar-fg', bg: ['--color-topbar-bg'], min: 4.5 },
    { item: 'Topbar', role: 'Dòng vai trò + link platform — topbar tối (navy)', state: { topbar: 'dark' }, fg: '--color-topbar-fg', bg: ['--color-topbar-bg'], min: 4.5 },
    { item: 'Topbar', role: 'Vòng focus icon (ring-topbar-fg) — topbar sáng', fg: '--color-topbar-fg', bg: ['--color-topbar-bg'], min: 3 },
    { item: 'Topbar', role: 'Vòng focus icon (ring-topbar-fg) — topbar tối (navy)', state: { topbar: 'dark' }, fg: '--color-topbar-fg', bg: ['--color-topbar-bg'], min: 3 },
    { item: 'Topbar', role: 'Topbar tối — tên người dùng trên khối người dùng', state: { topbar: 'dark' }, fg: '--color-topbar-fg-strong', bg: ['--color-topbar-bg', '--color-topbar-user-block'], min: 4.5 },
    { item: 'Topbar', role: 'Topbar tối — chữ cái avatar', state: { topbar: 'dark' }, fg: '--color-topbar-user-fg', bg: ['--color-topbar-bg', '--color-topbar-user-block', '--color-topbar-user'], min: 4.5 },
    { item: 'Topbar', role: 'Topbar tối — chữ ô tìm (cả khi focus)', state: { topbar: 'dark' }, fg: '--color-topbar-search-fg', bgs: [['--color-topbar-bg', '--color-topbar-search'], ['--color-topbar-bg', '--color-topbar-search-focus']], min: 4.5 },
    { item: 'Topbar', role: 'Topbar tối — viền ô tìm khi focus', state: { topbar: 'dark' }, fg: '--color-topbar-search-accent', bg: ['--color-topbar-bg', '--color-topbar-search-focus'], min: 3 },
    { item: 'Topbar', role: 'Topbar sáng — chữ ô tìm', fg: '--color-topbar-search-fg', bg: ['--color-topbar-bg', '--color-topbar-search'], min: 4.5 },

    // Placeholder (input) — now the muted role token, on the card and the page.
    { item: 'Chữ phụ', role: 'Placeholder ô nhập (là chữ)', fg: '--color-placeholder', bgs: [['--color-surface'], ['--color-bg']], min: 4.5 },

    // Sidebar group title (default navy sidebar in light, #212529 sidebar in the dark scheme).
    { item: 'Sidebar', role: 'Sidebar (navy / tối) — tiêu đề nhóm', fg: '--color-sidebar-title', bg: ['--color-sidebar-bg'], min: 4.5 },

    // Avatar / settings-shell active — the brand ink on the primary soft tint.
    { item: 'Primary làm chữ/viền', role: 'Avatar / mục đang chọn settings-shell — chữ màu thương hiệu', fg: '--color-primary-text', bg: ['--color-surface', ['--color-primary', 0.1]], min: 4.5 },

    // Badge "dark" soft — the neutral dark-text tier (no standard name with its value yet, app.css).
    { item: 'Màu ngữ nghĩa', role: 'Badge "dark" soft — chữ', fg: '--color-admin-dark-text', bg: ['--color-surface', ['--color-gray-900', 0.1]], min: 4.5 },
];

// ── Storefront --primary-text ───────────────────────────────────────────────────────────────
// Mirrors App\Modules\Theme\Theming\ReadableColor::onAll() as ThemeResolver::cssVariables() calls
// it: the brand seed darkened (hue kept) until it reads on the card surface AND the page background.
// Sample brands only (no tenant data): the default seed, a light amber, a very light yellow.
const themeResolver = read('app/Modules/Theme/Theming/ThemeResolver.php');
const defaultSeed = (key) => themeResolver.match(new RegExp(`'${key}'\\s*=>\\s*'(#[0-9a-fA-F]{3,6})'`))?.[1];
const pageShare = Number(themeResolver.match(/PAGE_SURFACE_SHARE = ([\d.]+);/)?.[1]);
const SEEDS = [
    { name: 'mặc định (ThemeResolver::DEFAULTS)', primary: defaultSeed('primary'), surface: defaultSeed('surface'), ink: defaultSeed('ink'), body: defaultSeed('body') ?? '#333333' },
    { name: 'thương hiệu amber mẫu', primary: '#f59e0b', surface: '#ffffff', ink: '#0f172a', body: '#334155' },
    { name: 'thương hiệu vàng sáng mẫu', primary: '#facc15', surface: '#ffffff', ink: '#111111', body: '#333333' },
];

function readableOn(color, backgrounds, minimum = 4.5) {
    const rgb = parseColor(color, {});
    const surfaces = backgrounds.map((background) => parseColor(background, {}));
    const surfaceLuminance = luminance(surfaces[0]);
    const target = (surfaceLuminance + 0.05) / 0.05 >= 1.05 / (surfaceLuminance + 0.05) ? rgba(0, 0, 0) : rgba(255, 255, 255);
    for (let step = 0; step <= 20; step++) {
        const weight = step / 20;
        const candidate = rgba(...['r', 'g', 'b'].map((k) => Math.round(rgb[k] + (target[k] - rgb[k]) * weight)));
        if (surfaces.every((surface) => ratio(candidate, surface) >= minimum)) return hex(candidate);
    }
    return hex(target);
}

// storefront.css: body { background: color-mix(in srgb, var(--surface) 97%, var(--ink)) }
const storefrontPage = (seed) => hex(mix(parseColor(seed.surface, {}), parseColor(seed.ink, {}), pageShare, 1 - pageShare, 'srgb'));
const storefrontPrimaryText = (seed) => readableOn(seed.primary, [seed.surface, storefrontPage(seed)]);
// Mirrors ThemeResolver's --color-primary-on-dark (old --primary-on-ink): the brand lightened (hue
// kept) until it reads on the dark ink.
const storefrontPrimaryOnInk = (seed) => readableOn(seed.primary, [seed.ink]);
// Mirrors ThemeResolver::textOnFill(): --color-on-primary is white or the ink, whichever reads better
// on the brand fill (a tie keeps the ink).
const storefrontOnPrimary = (seed) => {
    const fill = parseColor(seed.primary, {});
    return ratio(rgba(255, 255, 255), fill) > ratio(parseColor(seed.ink, {}), fill) ? WHITE : seed.ink;
};

// ── Report ──────────────────────────────────────────────────────────────────────────────────────
// Truncated to 2 decimals before comparing, as axe-core does: 4.4988 is a fail, not a rounded 4.50.
const shown = (value) => Math.floor(value * 100) / 100;
const passes = (result, min) => result !== null && shown(result.value) >= min;
const cell = (result, min, none) => (result === null
    ? `❌ — (${none})`
    : `${passes(result, min) ? '✅' : '❌'} ${shown(result.value).toFixed(2)} · \`${result.fg}\` / \`${result.bg}\``);

let failures = 0;
let revision = 'working tree';
try {
    revision = execSync('git rev-parse --short HEAD', { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
} catch {
    // not a git checkout — keep the generic label
}

const lines = [
    `### Contrast check — \`${revision}\``,
    '',
    'Tỉ lệ WCAG 2 (làm tròn xuống 2 chữ số như axe) · chữ ≥ 4.5 · viền / vòng focus / icon ≥ 3 · ô = tỉ lệ · màu vẽ / màu nền (đã trộn alpha, điểm xấu nhất).',
    '',
    '| Mục | Cặp | Ngưỡng | Sáng | Tối |',
    '|---|---|---|---|---|',
];

const [defaultBrand] = BRANDS;
for (const row of ROWS) {
    const results = both.map((scheme) => (row.fg === null ? null : measure(row, { scheme, brand: defaultBrand })));
    lines.push(`| ${row.item} | ${row.role} | ${row.min} | ${cell(results[0], row.min, row.none)} | ${cell(results[1], row.min, row.none)} |`);
}

// Every colour of the brand table, every row, both schemes (the default colour included: its
// failures are counted here, once).
lines.push('', `**Màu thương hiệu trang quản trị** — mọi cặp ở trên, đo lại cho từng màu của \`resources/admin-brands.json\` (${BRANDS.length} màu). Ô = tỉ lệ thấp nhất trong các cặp chữ (≥ 4.5) và các cặp viền / vòng focus / icon (≥ 3).`, '');
lines.push('| Màu | `--color-primary` (solid) | `--color-primary-text` sáng | chữ — Sáng | viền/icon — Sáng | chữ — Tối | viền/icon — Tối | Cặp chưa đạt |', '|---|---|---|---|---|---|---|---|');
for (const brand of BRANDS) {
    const worst = { light: { 4.5: null, 3: null }, dark: { 4.5: null, 3: null } };
    const failed = [];
    for (const row of ROWS) {
        for (const scheme of both) {
            const result = row.fg === null ? null : measure(row, { scheme, brand });
            if (!passes(result, row.min)) failed.push(`${row.role} (${scheme === 'light' ? 'Sáng' : 'Tối'}${result ? ` ${shown(result.value).toFixed(2)}` : ''})`);
            if (result === null) continue;
            const bucket = row.min >= 4.5 ? 4.5 : 3;
            if (!worst[scheme][bucket] || result.value < worst[scheme][bucket].value) worst[scheme][bucket] = result;
        }
    }
    failures += failed.length;
    const ratioCell = (result, min) => (result ? `${passes(result, min) ? '✅' : '❌'} ${shown(result.value).toFixed(2)}` : '—');
    lines.push(`| ${brand.name} (\`${brand.key}\`) | \`${brand.solid}\` | \`${brand.ink}\` | ${ratioCell(worst.light[4.5], 4.5)} | ${ratioCell(worst.light[3], 3)} | ${ratioCell(worst.dark[4.5], 4.5)} | ${ratioCell(worst.dark[3], 3)} | ${failed.length ? failed.join('; ') : '0'} |`);
}

lines.push('', `**Storefront — chữ màu thương hiệu** (\`--color-primary-text\` cho chữ trên nền sáng: thẻ = \`--color-surface\`, nền trang = \`color-mix(in srgb, --surface ${pageShare * 100}%, --ink)\`; \`--color-primary-on-dark\` cho chữ trên nền tối \`--ink\`: footer, menu con, CTA phụ; \`--color-on-primary\` cho chữ trên nền \`--color-primary\`. Tên cũ \`--primary-text\` · \`--primary-on-ink\` mang đúng giá trị đó.)`, '');
lines.push('| Bộ màu | `--color-primary` | `--color-primary-text` | trên `--color-surface` | trên nền trang | `--color-primary-on-dark` | trên `--ink` | `--color-on-primary` | trên `--color-primary` |', '|---|---|---|---|---|---|---|---|---|');
for (const seed of SEEDS) {
    const text = storefrontPrimaryText(seed);
    const onInk = storefrontPrimaryOnInk(seed);
    const onPrimary = storefrontOnPrimary(seed);
    const measureOn = (fg, background) => ({ value: ratio(parseColor(fg, {}), parseColor(background, {})), fg, bg: background });
    const results = [measureOn(text, seed.surface), measureOn(text, storefrontPage(seed)), measureOn(onInk, seed.ink), measureOn(onPrimary, seed.primary)];
    if (results.some((result) => !passes(result, 4.5))) failures++;
    lines.push(`| ${seed.name} | \`${seed.primary}\` | \`${text}\` | ${cell(results[0], 4.5)} | ${cell(results[1], 4.5)} | \`${onInk}\` | ${cell(results[2], 4.5)} | \`${onPrimary}\` | ${cell(results[3], 4.5)} |`);
}

lines.push('', '**Storefront — placeholder** (chữ ví dụ trong ô = `color-mix(--body 80%, white)`; hướng dẫn đặt ở dòng gợi ý dưới ô, KHÔNG trong placeholder). Đo trên `--surface`.', '');
lines.push('| Bộ màu | `--body` | placeholder | trên `--surface` |', '|---|---|---|---|');
for (const seed of SEEDS) {
    const placeholder = hex(mix(parseColor(seed.body, {}), rgba(255, 255, 255), 0.8, 0.2, 'srgb'));
    const result = { value: ratio(parseColor(placeholder, {}), parseColor(seed.surface, {})), fg: placeholder, bg: seed.surface };
    if (!passes(result, 4.5)) failures++;
    lines.push(`| ${seed.name} | \`${seed.body}\` | \`${placeholder}\` | ${cell(result, 4.5)} |`);
}

lines.push('', failures === 0 ? '**Kết quả:** mọi cặp được gác đều đạt ngưỡng.' : `**Kết quả:** ${failures} cặp được gác chưa đạt.`);
console.log(lines.join('\n'));
process.exitCode = failures === 0 ? 0 : 1;
