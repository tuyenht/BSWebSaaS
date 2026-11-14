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
import { railDot, railPages, railTarget, swipe, wrap } from './sf-slider-math.js';

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
// The arrows loop: Next at the end goes to the start, Prev at the start to the end.
const phone = window.matchMedia('(max-width: 639px)');

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
    let hovered = false;
    let focused = false;
    let held = false;
    let timer = 0;
    let holdTimer = 0;
    let pages = 0;
    // Whether the row overflows, measured by sync() (every layout read happens there, before its writes),
    // so schedule() never forces a layout of its own right after the dots were drawn.
    let over = false;

    const overflows = () => track.scrollWidth > track.clientWidth + 1;
    const rotates = () => interval > 0 && !reduce.matches && !(phone.matches && root.dataset.mobile === 'swipe') && over;
    const running = () => present && rotates() && !hovered && !focused && !held && !document.hidden;
    const step = () => (track.children.length > 1 ? track.children[1].offsetLeft - track.children[0].offsetLeft : track.clientWidth);
    const move = (dir) => {
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
    const light = () => lightDots(railDot(track.scrollLeft, track.clientWidth, track.scrollWidth));
    // measure() does every layout read and returns what apply() needs; apply() only writes. Start-up runs
    // every rail's measure() before any rail's apply(), so six rails cost one layout pass, not six.
    const measure = () => {
        over = overflows();
        const show = over && mode !== 'none';
        return {
            show,
            n: dotsBox && show ? railPages(track.clientWidth, track.scrollWidth) : 0,
            on: dotsBox ? railDot(track.scrollLeft, track.clientWidth, track.scrollWidth) : 0,
        };
    };
    const apply = ({ show, n, on }) => {
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
        schedule();
    };
    const sync = () => apply(measure());
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
    if (dotsBox) {
        track.addEventListener('scroll', light, { passive: true });
    }
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
    let resized;
    const later = () => {
        clearTimeout(resized);
        resized = setTimeout(sync, 200);
    };
    window.addEventListener('resize', later);
    // A row can start to overflow once its lazy images or the web font arrive.
    track.addEventListener('load', later, true);
    document.fonts?.ready.then(later);
    phone.addEventListener?.('change', sync);
    reduce.addEventListener?.('change', sync);
    whenPresent(schedule);
    return () => {
        const measured = measure();
        return () => {
            controls.hidden = false;
            apply(measured);
        };
    };
}

// Start-up: wire every rail, then read every rail's layout, then write every rail's controls.
const started = [...document.querySelectorAll('[data-sf-rail]')].map(initRail).filter(Boolean);
started.map((measure) => measure()).forEach((apply) => apply());
