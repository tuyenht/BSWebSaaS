// A post's table of contents (the side column's list) or a product's section bar: marks the entry
// being read with aria-current, which the stylesheet draws as the highlighted row (or pill). The entry is the last heading
// whose top has passed a line a third down the window (33%) (the first until then), or the last one once
// the page is scrolled to its end (a short closing section never reaches the line). IntersectionObservers
// tell when a heading crosses that line or the footer scrolls in, so nothing runs between headings.
// The list is plain anchor links and works without this file; nothing here moves the page (the
// product bar only slides sideways inside itself to keep the marked pill in view), and the
// row's colour change is switched off for visitors who asked for reduced motion (stylesheet).
const nav = document.querySelector('.post-toc, .product-toc');
const strip = nav?.querySelector('.product-toc__list');
const links = nav ? [...nav.querySelectorAll('a[href^="#"]')] : [];
const target = (link) => {
    try {
        return document.getElementById(decodeURIComponent(link.hash.slice(1)));
    } catch {
        return null;
    }
};
const pairs = links
    .map((link) => [link, target(link)])
    .filter(([, heading]) => heading);

if (pairs.length && 'IntersectionObserver' in window) {
    let current = null;
    const mark = () => {
        const line = window.innerHeight * 0.33;
        const atEnd = window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 2;
        let next = pairs[0][0];
        for (const [link, heading] of pairs) {
            if (atEnd) {
                next = link;
                continue;
            }
            if (heading.getBoundingClientRect().top > line) {
                break;
            }
            next = link;
        }
        if (next !== current) {
            current?.removeAttribute('aria-current');
            next.setAttribute('aria-current', 'true');
            current = next;
            if (strip) {
                const pill = next.getBoundingClientRect();
                const box = strip.getBoundingClientRect();
                if (pill.left < box.left || pill.right > box.right) {
                    strip.scrollLeft += pill.left - box.left - (box.width - pill.width) / 2;
                }
            }
        }
    };
    const observer = new IntersectionObserver(mark, { rootMargin: '0px 0px -67% 0px' });
    pairs.forEach(([, heading]) => observer.observe(heading));
    const footer = document.querySelector('footer');
    if (footer) {
        new IntersectionObserver(mark, { threshold: [0, 0.25, 0.5, 0.75, 1] }).observe(footer);
    }
    mark();
}
