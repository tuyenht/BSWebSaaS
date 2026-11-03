// Count-up for the stats block, loaded only on a page that has one. The server already prints the
// final number, so print, search engines and reduced motion stay right. Set up after load (when the
// browser has restored the scroll position): a number already in view is left alone; one out of view
// shows 0 until it scrolls in, then counts up. Its box keeps the final height so nothing below moves.
const fmt = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

const run = (el) => {
    const end = Number(el.dataset.count);
    const t0 = performance.now();
    const step = (now) => {
        const p = Math.min((now - t0) / 1200, 1);
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
    const waiting = new WeakSet();
    const io = new IntersectionObserver((entries) => {
        for (const e of entries) {
            const el = e.target;
            if (!waiting.has(el)) {
                // First report for this number: in view → leave it; out of view → hold its box, show 0.
                if (e.isIntersecting) {
                    io.unobserve(el);
                } else {
                    waiting.add(el);
                    el.parentElement.style.minHeight = el.parentElement.offsetHeight + 'px';
                    el.textContent = '0';
                }
            } else if (e.isIntersecting) {
                io.unobserve(el);
                run(el);
            }
        }
    }, { threshold: 0.4 });
    for (const el of document.querySelectorAll('[data-count]')) {
        io.observe(el);
    }
};

if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    if (document.readyState === 'complete') {
        start();
    } else {
        addEventListener('load', start, { once: true });
    }
}
