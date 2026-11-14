#!/usr/bin/env node
/**
 * Static guard for the storefront menu: the desktop bar and the phone drawer render the same partial,
 * so a state rule on a shared menu class (.sf-nav__link / __chev / __sublink with :hover, :focus,
 * :active, [aria-current] or .is-…) that is not scoped by .sf-nav--desk or .sf-nav--mob leaks into
 * both. The desktop's dark-bar hover once painted a dark fill under the drawer's dark text after a tap.
 *
 *   node scripts/sf-nav-scope-check.mjs [path/to/storefront.css]
 *
 * :where(.sf-nav--desk) counts as a scope. .sf-nav__link--cta is a distinct class painted the same on
 * both navs on purpose, so it is exempt by name. Pure Node, no dependency. Exit 1 = an unscoped rule.
 */
import { readFileSync } from 'node:fs';

const file = process.argv[2] ?? new URL('../resources/css/storefront.css', import.meta.url).pathname;
const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));

const shared = /\.sf-nav__(link|chev|sublink)(?![\w-])/;
const state = /:(hover|focus|focus-visible|focus-within|active)\b|\[aria-current|\.is-[\w-]+/;
const scope = /\.sf-nav--(desk|mob)(?![\w-])/;

// Split a selector list at top-level commas only (a comma inside :is(...) / :has(...) stays).
const topLevelList = (list) => {
    const out = [];
    let depth = 0;
    let cur = '';
    for (const ch of list) {
        depth += ch === '(' || ch === '[' ? 1 : (ch === ')' || ch === ']' ? -1 : 0);
        if (ch === ',' && depth === 0) {
            out.push(cur);
            cur = '';
        } else {
            cur += ch;
        }
    }
    out.push(cur);
    return out.map((x) => x.trim()).filter(Boolean);
};

// Selector lists sit before each "{" (a nested at-rule's own "{" has no shared class, so it is skipped).
const bad = [];
const re = /([^{}]+)\{/g;
let m;
while ((m = re.exec(css)) !== null) {
    const line = css.slice(0, m.index + m[1].length - m[1].trimStart().length).split('\n').length;
    for (const sel of topLevelList(m[1])) {
        const bare = sel.replace(/\.sf-nav__link--cta/g, '');
        if (shared.test(bare) && state.test(bare) && !scope.test(bare)) {
            bad.push(`${line}: ${sel}`);
        }
    }
}

if (bad.length > 0) {
    console.error('Menu state rules without .sf-nav--desk or .sf-nav--mob (they would apply to the phone drawer too):');
    for (const b of bad) {
        console.error(`  storefront.css:${b}`);
    }
    process.exit(1);
}
console.log('sf-nav scope check: every menu state rule is scoped to .sf-nav--desk or .sf-nav--mob');
