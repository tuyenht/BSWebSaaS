// A news post's reading progress line (the width of the amber bar at the top of the window) and its
// "copy the link" buttons. The share links are plain links and the progress line stays empty without this
// file; the copy button is drawn hidden and only shown here. The bar is moved through a CSS variable
// (no inline style attribute), and not at all for visitors who asked for reduced motion.
const bar = document.querySelector('.post-progress__bar');
if (bar && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    let frame = 0;
    const draw = () => {
        frame = 0;
        const page = document.documentElement;
        const room = page.scrollHeight - page.clientHeight;
        bar.style.setProperty('--p', room > 0 ? Math.min(1, Math.max(0, page.scrollTop / room)).toFixed(3) : '0');
    };
    addEventListener('scroll', () => { frame ||= requestAnimationFrame(draw); }, { passive: true });
    draw();
}

const copy = async (url) => {
    try {
        await navigator.clipboard.writeText(url);
        return true;
    } catch {
        const box = document.createElement('textarea');
        box.value = url;
        box.setAttribute('readonly', '');
        box.style.position = 'fixed';
        box.style.opacity = '0';
        document.body.append(box);
        box.select();
        const done = document.execCommand?.('copy') === true;
        box.remove();
        return done;
    }
};

for (const button of document.querySelectorAll('.post-share__copy')) {
    button.hidden = false;
    button.addEventListener('click', async () => {
        const status = button.parentElement?.querySelector('.post-share__status');
        if (await copy(button.dataset.url ?? location.href) && status) {
            status.textContent = button.dataset.done ?? '';
            setTimeout(() => { status.textContent = ''; }, 2500);
        }
    });
}
