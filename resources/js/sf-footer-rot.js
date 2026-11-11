// The footer's factory cards below 1024px: one card at a time with arrows and dots, turning by itself every
// 3 seconds like the design, loaded only where the footer has two or more cards. The server prints every
// card and the controls hidden, so without this script the cards simply stack. The turning stops while the
// pointer, a finger or the keyboard is inside, when the visitor asks for reduced motion, when the tab is
// hidden, and for good once a visitor picks a card by hand.
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
const narrow = window.matchMedia('(max-width: 1023.98px)');

for (const root of document.querySelectorAll('[data-sf-rot]')) {
    const cards = [...root.querySelectorAll('.sf-factory')];
    const nav = root.parentElement.querySelector('[data-sf-rot-nav]');
    const dots = nav ? [...nav.querySelectorAll('[data-sf-rot-go]')] : [];
    let current = 0;
    let timer = 0;
    let held = false;
    let picked = false;

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
        if (narrow.matches && !reduce.matches && !held && !picked && !document.hidden) {
            timer = setTimeout(() => {
                show(current + 1);
                schedule();
            }, 3000);
        }
    };
    const pick = (n) => {
        picked = true;
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
    narrow.addEventListener('change', apply);
    reduce.addEventListener('change', schedule);
    document.addEventListener('visibilitychange', schedule);
    apply();
}
