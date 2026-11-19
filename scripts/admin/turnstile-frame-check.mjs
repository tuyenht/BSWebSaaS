// Frame check for the Cloudflare Turnstile box on the sign-in page: at each display scale and window width,
// the page's frame (.auth-turnstile) must sit on the widget's own edges and show a border on all four sides.
//
//   node scripts/admin/turnstile-frame-check.mjs --chrome=<path> [--scales=1,1.25,1.5,2] [--widths=1280,412,390,360,320] <url>
//
// Why real display scales: the lost right edge (Windows scaling 125%/150% makes the field 320.4px wide)
// only shows when Chrome itself runs at that scale (--force-device-scale-factor); emulating the scale over
// the DevTools protocol draws it whole. So one Chrome runs per scale, and widths are emulated inside it.
//
// Two checks per scale × width:
//   1. geometry: the widget's iframe (found through Cloudflare's closed shadow root) and the frame's border
//      box share every edge within 1px, so no strip of Cloudflare's own border or of the page shows inside;
//   2. pixels: across the middle of each side, some pixel within 3 device pixels of the edge differs from
//      the page just outside by at least THRESHOLD (sum of the RGB differences), i.e. a border is drawn.
// The widget only has to be drawn, not solved: a real site key in a headless browser may stop at the challenge.
// A local page can use Cloudflare's test key 1x00000000000000000000AA (passes at once, adds a red notice).
//
// Plain Node 22 (global WebSocket + fetch) talking to Chrome over the DevTools protocol: no dependency.
// Exit 0 = every frame whole, 1 = a frame check failed, 2 = could not run (no widget, no Chrome).
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateSync } from 'node:zlib';

const args = process.argv.slice(2);
const opt = (name, fallback) => (args.find((a) => a.startsWith(`--${name}=`)) ?? `=${fallback}`).split('=').slice(1).join('=');
const chromePath = opt('chrome', process.env.CHROME_PATH ?? '');
const scales = opt('scales', '1,1.25,1.5,2').split(',').map(Number);
const widths = opt('widths', '1280,412,390,360,320').split(',').map(Number);
const url = args.find((a) => !a.startsWith('--'));
const THRESHOLD = 30;
if (chromePath === '' || !url) {
    console.error('usage: turnstile-frame-check.mjs --chrome=<path> [--scales=1,1.25] [--widths=1280,390] <url>');
    process.exit(2);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Minimal PNG reader (8-bit RGB/RGBA, as Chrome writes them).
function readPng(buf) {
    let p = 8, w = 0, h = 0, type = 0;
    const idat = [];
    while (p < buf.length) {
        const len = buf.readUInt32BE(p), tag = buf.toString('ascii', p + 4, p + 8), d = buf.subarray(p + 8, p + 8 + len);
        if (tag === 'IHDR') { w = d.readUInt32BE(0); h = d.readUInt32BE(4); type = d[9]; }
        if (tag === 'IDAT') { idat.push(d); }
        p += 12 + len;
    }
    const bpp = type === 6 ? 4 : 3, stride = w * bpp, raw = inflateSync(Buffer.concat(idat)), out = Buffer.alloc(h * stride);
    for (let y = 0; y < h; y++) {
        const f = raw[y * (stride + 1)], row = raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1));
        for (let x = 0; x < stride; x++) {
            const a = x >= bpp ? out[y * stride + x - bpp] : 0, b = y ? out[(y - 1) * stride + x] : 0, c = x >= bpp && y ? out[(y - 1) * stride + x - bpp] : 0;
            let v = row[x];
            if (f === 1) { v += a; } else if (f === 2) { v += b; } else if (f === 3) { v += (a + b) >> 1; } else if (f === 4) {
                const pp = a + b - c, pa = Math.abs(pp - a), pb = Math.abs(pp - b), pc = Math.abs(pp - c);
                v += pa <= pb && pa <= pc ? a : pb <= pc ? b : c;
            }
            out[y * stride + x] = v & 255;
        }
    }
    return { w, h, px: (x, y) => { const i = y * stride + x * bpp; return [out[i], out[i + 1], out[i + 2]]; } };
}
// The Chrome and profile of the scale being run, so a stop (Ctrl+C, timeout) still removes them.
let current = null;
const cleanUp = () => {
    if (current) {
        current.chrome.kill('SIGKILL');
        rmSync(current.profile, { recursive: true, force: true });
        current = null;
    }
};
for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => { cleanUp(); process.exit(2); });
}
const diff = (a, b) => Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1]) + Math.abs(a[2] - b[2]);

const HEIGHT = 1600; // tall enough that the frame is on screen without scrolling (viewport = page coordinates)
let failed = false;

async function runScale(scale) {
    const profile = mkdtempSync(join(tmpdir(), 'turnstile-frame-'));
    // Port 0: Chrome picks a free port and writes it to DevToolsActivePort, so a stray Chrome on a fixed
    // port can never answer for this one.
    const chrome = spawn(chromePath, ['--headless=new', '--no-sandbox', '--remote-debugging-port=0', `--force-device-scale-factor=${scale}`,
        `--window-size=1280,${HEIGHT}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore' });
    current = { chrome, profile };
    let ws = null;
    try {
        let port = 0;
        for (let i = 0; i < 50 && !port; i++) {
            await sleep(200);
            const file = join(profile, 'DevToolsActivePort');
            port = existsSync(file) ? Number(readFileSync(file, 'utf8').split('\n')[0]) : 0;
        }
        let tabs = [];
        for (let i = 0; port && i < 50 && !tabs.some((t) => t.type === 'page'); i++) {
            tabs = await fetch(`http://127.0.0.1:${port}/json`).then((r) => r.json()).catch(() => []);
            if (!tabs.some((t) => t.type === 'page')) { await sleep(200); }
        }
        const page = tabs.find((t) => t.type === 'page');
        if (!page) { throw new Error('Chrome did not start'); }
        ws = new WebSocket(page.webSocketDebuggerUrl);
        await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });
        let id = 0;
        const pending = new Map();
        ws.onmessage = (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id).resolve(m); pending.delete(m.id); } };
        ws.onclose = () => { for (const p of pending.values()) { p.reject(new Error('Chrome closed the connection')); } pending.clear(); };
        // Every call gives up after 20 s, so a stalled Chrome stops the run instead of hanging it.
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const i = ++id;
            const timer = setTimeout(() => { pending.delete(i); reject(new Error(`${method} got no answer in 20 s`)); }, 20000);
            pending.set(i, { resolve: (m) => { clearTimeout(timer); resolve(m); }, reject: (e) => { clearTimeout(timer); reject(e); } });
            ws.send(JSON.stringify({ id: i, method, params }));
        });
        const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, returnByValue: true })).result?.result?.value;

        for (const width of widths) {
            // deviceScaleFactor 0 keeps Chrome's real scale; only the width is emulated.
            await send('Emulation.setDeviceMetricsOverride', { width, height: HEIGHT, deviceScaleFactor: 0, mobile: false });
            // Leave the previous page first, so its widget can never be measured as this one's.
            await send('Page.navigate', { url: 'about:blank' });
            await send('Page.navigate', { url });
            for (let i = 0; i < 80 && (await evaluate('location.href !== "about:blank" && document.readyState')) !== 'complete'; i++) { await sleep(250); }
            await evaluate('window.scrollTo(0, 0)');
            // The page may still move (logo, fonts): read the frame, the widget, then the frame again, and
            // accept only a pair taken while nothing moved.
            const hostRect = () => evaluate('(()=>{const e=document.querySelector(".auth-turnstile");if(!e)return null;const r=e.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height}})()');
            const widgetRect = async () => {
                const doc = await send('DOM.getDocument', { depth: -1, pierce: true });
                const found = [];
                const walk = (n) => {
                    if (n.nodeName === 'IFRAME' && /challenges\.cloudflare\.com/.test((n.attributes ?? []).join(' '))) { found.push(n); }
                    (n.children ?? []).forEach(walk); (n.shadowRoots ?? []).forEach(walk);
                };
                walk(doc.result.root);
                for (const n of found) {
                    const q = (await send('DOM.getBoxModel', { nodeId: n.nodeId })).result?.model?.border;
                    if (q && q[5] - q[1] > 20) { return { x: q[0], y: q[1], w: q[2] - q[0], h: q[5] - q[1] }; }
                }
                return null;
            };
            const same = (a, b) => a && b && ['x', 'y', 'w', 'h'].every((k) => Math.abs(a[k] - b[k]) < 0.01);
            let frame = null, host = null;
            for (let i = 0; i < 60 && !frame; i++) {
                await sleep(250);
                frame = await widgetRect();
            }
            if (!frame) { throw new Error(`no Turnstile widget drawn at ${width}px`); }
            for (let i = 0, settled = false; i < 20 && !settled; i++) {
                await sleep(300);
                const before = await hostRect();
                frame = await widgetRect();
                host = await hostRect();
                settled = same(before, host);
            }
            if (!host) { throw new Error('no .auth-turnstile frame on the page'); }

            const edges = {
                left: host.x - frame.x, top: host.y - frame.y,
                right: host.x + host.w - (frame.x + frame.w), bottom: host.y + host.h - (frame.y + frame.h),
            };
            const geoBad = Object.entries(edges).filter(([, d]) => Math.abs(d) > 1).map(([k, d]) => `${k} ${d.toFixed(2)}px`);

            const pad = 4;
            const shot = await send('Page.captureScreenshot', { format: 'png', clip: { x: host.x - pad, y: host.y - pad, width: host.w + 2 * pad, height: host.h + 2 * pad, scale: 1 } });
            const img = readPng(Buffer.from(shot.result.data, 'base64'));
            const k = img.w / (host.w + 2 * pad); // device pixels per CSS pixel in the image
            const L = Math.round(pad * k), T = Math.round(pad * k), R = Math.round((pad + host.w) * k) - 1, B = Math.round((pad + host.h) * k) - 1;
            const midX = Math.round(img.w / 2), midY = Math.round(img.h / 2);
            const side = (name, outside, scan) => {
                const best = Math.max(...scan.map((p) => diff(img.px(p[0], p[1]), img.px(outside[0], outside[1]))));
                return best >= THRESHOLD ? null : `${name} border missing (best ${best})`;
            };
            const span = (from, to) => Array.from({ length: to - from + 1 }, (_, i) => from + i);
            const pxBad = [
                side('left', [L - 3, midY], span(L - 2, L + 3).map((x) => [x, midY])),
                side('right', [R + 3, midY], span(R - 3, R + 2).map((x) => [x, midY])),
                side('top', [midX, T - 3], span(T - 2, T + 3).map((y) => [midX, y])),
                side('bottom', [midX, B + 3], span(B - 3, B + 2).map((y) => [midX, y])),
            ].filter(Boolean);

            const ok = geoBad.length === 0 && pxBad.length === 0;
            failed ||= !ok;
            console.log(`${ok ? 'ok  ' : 'FAIL'} scale ${scale} · ${width}px · frame ${host.w.toFixed(2)}×${host.h.toFixed(2)} · widget ${frame.w.toFixed(2)}×${frame.h.toFixed(2)}`
                + (geoBad.length ? ` · edges off: ${geoBad.join(', ')}` : '') + (pxBad.length ? ` · ${pxBad.join(', ')}` : ''));
        }
    } finally {
        ws?.close();
        chrome.kill('SIGKILL');
        await sleep(300);
        cleanUp();
    }
}

try {
    for (const scale of scales) {
        await runScale(scale);
    }
} catch (e) {
    console.error(`could not run: ${e.message}`);
    process.exit(failed ? 1 : 2); // a frame already seen failing outranks a later stop
}
console.log(failed ? 'Turnstile frame: FAILED' : 'Turnstile frame: every edge drawn');
process.exit(failed ? 1 : 0);
