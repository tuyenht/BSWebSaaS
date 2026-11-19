// The picker's upload list (resources/js/media-upload-rows.js, design board 13):
// `node --test tests/js/media-upload-rows.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';
import { classify, createUploadList, fmtSize, messageFor, reasonOf, RETRY, summary } from '../../resources/js/media-upload-rows.js';
import { usePipeline, withReason } from '../../resources/js/media-upload-pipeline.js';

const MSGS = {
    too_large: 'Tệp nặng :size, quá mức 10 MB.',
    unsupported_svg: 'Ảnh SVG chưa nhận được.',
    unsupported_type: 'Định dạng này chưa nhận được.',
    storage_failed: 'Chưa lưu được tệp — không phải lỗi của bạn.',
    unexpected: 'Chưa tải được tệp này. Bấm Thử lại.',
};
const LABELS = {
    done: 'Xong', failed: 'Chưa tải được', retry: 'Thử lại', stop: 'Dừng tải :name', drop: 'Bỏ khỏi danh sách :name',
    meta: ':size · :w × :h điểm ảnh · đã vào Thư viện', metaNoDim: ':size · đã vào Thư viện',
    headRun: 'Đang tải :n tệp', headDone: ':n tệp xong', headErr: ':n tệp chưa tải được',
};
const RULES = { maxBytes: 10 * 1048576, allowed: ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'] };

function mount() {
    const dom = new JSDOM('<!doctype html><div id="head"><span data-up-summary></span><button data-up-clear hidden></button></div><div id="list"></div><div id="drop" tabindex="-1"></div>');
    const doc = dom.window.document;
    const calls = { retry: [], remove: [] };
    const list = createUploadList({
        listEl: doc.getElementById('list'),
        headEl: doc.getElementById('head'),
        msgs: MSGS,
        labels: LABELS,
        icon: (name) => {
            const i = doc.createElement('i');
            i.dataset.icon = name;
            return i;
        },
        fallbackFocus: () => doc.getElementById('drop'),
        onRetry: (id) => calls.retry.push(id),
        onRemove: (id, running) => calls.remove.push([id, running]),
    });
    return { doc, list, calls, row: (id) => doc.querySelector(`[data-up-id="${id}"]`) };
}

test('refuses before upload what the server would refuse: too large, SVG, other formats', () => {
    assert.equal(classify({ name: 'a.jpg', type: 'image/jpeg', size: 11 * 1048576 }, RULES), 'too_large');
    assert.equal(classify({ name: 'logo.svg', type: 'image/svg+xml', size: 1000 }, RULES), 'unsupported_svg');
    assert.equal(classify({ name: 'logo.SVG', type: '', size: 1000 }, RULES), 'unsupported_svg');
    assert.equal(classify({ name: 'hop-dong.docx', type: 'application/msword', size: 1000 }, RULES), 'unsupported_type');
    assert.equal(classify({ name: 'may-phay.jpg', type: 'image/jpeg', size: 1000 }, RULES), null);
    assert.equal(classify({ name: 'catalogue.pdf', type: 'application/pdf', size: 1000 }, RULES), null);
    assert.equal(classify({ name: 'anh.webp', type: '', size: 1000 }, RULES), null);
    assert.equal(classify({ name: 'bang-gia.xlsx', type: '', size: 1000 }, RULES), 'unsupported_type');
    assert.equal(classify({ name: 'may-tien-cnc.jpg', type: 'image/jpeg', size: 0 }, RULES), 'file_invalid');
});

test('writes sizes the way the board does', () => {
    assert.equal(fmtSize(12.4 * 1048576), '12,4 MB');
    assert.equal(fmtSize(48 * 1024), '48 KB');
    assert.equal(fmtSize(10), '1 KB');
});

test('only the failures a second try can fix offer Thử lại', () => {
    assert.deepEqual(RETRY, ['upload_session_invalid', 'upload_missing', 'storage_failed', 'fetch_unreachable', 'unexpected']);
});

test('takes the reason from the server answer, else from the status, else the fallback', () => {
    assert.equal(withReason(new Error('x'), 'storage_failed', 500).reason, 'storage_failed');
    assert.equal(withReason(new Error('x'), undefined, 500).reason, 'unexpected');
    assert.equal(withReason(new Error('x'), undefined, 413).reason, 'too_large');
    assert.equal(withReason(new Error('x'), undefined, 422).reason, undefined);
    assert.equal(reasonOf(withReason(new Error('x'), undefined, 422), 'upload_missing'), 'upload_missing');
    assert.equal(reasonOf(null), 'unexpected');
});

test('a reason with no sentence of its own keeps the server sentence', () => {
    assert.equal(messageFor('in_trash', { msgs: MSGS, serverMessage: 'Tệp này đang nằm trong Thùng rác.' }), 'Tệp này đang nằm trong Thùng rác.');
    assert.equal(messageFor('too_large', { msgs: MSGS, size: 12.4 * 1048576 }), 'Tệp nặng 12,4 MB, quá mức 10 MB.');
    assert.equal(messageFor('nonsense', { msgs: MSGS }), MSGS.unexpected);
});

test('the summary counts each state', () => {
    assert.equal(summary([{ st: 'run' }, { st: 'run' }, { st: 'done' }, { st: 'err' }], LABELS), 'Đang tải 2 tệp · 1 tệp xong · 1 tệp chưa tải được');
    assert.equal(summary([{ st: 'done' }], LABELS), '1 tệp xong');
});

test('a row runs, finishes with its size and pixels, and the head offers to hide finished files', () => {
    const { doc, list, row } = mount();
    assert.equal(doc.querySelector('[data-up-summary]').textContent, '');
    list.add('f1', { name: 'may-phay-cnc.jpg', size: 1.8 * 1048576 });
    assert.equal(doc.querySelector('[data-up-summary]').textContent, 'Đang tải 1 tệp');
    assert.equal(row('f1').dataset.st, 'run');
    assert.equal(row('f1').querySelector('.mp-upbar').getAttribute('aria-valuenow'), '0');
    assert.equal(row('f1').querySelector('.mp-upx').getAttribute('aria-label'), 'Dừng tải may-phay-cnc.jpg');

    list.progress('f1', 46.6);
    assert.equal(row('f1').querySelector('.mp-upbar').getAttribute('aria-valuenow'), '47');
    assert.match(row('f1').querySelector('.mp-ups').textContent, /47%/);

    list.done('f1', { width: 1600, height: 1067 });
    assert.equal(row('f1').dataset.st, 'done');
    assert.equal(row('f1').querySelector('.mp-upmeta').textContent, '1,8 MB · 1600 × 1067 điểm ảnh · đã vào Thư viện');
    assert.equal(row('f1').querySelector('.mp-upbar'), null);
    assert.equal(doc.querySelector('[data-up-summary]').textContent, '1 tệp xong');
    assert.equal(doc.querySelector('[data-up-clear]').hidden, false);

    assert.deepEqual(list.clearDone(), ['f1']);
    assert.equal(row('f1'), null);
    assert.equal(doc.querySelector('[data-up-summary]').textContent, '');
    assert.equal(doc.querySelector('[data-up-clear]').hidden, true);
});

test('a failure shows its sentence; Thử lại only when a retry can help, and it keeps the row', () => {
    const { list, row, calls } = mount();
    list.add('a', { name: 'logo.svg', size: 1000 });
    list.fail('a', 'unsupported_svg');
    assert.equal(row('a').dataset.st, 'err');
    assert.equal(row('a').querySelector('.mp-upmsg').textContent, MSGS.unsupported_svg);
    assert.equal(row('a').querySelector('.mp-upx.rt'), null);
    assert.equal(row('a').querySelector('.mp-upx').getAttribute('aria-label'), 'Bỏ khỏi danh sách logo.svg');

    list.add('b', { name: 'anh.jpg', size: 2000 });
    list.fail('b', 'storage_failed', 'server words');
    const rt = row('b').querySelector('.mp-upx.rt');
    assert.ok(rt);
    assert.match(rt.textContent, /Thử lại/);
    rt.click();
    assert.deepEqual(calls.retry, ['b']);

    list.rekey('b', 'b2');
    assert.equal(row('b'), null);
    assert.equal(row('b2').dataset.st, 'run');

    row('a').querySelector('.mp-upx').click();
    assert.deepEqual(calls.remove, [['a', false]]);
    list.remove('a');
    assert.equal(row('a'), null);
});

test('the file name is shown whole, the extension apart so it is never cut', () => {
    const { list, row } = mount();
    list.add('n', { name: 'anh-toan-canh-xuong.ban-goc.jpg', size: 10 });
    const parts = [...row('n').querySelectorAll('.mp-upnm > span')].map((s) => s.textContent);
    assert.deepEqual(parts, ['anh-toan-canh-xuong.ban-goc', '.jpg']);
});

test('a name is shown as typed, even with $& in it', () => {
    const { list, row } = mount();
    list.add('d', { name: "a$&b$'c.jpg", size: 10 });
    assert.equal(row('d').querySelector('.mp-upx').getAttribute('aria-label'), "Dừng tải a$&b$'c.jpg");
});

test('once the bytes are sent, × takes the row off the list instead of stopping it', () => {
    const { list, row, calls } = mount();
    list.add('s', { name: 'anh.jpg', size: 10 });
    list.sent('s');
    assert.equal(row('s').dataset.st, 'run');
    assert.equal(row('s').querySelector('.mp-upx').getAttribute('aria-label'), 'Bỏ khỏi danh sách anh.jpg');
    row('s').querySelector('.mp-upx').click();
    assert.deepEqual(calls.remove, [['s', false]]);
});

test('a keyboard user keeps their place when a row changes or leaves', () => {
    const { doc, list, row } = mount();
    list.add('a', { name: 'a.jpg', size: 10 });
    list.add('b', { name: 'b.jpg', size: 10 });
    row('b').querySelector('.mp-upx').focus();
    list.fail('b', 'storage_failed');
    assert.equal(doc.activeElement, row('b').querySelector('.mp-upx:last-child'));

    row('b').querySelector('.mp-upx.rt').focus();
    list.fail('b', 'unexpected');
    assert.equal(doc.activeElement, row('b').querySelector('.mp-upx.rt'));

    list.remove('b'); // newest first: a is the next row
    assert.equal(doc.activeElement, row('a').querySelector('.mp-upx:last-child'));
    list.remove('a');
    assert.equal(doc.activeElement, doc.getElementById('drop'));
});

test('«Ẩn tệp đã xong» hands focus to the first row left before it hides itself', () => {
    const { doc, list, row } = mount();
    list.add('a', { name: 'a.jpg', size: 10 });
    list.fail('a', 'storage_failed');
    list.add('c', { name: 'c.jpg', size: 10 });
    list.fail('c', 'storage_failed');
    list.add('b', { name: 'b.jpg', size: 10 });
    list.done('b');
    doc.querySelector('[data-up-clear]').focus();
    list.clearDone();
    assert.equal(doc.activeElement, row('c').querySelector('.mp-upx:last-child')); // the top one: newest first
});

test('the same file picked again replaces its old row instead of leaving a stray one', () => {
    const { doc, list, row } = mount();
    list.add('same', { name: 'a.jpg', size: 10 });
    list.done('same');
    list.add('same', { name: 'a.jpg', size: 10 });
    assert.equal(doc.querySelectorAll('.mp-up').length, 1);
    assert.equal(row('same').dataset.st, 'run');
    assert.equal(doc.querySelector('[data-up-summary]').textContent, 'Đang tải 1 tệp');
});

test('the local upload takes the reason from the refusal and sends again only after a fault', () => {
    globalThis.document = new JSDOM('<!doctype html>').window.document;
    let opts = null;
    class XHRStub {}
    const uppy = { use: (plugin, o) => { if (plugin === XHRStub) opts = o; } };
    usePipeline(uppy, { disk: 'local', uploadUrl: '/u' }, class {}, XHRStub);
    delete globalThis.document;

    assert.equal(opts.onAfterResponse({ status: 201, responseText: '{}' }), undefined);
    assert.throws(() => opts.onAfterResponse({ status: 422, responseText: '{"reason":"file_invalid","message":"Tệp hỏng."}' }),
        (e) => e.reason === 'file_invalid' && e.message === 'Tệp hỏng.');
    assert.throws(() => opts.onAfterResponse({ status: 502, responseText: '<html>' }), (e) => e.reason === 'unexpected');
    assert.equal(opts.shouldRetry({ status: 422 }), false);
    assert.equal(opts.shouldRetry({ status: 0 }), true);
    assert.equal(opts.shouldRetry({ status: 503 }), true);
});
