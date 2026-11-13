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

let run = 0;
async function mount({ reduced = false, still = false } = {}) {
    const dom = new JSDOM(html);
    const { window } = dom;
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

if (failures.length > 0) {
    console.error(`slider-check: ${failures.length} failed`);
    process.exit(1);
}
console.log('slider-check: all passed');
