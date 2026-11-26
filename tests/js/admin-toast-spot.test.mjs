// Where a toast stands while a dialog is open (resources/js/admin-toast-spot.js): it moves only when it
// really lies over one of the dialog's controls — above the dialog, beside it, above a drawer's footer, or
// under the dialog's own layer and dim. `node --test tests/js/admin-toast-spot.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { pretendToBeVisual: true });
const { window } = dom;
globalThis.window = window;
globalThis.document = window.document;
globalThis.MutationObserver = window.MutationObserver;
globalThis.requestAnimationFrame = window.requestAnimationFrame.bind(window);
const { toastSpot, watch, unwatch } = await import('../../resources/js/admin-toast-spot.js');
const doc = window.document;

// jsdom lays nothing out: each scene gives its elements their boxes, drawn in the order listed (last on top)
let painted = [];
const inside = (r, x, y) => x >= r.left && x < r.right && y >= r.top && y < r.bottom;
doc.elementsFromPoint = (x, y) => painted.filter(([el, r]) => el.isConnected && inside(r, x, y)).map(([el]) => el).reverse();
function lay(el, r, counter, clipped = false) {
    const box = { ...r, x: r.left, y: r.top, width: r.right - r.left, height: r.bottom - r.top };
    el.getBoundingClientRect = () => {
        if (counter) {
            counter.n++;
        }

        return box;
    };
    el.getClientRects = () => [box];
    if (!clipped) {
        painted.push([el, r]);
    }
}
const frame = () => new Promise((ok) => window.requestAnimationFrame(() => window.requestAnimationFrame(ok)));
const R = (top, bottom, left, right) => ({ top, bottom, left, right });

// the toast's own layer comes from its class (z-95), as on the page, not from its inline style
const TOAST = '<style>.z-95 { position: fixed; z-index: 95 }</style><div id="t" data-toast-spot class="z-95"><div id="tb" data-tone="success"><span>Đã lưu</span><button id="tc">Đóng</button></div></div>';

/**
 * Draws `html` on a vw × vh screen, the toast box at `toast`, the boxes in `rects` (id → rect, in paint order);
 * `clipped` ids have a box but are cut off by a scrolling parent, so a tap never reaches them.
 */
async function scene({ vw, vh, html, rects, toast, clipped = [] }) {
    Object.defineProperty(window, 'innerWidth', { value: vw, configurable: true });
    Object.defineProperty(window, 'innerHeight', { value: vh, configurable: true });
    painted = [];
    doc.body.innerHTML = html + TOAST;
    for (const [id, r] of Object.entries(rects)) {
        lay(doc.getElementById(id), r, null, clipped.includes(id));
    }
    const root = doc.getElementById('t');
    const box = doc.getElementById('tb');
    const reads = { n: 0 };
    lay(box, toast, reads);
    lay(doc.getElementById('tc'), { ...toast, left: toast.right - 80 });
    const timer = { hold: 0, release: 0 };
    // the box's timer as the toast component keeps it: hold() stops a running one, release() starts it again
    const d = { timer: 1, hold() { this.timer = null; timer.hold++; }, release() { this.timer = 1; timer.release++; } };
    box._x_dataStack = [d];
    watch(root);
    await frame();

    return { root, timer, reads, d, spot: () => root.getAttribute('data-spot'), done: async () => { unwatch(root); await frame(); } };
}

// a phone (390 × 844): the toast at its usual spot, 16px from the bottom, 87px tall, 12px from each side
const phone = { vw: 390, vh: 844, toast: R(741, 828, 12, 378) };
const wide = { vw: 1366, vh: 900, toast: R(818, 884, 403, 963) };

test('no dialog, or a dialog clear of the toast: the usual spot', () => {
    assert.deepEqual(toastSpot({ ...phone, box: null }), { mode: 'def' });
    assert.deepEqual(toastSpot({ ...phone, box: R(300, 600, 24, 366) }), { mode: 'def' });
});

test('a question sheet from the bottom (its «Ở lại» under the toast): the toast stands 8px above the sheet', () => {
    const spot = toastSpot({ ...phone, box: R(560, 844, 0, 390), hits: true });
    assert.deepEqual(spot, { mode: 'up', bottom: 844 - 560 + 8 });
    assert.ok(844 - spot.bottom - 87 >= 8); // its top edge stays at least 8px below the top of the screen
});

test('a dialog the toast touches but whose controls it does not cover: the usual spot', () => {
    assert.deepEqual(toastSpot({ ...phone, box: R(68, 844, 0, 390), hits: false }), { mode: 'def' });
});

test('a sheet too tall to stand above, full width: under the dialog', () => {
    assert.deepEqual(toastSpot({ ...phone, box: R(68, 844, 0, 390), hits: true }), { mode: 'under' });
});

test('a side panel on a wide screen: the toast moves into the free side, narrower when it must be', () => {
    assert.deepEqual(toastSpot({ ...wide, box: R(0, 900, 886, 1366), hits: true }), { mode: 'side', left: 443, width: 560 });
    assert.deepEqual(toastSpot({ ...wide, box: R(0, 900, 0, 480), hits: true }), { mode: 'side', left: 923, width: 560 });
    assert.deepEqual(toastSpot({ ...wide, vw: 900, toast: R(818, 884, 170, 730), box: R(0, 900, 400, 900), hits: true }), { mode: 'side', left: 200, width: 384 });
    // a side less than 280px wide is no room
    assert.deepEqual(toastSpot({ vw: 700, vh: 900, toast: R(818, 884, 70, 630), box: R(0, 900, 260, 700), hits: true }), { mode: 'under' });
});

test('a drawer on a phone: above its footer buttons when that fits below its header, else where it is', () => {
    const at = { ...phone, box: R(0, 844, 0, 390), hits: true, keep: true, head: R(0, 64, 0, 390) };
    assert.deepEqual(toastSpot({ ...at, foot: R(724, 844, 0, 390) }), { mode: 'up', bottom: 844 - 724 + 8 });
    assert.deepEqual(toastSpot({ ...at, foot: R(150, 844, 0, 390) }), { mode: 'keep' });
    assert.deepEqual(toastSpot({ ...at, foot: null }), { mode: 'keep' });
});

// ---- in the page: what the placement measures and what it does to the toast ----

test('the theme customizer (a panel at z 50 over a dim at z 40) on a phone: the toast goes under both, never over the panel', async () => {
    const s = await scene({
        ...phone,
        html: `<div class="contents"><div id="dim" style="position:fixed;z-index:40"></div>
            <section id="cz" role="dialog" aria-modal="true" style="position:fixed;z-index:50"><header id="czh"><button id="czx">✕</button></header>
            <footer id="czf"><button id="reset">Đặt lại</button></footer></section></div>`,
        rects: { dim: R(0, 844, 0, 390), cz: R(0, 844, 70, 390), czh: R(0, 48, 70, 390), czx: R(4, 44, 330, 370), czf: R(778, 844, 70, 390), reset: R(790, 830, 82, 378) },
    });
    assert.equal(s.spot(), 'under');
    const z = Number(s.root.style.zIndex);
    assert.ok(s.root.style.zIndex !== '' && z < 40, `z-index ${s.root.style.zIndex} must be below the dim (40) and the panel (50)`);
    assert.equal(s.timer.hold, 1);
    await s.done();
});

test('a dialog drawn above the toast\'s own layer (z 100): the toast keeps its z-index', async () => {
    const s = await scene({
        ...phone,
        html: '<div id="w" role="dialog" aria-modal="true" style="position:fixed;z-index:100"><div id="card"><button id="ok">Ở lại</button></div></div>',
        rects: { w: R(0, 844, 0, 390), card: R(68, 844, 0, 390), ok: R(780, 824, 16, 374) },
    });
    assert.equal(s.spot(), 'under');
    assert.equal(s.root.style.zIndex, '');
    await s.done();
});

test('the media picker (aria-modal on its full-screen dim) on a computer: its card is far from the toast, which stays and runs', async () => {
    const s = await scene({
        ...wide,
        html: '<div id="mp" role="dialog" aria-modal="true" style="position:fixed;z-index:90"><div id="card"><button id="ins">Chèn</button></div></div>',
        rects: { mp: R(0, 900, 0, 1366), card: R(120, 780, 163, 1203), ins: R(724, 764, 1080, 1187) },
    });
    assert.equal(s.spot(), 'def');
    assert.equal(s.root.style.zIndex, '');
    assert.equal(s.timer.hold, 0);
    await s.done();
});

test('a workflow note dialog (a dim and a card inside a full-screen layer): the toast stays and runs', async () => {
    const s = await scene({
        ...wide,
        html: '<div id="wf" role="dialog" aria-modal="true" style="position:fixed;z-index:90"><div id="dim"></div><div id="card"><button id="go">Gửi</button></div></div>',
        rects: { wf: R(0, 900, 0, 1366), dim: R(0, 900, 0, 1366), card: R(300, 600, 427, 939), go: R(548, 588, 820, 923) },
    });
    assert.equal(s.spot(), 'def');
    assert.equal(s.timer.hold, 0);
    await s.done();
});

test('the media picker on a phone: the toast touches its card but no control a tap would reach — it stays', async () => {
    const s = await scene({
        ...phone,
        html: '<div id="mp" role="dialog" aria-modal="true" style="position:fixed;z-index:90"><div id="card"><div id="grid"><button id="pic">Ảnh</button></div><button id="ins">Chèn</button></div></div>',
        // «Chèn» ends 1px above the toast; a thumbnail scrolled out of the grid's view lies under it, clipped
        rects: { mp: R(0, 844, 0, 390), card: R(92, 752, 16, 374), grid: R(150, 690, 16, 374), pic: R(700, 800, 30, 130), ins: R(700, 740, 250, 360) },
        clipped: ['pic'],
    });
    assert.equal(s.spot(), 'def');
    assert.equal(s.timer.hold, 0);
    await s.done();
});

test('a button whose edge the toast only grazes (1px, a rounding pixel): the toast stays', async () => {
    const s = await scene({
        ...wide,
        html: '<div id="mp" role="dialog" aria-modal="true" style="position:fixed;z-index:90"><div id="card"><button id="b">Tải lên</button></div></div>',
        rects: { mp: R(0, 900, 0, 1366), card: R(120, 830, 163, 1203), b: R(790, 829, 962, 1023) },
    });
    assert.equal(s.spot(), 'def');
    await s.done();
});

test('…and when the toast does lie over «Chèn»: under the picker\'s layer (z 89), timer held', async () => {
    const s = await scene({
        ...phone,
        html: '<div id="mp" role="dialog" aria-modal="true" style="position:fixed;z-index:90"><div id="card"><button id="ins">Chèn</button></div></div>',
        rects: { mp: R(0, 844, 0, 390), card: R(92, 752, 16, 374), ins: R(704, 748, 250, 360) },
    });
    assert.equal(s.spot(), 'under');
    assert.equal(s.root.style.zIndex, '89');
    assert.equal(s.timer.hold, 1);
    await s.done();
});

// a full-screen drawer on a phone (vh tall), its footer from footTop to the bottom
const drawer = (footTop, vh = 844) => ({
    ...phone, vh, toast: R(vh - 103, vh - 16, 12, 378),
    html: `<div data-bs-modal data-toast-keep><div id="dim" style="position:fixed;z-index:60"></div>
        <div id="panel" role="dialog" aria-modal="true" tabindex="-1" style="position:fixed;z-index:60">
          <div id="head"><button id="x">✕</button></div><div id="body"><input id="name"></div>
          <div id="foot" data-toast-foot><button id="cancel">Hủy</button><button id="save">Lưu</button></div></div></div>`,
    rects: {
        dim: R(0, vh, 0, 390), panel: R(0, vh, 0, 390), head: R(0, 64, 0, 390), x: R(10, 54, 336, 380), body: R(64, footTop, 0, 390),
        name: R(100, 144, 20, 370), foot: R(footTop, vh, 0, 390), cancel: R(footTop + 64, footTop + 108, 20, 370), save: R(footTop + 12, footTop + 56, 20, 370),
    },
});

test('a drawer on a phone, the toast over its «Hủy» / «Lưu»: the toast stands just above the footer, its timer running', async () => {
    const s = await scene(drawer(724));
    assert.equal(s.spot(), 'up');
    assert.equal(s.root.style.getPropertyValue('--toast-over'), `${844 - 724 + 8}px`);
    assert.equal(s.timer.hold, 0);
    await s.done();
});

test('a drawer whose footer leaves no room above it (the keyboard is up): the toast stays where it is, never held', async () => {
    const s = await scene(drawer(130, 250));
    assert.equal(s.spot(), 'keep');
    assert.equal(s.root.style.zIndex, '');
    assert.equal(s.timer.hold, 0);
    await s.done();
});

test('after wire:navigate swapped the body, a dialog opening on the new page still moves the toast', async () => {
    const s = await scene({ ...phone, html: '', rects: {} });
    // the new page's body, with its own toast, comes in before the old toast has let go
    const nb = doc.createElement('body');
    nb.innerHTML = TOAST.replace('id="t"', 'id="t2"').replace('id="tb"', 'id="tb2"').replace('id="tc"', 'id="tc2"');
    doc.body.replaceWith(nb);
    const root = doc.getElementById('t2');
    lay(doc.getElementById('tb2'), phone.toast);
    lay(doc.getElementById('tc2'), { ...phone.toast, left: 298 });
    watch(root);
    await frame();
    assert.equal(root.getAttribute('data-spot'), 'def');
    const sheet = doc.createElement('div');
    sheet.innerHTML = '<div id="q" role="alertdialog" aria-modal="true" style="position:fixed;z-index:90"><button id="stay">Ở lại</button></div>';
    nb.appendChild(sheet);
    lay(doc.getElementById('q'), R(560, 844, 0, 390));
    lay(doc.getElementById('stay'), R(792, 832, 16, 374));
    await frame();
    assert.equal(root.getAttribute('data-spot'), 'up');
    unwatch(root);
    unwatch(s.root);
    await frame();
});

test('a change elsewhere on the page (a progress bar) while a dialog is open does not re-measure the toast', async () => {
    const s = await scene({
        ...wide,
        html: '<div id="m" role="dialog" aria-modal="true" style="position:fixed;z-index:90"><button id="ok">OK</button></div><div id="bar" style="width:10%"></div>',
        rects: { m: R(300, 600, 433, 933), ok: R(540, 580, 800, 900) },
    });
    const before = s.reads.n;
    for (let i = 2; i <= 6; i++) {
        doc.getElementById('bar').setAttribute('style', `width:${i * 10}%`);
        await frame();
    }
    assert.equal(s.reads.n, before);
    await s.done();
});

// a bottom sheet question at z 90 (its «Ở lại» under the toast's usual spot on a phone), added to the page
function addQuestion(top = 560) {
    const q = doc.createElement('div');
    q.innerHTML = '<div id="qw" style="position:fixed;z-index:90"><div id="q" role="alertdialog" aria-modal="true"><button id="stay">Ở lại</button></div></div>';
    doc.body.appendChild(q);
    lay(doc.getElementById('q'), R(top, 844, 0, 390));
    lay(doc.getElementById('stay'), R(792, 832, 16, 374));

    return q;
}

test('a dialog that holds the toast under it, then closes: back at the usual spot, its timer started again once', async () => {
    const s = await scene({ ...phone, html: '', rects: {} });
    const q = addQuestion(68);
    await frame();
    assert.equal(s.spot(), 'under');
    assert.equal(s.root.style.zIndex, '89');
    assert.deepEqual(s.timer, { hold: 1, release: 0 });
    q.remove();
    await frame();
    assert.equal(s.spot(), 'def');
    assert.equal(s.root.style.zIndex, '');
    assert.deepEqual(s.timer, { hold: 1, release: 1 });
    await s.done();
});

test('a toast the pointer holds when a dialog sends it under: closing the dialog does not start its timer', async () => {
    const s = await scene({ ...phone, html: '', rects: {} });
    s.d.timer = null; // mouseenter held it
    const q = addQuestion(68);
    await frame();
    assert.equal(s.spot(), 'under');
    q.remove();
    await frame();
    assert.deepEqual(s.timer, { hold: 0, release: 0 });
    await s.done();
});

test('a question over a drawer: the question on top decides, and the toast stands above its sheet', async () => {
    const at = drawer(724);
    const s = await scene({ ...at, html: at.html, rects: at.rects });
    assert.equal(s.spot(), 'up'); // above the drawer's footer
    addQuestion(560);
    await frame();
    assert.equal(s.spot(), 'up');
    assert.equal(s.root.style.getPropertyValue('--toast-over'), `${844 - 560 + 8}px`);
    await s.done();
});

test('a drawer that had the toast under another dialog: once that closes the toast stays in sight, its timer running', async () => {
    const at = drawer(130, 250);
    const s = await scene({ ...at, html: at.html, rects: at.rects });
    assert.equal(s.spot(), 'keep');
    const q = doc.createElement('div');
    // with the keyboard up the question's card fills the screen: an opaque card is measured whole
    q.innerHTML = '<div id="qw" style="position:fixed;z-index:90"><div id="q" role="alertdialog" aria-modal="true" style="background:#fff"><button id="ok">Xóa</button></div></div>';
    doc.body.appendChild(q);
    lay(doc.getElementById('q'), R(0, 250, 0, 390));
    lay(doc.getElementById('ok'), R(190, 234, 16, 374));
    await frame();
    assert.equal(s.spot(), 'under');
    q.remove();
    await frame();
    assert.equal(s.spot(), 'keep');
    assert.deepEqual(s.timer, { hold: 1, release: 1 });
    await s.done();
});

test('a drawer whose field being typed in sits just above the footer: the toast does not lift onto it', async () => {
    const at = drawer(724);
    at.rects.name = R(620, 664, 20, 370);
    const s = await scene({ ...at, html: at.html, rects: at.rects });
    assert.equal(s.spot(), 'up');
    doc.getElementById('name').focus();
    doc.getElementById('foot').setAttribute('class', 'focus-in'); // any change inside the drawer re-places
    await frame();
    assert.equal(s.spot(), 'keep');
    await s.done();
});

test('a side panel on a computer: the toast moves into the free side, its width and centre set inline', async () => {
    const s = await scene({
        ...wide,
        html: '<div id="p" role="dialog" aria-modal="true" style="position:fixed;z-index:60"><button id="f">Tên</button></div>',
        rects: { p: R(0, 900, 886, 1366), f: R(820, 864, 906, 1346) },
    });
    // the toast at 403–963 reaches the panel's field at 906–963
    assert.equal(s.spot(), 'side');
    assert.equal(s.root.style.left, '443px');
    assert.equal(s.root.style.width, '560px');
    await s.done();
});

test('a change inside the open dialog (a morph adds a button under the toast): the toast moves', async () => {
    const s = await scene({
        ...phone,
        html: '<div id="mp" role="dialog" aria-modal="true" style="position:fixed;z-index:90"><div id="card"><div id="foot"></div></div></div>',
        rects: { mp: R(0, 844, 0, 390), card: R(92, 840, 16, 374), foot: R(740, 840, 16, 374) },
    });
    assert.equal(s.spot(), 'def');
    const b = doc.createElement('button');
    b.textContent = 'Xóa hẳn';
    lay(b, R(780, 824, 30, 360));
    doc.getElementById('foot').appendChild(b);
    await frame();
    assert.equal(s.spot(), 'under');
    await s.done();
});

test('leaving the page lets go of its body; once the new page is in, a dialog there moves the toast again', async () => {
    const s = await scene({ ...phone, html: '', rects: {} });
    doc.dispatchEvent(new window.Event('livewire:navigating'));
    const q = addQuestion(560);
    await frame();
    assert.equal(s.spot(), 'def'); // not watching while the page is being left
    doc.dispatchEvent(new window.Event('livewire:navigated'));
    await frame();
    assert.equal(s.spot(), 'up');
    q.remove();
    await frame();
    assert.equal(s.spot(), 'def');
    await s.done();
});
