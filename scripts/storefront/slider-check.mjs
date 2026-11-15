// Behaviour check of resources/js/sf-slider.js in jsdom (run in CI's frontend job):
// node scripts/storefront/slider-check.mjs. The markup mirrors themes/default/sections/hero_slider.
import { JSDOM } from 'jsdom';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const script = pathToFileURL(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/sf-slider.js')).href;

const slide = (n, active) => `<div class="sf-slide${active ? ' is-active' : ''}" role="group" aria-roledescription="slide" aria-label="Ảnh ${n}/3">
<div class="sf-slide__media"${n > 1 ? ' hidden' : ''}><img src="/s${n}.webp" alt=""></div><div class="sf-slide__content"><h2>Slide ${n}</h2></div></div>`;
const html = `<!doctype html><body><section class="sf-slider" data-sf-slider data-interval="5000" aria-roledescription="carousel">
<div class="sf-slider__controls" hidden>
<button type="button" data-sf-prev></button><button type="button" data-sf-next></button>
<div><button type="button" data-sf-dot="0" aria-current="true"></button><button type="button" data-sf-dot="1"></button><button type="button" data-sf-dot="2"></button></div>
</div>
<div class="sf-slider__viewport" aria-live="off">${slide(1, true)}${slide(2)}${slide(3)}</div></section></body>`;
// A row of cards that rotates (themes/default/partials/sf-rail-open and sf-rail-close).
const rail = `<div class="sf-rail" data-sf-rail data-interval="5000" data-mobile="rotate" data-arrows="auto">
<div class="sf-rail__list"><div>Thẻ 1</div><div>Thẻ 2</div><div>Thẻ 3</div><div>Thẻ 4</div></div>
<div class="sf-rail__controls"><button type="button" data-sf-rail-prev hidden></button><button type="button" data-sf-rail-next hidden></button></div></div>`;

let run = 0;
async function mount({ reduced = false, still = false, withRail = false } = {}) {
    const dom = new JSDOM(withRail ? html.replace('</body>', `${rail}</body>`) : html);
    const { window } = dom;
    // jsdom has no layout: the rail's row is 1000px of cards in a 300px box, and a scroll is counted.
    const sized = (wide) => ({ configurable: true, get() { return this.classList.contains('sf-rail__list') ? (wide ? 1000 : 300) : 0; } });
    Object.defineProperty(window.HTMLElement.prototype, 'scrollWidth', sized(true));
    Object.defineProperty(window.HTMLElement.prototype, 'clientWidth', sized(false));
    let railMoves = 0;
    window.HTMLElement.prototype.scrollTo = function scrollTo() {
        railMoves += 1;
    };
    let now = 0;
    let seq = 0;
    const queue = new Map();
    const listeners = [];
    window.matchMedia = () => ({ matches: reduced, addEventListener: (_t, fn) => listeners.push(fn) });
    let hidden = false;
    Object.defineProperty(window.document, 'hidden', { configurable: true, get: () => hidden });
    globalThis.window = window;
    globalThis.document = window.document;
    globalThis.setTimeout = (fn, ms) => {
        queue.set(++seq, { at: now + ms, fn });
        return seq;
    };
    globalThis.clearTimeout = (id) => queue.delete(id);
    await import(`${script}?run=${++run}`);
    // The visitor is here (a mouse move) unless the case is about the page nobody has touched yet.
    if (!still) {
        window.dispatchEvent(new window.Event('pointermove'));
    }
    const doc = window.document;
    const root = doc.querySelector('[data-sf-slider]');
    return {
        window,
        doc,
        root,
        tick(ms) {
            const end = now + ms;
            for (;;) {
                let next = null;
                for (const [id, t] of queue) {
                    if (t.at <= end && (next === null || t.at < queue.get(next).at)) {
                        next = id;
                    }
                }
                if (next === null) {
                    break;
                }
                const t = queue.get(next);
                queue.delete(next);
                now = t.at;
                t.fn();
            }
            now = end;
        },
        railMoves: () => railMoves,
        active: () => [...doc.querySelectorAll('.sf-slide')].findIndex((s) => s.classList.contains('is-active')),
        current: () => [...doc.querySelectorAll('[data-sf-dot]')].findIndex((d) => d.getAttribute('aria-current') === 'true'),
        media: (i) => doc.querySelectorAll('.sf-slide__media')[i],
        fire: (el, type) => el.dispatchEvent(new window.Event(type, { bubbles: type !== 'mouseenter' && type !== 'mouseleave' })),
        // pointerenter/leave do not bubble; jsdom has no PointerEvent, so the type rides on a plain event
        point: (el, type, pointerType = 'mouse') => el.dispatchEvent(Object.assign(new window.Event(type, { bubbles: type === 'pointerdown' }), { pointerType })),
        setHidden(value) {
            hidden = value;
            doc.dispatchEvent(new window.Event('visibilitychange'));
        },
    };
}

const failures = [];
const check = (name, ok) => {
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}`);
    if (!ok) {
        failures.push(name);
    }
};

{
    const s = await mount();
    check('init reveals the controls', s.doc.querySelector('.sf-slider__controls').hidden === false);
    s.doc.querySelector('[data-sf-next]').click();
    check('next moves the active slide and the current dot', s.active() === 1 && s.current() === 1);
    check('next reveals that slide\'s photo', s.media(1).hidden === false);
    s.doc.querySelector('[data-sf-prev]').click();
    check('prev moves back', s.active() === 0 && s.current() === 0);
    s.doc.querySelectorAll('[data-sf-dot]')[2].click();
    check('a dot shows its slide', s.active() === 2 && s.current() === 2);
    check('no pause button (the owner chose hover to pause)', s.doc.querySelector('[data-sf-pause]') === null);
    s.tick(5000);
    check('a slide button does not stop rotation', s.active() === 0);
}
{
    const s = await mount();
    check('rotating: live region off', s.doc.querySelector('.sf-slider__viewport').getAttribute('aria-live') === 'off');
    s.tick(3600);
    check('the next photo is revealed before its turn', s.media(1).hidden === false && s.active() === 0);
    check('slide 3 photo still hidden', s.media(2).hidden === true);
    s.tick(1400);
    check('autoplay advances at the interval', s.active() === 1);
    s.tick(5000);
    check('autoplay keeps going', s.active() === 2);
}
{
    const s = await mount({ still: true });
    s.tick(20000);
    check('nobody has touched the page yet: no autoplay', s.active() === 0);
    check('still page: the next photo stays hidden', s.media(1).hidden === true);
    check('still page: live region polite', s.doc.querySelector('.sf-slider__viewport').getAttribute('aria-live') === 'polite');
    s.window.dispatchEvent(new s.window.Event('scroll'));
    check('the first scroll starts rotation: live region off', s.doc.querySelector('.sf-slider__viewport').getAttribute('aria-live') === 'off');
    s.tick(5000);
    check('the first scroll starts rotation at the interval', s.active() === 1);
}
{
    const s = await mount({ still: true });
    s.window.dispatchEvent(new s.window.Event('keydown'));
    s.tick(5000);
    check('a key press also starts rotation', s.active() === 1);
}
// Every way a visitor shows up starts rotation, and only the first one is needed.
for (const type of ['pointerdown', 'wheel', 'touchstart']) {
    const s = await mount({ still: true });
    s.tick(20000);
    s.window.dispatchEvent(new s.window.Event(type));
    s.tick(5000);
    check(`a ${type} on an untouched page starts rotation`, s.active() === 1);
}
{
    const s = await mount({ still: true, withRail: true });
    s.tick(20000);
    check('rail: nobody has touched the page yet: the row does not move', s.railMoves() === 0);
    s.window.dispatchEvent(new s.window.Event('touchstart'));
    s.tick(5000);
    check('rail: the first touch starts the row moving at the interval', s.railMoves() === 1);
}
{
    const s = await mount({ withRail: true });
    s.tick(10000);
    check('rail: a visitor who is here sees the row move at the interval', s.railMoves() === 2);
}
{
    const s = await mount({ reduced: true });
    s.tick(20000);
    check('reduced motion: no autoplay', s.active() === 0);
}
{
    const s = await mount();
    const next = s.doc.querySelector('[data-sf-next]');
    next.matches = (sel) => sel === ':focus-visible';
    s.fire(next, 'focusin');
    s.tick(20000);
    check('keyboard focus inside pauses', s.active() === 0);
    s.point(s.root, 'pointerleave');
    s.tick(20000);
    check('leaving with the pointer keeps the keyboard pause', s.active() === 0);
    s.root.dispatchEvent(Object.assign(new s.window.Event('focusout', { bubbles: true }), { relatedTarget: null }));
    s.tick(5000);
    check('focus leaving the slider resumes', s.active() === 1);
}
{
    const s = await mount();
    const next = s.doc.querySelector('[data-sf-next]');
    next.matches = () => false;
    s.fire(next, 'focusin');
    s.tick(5000);
    check('focus left by a mouse click does not pause', s.active() === 1);
}
{
    const s = await mount();
    s.point(s.root, 'pointerenter');
    s.tick(20000);
    check('hover pauses', s.active() === 0);
    s.doc.querySelector('[data-sf-next]').click();
    s.tick(20000);
    check('an arrow click while hovering stays paused', s.active() === 1);
    s.point(s.root, 'pointerleave');
    s.tick(5000);
    check('leaving resumes', s.active() === 2);
}
{
    const s = await mount();
    s.point(s.root, 'pointerenter', 'touch');
    s.tick(5000);
    check('a touch pointer entering is not a hover', s.active() === 1);
    s.point(s.doc.querySelector('.sf-slider__viewport'), 'pointerdown', 'touch');
    s.tick(9000);
    check('a touch holds rotation', s.active() === 1);
    s.tick(6000);
    check('rotation comes back after the hold', s.active() === 2);
}
{
    const s = await mount();
    s.setHidden(true);
    s.tick(20000);
    check('a hidden page pauses', s.active() === 0);
    s.setHidden(false);
    s.tick(5000);
    check('a visible page resumes', s.active() === 1);
}
{
    const s = await mount();
    const next = s.doc.querySelector('[data-sf-next]');
    next.dispatchEvent(new s.window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    check('ArrowRight on a control moves to the next slide', s.active() === 1);
}

// A looping rail (the copies of the cards and the jump back onto the real cards), on a small layout model:
// each displayed item is 200px wide with no gap in a 300px row, so four cards are one 800px copy and the
// real cards start at scrollLeft 800. A copy is displayed only while its row has .is-loop (sf-rail.css).
// A row settles on scrollend; `ends: false` removes the event, as in a browser without it, and the row then
// settles after a quiet 150 ms.
// `idle: true` gives the window requestIdleCallback; `observe: true` gives it a ResizeObserver that the
// test reports to with resize(). `count` cards, `row` px wide; `c` = the CSS --sf-c the script reads for a
// view's cards; `list` = more classes on the row; `media` answers matchMedia (default: nothing matches).
async function mountLoop({ loop = '', grid = false, ends = true, idle = false, observe = false, count = 4, row: rowStart = 300, c = '1.25', list = '', interval = 0, media = () => false } = {}) {
    const cards = Array.from({ length: count }, (_, i) => `<li><a href="/the-${i + 1}" id="card-${i + 1}">Thẻ ${i + 1}</a></li>`).join('');
    const dom = new JSDOM(`<!doctype html><body><div class="sf-rail" data-sf-rail data-interval="${interval}" data-mobile="swipe" data-arrows="auto"${loop}>
<ul class="sf-rail__list${grid ? ' sf-rail__list--grid' : ''}${list}">${cards}</ul>
<div class="sf-rail__controls" hidden><button type="button" data-sf-rail-prev hidden></button><span data-sf-rail-dots hidden></span><button type="button" data-sf-rail-next hidden></button></div></div></body>`);
    const { window } = dom;
    if (!ends) {
        for (let o = window; o && 'onscrollend' in o; o = Object.getPrototypeOf(o)) {
            delete o.onscrollend;
        }
    }
    let W = 200;
    let rowW = rowStart;
    let cNow = c;
    const isList = (el) => el.classList?.contains('sf-rail__list');
    const shown = (el) => !(el.hasAttribute('data-sf-copy') && !el.parentElement.classList.contains('is-loop'));
    const row = (list) => [...list.children].filter(shown);
    const left = new WeakMap();
    const proto = window.HTMLElement.prototype;
    const getter = (name, get) => Object.defineProperty(proto, name, { configurable: true, get });
    getter('offsetWidth', function () {
        return isList(this.parentElement) && shown(this) ? W : 0;
    });
    getter('offsetLeft', function () {
        return isList(this.parentElement) ? Math.max(0, row(this.parentElement).indexOf(this)) * W : 0;
    });
    getter('clientWidth', function () {
        return isList(this) ? rowW : 0;
    });
    getter('scrollWidth', function () {
        return isList(this) ? row(this).length * W : 0;
    });
    Object.defineProperty(proto, 'scrollLeft', {
        configurable: true,
        get() {
            return left.get(this) ?? 0;
        },
        set(v) {
            left.set(this, Math.max(0, Math.min(v, this.scrollWidth - this.clientWidth)));
        },
    });
    const end = (el) => ends && el.dispatchEvent(new window.Event('scrollend'));
    proto.scrollTo = function scrollTo({ left: to }) {
        this.scrollLeft = to;
        this.dispatchEvent(new window.Event('scroll'));
        end(this);
    };
    proto.getBoundingClientRect = function rect() {
        const x = isList(this.parentElement) ? this.offsetLeft - this.parentElement.scrollLeft : 0;
        return { left: x, right: x + this.offsetWidth, top: 0, bottom: 0, width: this.offsetWidth, height: 0 };
    };
    const css = window.getComputedStyle.bind(window);
    window.getComputedStyle = (el) =>
        isList(el) ? { overflowX: el.classList.contains('sf-rail__list--grid') ? 'visible' : 'auto', getPropertyValue: (k) => (k === '--sf-c' ? ` ${cNow}` : '') } : css(el);
    window.matchMedia = (q) => ({ matches: media(q), addEventListener: () => {} });
    // jsdom reports a hidden page, where nothing rotates; this one is on screen.
    Object.defineProperty(window.document, 'hidden', { configurable: true, value: false });
    let now = 0;
    let seq = 0;
    const queue = new Map();
    window.performance.now = () => now;
    globalThis.performance = window.performance;
    if (idle) {
        window.requestIdleCallback = (fn) => globalThis.setTimeout(fn, 0);
    }
    let observed = null;
    if (observe) {
        window.ResizeObserver = class {
            constructor(fn) {
                observed = fn;
            }
            observe() {}
        };
    }
    globalThis.ResizeObserver = window.ResizeObserver;
    globalThis.window = window;
    globalThis.document = window.document;
    globalThis.getComputedStyle = window.getComputedStyle;
    globalThis.setTimeout = (fn, ms) => {
        queue.set(++seq, { at: now + ms, fn });
        return seq;
    };
    globalThis.clearTimeout = (id) => queue.delete(id);
    globalThis.requestAnimationFrame = (fn) => globalThis.setTimeout(fn, 16);
    await import(`${script}?run=${++run}`);
    const tick = (ms) => {
        const end = now + ms;
        for (;;) {
            const due = [...queue].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!due) {
                break;
            }
            queue.delete(due[0]);
            now = due[1].at;
            due[1].fn();
        }
        now = end;
    };
    const track = window.document.querySelector('.sf-rail__list');
    // A swipe that ends at `to`: the scroll, then (where the browser has it) scrollend.
    const scrollBy = (to) => {
        track.scrollLeft = to;
        track.dispatchEvent(new window.Event('scroll'));
        end(track);
    };
    // A touch on the row; `fingers` stay on the row afterwards and `away` elsewhere on the page.
    const touch = (type, fingers = 0, away = 0) => {
        const on = Array.from({ length: fingers }, () => ({ target: track.firstElementChild }));
        const off = Array.from({ length: away }, () => ({ target: window.document.body }));
        return track.dispatchEvent(Object.assign(new window.Event(type), { touches: [...on, ...off] }));
    };
    // The cards and the row take new widths (a phone turned) and the observer reports it.
    const resize = (card, row, cols = cNow) => {
        W = card;
        rowW = row;
        cNow = cols;
        observed?.([]);
    };
    const lit = () => [...window.document.querySelector('[data-sf-rail-dots]').children].findIndex((d) => d.classList.contains('is-on'));
    const dots = () => window.document.querySelector('[data-sf-rail-dots]').children.length;
    const click = (which) => window.document.querySelector(`[data-sf-rail-${which}]`).click();
    return { window, doc: window.document, track, tick, scrollBy, touch, resize, lit, dots, click };
}
for (const ends of [true, false]) {
    const s = await mountLoop({ ends });
    const way = ends ? 'scrollend' : 'no scrollend';
    s.tick(1000);
    check(`loop (${way}): nothing is copied before the visitor is here`, s.doc.querySelectorAll('[data-sf-copy]').length === 0 && s.track.scrollLeft === 0);
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    const copies = [...s.doc.querySelectorAll('[data-sf-copy]')];
    check(`loop (${way}): a copy of the cards before and after them once the visitor is here`, copies.length === 8 && s.track.classList.contains('is-loop'));
    check(`loop (${way}): the copies are hidden from screen readers, skipped by Tab and carry no id`, copies.every((c) => c.getAttribute('aria-hidden') === 'true') && [...s.doc.querySelectorAll('[data-sf-copy] a')].every((a) => a.getAttribute('tabindex') === '-1') && s.doc.querySelectorAll('[data-sf-copy] [id], [data-sf-copy][id]').length === 0);
    check(`loop (${way}): the view stays on the first real card`, s.track.scrollLeft === 800);
    s.scrollBy(1600);
    s.tick(200);
    check(`loop (${way}): a swipe past the last card settles on the first real card`, s.track.scrollLeft === 800);
    s.scrollBy(400);
    s.tick(200);
    check(`loop (${way}): a swipe back past the first card settles on the same view of the real cards`, s.track.scrollLeft === 1200);
    // The row's settle comes while the finger is still on it (a rotation ending under a resting finger).
    s.touch('touchstart', 1);
    s.scrollBy(1800);
    s.tick(400);
    check(`loop (${way}): no jump while a finger is on the row`, s.track.scrollLeft === 1800);
    s.touch('touchend');
    s.tick(400);
    check(`loop (${way}): the jump comes once the finger lifts and the row settles`, s.track.scrollLeft === 1000);
    s.scrollBy(800);
    s.doc.querySelector('[data-sf-rail-prev]').click();
    s.tick(200);
    check(`loop (${way}): Prev on the first card runs on to the last real card`, s.track.scrollLeft === 1400);
    s.doc.querySelector('[data-sf-rail-next]').click();
    s.tick(200);
    check(`loop (${way}): Next on the last card runs on to the first real card`, s.track.scrollLeft === 800);
}
{
    // The visitor's first sign of being here is a swipe on this row: the loop waits until it is still.
    const s = await mountLoop({ idle: true });
    s.tick(1000);
    s.touch('touchstart', 1);
    s.window.dispatchEvent(new s.window.Event('touchstart'));
    s.scrollBy(150);
    s.tick(400);
    check('loop, first touch on the row: no copies while the finger is on it', !s.track.classList.contains('is-loop'));
    s.touch('touchend');
    for (const to of [200, 250, 300, 350, 400]) {
        s.scrollBy(to);
        s.tick(50);
    }
    check('loop, first touch on the row: no copies while the row still moves after the finger lifts', !s.track.classList.contains('is-loop') && s.track.scrollLeft === 400);
    s.tick(600);
    check('loop, first touch on the row: copies once the row is still, with the view kept', s.track.classList.contains('is-loop') && s.track.scrollLeft === 1200);
    // Two fingers on the row (a pinch): lifting one keeps the row held.
    s.touch('touchstart', 2);
    s.scrollBy(1700);
    s.touch('touchend', 1);
    s.tick(400);
    check('loop: no jump while a second finger stays on the row', s.track.scrollLeft === 1700);
    s.touch('touchend');
    s.tick(400);
    check('loop: the jump comes once the last finger lifts', s.track.scrollLeft === 900);
    // A thumb resting elsewhere on the page does not hold the row.
    s.touch('touchstart', 1, 1);
    s.scrollBy(1700);
    s.touch('touchend', 0, 1);
    s.tick(400);
    check('loop: a finger elsewhere on the page does not hold the row', s.track.scrollLeft === 900);
}
{
    // The row and its cards change width with the loop on (a phone turned): no jump with the old copy
    // width, then the copy width is measured again and the nearest card is kept.
    const s = await mountLoop({ observe: true });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    s.scrollBy(1000);
    s.tick(200);
    s.resize(150, 200);
    s.scrollBy(1600);
    s.tick(100);
    check('loop, new width: no jump before the new copy width is measured', s.track.scrollLeft === 1600);
    s.tick(400);
    check('loop, new width: the nearest card is kept on the real cards', s.track.scrollLeft === 1050);
    s.scrollBy(1250);
    s.tick(400);
    check('loop, new width: a swipe past the last card settles by the new copy width', s.track.scrollLeft === 650);
    // A new width while the row still moves: no jump with the old copy width until it is measured.
    s.resize(200, 300);
    for (const to of [1250, 1300, 1350, 1400, 1450]) {
        s.scrollBy(to);
        s.tick(60);
    }
    check('loop, new width mid-scroll: no jump with the old copy width', s.track.scrollLeft === 1450);
}
{
    const s = await mountLoop({ loop: ' data-loop="off"' });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    check('loop off: no copies, the row stops at its ends', s.doc.querySelectorAll('[data-sf-copy]').length === 0 && !s.track.classList.contains('is-loop'));
}
{
    const s = await mountLoop({ grid: true });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    check('loop: a grid that does not scroll gets no copies', s.doc.querySelectorAll('[data-sf-copy]').length === 0);
}
{
    const s = await mountLoop({ count: 25 });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    check('loop: a row of more than 24 cards does not go round', s.doc.querySelectorAll('[data-sf-copy]').length === 0 && !s.track.classList.contains('is-loop'));
}

// A whole view at a time (data-advance="view"): 8 cards four across (the sample: two views, two dots).
const view = ' data-advance="view"';
{
    const s = await mountLoop({ loop: view, count: 8, row: 800, c: '4' });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    const marked = [...s.track.children].map((li, i) => (li.hasAttribute('data-sf-page') ? i : -1)).filter((i) => i >= 0);
    check('view: 8 cards four across make two dots', s.dots() === 2 && s.lit() === 0 && s.track.scrollLeft === 1600);
    check('view: a swipe stops only at the first card of a view, copies included', s.track.classList.contains('is-paged') && marked.join() === '0,4,8,12,16,20');
    s.click('next');
    s.tick(200);
    check('view: Next moves four cards and lights the second dot', s.track.scrollLeft === 2400 && s.lit() === 1);
    s.click('next');
    s.tick(200);
    check('view: Next on the last view goes round to the first', s.track.scrollLeft === 1600 && s.lit() === 0);
    s.click('prev');
    s.tick(200);
    check('view: Prev on the first view goes round to the last', s.track.scrollLeft === 2400 && s.lit() === 1);
}
{
    // 6 cards four across: two views (5, 6 then 1, 2 when it goes round); a swipe stops at every card.
    const s = await mountLoop({ loop: view, count: 6, row: 800, c: '4' });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    check('view, 6 cards four across: two dots, a swipe stops at every card', s.dots() === 2 && !s.track.classList.contains('is-paged'));
    s.click('next');
    s.tick(200);
    check('view, 6 cards: Next moves four cards to the second view', s.track.scrollLeft === 2000 && s.lit() === 1);
    s.click('next');
    s.tick(200);
    check('view, 6 cards: Next on the second view goes round to the first view, not two cards on', s.track.scrollLeft === 1200 && s.lit() === 0);
    s.click('next');
    s.tick(200);
    s.click('prev');
    s.tick(200);
    check('view, 6 cards: Prev goes back to the first view', s.track.scrollLeft === 1200 && s.lit() === 0);
    s.click('prev');
    s.tick(200);
    check('view, 6 cards: Prev on the first view goes round to the second', s.track.scrollLeft === 2000 && s.lit() === 1);
}
{
    // Keyboard focus on a card past the view: the row moves to the start of that card's view.
    const focus = (s, i) => s.doc.querySelector(`#card-${i}`).dispatchEvent(new s.window.FocusEvent('focusin', { bubbles: true }));
    const s = await mountLoop({ loop: view, count: 8, row: 800, c: '4' });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    focus(s, 6);
    s.tick(50);
    check('view: focus on card 6 puts the row on the second view', s.track.scrollLeft === 2400 && s.lit() === 1);
    const six = await mountLoop({ loop: view, count: 6, row: 800, c: '4' });
    six.window.dispatchEvent(new six.window.Event('pointermove'));
    six.tick(100);
    focus(six, 6);
    six.tick(50);
    check('view, 6 cards: focus leaves a row that stops at every card to the browser', six.track.scrollLeft === 1200);
}
{
    // A window that narrows from four across to two: the views, their marks and the dots follow.
    const s = await mountLoop({ loop: view, count: 8, row: 800, c: '4', observe: true });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    s.resize(200, 400, '2');
    s.tick(400);
    const marks = s.doc.querySelectorAll('[data-sf-page]').length;
    check('view, four across to two: four dots and a stop every second card', s.dots() === 4 && marks === 12 && s.track.classList.contains('is-paged'));
    s.click('next');
    s.tick(200);
    check('view, two across: Next moves two cards', s.track.scrollLeft === 2000 && s.lit() === 1);
}
{
    const s = await mountLoop({ loop: `${view} data-loop="off"`, count: 6, row: 800, c: '4' });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    s.click('next');
    s.tick(200);
    check('view, no loop: Next ends on the last four cards and lights the last dot', s.track.scrollLeft === 400 && s.lit() === 1);
    s.click('next');
    s.tick(200);
    check('view, no loop: Next at the end goes back to the start', s.track.scrollLeft === 0 && s.lit() === 0);
}
{
    // A view of one card (a phone's 1.25 row) moves one card, with the dots of a rail that moves by card.
    const s = await mountLoop({ loop: view });
    s.window.dispatchEvent(new s.window.Event('pointermove'));
    s.tick(100);
    s.click('next');
    s.tick(200);
    check('view of one card: Next moves one card', s.track.scrollLeft === 1000 && !s.track.classList.contains('is-paged'));
}
{
    // slider_small never rotates on a tablet; the same rail without it does (the check that the clock runs).
    const tablet = (q) => q.includes('1023px');
    const still = await mountLoop({ count: 8, row: 400, c: '2', interval: 3000, list: ' sf-rail__list--slider_small', media: tablet });
    still.window.dispatchEvent(new still.window.Event('pointermove'));
    still.tick(10000);
    check('slider_small: no rotation on a tablet', still.track.scrollLeft === 1600);
    const turns = await mountLoop({ count: 8, row: 400, c: '2', interval: 3000, media: tablet });
    turns.window.dispatchEvent(new turns.window.Event('pointermove'));
    turns.tick(3500);
    check('a slider rotates on a tablet', turns.track.scrollLeft === 1800);
}

if (failures.length > 0) {
    console.error(`slider-check: ${failures.length} failed`);
    process.exit(1);
}
console.log('slider-check: all passed');
