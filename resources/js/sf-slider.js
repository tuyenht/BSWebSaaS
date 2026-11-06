// Hero slider (WAI-ARIA APG carousel) and list rails, loaded only on pages with one of them.
// The server renders every slide, the controls (hidden) and their texts (data-label-*); this file
// only reveals the controls and moves the active slide. Rotation runs only when the block asks for
// it (data-interval > 0), the visitor has not asked for reduced motion and the page is visible; a
// pointer over the slider pauses it, and keyboard focus entering it, or any slide button, stops it
// until the visitor presses play. Photos of later slides sit in a hidden wrapper; each is revealed
// about 1.5 s before its turn, or when a control is hovered or focused.
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

function init(root) {
    const slides = [...root.querySelectorAll('.sf-slide')];
    const controls = root.querySelector('.sf-slider__controls');
    if (slides.length < 2 || !controls) {
        return;
    }
    const viewport = root.querySelector('.sf-slider__viewport');
    const dots = [...root.querySelectorAll('[data-sf-dot]')];
    const pause = root.querySelector('[data-sf-pause]');
    const interval = Number(root.dataset.interval) || 0;
    let current = 0;
    let timer = 0;
    let early = 0;
    let hovered = false;
    let pointer = false;
    let playing = interval > 0 && !reduce.matches;

    const at = (n) => (n + slides.length) % slides.length;
    const reveal = (n) => {
        const media = slides[at(n)].querySelector('.sf-slide__media');
        if (media) {
            media.hidden = false;
        }
    };
    const running = () => playing && !hovered && !document.hidden;
    const schedule = () => {
        clearTimeout(timer);
        clearTimeout(early);
        viewport.setAttribute('aria-live', running() ? 'off' : 'polite');
        if (running()) {
            early = setTimeout(() => reveal(current + 1), Math.max(0, interval - 1500));
            timer = setTimeout(() => show(current + 1), interval);
        }
    };
    const label = () => {
        if (pause) {
            pause.setAttribute('aria-label', playing ? pause.dataset.labelPause : pause.dataset.labelPlay);
            pause.classList.toggle('is-paused', !playing);
        }
    };
    const stop = () => {
        playing = false;
        label();
        schedule();
    };
    function show(n) {
        const next = at(n);
        reveal(next);
        slides[current].classList.remove('is-active');
        slides[next].classList.add('is-active');
        dots.forEach((dot, i) => (i === next ? dot.setAttribute('aria-current', 'true') : dot.removeAttribute('aria-current')));
        current = next;
        schedule();
    }
    const go = (n) => {
        stop();
        show(n);
    };

    controls.hidden = false;
    root.querySelector('[data-sf-prev]')?.addEventListener('click', () => go(current - 1));
    root.querySelector('[data-sf-next]')?.addEventListener('click', () => go(current + 1));
    dots.forEach((dot, i) => dot.addEventListener('click', () => go(i)));
    pause?.addEventListener('click', () => {
        playing = !playing;
        label();
        schedule();
    });
    controls.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            go(current + (event.key === 'ArrowLeft' ? -1 : 1));
        }
    });
    const near = () => {
        reveal(current + 1);
        reveal(current - 1);
    };
    controls.addEventListener('pointerover', near);
    controls.addEventListener('focusin', near);
    // Focus that follows a press is not keyboard focus (the pressed button acts on its own).
    root.addEventListener('pointerdown', () => {
        pointer = true;
        setTimeout(() => {
            pointer = false;
        }, 0);
    });
    root.addEventListener('focusin', () => !pointer && stop());
    root.addEventListener('mouseenter', () => {
        hovered = true;
        schedule();
    });
    root.addEventListener('mouseleave', () => {
        hovered = false;
        schedule();
    });
    document.addEventListener('visibilitychange', schedule);
    reduce.addEventListener?.('change', () => reduce.matches && stop());
    label();
    schedule();
}

document.querySelectorAll('[data-sf-slider]').forEach(init);

// List rail: a row of cards that scrolls sideways (a slider, or a swipe row on a phone). The server
// renders the row and the controls (hidden); this reveals the arrows where the row overflows (or
// always, as the block asks) and, for a slider, rotates it: never faster than the server's interval
// (3 s or more), never on a phone's swipe row, never under reduced motion, stopped while the pointer
// or focus is inside, held 10 s after a touch or drag, and stopped for good by the Pause button
// until the visitor presses Play.
const phone = window.matchMedia('(max-width: 639px)');
const HOLD = 10000;

function initRail(root) {
    const track = root.querySelector('.sf-rail__list');
    const controls = root.querySelector('.sf-rail__controls');
    if (!track || !controls) {
        return;
    }
    const prev = root.querySelector('[data-sf-rail-prev]');
    const next = root.querySelector('[data-sf-rail-next]');
    const pause = root.querySelector('[data-sf-rail-pause]');
    const interval = Number(root.dataset.interval) || 0;
    const mode = root.dataset.arrows;
    let playing = true;
    let hovered = false;
    let focused = false;
    let held = false;
    let pointer = false;
    let timer = 0;
    let holdTimer = 0;

    const overflows = () => track.scrollWidth > track.clientWidth + 1;
    const rotates = () => interval > 0 && !reduce.matches && !(phone.matches && root.dataset.mobile === 'swipe') && overflows();
    const running = () => rotates() && playing && !hovered && !focused && !held && !document.hidden;
    const step = () => (track.children.length > 1 ? track.children[1].offsetLeft - track.children[0].offsetLeft : track.clientWidth);
    const move = (dir) => {
        const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
        const left = dir > 0 && atEnd ? 0 : track.scrollLeft + dir * step();
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
    const label = () => {
        if (pause) {
            pause.setAttribute('aria-label', playing ? pause.dataset.labelPause : pause.dataset.labelPlay);
            pause.classList.toggle('is-paused', !playing);
        }
    };
    const sync = () => {
        const show = mode === 'always' || (mode === 'auto' && overflows());
        if (prev) {
            prev.hidden = !show;
        }
        if (next) {
            next.hidden = !show;
        }
        if (pause) {
            pause.hidden = !rotates();
        }
        // A row that scrolls needs a keyboard stop of its own when none of its cards holds a link.
        if (overflows()) {
            track.tabIndex = 0;
        } else {
            track.removeAttribute('tabindex');
        }
        schedule();
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

    controls.hidden = false;
    prev?.addEventListener('click', () => {
        hold();
        move(-1);
    });
    next?.addEventListener('click', () => {
        hold();
        move(1);
    });
    pause?.addEventListener('click', () => {
        playing = !playing;
        label();
        schedule();
    });
    track.addEventListener('pointerdown', hold);
    track.addEventListener('wheel', hold, { passive: true });
    root.addEventListener('pointerdown', () => {
        pointer = true;
        setTimeout(() => {
            pointer = false;
        }, 0);
    });
    root.addEventListener('focusin', () => {
        focused = !pointer;
        schedule();
    });
    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) {
            focused = false;
            schedule();
        }
    });
    root.addEventListener('mouseenter', () => {
        hovered = true;
        schedule();
    });
    root.addEventListener('mouseleave', () => {
        hovered = false;
        schedule();
    });
    document.addEventListener('visibilitychange', schedule);
    window.addEventListener('resize', sync);
    phone.addEventListener?.('change', sync);
    reduce.addEventListener?.('change', sync);
    label();
    sync();
}

document.querySelectorAll('[data-sf-rail]').forEach(initRail);
