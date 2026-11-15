// The writing frame's pure rules (resources/js/admin-rich-editor-text.js) without a browser: the
// footer's word count and reading time, and the link dialog's check of an address.
// `node --test tests/js/admin-rich-editor-text.test.mjs`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { countWords, linkVerdict, moveInlineStyles, readMinutes } from '../../resources/js/admin-rich-editor-text.js';

test('counts words across spaces, tabs and new lines, and nothing as zero', () => {
    assert.equal(countWords('Câu chuyện  An\tVinh\nhai mươi năm'), 7);
    assert.equal(countWords(''), 0);
    assert.equal(countWords('   '), 0);
    assert.equal(countWords(null), 0);
});

test('reads at 200 words a minute, rounded, never under one minute', () => {
    assert.equal(readMinutes(0), 1);
    assert.equal(readMinutes(98), 1);
    assert.equal(readMinutes(299), 1);
    assert.equal(readMinutes(300), 2);
    assert.equal(readMinutes(1000), 5);
    assert.equal(readMinutes('x'), 1);
});

test('the link dialog waits on an empty field and the bare https:// it starts with', () => {
    assert.equal(linkVerdict(''), 'empty');
    assert.equal(linkVerdict('  https://  '), 'empty');
});

test('the link dialog takes a web address and refuses anything else', () => {
    assert.equal(linkVerdict('https://anvinh.vn/lien-he'), 'good');
    assert.equal(linkVerdict(' http://a.vn '), 'good');
    assert.equal(linkVerdict('abc'), 'bad');
    assert.equal(linkVerdict('anvinh.vn'), 'bad');
    assert.equal(linkVerdict('https://localhost'), 'bad');
    assert.equal(linkVerdict('https://a.b c'), 'bad'); // a space inside an address
    assert.equal(linkVerdict('javascript:alert(1)'), 'bad');
});

test('a colour or size on bold, italic or linked text moves onto a span inside it, which the save keeps', async () => {
    const { JSDOM } = await import('jsdom');
    const doc = new JSDOM('<!doctype html><body></body>').window.document;
    assert.equal(
        moveInlineStyles('<p><strong style="color: rgb(240, 101, 72); font-size: 20px;">gá</strong> và <a href="https://a.vn" style="color: red;">x</a></p>', doc),
        '<p><strong><span style="color: rgb(240, 101, 72); font-size: 20px;">gá</span></strong> và <a href="https://a.vn"><span style="color: red;">x</span></a></p>',
    );
    // nothing to move: the same string back, untouched
    const plain = '<p><span style="color: red;">a</span> <strong>b</strong></p>';
    assert.equal(moveInlineStyles(plain, doc), plain);
});
