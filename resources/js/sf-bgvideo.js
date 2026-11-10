// Background video of the hero, loaded only on a page whose hero has one. The server prints the video
// with no source (so nothing is fetched) and its pause/play control hidden. After the page has loaded
// (the poster stays the page's main image), and only when the visitor has not asked for reduced motion
// or data saving, this adds the source and starts it muted: once it plays it replaces the poster and
// the control shows "Pause video". Autoplay refused: the poster stays and the control offers "Play
// video". The video fails to load: the poster stays and the control goes. Nothing is remembered.
const start = () => {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches || navigator.connection?.saveData) {
        return;
    }

    for (const video of document.querySelectorAll('video[data-sf-bgvideo]')) {
        const hero = video.closest('.hero');
        const button = hero?.querySelector('.hero__vctl');
        const label = button?.querySelector('.hero__vctl-label');
        if (!label || !video.dataset.src) {
            continue;
        }

        let failed = false;
        const show = (paused) => {
            if (failed) {
                return;
            }
            button.dataset.state = paused ? 'paused' : 'playing';
            label.textContent = paused ? button.dataset.play : button.dataset.pause;
            button.hidden = false;
        };
        const fail = () => {
            failed = true;
            hero.classList.remove('hero--playing');
            button.remove();
        };
        // A refused autoplay offers the button; any other refusal is the load failing (handled by fail).
        const play = () => video.play().then(() => show(false), (e) => {
            if (e?.name === 'NotAllowedError') {
                show(true);
            }
        });

        const source = document.createElement('source');
        source.src = video.dataset.src;
        source.type = video.dataset.type || '';
        source.addEventListener('error', fail, { once: true });
        video.addEventListener('error', fail, { once: true });
        video.addEventListener('playing', () => hero.classList.add('hero--playing'));
        button.addEventListener('click', () => {
            if (video.paused) {
                play();
            } else {
                video.pause();
                show(true);
            }
        });

        video.muted = true;
        video.append(source);
        play();
    }
};

if (document.readyState === 'complete') {
    start();
} else {
    addEventListener('load', start, { once: true });
}
