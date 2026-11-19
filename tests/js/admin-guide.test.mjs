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
    offer_text: 'Xem hướng dẫn khoảng 1 phút, đúng với quyền của bạn.', offer_later: 'Để sau', offer_now: 'Xem ngay', list_sep: ', ',
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
        { id: 'find', title: 'Tìm bài', steps: [
            { id: 'more-tabs', intro: false, v: [on('tabs-more', 'Khác', 'Nút này gom các trạng thái ít dùng: [[@menu]]. Chọn một mục thì mục đó hiện lên hàng tab.')] },
            { id: 'cols', intro: false, v: [on('cols', 'Cột', 'Bấm [[@]] để chọn cột.')] },
        ] },
    ],
    ui,
};

const dom = new JSDOM(
    '<!doctype html><html data-motion="reduce"><body><main><h1>Tin tức</h1>' +
        '<button type="button" data-guide-open aria-expanded="false">Hướng dẫn</button>' +
        '<section data-guide="role">Vai trò</section><button type="button" data-guide="add">Thêm bài viết</button>' +
        '<div data-guide="search" hidden><input></div><button type="button" data-guide="sort-wide" hidden>Sắp xếp</button>' +
        '<button type="button" data-guide="sort">Mới nhất ▾</button><button type="button" data-guide="cols">Cột · menu</button>' +
        // a group row's «Khác», hidden while every group fits; its menu shows only the groups that do not
        '<div hidden data-more-w><button type="button" data-guide="tabs-more" aria-haspopup="menu" aria-controls="gm-1">Khác</button></div>' +
        '<div id="gm-1" role="menu" style="display:none"><button type="button" role="menuitemradio" style="display:none"><span>Tất cả</span></button>' +
        '<button type="button" role="menuitemradio"><span>Bản nháp</span><span>3</span></button><button type="button" role="menuitemradio"><span>Riêng tư</span></button></div></main>' +
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
const tab = (shiftKey = false) => document.activeElement.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Tab', shiftKey, bubbles: true, cancelable: true }));
// a finger pressing and lifting at (x, y): the pointer fields a touch carries
const touch = (el, x, y) => {
    for (const type of ['pointerdown', 'pointerup']) {
        const ev = new window.MouseEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y });
        Object.defineProperties(ev, { pointerType: { value: 'touch' }, pointerId: { value: 7 } });
        el.dispatchEvent(ev);
    }
};
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

test('the first visit offers the guide in a dialog that dims the page; focus moves in, Tab stays on its buttons, Esc is «Để sau»', () => {
    localStorage.clear();
    const field = $('[data-guide="sort"]');
    field.focus();
    g.offer();

    const b = g.state().offer;
    assert.ok(b && b.getAttribute('role') === 'dialog' && b.getAttribute('aria-modal') === 'true');
    assert.equal(b.getAttribute('aria-labelledby'), 'bg-o-t');
    assert.equal($('#bg-o-t').textContent, 'Lần đầu dùng màn này?');
    assert.equal($('#bg-o-d').textContent, 'Xem hướng dẫn khoảng 1 phút, đúng với quyền của bạn.');
    assert.deepEqual([...b.querySelectorAll('button')].map((x) => x.textContent), ['Để sau', 'Xem ngay']);
    assert.equal(document.activeElement, b);
    // a layer that takes every press, the ring whose shadow dims the page around «Hướng dẫn», then the bubble
    assert.deepEqual([...document.body.children].slice(-3).map((x) => x.className), ['bg-offer-catch', 'bg-offer-hole bg-nm', 'bg-offer bg-nm']);
    assert.equal($('.bg-offer-hole').getAttribute('aria-hidden'), 'true');
    assert.equal($('.bg-offer-hole').style.width, '128px'); // the button and 4px around it

    const [later, now] = b.querySelectorAll('button');
    tab();
    assert.equal(document.activeElement, later);
    tab();
    assert.equal(document.activeElement, now);
    tab();
    assert.equal(document.activeElement, later);
    tab(true);
    assert.equal(document.activeElement, now);
    // focus sent onto the page comes back, and no key reaches the page
    field.focus();
    assert.equal(document.activeElement, b);
    let reached = 0;
    field.addEventListener('keydown', () => reached++);
    field.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'a', bubbles: true }));
    assert.equal(reached, 0);

    esc();
    assert.equal(g.state().offer, null);
    assert.equal($('.bg-offer-hole'), null);
    assert.equal($('.bg-offer-catch'), null);
    assert.equal(document.activeElement, field); // back where it was
    assert.deepEqual(JSON.parse(localStorage.getItem('bs-guide-seen:m1')), { 'posts|abc123': 'later' });

    g.offer();
    assert.equal(g.state().offer, null);
});

test('the bubble says the screen changed when another role or workflow was remembered; «Để sau» gives focus to «Hướng dẫn»', () => {
    localStorage.clear();
    localStorage.setItem('bs-guide-seen:m1', JSON.stringify({ 'posts|old': 'done' }));
    document.activeElement.blur();
    g.offer();

    assert.equal($('#bg-o-t').textContent, 'Cách làm ở màn này vừa thay đổi');
    assert.match($('#bg-o-d').textContent, /^Quyền hoặc quy trình duyệt của bạn đã khác trước\. Xem hướng dẫn/);
    g.state().offer.querySelector('.bg-prev').click();
    assert.equal(g.state().offer, null);
    assert.equal(document.activeElement, opener);
    assert.equal(JSON.parse(localStorage.getItem('bs-guide-seen:m1'))['posts|abc123'], 'later');
});

test('the dimmed page neither scrolls nor takes a press: a press on it is «Để sau», on the ringed button it opens the drawer', async () => {
    localStorage.clear();
    g.offer();
    const layer = $('.bg-offer-catch');
    for (const type of ['pointerdown', 'wheel', 'touchmove']) {
        const ev = new window.Event(type, { bubbles: true, cancelable: true });
        layer.dispatchEvent(ev);
        assert.ok(ev.defaultPrevented, type);
    }
    assert.ok(g.state().offer);
    layer.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, clientX: 600, clientY: 400 }));
    assert.equal(g.state().offer, null);
    assert.equal(JSON.parse(localStorage.getItem('bs-guide-seen:m1'))['posts|abc123'], 'later');

    // a finger: read when it lifts off the layer
    localStorage.clear();
    g.offer();
    touch($('.bg-offer-catch'), 600, 400);
    assert.equal(g.state().offer, null);
    assert.equal(JSON.parse(localStorage.getItem('bs-guide-seen:m1'))['posts|abc123'], 'later');

    // the ringed «Hướng dẫn» opens the drawer (which, as ever, remembers the member has found it)
    localStorage.clear();
    g.offer();
    $('.bg-offer-catch').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, clientX: 50, clientY: 90 }));
    assert.equal(g.state().offer, null);
    assert.ok(g.state().panel);
    assert.equal(g.state().opener, opener);
    esc();
    assert.equal(g.state().panel, null);
    assert.equal(document.activeElement, opener);

    localStorage.clear();
    g.offer();
    g.state().offer.querySelector('.bg-next').click();
    assert.equal(g.state().offer, null);
    assert.equal($('.bg-offer-catch'), null);
    await wait(60);
    assert.equal(g.state().card.querySelector('#bg-t').textContent, 'Nhóm quyền');
    esc();
    assert.equal(JSON.parse(localStorage.getItem('bs-guide-seen:m1'))['posts|abc123'], 'skip');
});

test('the «Khác» step shows only while its button does, and names the groups its menu holds', () => {
    const titles = () => [...g.state().panel.querySelectorAll('#bg-ch-1 li')].map((li) => li.querySelector('b').textContent);
    g.openPanel(opener);
    assert.deepEqual(titles(), ['Cột:']);
    esc();

    $('[data-more-w]').hidden = false;
    g.openPanel(opener);
    assert.deepEqual(titles(), ['Khác:', 'Cột:']);
    assert.match(g.state().panel.querySelector('#bg-ch-1 li').textContent, /Nút này gom các trạng thái ít dùng: bản nháp, riêng tư\. Chọn một mục/);
    esc();
    $('[data-more-w]').hidden = true;
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

test('another dialog the page opens: the bubble steps aside and focus stays in that dialog', () => {
    localStorage.clear();
    g.offer();
    assert.ok(g.state().offer);
    const other = document.createElement('div');
    other.setAttribute('role', 'dialog');
    other.innerHTML = '<input id="other-in">';
    document.body.append(other);
    const input = other.querySelector('input');
    input.focus();
    assert.equal(document.activeElement, input); // the trap does not pull it back
    document.dispatchEvent(new window.Event('scroll'));
    assert.equal(g.state().offer, null);
    assert.equal(document.activeElement, input); // and «Hướng dẫn» does not take it
    other.remove();
});

test('a menu or sheet the member has open keeps its focus: no bubble while it is open, and one already up steps aside', () => {
    localStorage.clear();
    // a row's ⋮ menu (on a narrow list a sheet, the same role="menu"), open, its first item focused
    const menu = document.createElement('div');
    menu.setAttribute('role', 'menu');
    menu.innerHTML = '<button type="button" role="menuitem">Sao chép đường dẫn</button>';
    document.body.append(menu);
    const item = menu.querySelector('button');
    item.focus();
    g.offer();
    assert.equal(g.state().offer, null);
    assert.equal(document.activeElement, item);
    g.hideOffer(true);

    // closed: the bubble comes; a menu opened after it (a screen reader's own press) keeps the focus it takes
    menu.style.display = 'none';
    g.offer();
    assert.ok(g.state().offer);
    menu.style.display = '';
    item.focus();
    assert.equal(document.activeElement, item); // the trap does not pull it back
    document.dispatchEvent(new window.Event('scroll'));
    assert.equal(g.state().offer, null);
    assert.equal(document.activeElement, item); // and «Hướng dẫn» does not take it

    // a menu that opened while the bubble held the focus (its first item could not take it): when the
    // bubble steps aside the focus goes to that first item, never to the page
    g.hideOffer(true);
    menu.style.display = 'none';
    g.offer();
    assert.ok(g.state().offer);
    assert.ok(g.state().offer.contains(document.activeElement));
    menu.style.display = '';
    document.dispatchEvent(new window.Event('scroll'));
    assert.equal(g.state().offer, null);
    assert.equal(document.activeElement, item);
    menu.remove();
});

test('while the bubble is open the keys that scroll the page do nothing, and Space still presses its button', () => {
    localStorage.clear();
    g.offer();
    for (const key of [' ', 'PageDown', 'PageUp', 'ArrowDown', 'ArrowUp', 'Home', 'End']) {
        const ev = new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
        document.body.dispatchEvent(ev);
        assert.ok(ev.defaultPrevented, key);
    }
    g.state().offer.querySelector('.bg-prev').focus();
    const sp = new window.KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
    document.activeElement.dispatchEvent(sp);
    assert.equal(sp.defaultPrevented, false);
    esc();
    assert.equal(g.state().offer, null);
});
