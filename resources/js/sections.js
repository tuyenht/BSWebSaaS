// Scroll-in motion of sections (StyleProps `motion`), loaded only on pages with a block that moves.
// The server never hides anything: without this file every section shows at once. When the visitor
// starts to scroll, only what is still below the screen is set back (opacity / translate, so nothing
// shifts) and brought in once its top passes the reveal line, a little above the bottom edge, where
// the eye is; until then the page is the plain one (its first paint, an accessibility checker, a
// search engine see every section), and what is already in view is left alone, so nothing flashes.
// A section marked `sf-s--stagger` stays put and brings in its head and each item of its list
// instead. What comes in together steps 60 ms apart (CSS), at most five steps. At the end of the
// page, and before printing, everything still set back shows at once. A visitor who asked for
// reduced motion gets no motion, and a browser without IntersectionObserver none.
const LINE = 0.88;
const moving = [...document.querySelectorAll('.sf-s--m-fade, .sf-s--m-rise')];

if (moving.length && 'IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const parts = moving.flatMap((section) => section.classList.contains('sf-s--stagger')
        ? [...section.querySelectorAll('.sf-section-head, li')].filter((el) => el.tagName !== 'LI' || !el.parentElement.closest('li'))
        : [section]);
    let waiting = [];

    const show = (all) => {
        let step = 0;
        waiting = waiting.filter((el) => {
            if (!all && el.getBoundingClientRect().top >= window.innerHeight * LINE) {
                return true;
            }
            el.style.setProperty('--sf-step', String(Math.min(step++, 5)));
            el.classList.replace('sf-s--before', 'sf-s--in');
            observer.unobserve(el);

            return false;
        });
    };
    const atEnd = () => {
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
            show(true);
        }
        if (!waiting.length) {
            window.removeEventListener('scroll', atEnd);
        }
    };
    const observer = new IntersectionObserver(() => show(false), { rootMargin: `0px 0px -${Math.round((1 - LINE) * 100)}% 0px` });

    window.addEventListener('scroll', () => {
        waiting = parts.filter((el) => el.getBoundingClientRect().top > window.innerHeight);
        for (const el of waiting) {
            el.classList.add('sf-s--before');
            observer.observe(el);
        }
        window.addEventListener('scroll', atEnd, { passive: true });
        atEnd();
    }, { once: true, passive: true });
    window.addEventListener('beforeprint', () => show(true));
}
