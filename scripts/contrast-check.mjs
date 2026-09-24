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
 * ROWS below say which token each piece of the UI paints with, as the Blade markup uses it today —
 * when a component switches tokens, update its row here in the same change. The storefront's
 * --primary-text is computed in PHP (ThemeResolver + ReadableColor, pinned by their Pest tests); the
 * storefront section mirrors that derivation.
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
const overrideRules = allRules.filter((rule) => rule.selector.startsWith(':root[') && !rule.selector.includes(' '));

const overrideSelectors = (state) => {
    const active = [];
    if (state.scheme === 'dark') active.push(":root[data-bs-theme='dark']");
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
    const active = overrideSelectors(state);
    overrideRules.filter((rule) => active.includes(rule.selector)).forEach(apply); // source order
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
    { item: 'Primary làm chữ/viền', role: 'Nút outline/ghost primary — chữ + viền', fg: '--color-accent', bg: ['--color-surface'], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Nút soft primary — chữ', fg: '--color-accent', bg: ['--color-surface', ['--color-admin-primary', 0.1]], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Tab đang chọn — chữ + gạch chân', fg: '--color-accent', bg: ['--color-surface'], min: 4.5 },
    { item: 'Primary làm chữ/viền', role: 'Ô nhập — viền khi focus', fg: '--color-accent', bg: ['--color-surface'], min: 3 },

    // Secondary text.
    { item: 'Chữ phụ', role: 'Chữ phụ (muted) trên thẻ', fg: '--color-body-muted', bg: ['--color-surface'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Chữ phụ (muted) trên nền trang', fg: '--color-body-muted', bg: ['--color-body'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Footer', fg: '--vz-footer-color', bg: ['--color-body'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Breadcrumb + phụ đề (page-header, trang Nội dung)', fg: '--color-body-muted', bg: ['--color-body'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Tab chưa chọn (x-admin::tabs, trang Nội dung)', fg: '--color-body-muted', bg: ['--color-surface'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Đầu cột bảng — x-admin::table', fg: '--color-body-muted', bg: ['--color-surface'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Đầu cột bảng — thead nền surface-muted (7 trang danh sách) · bộ đếm Jodit', fg: '--color-body-muted', bg: ['--color-surface-muted'], min: 4.5 },
    { item: 'Chữ phụ', role: 'Tiêu đề nhóm Customizer', fg: '--color-body-muted', bg: ['--color-surface'], min: 4.5 },

    // Semantic colors.
    ...['success', 'info', 'warning', 'danger'].flatMap((hue) => [
        { item: 'Màu ngữ nghĩa', role: `${hue} — chữ (thẻ / nền trang)`, fg: `--color-admin-${hue}-text`, bgs: [['--color-surface'], ['--color-body']], min: 4.5 },
        { item: 'Màu ngữ nghĩa', role: `${hue} — kiểu soft (badge / alert / nút; chữ + viền outline)`, fg: `--color-admin-${hue}-text`, bgs: [['--color-surface', [`--color-admin-${hue}`, 0.1]], ['--color-body', [`--color-admin-${hue}`, 0.1]]], min: 4.5 },
        { item: 'Màu ngữ nghĩa', role: `${hue} — alert: nút đóng (icon)`, fg: `--color-admin-${hue}-text`, bgs: [['--color-surface', [`--color-admin-${hue}`, 0.1]], ['--color-body', [`--color-admin-${hue}`, 0.1]]], min: 3 },
    ]),
    ...['success', 'info', 'danger'].map((hue) => (
        { item: 'Màu ngữ nghĩa', role: `${hue} solid — chữ trắng (nút, badge${hue === 'danger' ? ', số đếm sidebar' : ''})`, fg: WHITE, bg: [`--color-admin-${hue}-solid`], min: 4.5 }
    )),
    { item: 'Màu ngữ nghĩa', role: 'warning solid — chữ (nút, badge)', fg: '--color-admin-dark', bg: ['--color-admin-warning'], min: 4.5 },

    // The input's own boundary, against its fill and against the page around it.
    { item: 'Viền ô nhập', role: 'Viền ô nhập (input / select / textarea)', fg: '--color-border-input', bgs: [['--color-surface'], ['--color-body']], min: 3 },

    // Focus indicators.
    { item: 'Focus', role: 'Vòng focus — nút', fg: '--focus-ring-color', bgs: [['--color-surface'], ['--color-body']], min: 3 },
    { item: 'Focus', role: 'Vòng focus — tab', fg: '--focus-ring-color', bg: ['--color-surface'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — ô nhập', fg: '--focus-ring-color', bg: ['--color-surface'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — link / mục sidebar', state: { zone: '.admin-sidebar' }, fg: '--focus-ring-color', bg: ['--vz-vertical-menu-bg'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — mục sidebar sáng', state: { zone: '.admin-sidebar', sidebar: 'light' }, fg: '--focus-ring-color', bg: ['--vz-vertical-menu-bg'], min: 3 },
    { item: 'Focus', role: 'Vòng focus — header Customizer (nền navy, theo chữ trắng)', fg: WHITE, bg: ['--color-admin-primary'], min: 3 },

    // Sidebar ink (default dark sidebar; the dark scheme swaps in its own sidebar values).
    { item: 'Sidebar', role: 'Sidebar — mục thường', fg: '--vz-vertical-menu-item-color', bg: ['--vz-vertical-menu-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar — mục đang chọn (chữ trên nền active)', fg: '--vz-vertical-menu-item-active-color', bg: ['--vz-vertical-menu-bg', '--vz-vertical-menu-item-active-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar — vạch chỉ báo mục đang chọn', fg: '--vz-vertical-menu-item-active-color', bgs: [['--vz-vertical-menu-bg'], ['--vz-vertical-menu-bg', '--vz-vertical-menu-item-active-bg']], min: 3 },
    { item: 'Sidebar', role: 'Sidebar sáng — mục thường', state: { sidebar: 'light' }, fg: '--vz-vertical-menu-item-color', bg: ['--vz-vertical-menu-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar sáng — tiêu đề nhóm', state: { sidebar: 'light' }, fg: '--vz-vertical-menu-title-color', bg: ['--vz-vertical-menu-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar sáng — mục đang chọn (chữ trên nền active)', state: { sidebar: 'light' }, fg: '--vz-vertical-menu-item-active-color', bg: ['--vz-vertical-menu-bg', '--vz-vertical-menu-item-active-bg'], min: 4.5 },
    { item: 'Sidebar', role: 'Sidebar sáng — vạch chỉ báo mục đang chọn', state: { sidebar: 'light' }, fg: '--vz-vertical-menu-item-active-color', bgs: [['--vz-vertical-menu-bg'], ['--vz-vertical-menu-bg', '--vz-vertical-menu-item-active-bg']], min: 3 },

    // Gradient sidebars, every point of the band.
    ...['gradient', 'gradient-2', 'gradient-3', 'gradient-4'].flatMap((variant) => [
        { item: 'Sidebar gradient', role: `${variant} — mục thường (mọi điểm của dải)`, state: { sidebar: variant }, fg: '--vz-vertical-menu-item-color', bg: [{ gradient: variant }], min: 4.5 },
        { item: 'Sidebar gradient', role: `${variant} — mục đang chọn, chữ trên nền active (mọi điểm của dải)`, state: { sidebar: variant }, fg: '--vz-vertical-menu-item-active-color', bg: [{ gradient: variant }, '--vz-vertical-menu-item-active-bg'], min: 4.5 },
    ]),

    // Modal close button (an icon: 3:1).
    { item: 'Modal', role: 'Modal — nút đóng (icon)', fg: '--color-placeholder', bg: ['--color-surface'], min: 3 },

    // Watch-list — reported, never failing the run.
    { item: 'Theo dõi', role: 'Nút secondary solid — chữ trắng', fg: WHITE, bg: ['--color-admin-secondary'], min: 4.5 },
    { item: 'Theo dõi', role: 'Topbar — dòng vai trò dưới tên, link điều hướng platform (chữ dùng màu icon)', fg: '--vz-header-item-sub-color', bg: ['--vz-header-bg'], min: 4.5 },
    { item: 'Theo dõi', role: 'Placeholder ô nhập (là chữ)', fg: '--color-placeholder', bg: ['--color-surface'], min: 4.5 },
    { item: 'Theo dõi', role: 'Sidebar tối — tiêu đề nhóm (chưa hiển thị ở layout)', fg: '--vz-vertical-menu-title-color', bg: ['--vz-vertical-menu-bg'], min: 4.5 },
    { item: 'Theo dõi', role: 'Avatar / mục đang chọn settings-shell — chữ primary', fg: '--color-admin-primary', bg: ['--color-surface', ['--color-admin-primary', 0.1]], min: 4.5 },
    { item: 'Theo dõi', role: 'Badge "dark" soft — chữ', fg: '--color-admin-dark', bg: ['--color-surface', ['--color-admin-dark', 0.1]], min: 4.5 },
    { item: 'Theo dõi', role: 'Chữ faint — token trang trí, còn dùng làm chữ ở các màn khác', fg: '--color-body-faint', bg: ['--color-surface'], min: 4.5 },
];

// ── Storefront --primary-text ───────────────────────────────────────────────────────────────
// Mirrors App\Modules\Theme\Theming\ReadableColor::on() as ThemeResolver::cssVariables() calls it.
// Sample brands only (no tenant data): the default seed, a light amber, a very light yellow.
const themeResolver = read('app/Modules/Theme/Theming/ThemeResolver.php');
const defaultSeed = (key) => themeResolver.match(new RegExp(`'${key}'\\s*=>\\s*'(#[0-9a-fA-F]{3,6})'`))?.[1];
const SEEDS = [
    { name: 'mặc định (ThemeResolver::DEFAULTS)', primary: defaultSeed('primary'), surface: defaultSeed('surface'), ink: defaultSeed('ink') },
    { name: 'thương hiệu amber mẫu', primary: '#f59e0b', surface: '#ffffff', ink: '#0f172a' },
    { name: 'thương hiệu vàng sáng mẫu', primary: '#facc15', surface: '#ffffff', ink: '#111111' },
];

function readableOn(color, background, minimum = 4.5) {
    const rgb = parseColor(color, {});
    const surface = parseColor(background, {});
    const surfaceLuminance = luminance(surface);
    const target = (surfaceLuminance + 0.05) / 0.05 >= 1.05 / (surfaceLuminance + 0.05) ? rgba(0, 0, 0) : rgba(255, 255, 255);
    for (let step = 0; step <= 20; step++) {
        const weight = step / 20;
        const candidate = rgba(...['r', 'g', 'b'].map((k) => Math.round(rgb[k] + (target[k] - rgb[k]) * weight)));
        if (ratio(candidate, surface) >= minimum) return hex(candidate);
    }
    return hex(target);
}

const storefrontPrimaryText = (seed) => readableOn(seed.primary, seed.surface);
// storefront.css: body { background: color-mix(in srgb, var(--surface) 97%, var(--ink)) }
const storefrontPage = (seed) => hex(mix(parseColor(seed.surface, {}), parseColor(seed.ink, {}), 0.97, 0.03, 'srgb'));

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

for (const row of ROWS) {
    const results = both.map((scheme) => (row.fg === null ? null : measure(row, { scheme })));
    if (row.item !== 'Theo dõi' && results.some((result) => !passes(result, row.min))) failures++;
    lines.push(`| ${row.item} | ${row.role} | ${row.min} | ${cell(results[0], row.min, row.none)} | ${cell(results[1], row.min, row.none)} |`);
}

lines.push('', '**Storefront `--primary-text`** (sinh từ `--primary`; nền thẻ = `--surface`, nền trang = `color-mix(in srgb, --surface 97%, --ink)`)', '');
lines.push('| Bộ màu | `--primary` | `--primary-text` | trên `--surface` | trên nền trang |', '|---|---|---|---|---|');
for (const seed of SEEDS) {
    const text = storefrontPrimaryText(seed);
    const results = [seed.surface, storefrontPage(seed)].map((background) => ({
        value: ratio(parseColor(text, {}), parseColor(background, {})),
        fg: text,
        bg: background,
    }));
    if (results.some((result) => !passes(result, 4.5))) failures++;
    lines.push(`| ${seed.name} | \`${seed.primary}\` | \`${text}\` | ${cell(results[0], 4.5)} | ${cell(results[1], 4.5)} |`);
}

lines.push('', failures === 0 ? '**Kết quả:** mọi cặp được gác đều đạt ngưỡng.' : `**Kết quả:** ${failures} cặp được gác chưa đạt.`);
console.log(lines.join('\n'));
process.exitCode = failures === 0 ? 0 : 1;
