// Behaviour check of resources/js/sf-video.js in jsdom (run in CI's frontend job):
// node scripts/storefront/video-check.mjs. The markup mirrors themes/default/partials/sf-video.
import { JSDOM } from 'jsdom';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import assert from 'node:assert/strict';

const script = pathToFileURL(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/js/sf-video.js')).href;

const box = (src, title) => `<div class="sf-video sf-video--blank" data-sf-video data-src="${src}" data-title="${title}">
<a class="sf-video__play" href="https://www.youtube.com/watch?v=SxwkhMy4Ywo"><span class="sf-video__icon" aria-hidden="true"><svg></svg></span><span class="sf-video__notice">Bấm để xem video trên YouTube</span></a></div>`;
const html = `<!doctype html><body>
${box('https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&amp;autoplay=1', 'Nhà máy')}
${box('https://player.vimeo.com/video/123456789?autoplay=1', 'Video Vimeo')}
${box('https://evil.example/embed/x', 'Lạ')}
</body>`;

const dom = new JSDOM(html);
globalThis.window = dom.window;
globalThis.document = dom.window.document;
await import(script);

const doc = dom.window.document;
const [youtube, vimeo, unknown] = doc.querySelectorAll('[data-sf-video]');
let checks = 0;
const check = (fn) => {
    fn();
    checks++;
};

// Before any press: a real button with the notice as its name, and no player anywhere.
check(() => assert.equal(doc.querySelectorAll('iframe').length, 0));
for (const playable of [youtube, vimeo]) {
    const button = playable.querySelector('.sf-video__play');
    check(() => assert.equal(button.tagName, 'BUTTON'));
    check(() => assert.equal(button.type, 'button'));
    check(() => assert.equal(button.textContent.trim(), 'Bấm để xem video trên YouTube'));
    check(() => assert.equal(playable.querySelector('a'), null));
}
// An address that is not a known player keeps the plain link and never gets a frame.
check(() => assert.equal(unknown.querySelector('.sf-video__play').tagName, 'A'));

// The press builds the player with autoplay, names it and moves focus to it.
youtube.querySelector('button').click();
const frame = youtube.querySelector('iframe');
check(() => assert.ok(frame));
check(() => assert.equal(frame.getAttribute('src'), 'https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&autoplay=1'));
check(() => assert.equal(frame.title, 'Nhà máy'));
check(() => assert.match(frame.getAttribute('allow'), /autoplay/));
check(() => assert.equal(doc.activeElement, frame));
check(() => assert.equal(youtube.querySelector('.sf-video__play'), null));
check(() => assert.ok(youtube.classList.contains('sf-video--playing')));
// Only the pressed video loads.
check(() => assert.equal(doc.querySelectorAll('iframe').length, 1));

vimeo.querySelector('button').click();
check(() => assert.equal(vimeo.querySelector('iframe').getAttribute('src'), 'https://player.vimeo.com/video/123456789?autoplay=1'));
check(() => assert.equal(unknown.querySelector('iframe'), null));

console.log(`video-check: ${checks} checks passed`);
