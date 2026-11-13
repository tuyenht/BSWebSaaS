// The floating contact's part of the storefront script (resources/js/storefront.js), in a DOM without a
// browser: with click tracking on, a click on a contact link announces `bs:contact-click` at once (before
// the link leaves the page), naming the channel, place, device, page path and kind of link — never the
// number or the address; the "Liên hệ" button counts only its opening; the "Chép số" code is fetched at
// idle on a computer only; a first "Chép số" click before that code arrived still copies, and if the code
// never comes the number is selected. `node --test tests/js/sf-floating-contact.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

let load = 0;
const chunk = () => new URL(`../../resources/js/sf-fc-copy.js?run=${load}`, import.meta.url).href;

const widget = (src = '', track = true) => {
    const ev = (channel, at, lt) => (track ? ` data-fc="${channel}" data-fc-at="${at}" data-fc-lt="${lt}"` : '');
    return `<div class="sf-fc sf-fc--vertical" data-fc-root${src ? ` data-fc-js="${src}"` : ''}>
    <nav class="sf-fc__float sf-fc__float--right sf-fc__float--dial">
        <ul class="sf-fc__list" id="sf-fc-dial" popover>
            <li class="sf-fc__item sf-fc__item--call"><a id="call" class="sf-fc__btn" href="tel:0912345678"${ev('phone', 'dial', 'tel')}>Gọi</a>
                <span class="sf-fc__label"><span class="sf-fc__label-in"><span class="sf-fc__label-t">Gọi điện · <span class="sf-fc__num">0912 345 678</span></span><button type="button" id="copy" class="sf-fc__copy" data-fc-copy data-done="Đã chép"><span class="sf-fc__copy-t">Chép số</span></button><span class="sf-fc__sr" role="status"></span></span></span></li>
            <li class="sf-fc__item"><a id="mail" class="sf-fc__btn" href="mailto:lienhe@mau.test"${ev('email', 'dial', 'mailto')}><span id="mail-icon">@</span></a></li>
            <li class="sf-fc__item"><a id="zalo" class="sf-fc__btn" href="https://zalo.me/0912345678" rel="noopener"${ev('zalo', 'dial', 'web')}>Zalo</a></li>
        </ul>
        <button type="button" id="dial" class="sf-fc__dial" popovertarget="sf-fc-dial"${ev('dial', 'dial', 'none')}>Liên hệ</button>
    </nav>
</div>`;
};

// A fresh page and a fresh copy of the script run against it; `wide` = a computer's width.
async function start(html, { wide = false, clipboard = null } = {}) {
    const dom = new JSDOM(`<!doctype html><html><body>${html}</body></html>`, { url: 'https://mau.test/gioi-thieu?utm_source=x#lien-he', pretendToBeVisual: true });
    const w = dom.window;
    const idle = [];
    w.matchMedia = (query) => ({ matches: query.includes('max-width: 767') ? !wide : query.includes('min-width: 768') ? wide : false });
    w.requestIdleCallback = (fn) => idle.push(fn);
    if (clipboard) {
        Object.defineProperty(w.navigator, 'clipboard', { value: clipboard, configurable: true });
    }
    const beacons = [];
    Object.defineProperty(w.navigator, 'sendBeacon', { value: (url, body) => beacons.push([url, body]) && true, configurable: true });
    for (const name of ['window', 'document', 'location', 'navigator', 'CustomEvent', 'matchMedia', 'localStorage', 'getSelection', 'Element']) {
        Object.defineProperty(globalThis, name, { value: name === 'window' ? w : w[name], configurable: true, writable: true });
    }
    const events = [];
    w.document.addEventListener('bs:contact-click', (event) => events.push(event.detail));
    load += 1;
    await import(`../../resources/js/storefront.js?run=${load}`);
    if (w.document.readyState !== 'complete') {
        await new Promise((resolve) => w.addEventListener('load', resolve, { once: true }));
    }
    // jsdom cannot follow a link: stop it after the widget's own listener has run (as a browser would leave).
    w.document.addEventListener('click', (event) => event.preventDefault());
    const click = (id) => w.document.getElementById(id).dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));

    return { w, doc: w.document, events, beacons, idle, click };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 50));

test('a click on a contact link announces its channel, place, device, page path and kind of link at once', async () => {
    const { events, click } = await start(widget());

    click('mail-icon'); // a click on the icon inside the link counts for the link
    assert.deepEqual(events, [{ channel: 'email', placement: 'dial', device: 'phone', page_path: '/gioi-thieu', link_type: 'mailto' }]);

    click('zalo');
    assert.equal(events.length, 2, 'announced in the click itself, before an app link could leave the page');
    assert.deepEqual(events[1], { channel: 'zalo', placement: 'dial', device: 'phone', page_path: '/gioi-thieu', link_type: 'web' });
});

test('the announcement never carries the number, the address or the query string', async () => {
    const { events, click } = await start(widget(), { wide: true });

    click('call');
    click('mail');
    assert.equal(events[0].device, 'computer');
    const sent = JSON.stringify(events);
    for (const secret of ['0912', '345', 'lienhe', '@', 'mau.test', 'utm', '#']) {
        assert.ok(!sent.includes(secret), `no "${secret}" in ${sent}`);
    }
    assert.deepEqual(Object.keys(events[0]).sort(), ['channel', 'device', 'link_type', 'page_path', 'placement']);
});

test('the “Liên hệ” button counts its opening only, not its closing', async () => {
    const { doc, events, click } = await start(widget());
    const list = doc.getElementById('sf-fc-dial');
    let open = false;
    list.matches = (selector) => (selector === ':popover-open' ? open : false);

    click('dial'); // closed: this click opens it
    open = true;
    click('dial'); // open: this click closes it
    assert.deepEqual(events, [{ channel: 'dial', placement: 'dial', device: 'phone', page_path: '/gioi-thieu', link_type: 'none' }]);
});

test('a tap on a contact channel is counted with one beacon naming only the channel and the place', async () => {
    const { doc, beacons, click } = await start(widget());
    doc.getElementById('sf-fc-dial').matches = () => false;

    click('mail-icon');
    click('zalo');
    click('dial'); // opening the "Liên hệ" list is not a contact channel
    assert.deepEqual(beacons, [
        ['/_click', '{"channel":"email","placement":"dial"}'],
        ['/_click', '{"channel":"zalo","placement":"dial"}'],
    ]);
});

test('nothing is announced or counted with click tracking off', async () => {
    const { events, beacons, click } = await start(widget('', false));

    click('call');
    click('dial');
    assert.deepEqual(events, []);
    assert.deepEqual(beacons, []);
});

test('a page without the widget runs the script and clicks without an error', async () => {
    const { doc, events } = await start('<main><a id="x" href="/lien-he">Liên hệ</a></main>');

    assert.doesNotThrow(() => doc.getElementById('x').dispatchEvent(new doc.defaultView.MouseEvent('click', { bubbles: true, cancelable: true })));
    assert.deepEqual(events, []);
});

test('the “Chép số” code is asked for at idle on a computer only, and never without its address', async () => {
    const computer = await start(widget('/missing-chunk.js'), { wide: true });
    const phone = await start(widget('/missing-chunk.js'));
    const none = await start(widget(), { wide: true });

    assert.equal(computer.idle.length, 1);
    assert.equal(phone.idle.length, 0);
    assert.equal(none.idle.length, 0);
});

test('a first “Chép số” click before the code arrived copies in the click, then says “Đã chép”', async () => {
    const wrote = [];
    const { doc, click } = await start(widget(chunk()), { wide: true, clipboard: { writeText: (text) => (wrote.push(text), Promise.resolve()) } });

    click('copy');
    assert.deepEqual(wrote, ['0912345678'], 'written synchronously, inside the click');
    await settle();
    assert.equal(doc.querySelector('.sf-fc__copy-t').textContent, 'Đã chép');
    assert.equal(doc.querySelector('[role="status"]').textContent, 'Đã chép');
    assert.equal(wrote.length, 1, 'the code does not write a second time');
});

test('when no copy works, the number is selected and the button keeps saying “Chép số”', async () => {
    const { doc, click, w } = await start(widget('/missing-chunk.js'), { wide: true, clipboard: { writeText: () => Promise.reject(new Error('denied')) } });

    click('copy');
    await settle();
    assert.equal(w.getSelection().toString(), '0912 345 678');
    assert.equal(doc.querySelector('.sf-fc__copy-t').textContent, 'Chép số');
});

test('the copy code itself selects the number when the clipboard refuses', async () => {
    const { doc, click, w } = await start(widget(chunk()), { wide: true, clipboard: { writeText: () => Promise.reject(new Error('denied')) } });
    w.document.execCommand = () => false;

    click('copy');
    await settle();
    assert.equal(w.getSelection().toString(), '0912 345 678');
    assert.equal(doc.querySelector('.sf-fc__copy-t').textContent, 'Chép số');
    assert.equal(doc.querySelector('[role="status"]').textContent, '');
});
