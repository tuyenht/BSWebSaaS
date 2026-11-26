// «Xem trước trên website» (resources/js/admin-site-preview.js): Escape pressed inside the website
// frame closes the preview, unless the website's phone menu or search panel is open (that Escape is
// the website's), or the website took the key. A menu branch the server draws open for the current
// page does not hold the preview. `node --test tests/js/admin-site-preview.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';
import { sitePreview } from '../../resources/js/admin-site-preview.js';

// The framed website: a burger (closed or open), a menu branch drawn open by the server inside the
// hidden phone menu, and the website's own Escape handler that closes its menu.
function site({ burgerOpen = false, takesKey = false } = {}) {
    const dom = new JSDOM(`<!doctype html><body>
        <button data-menu-toggle aria-expanded="${burgerOpen}">Menu</button>
        <div id="mobile-menu" hidden><button aria-expanded="true">Tin tức</button></div>
    </body>`);
    const win = dom.window;
    // jsdom lays nothing out: an element counts as drawn unless it sits under [hidden]
    win.Element.prototype.getClientRects = function () {
        return this.closest('[hidden]') ? [] : [{}];
    };
    win.document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') {
            return;
        }
        win.document.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', 'false');
        if (takesKey) {
            e.preventDefault();
        }
    });

    return win;
}

function dialogOn(win) {
    const d = sitePreview();
    d.open = true;
    let closed = 0;
    d.hide = () => {
        closed++;
        d.open = false;
    };
    d.frameKeys({ contentWindow: win });

    return { d, closed: () => closed };
}

const escape = (win) => win.document.body.dispatchEvent(new win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

test('Escape in the website closes the preview, a branch drawn open for the current page notwithstanding', () => {
    const win = site();
    const { closed } = dialogOn(win);
    escape(win);
    assert.equal(closed(), 1);
});

test('while the phone menu is open, the first Escape is the website\'s and the second closes the preview', () => {
    const win = site({ burgerOpen: true });
    const { closed } = dialogOn(win);
    escape(win);
    assert.equal(closed(), 0);
    assert.equal(win.document.querySelector('[data-menu-toggle]').getAttribute('aria-expanded'), 'false');
    escape(win);
    assert.equal(closed(), 1);
});

test('a key the website took (preventDefault) leaves the preview open, and other keys do nothing', () => {
    const win = site({ takesKey: true });
    const { closed } = dialogOn(win);
    escape(win);
    win.document.body.dispatchEvent(new win.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    assert.equal(closed(), 0);
});
