// Click-to-load video (the video block and videos in rich text), loaded only on pages with one.
// The server renders the site's own poster (or a placeholder) and a link to the video's own page;
// nothing is requested from YouTube or Vimeo until the visitor presses play. This file turns each
// link into a real button (Enter and Space press it) and, on the press, puts the player in the box
// (with autoplay, so one press plays) and moves focus to it, so a keyboard user stays in place.
const PLAYER = /^https:\/\/(?:www\.youtube-nocookie\.com\/embed\/|player\.vimeo\.com\/video\/)/;

for (const box of document.querySelectorAll('[data-sf-video]')) {
    const link = box.querySelector('a.sf-video__play');
    const src = box.dataset.src || '';
    if (!link || !PLAYER.test(src)) {
        continue;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = link.className;
    button.append(...link.childNodes);
    link.replaceWith(button);

    button.addEventListener('click', () => {
        const frame = document.createElement('iframe');
        frame.className = 'sf-video__frame';
        frame.src = src;
        frame.title = box.dataset.title || '';
        frame.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen');
        frame.setAttribute('allowfullscreen', '');
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        box.replaceChildren(frame);
        box.classList.add('sf-video--playing');
        frame.focus();
    }, { once: true });
}
