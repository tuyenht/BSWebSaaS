// Enter in a settings field (the `x-on:keydown.enter.document` of components/save-bar.blade.php):
// the expression is read from the Blade file and run against real key events in a DOM without a
// browser, so a wrong condition fails here and not only a markup match.
// `node --test tests/js/admin-save-bar-enter.test.mjs`.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const blade = readFileSync(new URL('../../app/Modules/Admin/resources/views/components/save-bar.blade.php', import.meta.url), 'utf8');
const found = blade.match(/x-on:keydown\.enter\.document="([^"]*)"/);
assert.ok(found, 'the bar listens for Enter on the document');
const expr = found[1].replaceAll('&gt;', '>').replaceAll('&lt;', '<').replaceAll('&amp;', '&');

const dom = new JSDOM(`<!doctype html><html><body>
    <div data-set-shell><form><input id="text"><input id="mail" type="email"><input id="box" type="checkbox"><textarea id="area"></textarea></form>
        <div id="bar"></div></div>
    <form><input id="outside"></form></body></html>`);
const { document, KeyboardEvent } = dom.window;
const root = document.getElementById('bar');
// eslint-disable-next-line no-new-func
const handler = new Function('$event', '$root', 'go', 'document', expr);

// Fires Enter at `id` with `init` and returns how often the bar saved and whether Enter was stopped.
function press(id, init = {}, before = null) {
    let saves = 0;
    const target = document.getElementById(id);
    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true, ...init });
    Object.defineProperty(event, 'target', { value: target });
    if (init.keyCode !== undefined) {
        Object.defineProperty(event, 'keyCode', { value: init.keyCode });
    }
    if (before) {
        before(event);
    }
    handler(event, root, () => saves++, document);
    return { saves, stopped: event.defaultPrevented };
}

test('Enter in a one-line field of the section saves through the bar', () => {
    assert.deepEqual(press('text'), { saves: 1, stopped: true });
    assert.deepEqual(press('mail'), { saves: 1, stopped: true });
});

test('Enter while a Vietnamese word is still being typed does not save', () => {
    assert.deepEqual(press('text', { isComposing: true }), { saves: 0, stopped: false });
    // Safari confirms a Telex/VNI word with isComposing false and keyCode 229.
    assert.deepEqual(press('text', { keyCode: 229 }), { saves: 0, stopped: false });
});

test('Enter elsewhere is left alone', () => {
    assert.equal(press('area').saves, 0, 'a textarea keeps its new line');
    assert.equal(press('box').saves, 0, 'a checkbox is not a one-line field');
    assert.equal(press('outside').saves, 0, 'a field outside the settings section');
    assert.equal(press('text', {}, (e) => e.preventDefault()).saves, 0, 'Enter another handler already took');
});
