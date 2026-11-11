// Count-up for the stats block, loaded only on a page that has one. The server already prints the
// final number, so print, search engines and reduced motion stay right. While this script is on its way
// the stylesheet keeps a counted number transparent (only with scripting on and motion allowed), so the
// final number never flashes before it drops to 0; if the script never runs, the stylesheet shows the
// number after a short wait. Every number starts at 0 and counts up when it is 40% in view, the ones in
// the first screen too. A script that arrives after that wait leaves numbers already in view alone.
// Its box keeps the final height so nothing below moves.
const LATE_MS = 2400;

const fmt = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

const run = (el) => {
    const end = Number(el.dataset.count);
    const t0 = performance.now();
    const step = (now) => {
        const p = Math.min((now - t0) / 1400, 1);
        el.textContent = fmt(p < 1 ? Math.round(end * (1 - (1 - p) ** 3)) : end);
        if (p < 1) {
            requestAnimationFrame(step);
        } else {
            el.parentElement.style.minHeight = '';
        }
    };
    requestAnimationFrame(step);
};

const start = () => {
    const els = [...document.querySelectorAll('[data-count]')];
    const late = performance.now() > LATE_MS;
    const io = new IntersectionObserver((entries) => {
        for (const e of entries) {
            if (e.isIntersecting) {
                io.unobserve(e.target);
                run(e.target);
            }
        }
    }, { threshold: 0.4 });
    const hold = (el, h) => {
        el.parentElement.style.minHeight = h + 'px';
        el.textContent = '0';
        el.classList.add('is-counting');
        io.observe(el);
    };

    if (!late) {
        // All layout reads first, then the writes, so the page lays out once.
        const heights = els.map((el) => el.parentElement.offsetHeight);
        els.forEach((el, i) => hold(el, heights[i]));
        return;
    }
    // Late: the stylesheet has already shown the final numbers; count only the ones still out of view.
    const first = new IntersectionObserver((entries) => {
        const out = entries.filter((e) => !e.isIntersecting).map((e) => e.target);
        const heights = out.map((el) => el.parentElement.offsetHeight);
        out.forEach((el, i) => hold(el, heights[i]));
        first.disconnect();
    });
    els.forEach((el) => first.observe(el));
};

if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    start();
}
