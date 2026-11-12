// Tap check for storefront pages on a phone: every visible control must be the element a finger hits at
// its own centre (nothing drawn over it: a card's stretched-link overlay, a sticky bar, a slide), and a
// real tap on a rail's arrow must turn the rail and never open the card under it.
//
//   node scripts/storefront/tap-check.mjs --chrome=<path> [--widths=320,390] [--add-css=<css>] <url> [<url>…]
//
// --add-css tries a style fix on a live site before it is deployed (the CSS is added to every page).
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
const urls = args.filter((a) => !a.startsWith('--'));
if (chromePath === '' || urls.length === 0) {
    console.error('usage: tap-check.mjs --chrome=<path> [--widths=320,390] <url>…');
    process.exit(2);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const profile = mkdtempSync(join(tmpdir(), 'tap-check-'));
const port = 9300 + Math.floor(Math.random() * 500);
const chrome = spawn(chromePath, ['--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });

let ws;
let seq = 0;
const pending = new Map();
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
        for (let a = el.parentElement; a; a = a.parentElement) {
            const c = getComputedStyle(a);
            if (c.overflowX !== 'visible' || c.overflowY !== 'visible') { const b = a.getBoundingClientRect(); clips.push([b.left, b.top, b.right, b.bottom]); }
        }
        for (const r of el.getClientRects()) {
            let [l, t, rr, bb] = [r.left, r.top, r.right, r.bottom];
            for (const [cl, ct, cr, cb] of clips) { l = Math.max(l, cl); t = Math.max(t, ct); rr = Math.min(rr, cr); bb = Math.min(bb, cb); }
            if (rr - l < 2 || bb - t < 2) continue;
            const x = (l + rr) / 2, y = (t + bb) / 2;
            const top = document.elementFromPoint(x, y);
            if (top === el || el.contains(top)) continue;
            // A smaller control drawn on purpose over a bigger one (a rail's arrow on a card's edge) is fine:
            // the small one is checked in its own turn and must be on top. A bigger thing over a smaller
            // control is the fault: the finger lands on what is underneath.
            const over = top && top.closest(sel);
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

const railState = `JSON.stringify({ u: location.href, s: [...document.querySelectorAll('.sf-rail__list')].map((e) => Math.round(e.scrollLeft)).join(',') })`;

let failures = 0;
try {
    let target;
    for (let i = 0; i < 50 && !target; i++) {
        await sleep(200);
        target = await fetch(`http://127.0.0.1:${port}/json/version`).then((r) => r.json()).catch(() => undefined);
    }
    if (!target) {
        throw new Error('Chrome did not start');
    }
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
    ws.onmessage = (m) => {
        const msg = JSON.parse(m.data);
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

    for (const width of widths) {
        for (const url of urls) {
            const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
            const { sessionId: s } = await send('Target.attachToTarget', { targetId, flatten: true });
            await send('Page.enable', {}, s);
            await send('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 2, mobile: true }, s);
            await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }, s);
            // Reduced motion holds every slider and rotator still, so what is on top cannot change mid-check.
            await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] }, s);
            await send('Page.navigate', { url }, s);
            await sleep(2500);
            await evaluate(s, `(async () => { const st = document.createElement('style'); st.textContent = ${JSON.stringify(addCss)}; document.head.append(st); document.documentElement.style.scrollBehavior = 'auto'; for (let y = 0; y < document.documentElement.scrollHeight; y += 400) { scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } return '1'; })()`);
            await sleep(500);

            const covered = await evaluate(s, probe);
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
