// Where the focus goes back to after a row's action on a shared list (resources/js/admin-list-focus.js):
// `node --test tests/js/admin-list-focus.test.mjs`.
import assert from 'node:assert/strict';
import { mock, test } from 'node:test';
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
const { backTarget, carriesTrash, menuLeave, menuMove, trashHeld, trashDrawn, TRASH_HOLD_MS } = await import('../../resources/js/admin-list-focus.js');
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

test('a trash button ignores clicks while its move is on the way and for 600 ms after the list is drawn again', () => {
    const row = doc.createElement('div');
    row.innerHTML = '<button id="trashA" data-trash><span id="trashAi">Thùng rác</span></button><button id="trashB" data-trash>Thùng rác</button>';
    doc.body.append(row);
    const reached = [];
    for (const id of ['trashA', 'trashB']) {
        doc.getElementById(id).addEventListener('click', () => reached.push(id)); // what wire:click would do
    }
    const click = (id) => doc.getElementById(id).dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

    assert.equal(TRASH_HOLD_MS, 600);
    assert.equal(trashHeld(), false);
    click('trashAi'); // a press on the button's text counts as the button
    assert.deepEqual(reached, ['trashA']);
    // the second click of a double click, before the answer: held, whichever row's button it lands on
    click('trashA');
    click('trashB');
    assert.deepEqual(reached, ['trashA']);
    // the answer is drawn: still held for 600 ms, then the next row's button works again
    mock.timers.enable({ apis: ['Date'], now: Date.now() });
    try {
        trashDrawn();
        mock.timers.tick(TRASH_HOLD_MS - 1);
        click('trashB');
        assert.deepEqual(reached, ['trashA']);
        mock.timers.tick(1);
        click('trashB');
        assert.deepEqual(reached, ['trashA', 'trashB']);
        trashDrawn(); // its own answer starts the next hold
        assert.equal(trashHeld(), true);
        mock.timers.tick(TRASH_HOLD_MS);
        assert.equal(trashHeld(), false);
    } finally {
        mock.timers.reset();
        row.remove();
    }
});

test('while a trash button is held a click on anything else still goes through, and a hold never outlives a lost answer', () => {
    const row = doc.createElement('div');
    row.innerHTML = '<button id="trashC" data-trash>Thùng rác</button><button id="otherC">Sửa</button>';
    doc.body.append(row);
    let n = 0;
    doc.getElementById('otherC').addEventListener('click', () => n++);
    // a minute on, past the hold the test before left (its clock ran ahead of the real one)
    mock.timers.enable({ apis: ['Date'], now: Date.now() + 60000 });
    try {
        doc.getElementById('trashC').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        assert.equal(trashHeld(), true);
        doc.getElementById('otherC').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        assert.equal(n, 1);
        // the answer never comes (the connection dropped): after 15 s the button works again
        mock.timers.tick(15000);
        assert.equal(trashHeld(), true);
        mock.timers.tick(1);
        assert.equal(trashHeld(), false);
    } finally {
        mock.timers.reset();
        row.remove();
    }
});

test('only the answer that carries the move to the Trash starts the 600 ms', () => {
    const msg = (...names) => ({ getActions: () => names.map((name) => ({ name })) });
    assert.equal(carriesTrash(msg('trash')), true);
    assert.equal(carriesTrash(msg('search', 'trash')), true);
    assert.equal(carriesTrash(msg('changeStatus')), false); // a status change sent just before: the hold stays
    assert.equal(carriesTrash(msg()), false);
    assert.equal(carriesTrash({}), true); // a message that does not list its calls: every answer counts
});

test('a list menu opens on its first item that shows and its keys skip the hidden ones (a published page’s «Xem trên website»)', () => {
    const m = doc.createElement('div');
    m.setAttribute('role', 'menu');
    m.innerHTML = '<a id="m0" role="menuitem" data-xm href="#">Xem trên website</a><button id="m1" role="menuitem">Sao chép</button>'
        + '<button id="m2" role="menuitem">Nhân bản</button><button id="m3" role="menuitem" data-xm>Ẩn</button>';
    doc.body.append(m);
    const isShown = (el) => !el.hasAttribute('data-xm'); // the row shows these as its own buttons
    assert.equal(menuMove(m, 'first', isShown)?.id, 'm1');
    assert.equal(doc.activeElement.id, 'm1');
    assert.equal(menuMove(m, 'next', isShown)?.id, 'm2');
    assert.equal(menuMove(m, 'next', isShown)?.id, 'm1'); // wraps past the hidden ones
    assert.equal(menuMove(m, 'prev', isShown)?.id, 'm2');
    assert.equal(menuMove(m, 'last', isShown)?.id, 'm2');
    assert.equal(menuMove(m, 'first', () => false), null); // nothing shows: the focus stays where it is
    assert.equal(doc.activeElement.id, 'm2');
    m.remove();
});

test('Tab on an open menu item hands the focus back to the button and hides the menu at once, so the key moves on from the button', () => {
    const host = doc.createElement('div');
    host.innerHTML = '<button id="lvB">⋮</button><div id="lvShow" x-show="open"><div id="lvM" role="menu"><button id="lvI" role="menuitem">Sửa</button></div></div>';
    doc.body.append(host);
    const btn = doc.getElementById('lvB');
    const keyEvent = (shiftKey) => new window.KeyboardEvent('keydown', { key: 'Tab', shiftKey, cancelable: true });

    doc.getElementById('lvI').focus();
    const tab = keyEvent(false);
    menuLeave(tab, doc.getElementById('lvM'), () => btn.focus());
    assert.equal(doc.activeElement, btn);
    assert.equal(doc.getElementById('lvShow').style.display, 'none'); // the part with x-show, hidden before the browser moves on
    assert.equal(tab.defaultPrevented, false); // Tab goes on from the button to what follows it

    doc.getElementById('lvShow').style.display = '';
    doc.getElementById('lvI').focus();
    const back = keyEvent(true);
    menuLeave(back, doc.getElementById('lvM'), () => btn.focus());
    assert.equal(doc.activeElement, btn);
    assert.equal(back.defaultPrevented, true); // Shift+Tab stops on the button

    // a menu always open with nothing to give the focus back to keeps both keys and stays shown
    doc.getElementById('lvShow').style.display = '';
    doc.getElementById('lvI').focus();
    const stay = keyEvent(true);
    menuLeave(stay, doc.getElementById('lvM'), () => {});
    assert.equal(doc.activeElement.id, 'lvI');
    assert.equal(doc.getElementById('lvShow').style.display, '');
    assert.equal(stay.defaultPrevented, false);

    // the shared list menu carries x-show itself: that panel is the part hidden
    host.innerHTML = '<button id="lvB2">⋮</button><div id="lvP" x-show="open" role="menu"><button id="lvI2" role="menuitem">Sửa</button></div>';
    const btn2 = doc.getElementById('lvB2');
    doc.getElementById('lvI2').focus();
    menuLeave(keyEvent(false), doc.getElementById('lvP'), () => btn2.focus());
    assert.equal(doc.activeElement, btn2);
    assert.equal(doc.getElementById('lvP').style.display, 'none');
    host.remove();
});
