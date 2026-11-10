// A post's table of contents (the side column's list): marks the entry being read with
// aria-current, which the stylesheet draws as the highlighted row. The entry is the last heading
// whose top has passed a line a third down the window (33%) (the first until then); an IntersectionObserver
// tells when a heading crosses that line, so nothing runs while the page scrolls between headings.
// The list is plain anchor links and works without this file; nothing here moves the page, and the
// row's colour change is switched off for visitors who asked for reduced motion (stylesheet).
const nav = document.querySelector('.post-toc');
const links = nav ? [...nav.querySelectorAll('a[href^="#"]')] : [];
const pairs = links
    .map((link) => [link, document.getElementById(decodeURIComponent(link.hash.slice(1)))])
    .filter(([, heading]) => heading);

if (pairs.length && 'IntersectionObserver' in window) {
    let current = null;
    const mark = () => {
        const line = window.innerHeight * 0.33;
        let next = pairs[0][0];
        for (const [link, heading] of pairs) {
            if (heading.getBoundingClientRect().top > line) {
                break;
            }
            next = link;
        }
        if (next !== current) {
            current?.removeAttribute('aria-current');
            next.setAttribute('aria-current', 'true');
            current = next;
        }
    };
    const observer = new IntersectionObserver(mark, { rootMargin: '0px 0px -67% 0px' });
    pairs.forEach(([, heading]) => observer.observe(heading));
    mark();
}
