// The product photo switcher, loaded only on a product page with more than one photo. Without it
// the main photo and each thumbnail are plain links to the photo; with it a thumbnail or an arrow
// shows that photo in place, as does a sideways swipe on the photo, and the "1 / 4" count follows.
for (const box of document.querySelectorAll('[data-sf-gallery]')) {
    const main = box.querySelector('[data-sf-gallery-main]');
    const img = main?.querySelector('img');
    const count = box.querySelector('[data-sf-gallery-count]');
    const thumbs = [...box.querySelectorAll('[data-sf-gallery-thumb]')];
    if (!img || thumbs.length < 2) {
        continue;
    }
    let at = 0;
    const show = (index) => {
        at = (index + thumbs.length) % thumbs.length;
        const thumb = thumbs[at];
        if (thumb.dataset.srcset) {
            img.srcset = thumb.dataset.srcset;
        } else {
            img.removeAttribute('srcset');
        }
        img.src = thumb.href;
        main.href = thumb.href;
        if (count) {
            count.textContent = `${at + 1} / ${thumbs.length}`;
        }
        thumbs.forEach((other, k) => (k === at ? other.setAttribute('aria-current', 'true') : other.removeAttribute('aria-current')));
    };
    thumbs.forEach((thumb, k) => thumb.addEventListener('click', (event) => {
        event.preventDefault();
        show(k);
    }));
    for (const button of box.querySelectorAll('[data-sf-gallery-step]')) {
        button.hidden = false;
        button.addEventListener('click', () => show(at + Number(button.dataset.sfGalleryStep)));
    }
    // A sideways swipe on the photo does what the arrows do, as on the site's other sliders: one finger,
    // at least 40px and more sideways than up or down (a page scroll or a two-finger zoom is not a swipe).
    let start = null;
    main.addEventListener('touchstart', (event) => {
        const t = event.touches[0];
        start = event.touches.length === 1 ? { x: t.clientX, y: t.clientY } : null;
    }, { passive: true });
    main.addEventListener('touchend', (event) => {
        const t = event.changedTouches[0];
        const dx = start && t ? t.clientX - start.x : 0;
        const dy = start && t ? t.clientY - start.y : 0;
        start = null;
        if (Math.abs(dx) >= 40 && Math.abs(dx) > Math.abs(dy)) {
            show(at + (dx < 0 ? 1 : -1));
        }
    }, { passive: true });
    main.addEventListener('touchcancel', () => (start = null), { passive: true });
}
