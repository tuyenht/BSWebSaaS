// Tap check for storefront pages on a phone: every visible control must be the element a finger hits at
// its own centre (nothing drawn over it: a card's stretched-link overlay, a sticky bar, a slide), and a
// real tap on a rail's arrow must turn the rail and never open the card under it.
//
//   node scripts/storefront/tap-check.mjs --chrome=<path> [--widths=320,390] [--add-css=<css>] [--expect-arrows=<url>,…] [--ignore-certificate-errors] <url> [<url>…]
//
// A second pass checks the phone menu (the drawer): on the first page, at each width, every row (link, sub-link,
// chevron) is forced through rest / hover / active / focus-visible with CSS.forcePseudoState on a touch phone
// (hover: none, pointer: coarse), and its text must reach 4.5:1 (an icon-only chevron 3:1) against what is
// really behind it. It runs twice: with the site's own colours and with a red brand injected over them, so a
// desktop-bar state that leaks into the drawer, or a brand colour that fails on a fill, cannot pass.
//
// --add-css tries a style fix on a live site before it is deployed (the CSS is added to every page).
// --expect-arrows names pages that must show at least one rail arrow, so a page that lost its rails
// (or failed to load) cannot pass by having nothing to tap.
// --ignore-certificate-errors is passed on to Chrome: for a test server with a self-made certificate
// (CI serves the measurement site over HTTPS with Caddy's `tls internal`); never needed on a live site.
//
// Plain Node 22 (global WebSocket + fetch) talking to Chrome over the DevTools protocol: no dependency.
// Exit 0 = clean, 1 = a covered control or a bad tap, 2 = could not run.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const args = process.argv.slice(2);
const opt = (name, fallback) => (args.find((a) => a.startsWith(`--${name}=`)) ?? `=${fallback}`).split('=').slice(1).join('=');
const chromePath = opt('chrome', process.env.CHROME_PATH ?? '');
const widths = opt('widths', '320,390').split(',').map(Number);
const addCss = opt('add-css', '');
const expectArrows = opt('expect-arrows', '').split(',').filter(Boolean);
const ignoreCertErrors = args.includes('--ignore-certificate-errors');
const urls = args.filter((a) => !a.startsWith('--'));
if (chromePath === '' || urls.length === 0) {
    console.error('usage: tap-check.mjs --chrome=<path> [--widths=320,390] <url>…');
    process.exit(2);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const profile = mkdtempSync(join(tmpdir(), 'tap-check-'));
const port = 9300 + Math.floor(Math.random() * 500);
const chromeArgs = ['--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`];
if (ignoreCertErrors) {
    chromeArgs.push('--ignore-certificate-errors');
}
const chrome = spawn(chromePath, [...chromeArgs, 'about:blank'], { stdio: 'ignore' });
let chromeError;
chrome.on('error', (e) => { chromeError = e; });

let ws;
let seq = 0;
const pending = new Map();
const waiters = [];
const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params, sessionId }));
});

// What runs in the page. Controls hidden from everyone (display/visibility, a closed <details>, inert or
// aria-hidden parts such as off-screen slides) and visually hidden ones (the 1px skip link) are skipped.
// Each line box of a control is checked at its centre, so a link that wraps inside a sentence counts too.
const probe = `(async () => {
    const sel = 'a[href], button, [role="button"], summary, input:not([type=hidden]), select, textarea';
    const desc = (e) => e ? e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\\s+/).slice(0, 2).join('.') : '') : 'nothing';
    const bad = [];
    for (const el of document.querySelectorAll(sel)) {
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none') continue;
        if (el.closest('[hidden], [inert], [aria-hidden="true"]')) continue;
        const closed = el.closest('details:not([open])');
        if (closed && !el.closest('summary')) continue;
        el.scrollIntoView({ block: 'center', inline: 'nearest' });
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
        // The part a finger can reach: the line box cut to the window and to every scrolling or clipping
        // parent (a card half scrolled out of a rail is only tappable where it shows).
        const clips = [[0, 0, innerWidth, innerHeight]];
        for (let a = el.parentElement, fixed = cs.position === 'fixed'; a && !fixed; a = a.parentElement) {
            const c = getComputedStyle(a);
            fixed = c.position === 'fixed';
            if (c.overflowX !== 'visible' || c.overflowY !== 'visible') { const b = a.getBoundingClientRect(); clips.push([b.left, b.top, b.right, b.bottom]); }
        }
        for (const r of el.getClientRects()) {
            let [l, t, rr, bb] = [r.left, r.top, r.right, r.bottom];
            for (const [cl, ct, cr, cb] of clips) { l = Math.max(l, cl); t = Math.max(t, ct); rr = Math.min(rr, cr); bb = Math.min(bb, cb); }
            if (rr - l < 2 || bb - t < 2) continue;
            const x = (l + rr) / 2, y = (t + bb) / 2;
            const top = document.elementFromPoint(x, y);
            if (top === el || el.contains(top)) continue;
            // The arrows and slider controls are drawn on purpose over the edge of what they move (a card):
            // a smaller one of those over a bigger control is fine, and it is checked in its own turn and
            // must be on top. Anything else over a control, or a bigger thing over a smaller one, is the
            // fault: the finger lands on what is underneath.
            const over = top && top.closest('.sf-rail__btn, .sf-rot__arrow, .sf-slider__controls button');
            if (over && !over.contains(el)) {
                const ob = over.getBoundingClientRect(), eb = el.getBoundingClientRect();
                if (ob.width * ob.height < eb.width * eb.height) continue;
            }
            bad.push({ control: desc(el), text: (el.getAttribute('aria-label') || el.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 40), at: [Math.round(x), Math.round(y)], covered_by: desc(top) });
            break;
        }
    }
    return JSON.stringify(bad);
})()`;

// A touch phone: no hover, a coarse pointer (so the desktop-only hover rules and the sticky :hover a tap leaves are
// both exercised), and reduced motion.
const phoneMedia = [
    { name: 'prefers-reduced-motion', value: 'reduce' },
    { name: 'hover', value: 'none' },
    { name: 'any-hover', value: 'none' },
    { name: 'pointer', value: 'coarse' },
    { name: 'any-pointer', value: 'coarse' },
];

// A red brand as ThemeResolver derives it (the seed, a darkened variant readable on light, a lightened one on dark):
// a second palette over the site's own, because the colour a state paints with is a brand token.
const redBrand = ':root{--color-primary:#dc2626;--color-primary-text:#b91c1c;--color-primary-on-dark:#f87171;--color-on-primary:#fff}';

// What runs in the page for the drawer rows: lists the controls, then reports a colour pair per control.
const drawerList = `(async () => {
    document.documentElement.style.scrollBehavior = 'auto';
    const st = document.createElement('style');
    st.textContent = '*{transition:none!important;animation:none!important}';
    document.head.append(st);
    const burger = document.querySelector('.sf-burger');
    if (!burger) return JSON.stringify(-1);
    burger.click();
    await new Promise((r) => setTimeout(r, 400));
    for (const chev of document.querySelectorAll('.sf-nav--mob .sf-nav__item--parent > .sf-nav__chev')) {
        if (chev.getAttribute('aria-expanded') !== 'true') chev.click();
    }
    await new Promise((r) => setTimeout(r, 300));
    window.__rows = [...document.querySelectorAll('.sf-nav--mob :is(.sf-nav__link, .sf-nav__sublink, .sf-nav__chev)')].filter((e) => {
        const c = getComputedStyle(e);
        return c.display !== 'none' && c.visibility !== 'hidden' && e.getClientRects().length > 0 && !e.closest('[hidden]');
    });
    return JSON.stringify(window.__rows.length);
})()`;
const drawerRatio = (i) => `(() => {
    const e = window.__rows[${i}];
    e.scrollIntoView({ block: 'center' });
    const cv = document.createElement('canvas'); cv.width = cv.height = 1;
    const cx = cv.getContext('2d', { willReadFrequently: true });
    const rgba = (c) => { cx.clearRect(0, 0, 1, 1); cx.fillStyle = '#000'; cx.fillStyle = c; cx.fillRect(0, 0, 1, 1); const d = cx.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2], d[3] / 255]; };
    const over = (top, under) => { const a = top[3] + under[3] * (1 - top[3]); return a === 0 ? [255, 255, 255, 1] : [0, 1, 2].map((k) => (top[k] * top[3] + under[k] * under[3] * (1 - top[3])) / a).concat(a); };
    let bg = [255, 255, 255, 1];
    const chain = [];
    for (let a = e; a; a = a.parentElement) chain.push(a);
    for (const a of chain.reverse()) { const b = rgba(getComputedStyle(a).backgroundColor); if (b[3] > 0) bg = over(b, bg); }
    const fg = over(rgba(getComputedStyle(e).color), bg);
    const lum = (c) => { const f = c.slice(0, 3).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2]; };
    const [hi, lo] = [lum(fg), lum(bg)].sort((x, y) => y - x);
    const text = (e.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30);
    return JSON.stringify({ text: text || (e.getAttribute('aria-label') || '').slice(0, 30) || '(chevron)', icon: text === '', ratio: Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100 });
})()`;

const railState = `JSON.stringify({ u: location.href, s: [...document.querySelectorAll('.sf-rail__list')].map((e) => Math.round(e.scrollLeft)).join(',') })`;

let failures = 0;
try {
    let target;
    for (let i = 0; i < 50 && !target; i++) {
        await sleep(200);
        target = await fetch(`http://127.0.0.1:${port}/json/version`).then((r) => r.json()).catch(() => undefined);
    }
    if (!target) {
        throw new Error(chromeError ? `Chrome did not start: ${chromeError.message}` : 'Chrome did not start');
    }
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
    // Chrome gone: fail every call still waiting instead of hanging until the job times out.
    ws.onclose = () => {
        for (const p of pending.values()) {
            p.reject(new Error('Chrome closed the connection'));
        }
        pending.clear();
    };
    ws.onmessage = (m) => {
        const msg = JSON.parse(m.data);
        if (msg.method) {
            for (const w of waiters.filter((w) => w.method === msg.method && w.sessionId === msg.sessionId)) {
                waiters.splice(waiters.indexOf(w), 1);
                w.resolve();
            }
        }
        if (msg.id && pending.has(msg.id)) {
            const p = pending.get(msg.id);
            pending.delete(msg.id);
            msg.error ? p.reject(new Error(msg.error.message)) : p.resolve(msg.result);
        }
    };
    const evaluate = async (s, expression) => {
        const res = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }, s);
        if (res.exceptionDetails) {
            throw new Error(res.exceptionDetails.exception?.description ?? 'page script failed');
        }
        return JSON.parse(res.result.value);
    };

    // The phone menu on the first page, at each width, in the site's palette and in a red one.
    const drawerPass = async (width, url, palette, css) => {
        const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
        const { sessionId: s } = await send('Target.attachToTarget', { targetId, flatten: true });
        await send('Page.enable', {}, s);
        await send('DOM.enable', {}, s);
        await send('CSS.enable', {}, s);
        await send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 2, mobile: true }, s);
        await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }, s);
        await send('Emulation.setEmulatedMedia', { features: phoneMedia }, s);
        const loaded = new Promise((resolve) => waiters.push({ method: 'Page.loadEventFired', sessionId: s, resolve }));
        await send('Page.navigate', { url }, s);
        await Promise.race([loaded, sleep(20000)]);
        await sleep(800);
        await evaluate(s, `(() => { const st = document.createElement('style'); st.textContent = ${JSON.stringify(addCss + css)}; document.head.append(st); return '1'; })()`);
        const count = await evaluate(s, drawerList);
        const bad = [];
        if (count <= 0) {
            bad.push(`the phone menu has no rows to check (${count === -1 ? 'no burger button' : 'none visible'})`);
        } else {
            await send('DOM.getDocument', { depth: 0 }, s);
            for (let i = 0; i < count; i++) {
                for (const [name, forced] of [['rest', []], ['hover', ['hover']], ['active', ['active']], ['focus-visible', ['focus', 'focus-visible']]]) {
                    const obj = await send('Runtime.evaluate', { expression: `window.__rows[${i}]` }, s);
                    const { nodeId } = await send('DOM.requestNode', { objectId: obj.result.objectId }, s);
                    await send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: forced }, s);
                    await sleep(30);
                    const r = await evaluate(s, drawerRatio(i));
                    if (r.ratio < (r.icon ? 3 : 4.5)) {
                        bad.push(`"${r.text}" (${r.icon ? 'icon' : 'text'}) ${name} ${r.ratio}:1, needs ${r.icon ? 3 : 4.5}:1`);
                    }
                    await send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] }, s);
                }
            }
        }
        await send('Target.closeTarget', { targetId });
        console.log((bad.length ? 'FAIL ' : 'ok   ') + `${width}px ${url}: phone menu rows, ${palette} palette, ${count} rows x 4 states, ${bad.length} low contrast`);
        for (const b of bad) {
            console.log(`       menu row: ${b}`);
        }
        failures += bad.length;
    };
    for (const width of widths) {
        await drawerPass(width, urls[0], 'site', '');
        await drawerPass(width, urls[0], 'red brand', redBrand);
    }

    for (const width of widths) {
        for (const url of urls) {
            const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
            const { sessionId: s } = await send('Target.attachToTarget', { targetId, flatten: true });
            await send('Page.enable', {}, s);
            await send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 2, mobile: true }, s);
            await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }, s);
            // Reduced motion holds every slider and rotator still, so what is on top cannot change mid-check.
            await send('Emulation.setEmulatedMedia', { features: phoneMedia }, s);
            const loaded = new Promise((resolve) => waiters.push({ method: 'Page.loadEventFired', sessionId: s, resolve }));
            const nav = await send('Page.navigate', { url }, s);
            if (nav.errorText) {
                throw new Error(`${url} did not load: ${nav.errorText}`);
            }
            await Promise.race([loaded, sleep(20000)]);
            await sleep(800);
            await evaluate(s, `(async () => { const st = document.createElement('style'); st.textContent = ${JSON.stringify(addCss)}; document.head.append(st); document.documentElement.style.scrollBehavior = 'auto'; for (let y = 0; y < document.documentElement.scrollHeight; y += 400) { scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } return '1'; })()`);
            await sleep(500);

            const status = await evaluate(s, `JSON.stringify(performance.getEntriesByType('navigation')[0]?.responseStatus ?? 0)`);
            const controls = await evaluate(s, `JSON.stringify(document.querySelectorAll('a[href], button').length)`);
            const covered = await evaluate(s, probe);
            if ((status !== 0 && status !== 200) || controls === 0) {
                covered.push({ control: 'page', text: `answered ${status} with ${controls} controls`, at: [0, 0], covered_by: 'nothing to check' });
            }
            // Real taps on every rail arrow the page shows.
            const arrows = await evaluate(s, `JSON.stringify([...document.querySelectorAll('.sf-rail__btn')].map((b, i) => (b.hidden || getComputedStyle(b).display === 'none') ? null : i).filter((i) => i !== null))`);
            const taps = [];
            for (const i of arrows) {
                const box = await evaluate(s, `(() => { const b = document.querySelectorAll('.sf-rail__btn')[${i}]; b.scrollIntoView({ block: 'center' }); const r = b.getBoundingClientRect(); return JSON.stringify([r.left + r.width / 2, r.top + r.height / 2, b.className]); })()`);
                await sleep(200);
                const before = await evaluate(s, railState);
                const point = [{ x: box[0], y: box[1] }];
                await send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: point }, s);
                await send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }, s);
                await sleep(900);
                const after = await evaluate(s, railState);
                if (after.u !== before.u) {
                    taps.push(`${box[2]} opened ${after.u}`);
                    await send('Page.navigate', { url }, s);
                    await sleep(2000);
                } else if (after.s === before.s) {
                    // An arrow at the end of a rail that does not loop may rightly do nothing.
                    const canMove = await evaluate(s, `(() => { const b = document.querySelectorAll('.sf-rail__btn')[${i}]; return JSON.stringify(!b.disabled && b.getAttribute('aria-disabled') !== 'true'); })()`);
                    if (canMove) {
                        taps.push(`${box[2]} did not turn its rail`);
                    }
                }
            }
            await send('Target.closeTarget', { targetId });
            if (arrows.length === 0 && expectArrows.some((e) => url === e || url.endsWith(e))) {
                taps.push('no rail arrow on a page that must have one');
            }

            const line = `${width}px ${url}: ${covered.length} covered, ${arrows.length} arrows tapped, ${taps.length} bad taps`;
            console.log((covered.length || taps.length ? 'FAIL ' : 'ok   ') + line);
            for (const c of covered) {
                console.log(`       covered: ${c.control} "${c.text}" at ${c.at.join(',')} by ${c.covered_by}`);
            }
            for (const t of taps) {
                console.log(`       tap: ${t}`);
            }
            failures += covered.length + taps.length;
        }
    }
} catch (e) {
    console.error(`tap-check could not run: ${e.message}`);
    failures = -1;
} finally {
    ws?.close();
    chrome.kill();
    await sleep(300);
    rmSync(profile, { recursive: true, force: true });
}
process.exit(failures < 0 ? 2 : (failures > 0 ? 1 : 0));
