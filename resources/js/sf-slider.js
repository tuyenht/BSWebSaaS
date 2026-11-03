// Hero slider (WAI-ARIA APG carousel), loaded only on pages with a slider of 2+ slides.
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
