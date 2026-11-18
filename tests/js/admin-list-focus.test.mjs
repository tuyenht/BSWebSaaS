// Where the focus goes back to after a row's action on a shared list (resources/js/admin-list-focus.js):
// `node --test tests/js/admin-list-focus.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const dom = new JSDOM(`<!doctype html><html><body>
  <div class="list-rows">
    <div role="row" data-rk="A"><div data-guide="row-status"><button id="pillA">Bản nháp</button></div>
      <div class="list-more"><button id="moreA" data-guide="row-more">⋮</button><div role="menu"><button id="itemA" role="menuitem">Nhân bản</button></div></div></div>
    <div role="row" data-rk="B"><div data-guide="row-status"><span>Đã đăng</span></div>
      <div class="list-more"><button id="moreB" data-guide="row-more">⋮</button></div></div>
  </div>
  <div id="toast"><button id="undo" data-toast-undo>Hoàn tác</button></div>
  <input id="other">
</body></html>`);
const { window } = dom;
globalThis.window = window;
globalThis.document = window.document;
globalThis.Element = window.Element;
const { backTarget } = await import('../../resources/js/admin-list-focus.js');
const doc = window.document;
// jsdom lays nothing out: "shown" is whatever is still in the page and not marked hidden here
const shown = (el) => !!el && el.isConnected && ! el.closest('[data-hidden]');

test('after a pick from the ⋮ the focus goes back to that row\'s ⋮', () => {
    doc.body.focus();
    assert.equal(backTarget(doc, { rk: 'A', kind: 'more' }, shown)?.id, 'moreA');
});

test('after a status change it goes to the row\'s status button, or its ⋮ when the status is not a button', () => {
    assert.equal(backTarget(doc, { rk: 'A', kind: 'st' }, shown)?.id, 'pillA');
    assert.equal(backTarget(doc, { rk: 'B', kind: 'st' }, shown)?.id, 'moreB');
});

test('a row that is gone (moved to the Trash) sends it to «Hoàn tác» on the message', () => {
    assert.equal(backTarget(doc, { rk: 'GONE', kind: 'more' }, shown)?.id, 'undo');
    doc.getElementById('toast').setAttribute('data-hidden', '');
    assert.equal(backTarget(doc, { rk: 'GONE', kind: 'more' }, shown), null);
    doc.getElementById('toast').removeAttribute('data-hidden');
});

test('focus the member put somewhere else meanwhile stays there; focus left on a closed menu item does not', () => {
    doc.getElementById('other').focus();
    assert.equal(backTarget(doc, { rk: 'A', kind: 'more' }, shown), false);
    doc.getElementById('itemA').focus();
    assert.equal(backTarget(doc, { rk: 'A', kind: 'more' }, shown)?.id, 'moreA');
    doc.body.focus();
});

test('an open dialog keeps the focus: the return waits until it has closed', () => {
    const dlg = doc.createElement('div');
    dlg.setAttribute('aria-modal', 'true');
    doc.body.append(dlg);
    assert.equal(backTarget(doc, { rk: 'A', kind: 'more' }, shown), null);
    dlg.remove();
    assert.equal(backTarget(doc, { rk: 'A', kind: 'more' }, shown)?.id, 'moreA');
});

test('the list asks for a return through window.bsListFocus', () => {
    window.document.getElementById('itemA').dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    assert.equal(typeof window.bsListFocus.expect, 'function');
});
