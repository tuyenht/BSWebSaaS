// The footer's factory cards below 1024px: one card at a time with arrows, dots and a sideways swipe,
// turning by itself every 5 seconds, loaded only where the footer has two or more cards. The server prints
// every card and the controls hidden, so without this script the cards simply stack. The turning stops
// while the pointer, a finger or the keyboard is inside, when the visitor asks for reduced motion and when
// the tab is hidden; a card picked by hand (arrow, dot or swipe) stays 10 seconds before it turns on, as
// the page's other sliders do.
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
const narrow = window.matchMedia('(max-width: 1023.98px)');
const EVERY = 5000;
const REST = 10000;
// A swipe: at least 40px sideways and more sideways than up or down (a page scroll is not a swipe).
const swipe = (dx, dy) => (Math.abs(dx) >= 40 && Math.abs(dx) > Math.abs(dy) ? Math.sign(dx) : 0);

for (const root of document.querySelectorAll('[data-sf-rot]')) {
    const cards = [...root.querySelectorAll('.sf-factory')];
    const nav = root.parentElement.querySelector('[data-sf-rot-nav]');
    const dots = nav ? [...nav.querySelectorAll('[data-sf-rot-go]')] : [];
    let current = 0;
    let timer = 0;
    let held = false;
    let resting = false;
    let rest = 0;
    let start = null;

    const show = (n) => {
        current = (n + cards.length) % cards.length;
        cards.forEach((card, i) => {
            card.setAttribute('aria-hidden', i === current ? 'false' : 'true');
            card.inert = i !== current;
        });
        dots.forEach((dot, i) => (i === current ? dot.setAttribute('aria-current', 'true') : dot.removeAttribute('aria-current')));
    };
    const schedule = () => {
        clearTimeout(timer);
        if (narrow.matches && !reduce.matches && !held && !resting && !document.hidden) {
            timer = setTimeout(() => {
                show(current + 1);
                schedule();
            }, EVERY);
        }
    };
    const pick = (n) => {
        resting = true;
        clearTimeout(rest);
        rest = setTimeout(() => {
            resting = false;
            schedule();
        }, REST);
        show(n);
        schedule();
    };
    const hold = (on) => () => {
        held = on;
        schedule();
    };
    const apply = () => {
        const on = narrow.matches;
        root.classList.toggle('is-rot', on);
        if (nav) {
            nav.hidden = !on;
        }
        if (on) {
            show(current);
        } else {
            cards.forEach((card) => {
                card.removeAttribute('aria-hidden');
                card.inert = false;
            });
        }
        schedule();
    };

    nav?.querySelector('[data-sf-rot-prev]')?.addEventListener('click', () => pick(current - 1));
    nav?.querySelector('[data-sf-rot-next]')?.addEventListener('click', () => pick(current + 1));
    dots.forEach((dot, i) => dot.addEventListener('click', () => pick(i)));
    for (const el of [root, nav].filter(Boolean)) {
        el.addEventListener('mouseenter', hold(true));
        el.addEventListener('mouseleave', hold(false));
        el.addEventListener('focusin', hold(true));
        el.addEventListener('focusout', hold(false));
        el.addEventListener('touchstart', hold(true), { passive: true });
        el.addEventListener('touchend', hold(false), { passive: true });
    }
    root.addEventListener('touchstart', (event) => {
        const t = event.touches[0];
        start = event.touches.length === 1 && root.classList.contains('is-rot') ? { x: t.clientX, y: t.clientY } : null;
    }, { passive: true });
    root.addEventListener('touchend', (event) => {
        const t = event.changedTouches[0];
        const dir = start && t ? swipe(t.clientX - start.x, t.clientY - start.y) : 0;
        start = null;
        if (dir) {
            pick(current - dir);
        }
    }, { passive: true });
    root.addEventListener('touchcancel', () => (start = null), { passive: true });
    narrow.addEventListener('change', apply);
    reduce.addEventListener('change', schedule);
    document.addEventListener('visibilitychange', schedule);
    apply();
}
