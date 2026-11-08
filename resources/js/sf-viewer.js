// The photo viewer, loaded only on a page with a photo that opens larger (a gallery picture, a
// portrait about photo). Each such photo is a plain link to the photo itself, so it still opens
// without this file. With it, a click opens the photo in a modal dialog: whole (never cut), with its
// caption and, for gallery pictures, "n / N" and previous / next (buttons, arrow keys or a swipe; the
// last wraps to the first). Esc, the close button or a click beside the photo closes it, and focus
// goes back to the photo that opened it. The frame and its labels come from #sf-viewer (head).
const links = [...document.querySelectorAll('a[data-sf-zoom]')];
const frame = document.getElementById('sf-viewer');
let dialog = null;
let set = [];
let at = 0;
let opener = null;

const caption = (link) => (link.closest('figure')?.querySelector('figcaption')?.textContent || link.querySelector('img')?.alt || '').trim();

function show(index) {
    at = (index + set.length) % set.length;
    const link = set[at];
    const thumb = link.querySelector('img');
    const img = dialog.querySelector('.sf-viewer__img');
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    if (thumb?.srcset) {
        img.srcset = thumb.srcset;
        img.sizes = '100vw';
    }
    img.src = link.href;
    // The caption below names the photo (read out once, as it changes); the image itself stays unnamed.
    dialog.querySelector('.sf-viewer__text').textContent = caption(link);
    dialog.querySelector('.sf-viewer__count').textContent = set.length > 1 ? `${at + 1} / ${set.length}` : '';
}

function build() {
    dialog = frame.content.firstElementChild.cloneNode(true);
    document.body.append(dialog);
    dialog.querySelector('.sf-viewer__prev').addEventListener('click', () => show(at - 1));
    dialog.querySelector('.sf-viewer__next').addEventListener('click', () => show(at + 1));
    dialog.querySelector('.sf-viewer__close').addEventListener('click', () => dialog.close());
    // A click on the dark area (the dialog itself or the space around the photo) closes it. The
    // pointer click that ends a swipe does nothing; a key press (a click with detail 0) always counts.
    let swipedAt = -1;
    dialog.addEventListener('click', (event) => {
        if (event.detail > 0 && event.timeStamp - swipedAt < 500) {
            event.stopPropagation();
            event.preventDefault();
        } else if (event.target === dialog || event.target.classList.contains('sf-viewer__figure')) {
            dialog.close();
        }
    }, true);
    dialog.addEventListener('keydown', (event) => {
        if (set.length > 1 && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
            event.preventDefault();
            show(at + (event.key === 'ArrowLeft' ? -1 : 1));
        }
    });
    // A swipe: one finger (or pen) across more than 50px. A second finger (a pinch) cancels it.
    const down = new Map();
    let pinched = false;
    dialog.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'mouse') {
            down.set(event.pointerId, event.clientX);
            pinched ||= down.size > 1;
        }
    });
    const lift = (event) => {
        const startX = down.get(event.pointerId);
        if (startX !== undefined && event.type === 'pointerup' && !pinched && set.length > 1 && Math.abs(event.clientX - startX) > 50) {
            swipedAt = event.timeStamp;
            show(at + (event.clientX < startX ? 1 : -1));
        }
        down.delete(event.pointerId);
        pinched &&= down.size > 0;
    };
    dialog.addEventListener('pointerup', lift);
    dialog.addEventListener('pointercancel', lift);
    dialog.addEventListener('close', () => opener?.focus());
}

if (frame) {
    for (const link of links) {
        link.addEventListener('click', (event) => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            if (!dialog) {
                build();
            }
            set = link.dataset.sfZoom === 'gallery' ? links.filter((one) => one.dataset.sfZoom === 'gallery') : [link];
            dialog.classList.toggle('sf-viewer--one', set.length < 2);
            opener = link;
            show(set.indexOf(link));
            dialog.showModal();
            dialog.querySelector('.sf-viewer__close').focus();
        });
    }
}
