// Scroll-in motion of sections (StyleProps `motion`), loaded only on pages with a block that moves.
// The server never hides anything: without this file every section shows at once. Here only the
// sections still below the screen are set back (opacity / transform, so nothing shifts) and shown
// again when they scroll in; sections already in view are left alone, so nothing flashes. A visitor
// who asked for reduced motion gets no motion, and a browser without IntersectionObserver none.
const moving = [...document.querySelectorAll('.sf-s--m-fade, .sf-s--m-rise')];

if (moving.length && 'IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                entry.target.classList.remove('sf-s--before');
                observer.unobserve(entry.target);
            }
        }
    }, { threshold: 0 });

    for (const section of moving) {
        if (section.getBoundingClientRect().top > window.innerHeight) {
            section.classList.add('sf-s--before');
            observer.observe(section);
        }
    }
}
