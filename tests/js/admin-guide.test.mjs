// A screen's «Hướng dẫn» (resources/js/admin-guide.js) — the drawer, «Chỉ cho tôi», a part's tour, the
// first-visit bubble and the put-away role card a tour opens —
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
    offer_title: 'Lần đầu dùng màn này?', offer_title_changed: 'Cách làm ở màn này vừa thay đổi', offer_changed: 'Quyền hoặc quy trình duyệt của bạn đã khác trước.',
    offer_text: 'Xem hướng dẫn khoảng 1 phút, đúng với quyền của bạn.', offer_later: 'Để sau', offer_now: 'Xem ngay',
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

test('the first visit offers the guide in a bubble after the button, without taking focus; Esc from it puts it away for good', async () => {
    localStorage.clear();
    const field = $('[data-guide="sort"]');
    field.focus();
    g.offer();

    const b = g.state().offer;
    assert.ok(b && b.getAttribute('role') === 'dialog' && b.getAttribute('aria-modal') === 'false');
    assert.equal(b.previousElementSibling, opener); // next in the tab order
    assert.equal($('#bg-o-t').textContent, 'Lần đầu dùng màn này?');
    assert.equal($('#bg-o-d').textContent, 'Xem hướng dẫn khoảng 1 phút, đúng với quyền của bạn.');
    assert.deepEqual([...b.querySelectorAll('button')].map((x) => x.textContent), ['Để sau', 'Xem ngay']);
    assert.equal(document.activeElement, field);
    assert.ok($('.bg-offer-hole'));
    await wait(200);
    assert.equal($('.bg-sr[aria-live="polite"]').textContent, 'Lần đầu dùng màn này? Xem hướng dẫn khoảng 1 phút, đúng với quyền của bạn.');

    // Esc on the page belongs to what is open there: the bubble steps aside, nothing is remembered
    esc();
    assert.equal(g.state().offer, null);
    assert.equal($('.bg-offer-hole'), null);
    assert.equal($('.bg-sr'), null);
    assert.equal(document.activeElement, field);
    assert.equal(localStorage.getItem('bs-guide-seen:m1'), null);

    g.offer();
    g.state().offer.querySelector('.bg-next').focus();
    esc();
    assert.equal(g.state().offer, null);
    assert.deepEqual(JSON.parse(localStorage.getItem('bs-guide-seen:m1')), { 'posts|abc123': 'later' });

    g.offer();
    assert.equal(g.state().offer, null);
});

test('the bubble says the screen changed when another role or workflow was remembered; «Để sau» returns focus from inside it', () => {
    localStorage.clear();
    localStorage.setItem('bs-guide-seen:m1', JSON.stringify({ 'posts|old': 'done' }));
    g.offer();

    assert.equal($('#bg-o-t').textContent, 'Cách làm ở màn này vừa thay đổi');
    assert.match($('#bg-o-d').textContent, /^Quyền hoặc quy trình duyệt của bạn đã khác trước\. Xem hướng dẫn/);
    const later = g.state().offer.querySelector('.bg-prev');
    later.focus();
    later.click();
    assert.equal(g.state().offer, null);
    assert.equal(document.activeElement, opener);
    assert.equal(JSON.parse(localStorage.getItem('bs-guide-seen:m1'))['posts|abc123'], 'later');
});

test('a press elsewhere on the page puts the bubble away; «Xem ngay» runs the first part', async () => {
    localStorage.clear();
    g.offer();
    $('[data-guide="cols"]').dispatchEvent(new window.Event('pointerdown', { bubbles: true }));
    assert.ok(g.state().offer); // a finger that starts a scroll is not a press
    $('[data-guide="cols"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    assert.equal(g.state().offer, null);
    assert.equal(JSON.parse(localStorage.getItem('bs-guide-seen:m1'))['posts|abc123'], 'later');

    localStorage.clear();
    g.offer();
    g.state().offer.querySelector('.bg-next').click();
    assert.equal(g.state().offer, null);
    await wait(60);
    assert.equal(g.state().card.querySelector('#bg-t').textContent, 'Nhóm quyền');
    esc();
    assert.equal(JSON.parse(localStorage.getItem('bs-guide-seen:m1'))['posts|abc123'], 'skip');
});

test('a tour opens the put-away role card for its first step and closes it again when it ends', async () => {
    localStorage.clear();
    const live = $('main > [data-guide="role"]');
    live.hidden = true;
    const copy = document.createElement('section');
    copy.hidden = true;
    copy.setAttribute('data-role-copy', '');
    copy.setAttribute('data-guide', 'role');
    copy.textContent = 'Vai trò (đã ẩn)';
    live.after(copy);

    g.startTour('main');
    assert.equal(copy.hidden, false);
    await wait(60);
    assert.equal(g.state().card.querySelector('#bg-t').textContent, 'Nhóm quyền');
    esc();
    assert.equal(g.state().card, null);
    assert.equal(copy.hidden, true);

    g.startTour('find'); // a part with no step on the role card leaves the put-away card alone
    assert.equal(copy.hidden, true);
    await wait(60);
    assert.equal(copy.hidden, true);
    esc();

    copy.remove();
    live.hidden = false;
});
