// Hero slider (WAI-ARIA APG carousel) and list rails, loaded only on pages with one of them.
// The server renders every slide, the controls (hidden) and their texts; this file only reveals the
// controls and moves the active slide. Rotation runs only when the block asks for it (data-interval
// > 0), the visitor has not asked for reduced motion and the page is visible. It pauses while a mouse
// is over the block (and runs on when it leaves, also after an arrow click), while keyboard focus is
// inside, and for 10 s after a touch or swipe. There is no pause button. Photos of later slides sit in
// a hidden wrapper; each is revealed about 1.5 s before its turn, or when a control is hovered or focused.
// Nothing rotates (slider or rail) before the visitor's first sign of being there: a scroll, a touch, a
// mouse move or a key. Until then the page holds still, so it is finished as soon as it is drawn; a
// page-speed test, which never touches the page, would otherwise count each slide change as the page
// still drawing.
import { loopDot, loopHome, railDot, railPages, railTarget, swipe, wrap } from './sf-slider-math.js';

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
const HOLD = 10000;
let present = false;
const waiting = [];
const arrive = () => {
    if (!present) {
        present = true;
        waiting.splice(0).forEach((fn) => fn());
    }
};
['pointerdown', 'pointermove', 'keydown', 'scroll', 'wheel', 'touchstart'].forEach((type) => window.addEventListener(type, arrive, { capture: true, passive: true, once: true }));
// Runs fn now when the visitor is already here, else once they arrive.
const whenPresent = (fn) => (present ? fn() : waiting.push(fn));

function init(root) {
    const slides = [...root.querySelectorAll('.sf-slide')];
    const controls = root.querySelector('.sf-slider__controls');
    if (slides.length < 2 || !controls) {
        return;
    }
    const viewport = root.querySelector('.sf-slider__viewport');
    const dots = [...root.querySelectorAll('[data-sf-dot]')];
    const interval = Number(root.dataset.interval) || 0;
    let current = 0;
    let timer = 0;
    let early = 0;
    let holdTimer = 0;
    let hovered = false;
    let focused = false;
    let held = false;
    let start = null;

    const reveal = (n) => {
        const media = slides[wrap(n, slides.length)].querySelector('.sf-slide__media');
        if (media) {
            media.hidden = false;
        }
    };
    const running = () => present && interval > 0 && !reduce.matches && !hovered && !focused && !held && !document.hidden;
    const schedule = () => {
        clearTimeout(timer);
        clearTimeout(early);
        viewport.setAttribute('aria-live', running() ? 'off' : 'polite');
        if (running()) {
            early = setTimeout(() => reveal(current + 1), Math.max(0, interval - 1500));
            timer = setTimeout(() => show(current + 1), interval);
        }
    };
    function show(n) {
        const next = wrap(n, slides.length);
        reveal(next);
        slides[current].classList.remove('is-active');
        slides[next].classList.add('is-active');
        dots.forEach((dot, i) => (i === next ? dot.setAttribute('aria-current', 'true') : dot.removeAttribute('aria-current')));
        current = next;
        schedule();
    }
    // A touch or swipe holds the rotation for HOLD ms, then it runs on.
    const hold = () => {
        held = true;
        clearTimeout(holdTimer);
        holdTimer = setTimeout(() => {
            held = false;
            schedule();
        }, HOLD);
        schedule();
    };

    controls.hidden = false;
    root.querySelector('[data-sf-prev]')?.addEventListener('click', () => show(current - 1));
    root.querySelector('[data-sf-next]')?.addEventListener('click', () => show(current + 1));
    dots.forEach((dot, i) => dot.addEventListener('click', () => show(i)));
    controls.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            show(current + (event.key === 'ArrowLeft' ? -1 : 1));
        }
    });
    const near = () => {
        reveal(current + 1);
        reveal(current - 1);
    };
    controls.addEventListener('pointerover', near);
    controls.addEventListener('focusin', near);
    // Swipe: pointer events on the photo area (CSS touch-action: pan-y leaves vertical scrolling to the page).
    viewport.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'mouse') {
            start = { x: event.clientX, y: event.clientY };
            near();
            hold();
        }
    });
    viewport.addEventListener('pointerup', (event) => {
        const dir = start ? swipe(event.clientX - start.x, event.clientY - start.y) : 0;
        start = null;
        if (dir) {
            show(current - dir);
        }
    });
    viewport.addEventListener('pointercancel', () => {
        start = null;
    });
    root.addEventListener('pointerenter', (event) => {
        hovered = event.pointerType !== 'touch';
        schedule();
    });
    root.addEventListener('pointerleave', () => {
        hovered = false;
        schedule();
    });
    // Only keyboard focus pauses; focus left on a button after a click does not (the mouse leaving resumes).
    root.addEventListener('focusin', (event) => {
        focused = event.target.matches(':focus-visible');
        schedule();
    });
    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) {
            focused = false;
            schedule();
        }
    });
    document.addEventListener('visibilitychange', schedule);
    reduce.addEventListener?.('change', schedule);
    schedule();
    whenPresent(schedule);
}

document.querySelectorAll('[data-sf-slider]').forEach(init);

// List rail: a row of cards that scrolls sideways (a slider, or a swipe row on a phone). The server
// renders the row and the controls (hidden); this reveals the arrows where the row overflows (never
// on a row that cannot scroll; `auto` and `always` show them wherever it overflows, on every device,
// touch included; `none` never), draws one dot per screenful and, for a slider, rotates it: never
// faster than the server's interval (3 s or more), never on a phone's swipe row, never under reduced
// motion; paused while a mouse is over it or keyboard focus is inside, and for 10 s after a touch.
// A row that scrolls goes round: once the visitor is here, a copy of the cards stands before them and
// another after them, so a swipe, an arrow or the rotation runs on past the last card into the first
// (and back) without stopping. When a scroll settles on a copy, the row jumps the width of one copy to
// the same view on the real cards. The copies are hidden from screen readers and skipped by Tab; a tap
// on one still opens its link. A block can turn this off (data-loop="off", the display group's `loop`).
// A row that does not loop keeps the old arrows: Next at the end goes to the start, Prev at the start
// to the end.
const phone = window.matchMedia('(max-width: 639px)');
const settles = 'onscrollend' in window;

const copy = (item) => {
    const c = item.cloneNode(true);
    c.setAttribute('data-sf-copy', '');
    c.setAttribute('aria-hidden', 'true');
    [c, ...c.querySelectorAll('[id]')].forEach((el) => el.removeAttribute('id'));
    c.querySelectorAll('a[href], area[href], button, input, select, textarea, summary, iframe, video[controls], audio[controls], [contenteditable], [tabindex]').forEach((el) =>
        el.setAttribute('tabindex', '-1'),
    );
    return c;
};

function initRail(root) {
    const track = root.querySelector('.sf-rail__list');
    const controls = root.querySelector('.sf-rail__controls');
    if (!track || !controls) {
        return;
    }
    const prev = root.querySelector('[data-sf-rail-prev]');
    const next = root.querySelector('[data-sf-rail-next]');
    const dotsBox = root.querySelector('[data-sf-rail-dots]');
    const interval = Number(root.dataset.interval) || 0;
    const mode = root.dataset.arrows;
    const items = [...track.children];
    let hovered = false;
    let focused = false;
    let held = false;
    let touching = false;
    let timer = 0;
    let holdTimer = 0;
    let settleTimer = 0;
    // A settle that came while a finger was on the row (skipped) and a quiet-time settle still to come.
    let skipped = false;
    let pending = false;
    let pages = 0;
    // Whether the row overflows, measured by sync() (every layout read happens there, before its writes),
    // so schedule() never forces a layout of its own right after the dots were drawn.
    let over = false;
    // The loop: whether the copies are shown, one copy's width, and the real cards' width.
    let loop = false;
    let period = 0;
    let span = 0;
    // Whether the row scrolled at the last measure: a row that has just started to scroll (a phone turned
    // upright) starts at its first card, whatever offset the browser kept from before.
    let scrolled = false;
    // The row's width at the last measure, when it last scrolled, whether a loop change waits for the row
    // to be still, and whether a new width waits for its sync (no jump meanwhile: the copy width is old).
    let width = -1;
    let lastScroll = -Infinity;
    let deferred = false;
    let resizing = false;

    const overflows = () => track.scrollWidth > track.clientWidth + 1;
    const rotates = () => interval > 0 && !reduce.matches && !(phone.matches && root.dataset.mobile === 'swipe') && over;
    const running = () => present && rotates() && !hovered && !focused && !held && !document.hidden;
    const step = () => (items.length > 1 ? items[1].offsetLeft - items[0].offsetLeft : track.clientWidth);
    // A settled scroll on a copy jumps to the same view on the real cards (never under a finger, never
    // with a copy width that a new row width has made old).
    const home = () => {
        if (loop && (touching || resizing)) {
            skipped = true;
        } else if (loop) {
            const left = loopHome(track.scrollLeft, period);
            if (left !== track.scrollLeft) {
                track.scrollTo({ left, behavior: 'instant' });
            }
        }
    };
    const move = (dir) => {
        if (loop) {
            home();
            track.scrollTo({ left: track.scrollLeft + dir * step(), behavior: reduce.matches ? 'auto' : 'smooth' });
            return;
        }
        const left = railTarget(dir, track.scrollLeft, track.clientWidth, track.scrollWidth, step());
        track.scrollTo({ left, behavior: reduce.matches ? 'auto' : 'smooth' });
    };
    const schedule = () => {
        clearTimeout(timer);
        if (running()) {
            timer = setTimeout(() => {
                move(1);
                schedule();
            }, interval);
        }
    };
    const lightDots = (on) => {
        [...dotsBox.children].forEach((dot, i) => dot.classList.toggle('is-on', i === on));
    };
    const dot = () => (loop ? loopDot(track.scrollLeft, period, track.clientWidth, span) : railDot(track.scrollLeft, track.clientWidth, track.scrollWidth));
    const light = () => lightDots(dot());
    // measure() does every layout read and returns what apply() needs; apply() only writes and returns,
    // when the loop turns on or off, a fix() that keeps the same cards in view (a loop turning off leaves a
    // row that no longer scrolls, so there is no view to keep). Start-up and the visitor's
    // arrival run every rail's measure(), then every apply(), then every fix(): two layout passes in all.
    const measure = () => {
        const first = items[0];
        const last = items[items.length - 1];
        // The real cards' width; on a grid that wraps it is never wider than the row.
        span = items.length > 1 ? last.offsetLeft + last.offsetWidth - first.offsetLeft : 0;
        width = track.clientWidth;
        over = loop ? span > width + 1 : overflows();
        const show = over && mode !== 'none';
        const scrolls = over && span > 0 && getComputedStyle(track).overflowX !== 'visible';
        // The loop is switched, or its copy width measured again, only on a still row: never under a finger
        // or mid-fling (an instant jump would cut the swipe short). Otherwise it is tried again later.
        const still = !touching && performance.now() - lastScroll > 150;
        const wants = present && scrolls && root.dataset.loop !== 'off';
        const loops = still ? wants : loop;
        deferred = !still && (loop || wants);
        if (deferred && !touching) {
            later();
        }
        // Where the view stands on the real cards when the loop turns on (the copies are hidden until then).
        const x = loops && !loop && scrolled ? track.scrollLeft : 0;
        scrolled = scrolls;
        // A loop that stays on across a change of width (a phone turned, a window resized) has a new copy
        // width: it is measured again, with the real card now nearest the row's start (the browser keeps
        // the snapped card in view), so fix() can put that card back at the start of the real cards.
        let re = null;
        if (loop && loops && still) {
            const p = first.getBoundingClientRect().left - track.children[0].getBoundingClientRect().left;
            if (Math.abs(p - period) > 1) {
                const edge = track.getBoundingClientRect().left;
                let best = Infinity;
                re = { p, k: 0 };
                [...track.children].forEach((child, i) => {
                    const d = Math.abs(child.getBoundingClientRect().left - edge);
                    if (d < best) {
                        best = d;
                        re.k = i % items.length;
                    }
                });
            }
        }
        return {
            show,
            loops,
            x,
            re,
            n: dotsBox && show ? railPages(width, loops ? span : track.scrollWidth) : 0,
            on: dotsBox ? dot() : 0,
        };
    };
    const apply = ({ show, loops, x, re, n, on }) => {
        [prev, next].forEach((btn) => btn && (btn.hidden = !show));
        if (dotsBox) {
            if (n !== pages) {
                pages = n;
                dotsBox.replaceChildren(...Array.from({ length: n }, () => document.createElement('span')));
            }
            dotsBox.hidden = n < 2;
            lightDots(on);
        }
        // A row that scrolls needs a keyboard stop of its own when none of its cards holds a link.
        if (over) {
            track.tabIndex = 0;
        } else {
            track.removeAttribute('tabindex');
        }
        let fix = null;
        if (loops !== loop) {
            if (loops && !track.querySelector('[data-sf-copy]')) {
                track.prepend(...items.map(copy));
                track.append(...items.map(copy));
            }
            track.classList.toggle('is-loop', loops);
            loop = loops;
            fix = () => {
                if (loop) {
                    period = items[0].getBoundingClientRect().left - track.children[0].getBoundingClientRect().left;
                    track.scrollTo({ left: period + x, behavior: 'instant' });
                }
                if (dotsBox) {
                    light();
                }
            };
        } else if (re) {
            fix = () => {
                period = re.p;
                track.scrollTo({ left: period + items[re.k].offsetLeft - items[0].offsetLeft, behavior: 'instant' });
                if (dotsBox) {
                    light();
                }
            };
        }
        schedule();
        return fix;
    };
    // A new width stops blocking jumps only once a still row has had its copy width measured again.
    const sync = () => {
        const measured = measure();
        resizing &&= deferred;
        apply(measured)?.();
    };
    const hold = () => {
        held = true;
        clearTimeout(holdTimer);
        holdTimer = setTimeout(() => {
            held = false;
            schedule();
        }, HOLD);
        schedule();
    };

    prev?.addEventListener('click', () => move(-1));
    next?.addEventListener('click', () => move(1));
    track.addEventListener('pointerdown', (event) => event.pointerType !== 'mouse' && hold());
    // A finger on the row: no jump until it lifts and the row has settled (scrollend, or a quiet 150 ms
    // where the browser has no scrollend event or a settle was skipped under the finger).
    const settle = () => {
        clearTimeout(settleTimer);
        pending = true;
        settleTimer = setTimeout(() => {
            pending = false;
            home();
        }, 150);
    };
    track.addEventListener('touchstart', () => (touching = true), { passive: true });
    const lift = (event) => {
        // A second finger still on the row (a pinch) keeps it held; a finger elsewhere on the page does not.
        touching = [...event.touches].some((t) => track.contains(t.target));
        if (touching) {
            return;
        }
        if (!settles || skipped) {
            skipped = false;
            settle();
        }
        // A loop change that waited for the finger (the visitor's first touch was on this row, or the row
        // changed width under it) is tried again.
        if (deferred) {
            later();
        }
    };
    track.addEventListener('touchend', lift, { passive: true });
    track.addEventListener('touchcancel', lift, { passive: true });
    if (settles) {
        track.addEventListener('scrollend', home);
    }
    track.addEventListener(
        'scroll',
        () => {
            lastScroll = performance.now();
            if (dotsBox) {
                light();
            }
            if (loop && (!settles || pending)) {
                settle();
            }
        },
        { passive: true },
    );
    root.addEventListener('focusin', (event) => {
        focused = event.target.matches(':focus-visible');
        schedule();
    });
    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) {
            focused = false;
            schedule();
        }
    });
    root.addEventListener('pointerenter', (event) => {
        hovered = event.pointerType !== 'touch';
        schedule();
    });
    root.addEventListener('pointerleave', () => {
        hovered = false;
        schedule();
    });
    document.addEventListener('visibilitychange', schedule);
    let syncTimer;
    function later() {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(sync, 200);
    }
    // A new row width: a window resized, a phone turned, a row in a tab that was hidden at start (measured
    // 0 wide) being shown. A report of the width already measured (the first one, a change of height) is
    // dropped.
    const resized = () => {
        if (track.clientWidth !== width) {
            resizing = true;
            later();
        }
    };
    if (window.ResizeObserver) {
        new ResizeObserver(resized).observe(track);
    } else {
        window.addEventListener('resize', resized);
    }
    // A row can start to overflow once its lazy images or the web font arrive.
    track.addEventListener('load', later, true);
    document.fonts?.ready.then(later);
    phone.addEventListener?.('change', sync);
    reduce.addEventListener?.('change', sync);
    return { measure, apply, controls, schedule, changes: (m) => m.loops !== loop || m.re !== null };
}

// Every rail's measure(), then every apply(), then every fix(): see measure() in initRail. Start-up also
// reveals the controls; the visitor's arrival turns the loops on and starts the rotation.
const rails = [...document.querySelectorAll('[data-sf-rail]')].map(initRail).filter(Boolean);
const syncAll = (first = false, changed = false) =>
    rails
        .map((rail) => [rail, rail.measure()])
        .filter(([rail, measured]) => !changed || rail.changes(measured))
        .map(([rail, measured]) => {
            if (first) {
                rail.controls.hidden = false;
            }
            return rail.apply(measured);
        })
        .forEach((fix) => fix?.());
syncAll(true);
// The visitor's arrival starts the rotation at once; the loops (copies of the cards, a layout pass) wait
// for an idle moment, so the first tap or key press is not held up by them.
const soon = (fn) => (window.requestIdleCallback ? window.requestIdleCallback(fn, { timeout: 300 }) : setTimeout(fn, 50));
whenPresent(() => {
    rails.forEach((rail) => rail.schedule());
    soon(() => syncAll(false, true));
});
