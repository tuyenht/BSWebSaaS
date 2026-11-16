// A screen's «Hướng dẫn» (resources/js/admin-guide.js) — the drawer, «Chỉ cho tôi» and a part's tour —
// in a DOM without a browser: `node --test tests/js/admin-guide.test.mjs`.
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

// the module pulls its stylesheet in for Vite; here a stylesheet is nothing
register(
    'data:text/javascript,' +
        encodeURIComponent("export async function load(url, ctx, next) { return url.endsWith('.css') ? { format: 'module', source: '', shortCircuit: true } : next(url, ctx); }"),
);

const ui = {
    heading: 'Hướng dẫn: :title', close: 'Đóng hướng dẫn', close_tip: 'Đóng (Esc)', role: 'Bạn là :role: :may.', part: 'Phần :n · :title',
    steps: ':n bước', steps_one: '1 bước', seen: 'Đã xem', show: 'Chỉ cho tôi', show_named: 'Chỉ cho tôi: :step', run: 'Đi qua :n bước', run_one: 'Đi qua 1 bước',
    exit: 'Thoát', exit_tip: 'Thoát (Esc)', back: 'Quay lại Hướng dẫn', prev: 'Trước', prev_aria: 'Bước trước', next: 'Tiếp', next_aria: 'Bước tiếp',
    done: 'Xong', next_part: 'Xem tiếp: :title', count: 'Phần :i/:n · bước :k/:m', count_intro: 'Phần :i/:n', title_sep: ':title:', quote: '«:x»',
};
const on = (t, title, text) => ({ t, title, text });
const ctx = {
    screen: 'posts', title: 'Tin tức', scenario: 'abc123', member: 'm1', role: { name: 'Biên tập', may: 'được đăng', note: 'Website đăng thẳng.' }, purpose: 'Mọi tin tức.',
    parts: [
        { id: 'main', title: 'Thêm bài', steps: [
            { id: 'intro', intro: true, v: [on('role', 'Nhóm quyền', 'Bạn là biên tập.')] },
            { id: 'add', intro: false, v: [on('add', 'Thêm bài viết', 'Bấm [[Thêm bài viết]] để viết.')] },
            { id: 'search', intro: false, v: [on('search', 'Tìm', 'Gõ tên bài.')] },
            { id: 'sort', intro: false, v: [on('sort-wide', 'Sắp xếp', 'Bấm [[@]].'), on('sort', 'Sắp xếp', 'Bấm [[@]] để đổi thứ tự.')] },
        ] },
        { id: 'find', title: 'Tìm bài', steps: [{ id: 'cols', intro: false, v: [on('cols', 'Cột', 'Bấm [[@]] để chọn cột.')] }] },
    ],
    ui,
};

const dom = new JSDOM(
    '<!doctype html><html data-motion="reduce"><body><main><h1>Tin tức</h1>' +
        '<button type="button" data-guide-open aria-expanded="false">Hướng dẫn</button>' +
        '<section data-guide="role">Vai trò</section><button type="button" data-guide="add">Thêm bài viết</button>' +
        '<div data-guide="search" hidden><input></div><button type="button" data-guide="sort-wide" hidden>Sắp xếp</button>' +
        '<button type="button" data-guide="sort">Mới nhất ▾</button><button type="button" data-guide="cols">Cột · menu</button></main>' +
        '<script type="application/json" data-guide-ctx>' + JSON.stringify(ctx) + '</script>' +
        '<template data-guide-icons><span data-i="close"><svg></svg></span><span data-i="cursor"><svg></svg></span><span data-i="check"><svg></svg></span></template>' +
        '</body></html>',
    { url: 'http://a.test/admin/content?section=posts', pretendToBeVisual: true },
);
const { window } = dom;
for (const k of ['document', 'localStorage', 'Element', 'HTMLElement']) {
    globalThis[k] = window[k];
}
for (const k of ['getComputedStyle', 'requestAnimationFrame', 'cancelAnimationFrame']) {
    globalThis[k] = window[k].bind(window);
}
for (const k of ['innerWidth', 'innerHeight', 'scrollX', 'scrollY']) {
    Object.defineProperty(globalThis, k, { get: () => window[k], configurable: true });
}
globalThis.window = window;
// a box for what shows; nothing for what is hidden (JSDOM lays nothing out)
window.Element.prototype.getBoundingClientRect = function () {
    const shown = this.isConnected && !this.closest('[hidden]');
    const w = shown ? 120 : 0;
    const h = shown ? 32 : 0;
    return { x: 20, y: 80, left: 20, top: 80, width: w, height: h, right: 20 + w, bottom: 80 + h, toJSON() {} };
};
Object.defineProperty(window.HTMLElement.prototype, 'innerText', { get() { return this.textContent; }, configurable: true });

const g = await import('../../resources/js/admin-guide.js');
const $ = (s) => document.querySelector(s);
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const esc = () => document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
const opener = $('[data-guide-open]');

test('the drawer opens from its button, lists only the steps on the screen, and gives focus back on close', () => {
    opener.focus();
    g.openPanel(opener);
    const p = g.state().panel;

    assert.ok(p && p.getAttribute('role') === 'dialog' && p.getAttribute('aria-modal') === 'true');
    assert.equal(opener.getAttribute('aria-expanded'), 'true');
    assert.equal(document.activeElement, p.querySelector('.bg-x'));
    assert.equal($('#bg-h').textContent, 'Hướng dẫn: Tin tức');
    // part 1: the role is the box at the top, the hidden search is left out, the sort step takes its shown variant
    const items = [...p.querySelectorAll('#bg-ch-0 li')].map((li) => li.querySelector('b').textContent);
    assert.deepEqual(items, ['Thêm bài viết:', 'Sắp xếp:']);
    assert.match(p.querySelector('#bg-ch-0 li').textContent, /Bấm «Thêm bài viết» để viết\./);
    assert.match(p.querySelectorAll('#bg-ch-0 li')[1].textContent, /Bấm «Mới nhất» để đổi thứ tự\./);
    assert.equal(p.querySelector('#bg-ch-1').hidden, true);

    esc();
    assert.equal(g.state().panel, null);
    assert.equal($('.bg-panel'), null);
    assert.equal(opener.getAttribute('aria-expanded'), 'false');
    assert.equal(document.activeElement, opener);
});

test('«Chỉ cho tôi» shows one step, «Tiếp» skips a step that is not on the screen, and writes nothing', () => {
    localStorage.clear();
    g.openPanel(opener);
    g.state().panel.querySelector('.bg-show[data-ch="main"][data-i="0"]').click();

    const card = g.state().card;
    assert.equal(g.state().panel, null);
    assert.equal(card.querySelector('#bg-t').textContent, 'Thêm bài viết');
    assert.ok(card.querySelector('.bg-back'));

    card.querySelector('[data-a="fwd"]').click();
    assert.equal(g.state().card.querySelector('#bg-t').textContent, 'Sắp xếp');
    assert.equal(g.state().card.querySelector('[data-a="fwd"]'), null);

    // «Quay lại Hướng dẫn» reopens the drawer on that step
    g.state().card.querySelector('.bg-back').click();
    assert.equal(g.state().card, null);
    assert.equal(document.activeElement, g.state().panel.querySelector('.bg-show[data-ch="main"][data-i="1"]'));
    assert.equal(localStorage.getItem('bs-guide-ch:m1'), null);
    esc();
});

test('a part\'s tour ends with «Xong», which marks the part seen for this member and scenario', async () => {
    localStorage.clear();
    g.startTour('find');
    await wait(60);

    const card = g.state().card;
    assert.ok(card);
    assert.equal(card.querySelector('#bg-t').textContent, 'Cột');
    // the control reads "Cột · menu": its label stops before what follows « · »
    assert.equal(card.querySelector('[aria-live]').textContent, 'Bấm «Cột» để chọn cột.');
    assert.equal(card.querySelector('[data-a="done"]').textContent, 'Xong');
    card.querySelector('[data-a="done"]').click();

    assert.equal(g.state().card, null);
    assert.deepEqual(JSON.parse(localStorage.getItem('bs-guide-ch:m1')), { 'posts|abc123': ['find'] });
    assert.deepEqual(JSON.parse(localStorage.getItem('bs-guide-seen:m1')), { 'posts|abc123': 'done' });

    g.openPanel(opener);
    assert.equal(g.state().panel.querySelector('[aria-controls="bg-ch-1"] .bg-seen').textContent, 'Đã xem');
    assert.equal(g.state().panel.querySelector('[aria-controls="bg-ch-0"] .bg-seen'), null);
    esc();
});
