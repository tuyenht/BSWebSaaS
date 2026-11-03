// Count-up for the stats block, loaded only on a page that has one. The server already prints the
// final number, so this only animates numbers that start below the fold (nothing resets above it),
// and nothing at all with reduced motion or without IntersectionObserver.
const nodes = [...document.querySelectorAll('[data-count]')];

if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const fmt = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const run = (el) => {
        const end = Number(el.dataset.count);
        const text = el.textContent;
        const t0 = performance.now();
        const step = (now) => {
            const p = Math.min((now - t0) / 1200, 1);
            el.textContent = p < 1 ? fmt(Math.round(end * (1 - (1 - p) ** 3))) : text;
            if (p < 1) {
                requestAnimationFrame(step);
            }
        };
        requestAnimationFrame(step);
    };
    const io = new IntersectionObserver((entries) => {
        for (const e of entries) {
            if (e.isIntersecting) {
                io.unobserve(e.target);
                run(e.target);
            }
        }
    }, { threshold: 0.4 });
    for (const el of nodes) {
        if (el.getBoundingClientRect().top >= innerHeight) {
            io.observe(el);
        }
    }
}
