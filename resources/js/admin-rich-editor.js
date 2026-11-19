// The shared writing frame (lazy chunk, mounted from app.js on any admin page that carries a
// [data-rich-editor] textarea). ONE call-site contract, zero per-page config:
//
//   <x-bs-admin::rich-editor target="description" :value="$description" />
//
// The Blade component renders the frame of design 06: a one-row formatting bar (the everyday
// buttons, the rest behind «Thêm công cụ»), the writing area and a footer with the block under the
// caret and the counts. This module drives that markup and uses Jodit only as the editing engine
// (its own toolbar and status bar are off): commands, paste cleaning, undo, the table and image
// handles, the source view.
//
// Livewire 4 bridge (verified against v4.3.3 source). Component writes stay DOM-only on
// purpose: Livewire.find(id) returns the $wire proxy itself, and $wire.$set(name, value,
// live = true) defaults to LIVE — a network round-trip per keystroke — so editors never
// talk to $wire directly:
//   · content OUT: mirror HTML into the component's hidden [data-rich-sync] input + `input`
//     event — plain wire:model (deferred by default in LW4) carries it with the next
//     request (Save).
//   · actions IN:  the image/video buttons .click() the hidden [data-rich-action] wire:click
//     buttons rendered by the blade component (host component must use the
//     WithMediaLibraryPicker trait, or the wire:click fails server-side).
//   · results back: PHP dispatch() → Livewire.on('admin-editor-insert-image|html') — the
//     official LW4 bus (a __livewire-guarded window listener; the callback receives the
//     dispatch params object directly). Applied to the editor that owned the request.
// Jodit's own dialogs (table cells, image size) follow the admin locale via <html lang>; Jodit
// ships no Vietnamese, so we register our own pack.

import { Jodit } from 'jodit';
import 'jodit/es2021/jodit.min.css';
import { countWords, linkVerdict, moveInlineStyles, readMinutes } from './admin-rich-editor-text.js';
// The bare `jodit` entry (esm/index.js → esm/plugins/index.js) ships only the "essential"
// plugins. Every tool whose command lives outside that set must import its plugin here, or the
// button renders but silently no-ops on click.
// tests/Feature/Admin/RichEditorContractTest pins tool↔plugin↔sanitizer consistency.
import 'jodit/esm/plugins/justify/justify.js'; // left/center/right/justify
import 'jodit/esm/plugins/indent/indent.js'; // indent/outdent (margin-left; padding-left in cells)
import 'jodit/esm/plugins/clean-html/clean-html.js'; // eraser (removeFormat)
import 'jodit/esm/plugins/source/source.js';
import 'jodit/esm/plugins/resizer/resizer.js'; // drag-resize frames (img → width/height attrs)
import 'jodit/esm/plugins/select-cells/select-cells.js'; // fires the cell popup + all table* commands
import 'jodit/esm/plugins/resize-cells/resize-cells.js'; // drag column widths
import 'jodit/esm/plugins/table-keyboard-navigation/table-keyboard-navigation.js';
import 'jodit/esm/plugins/image-properties/image-properties.js'; // dblclick img → alt/size dialog
import 'jodit/esm/plugins/paste/paste.js'; // consumes askBeforePasteHTML/defaultActionOnPaste
import 'jodit/esm/plugins/paste-from-word/paste-from-word.js';
import jaPack from 'jodit/esm/langs/ja.js';
import zhPack from 'jodit/esm/langs/zh_cn.js';
import viPack from './admin-rich-editor-lang-vi.js';

// Every tool the frame offers, in the bar's order (the three lists are paragraph, fontsize and
// brush). Deliberately "just enough" (owner, benchmarked against the old T5G CKEditor set): NO
// templates/print/preview/find-replace/anchor/emoji — rare needs stay reachable via source view.
const FULL_BUTTONS = [
    'paragraph', 'bold', 'italic', 'ul', 'ol', 'link', 'libraryImage', 'embedVideo', 'undo', 'redo',
    'underline', 'strikethrough', 'superscript', 'subscript', 'fontsize', 'brush',
    'left', 'center', 'right', 'justify', 'outdent', 'indent', 'unlink', 'eraser',
    'table', 'hr', 'symbols', 'source', 'fullsize',
];

const BLOCKS = { p: 1, h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1, blockquote: 1, li: 1, td: 1, th: 1, figcaption: 1, figure: 1, pre: 1, table: 1, ul: 1, ol: 1 };

/** Admin UI locale → Jodit language key (unknown → en). */
function editorLanguage() {
    const lang = (document.documentElement.lang || 'en').toLowerCase().split('-')[0];

    return { vi: 'vi', ja: 'ja', zh: 'zh_cn', en: 'en' }[lang] ?? 'en';
}

let langsRegistered = false;

function registerLanguages() {
    if (langsRegistered) {
        return;
    }
    Jodit.lang.vi = viPack;
    Jodit.lang.ja = jaPack;
    Jodit.lang.zh_cn = zhPack;
    langsRegistered = true;
}

// The frame that owns the library-picker request — the Livewire insert event lands there.
let activeFrame = null;
let globalBound = false;
const frames = new Set();

// Livewire.on callbacks receive the PHP dispatch(...named args) as one params object.
function onLivewire(name, handler) {
    const hook = () => window.Livewire.on(name, handler);
    if (window.Livewire?.on) {
        hook();
    } else {
        // This chunk normally loads after Livewire boots (livewire:navigated), but on a cold
        // first paint the import can win the race — livewire:init still fires in LW4.
        document.addEventListener('livewire:init', hook, { once: true });
    }
}

function toast(text) {
    document.dispatchEvent(new CustomEvent('bs-toast', { detail: { text } }));
}

function bindGlobal() {
    if (globalBound) {
        return;
    }
    onLivewire('admin-editor-insert-image', (params) => {
        const url = params?.url;
        if (typeof url === 'string' && url !== '' && activeFrame) {
            activeFrame.restore();
            activeFrame.editor.s.insertImage(url);
        }
    });
    // Server-built fragments (video embeds) — inserted verbatim at the caret; the write-time
    // sanitizer remains the gate on what survives into the DOM.
    onLivewire('admin-editor-insert-html', (params) => {
        const html = params?.html;
        if (typeof html === 'string' && html !== '' && activeFrame) {
            activeFrame.restore();
            activeFrame.editor.s.insertHTML(html);
        }
    });
    // The server replaced a field's whole document (the editor took a newer saved version, or put
    // back the text kept on this device): the frame shows it. The carrier takes the same value
    // without an `input` event, as the server already holds it: the page does not read as changed.
    onLivewire('admin-editor-set-html', (params) => {
        const target = params?.target;
        const html = params?.html;
        if (typeof target !== 'string' || typeof html !== 'string') {
            return;
        }
        for (const frame of frames) {
            if (frame.target === target && frame.wrap.isConnected) {
                frame.setHtml(html);
            }
        }
    });
    // A button outside the frame that appends a ready fragment to the end of one editor (the
    // product's «Chèn bảng thông số»): [data-rich-append="<target>"] + data-rich-html + data-rich-toast.
    // Delegated, so a Livewire morph of the button never loses the handler.
    document.addEventListener('click', (event) => {
        const button = event.target.closest?.('[data-rich-append]');
        if (!button) {
            return;
        }
        const scope = button.closest('[wire\\:id]') || document;
        const frame = [...frames].find((f) => f.target === button.dataset.richAppend && scope.contains(f.wrap));
        if (!frame) {
            return;
        }
        frame.append(button.dataset.richHtml || '');
        if (button.dataset.richToast) {
            toast(button.dataset.richToast);
        }
    });
    globalBound = true;
}

// Fire the hidden wire:click button the blade component renders next to the editor — the only
// cross-version-safe way to call a Livewire action from third-party JS.
function clickWireAction(frame, action) {
    activeFrame = frame;
    frame.leaveFullscreen(); // the media and video dialogs open over the page, not over a full-screen frame
    frame.save();
    frame.wrap.closest('[wire\\:id]')?.querySelector(`[data-rich-action="${action}"]`)?.click();
}

function readToolbarPref() {
    try {
        const o = JSON.parse(localStorage.getItem('bs-editor') || 'null');
        if (o && typeof o === 'object' && o.toolbar) {
            return o.toolbar === 'full';
        }
        const u = JSON.parse(localStorage.getItem('bs-ui') || 'null');

        return !!(u && u.editorToolbar === 'full');
    } catch {
        return false;
    }
}

function saveToolbarPref(full) {
    try {
        let o = JSON.parse(localStorage.getItem('bs-editor') || 'null');
        o = o && typeof o === 'object' ? o : {};
        o.toolbar = full ? 'full' : 'compact';
        localStorage.setItem('bs-editor', JSON.stringify(o));
    } catch {
        // private mode / blocked storage: the bar simply starts compact next time
    }
}

function editorOptions(placeholder) {
    return {
        toolbar: false,
        statusbar: false,
        ...(placeholder ? { placeholder } : { showPlaceholder: false }),
        language: editorLanguage(),
        hidePoweredByJodit: true, // MIT license asks for the LICENSE file, not a UI credit
        minHeight: 300,
        // Paste lands as cleaned HTML without the "keep formatting?" prompt; the server-side
        // sanitizer is the actual gate, this only keeps the UX quiet.
        askBeforePasteHTML: false,
        defaultActionOnPaste: 'insert_clear_html',
        disablePlugins: ['add-new-line'],
        // Source view stays a plain textarea: 'ace' (the default) AND beautifyHTML both load
        // libraries from cdnjs at runtime — a white-label/CSP violation.
        sourceEditor: 'area',
        beautifyHTML: false,
        // Dblclick-image dialog trimmed to what survives the server allowlist (img[src|alt|
        // width|height]) — src/link/margins/class/style/align edits and the unconfigured
        // filebrowser affordances would either be stripped on save or dead-end.
        image: {
            editSrc: false,
            useImageEditor: false,
            editTitle: false,
            editLink: false,
            editBorderRadius: false,
            editMargins: false,
            editClass: false,
            editStyle: false,
            editId: false,
            editAlign: false,
        },
    };
}

const escapeHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

function mountFrame(wrap) {
    const el = wrap.querySelector('[data-rich-editor]');
    const target = el.dataset.richTarget;
    const words = JSON.parse(wrap.dataset.rteWords || '{}');
    const q = (sel) => wrap.querySelector(sel);
    const tbw = q('.rte-tbw');
    const tbs = q('.rte-tbs');
    const tb = q('.rte-tb');
    const gk = q('.rte-gk');
    const toggle = q('[data-rte-toggle]');
    const arrows = { l: q('[data-rte-arr="-1"]'), r: q('[data-rte-arr="1"]') };
    const frameEl = q('.rte-frame');
    const sheet = q('[data-rte-sheet]');
    const linkDlg = q('[data-rte-link]');
    const linkIn = q('[data-rte-link-in]');
    const linkNote = q('[data-rte-link-note]');
    const linkOkBtn = q('[data-rte-link-ok]');
    const charsDlg = q('[data-rte-chars]');
    const tag = q('[data-rte-tag]');
    const coarse = window.matchMedia('(any-pointer: coarse)');
    const fmt = new Intl.NumberFormat(document.documentElement.lang || 'vi');
    // The wire:model carrier lives OUTSIDE the wire:ignore wrapper (morph-safe) — writing
    // into it never touches the editor's DOM, so IME composition stays intact.
    const syncEl = wrap.closest('[wire\\:id]')?.querySelector(`[data-rich-sync="${target}"]`);

    const editor = Jodit.make(el, editorOptions(el.dataset.richPlaceholder));
    const body = editor.editor;
    // The writing area is what a screen reader lands on: give it the field's name.
    const name = el.getAttribute('aria-label') || document.querySelector(`label[for="${el.id}"]`)?.textContent?.trim();
    if (name) {
        body.setAttribute('aria-label', name);
    }
    body.setAttribute('role', 'textbox');
    body.setAttribute('aria-multiline', 'true');

    let saved = null;
    let tagNode = null;
    let openName = null;
    let ddHome = null;
    let measureN = 0;
    let raf = 0;
    let idle = 0;

    const frame = { wrap, target, editor };
    let quiet = false;

    const push = (raw) => {
        if (!syncEl || typeof raw !== 'string' || quiet) {
            return;
        }
        const value = moveInlineStyles(raw);
        if (syncEl.value === value) {
            return;
        }
        syncEl.value = value;
        syncEl.dispatchEvent(new Event('input', { bubbles: true }));
    };
    // A document the server set (admin-editor-set-html): shown, the carrier matched, nothing sent.
    frame.setHtml = (html) => {
        quiet = true;
        try {
            editor.value = html;
        } finally {
            quiet = false;
        }
        if (syncEl) {
            syncEl.value = moveInlineStyles(editor.value);
        }
        counts();
    };

    // ── selection kept across a click on the bar or a dialog ──
    const liveRange = () => {
        const sel = editor.s.sel;
        if (!sel || !sel.rangeCount) {
            return null;
        }
        const r = sel.getRangeAt(0);

        return body.contains(r.commonAncestorContainer) ? r : null;
    };
    frame.save = () => {
        const r = liveRange();
        if (r) {
            saved = r.cloneRange();
        }
    };
    frame.restore = () => {
        if (editor.getMode() !== Jodit.constants.MODE_WYSIWYG) {
            return;
        }
        if (saved && body.contains(saved.commonAncestorContainer)) {
            editor.s.selectRange(saved);
        } else if (!liveRange()) {
            editor.s.focus();
        }
    };
    frame.append = (html) => {
        if (editor.getMode() !== Jodit.constants.MODE_WYSIWYG) {
            editor.toggleMode();
            syncSource();
        }
        editor.value = editor.value + html;
        push(editor.value);
        body.lastElementChild?.scrollIntoView?.({ block: 'nearest' });
    };

    const run = (command, value) => {
        frame.restore();
        editor.execCommand(command, false, value);
        frame.save();
    };
    const insert = (html) => {
        frame.restore();
        editor.s.insertHTML(html);
        frame.save();
    };

    // ── footer: block under the caret + counts ──
    const counts = () => {
        const text = body.innerText || '';
        const w = countWords(text);
        q('[data-rte-n="chars"]').textContent = fmt.format(text.replace(/\n/g, '').length);
        q('[data-rte-n="words"]').textContent = fmt.format(w);
        q('[data-rte-n="read"]').textContent = String(words.minutes || ':n').replace(':n', fmt.format(readMinutes(w)));
    };
    const blockName = (node) => {
        const t = node.tagName.toLowerCase();
        const b = words.blocks || {};
        if (/^h[1-6]$/.test(t)) {
            return String(b.h || '').replace(':n', t.slice(1));
        }
        if (t === 'li') {
            return node.parentElement?.tagName === 'OL' ? b.oli : b.li;
        }

        return b[t] || b.p;
    };
    const readTag = () => {
        const r = liveRange();
        let n = r ? r.startContainer : null;
        if (n && n.nodeType !== 1) {
            n = n.parentNode;
        }
        tagNode = null;
        while (n && n !== body) {
            if (BLOCKS[n.tagName.toLowerCase()]) {
                tagNode = n;
                break;
            }
            n = n.parentNode;
        }
        if (!r) {
            return; // the caret left the writing area: keep the last block shown
        }
        tag.hidden = !tagNode;
        if (tagNode) {
            const label = blockName(tagNode);
            tag.textContent = label;
            tag.title = String(words.pick || '').replace(':name', label);
        }
    };

    // ── «Thêm công cụ» ──
    const setFull = (full, focusFirst) => {
        for (const g of wrap.querySelectorAll('[data-rte-more]')) {
            g.hidden = !full;
        }
        toggle.setAttribute('aria-expanded', String(full));
        toggle.title = full ? words.lessTitle : words.moreTitle;
        toggle.setAttribute('aria-label', full ? words.less : words.more);
        q('.rte-tgl-t').textContent = full ? words.less : words.more;
        if (focusFirst) {
            q('[data-rte-more] button')?.focus();
        }
        schedule();
    };

    // ── narrow bar: ‹ › arrows, two rows, the strip stopping at a whole button ──
    const isNarrow = () => tbw.clientWidth < 600 || coarse.matches;
    const stripButtons = () => [...tb.querySelectorAll('.rte-g > button, .rte-g > span > button')].filter((b) => b.getClientRects().length);
    const lastIn = () => {
        let right = -Infinity;
        for (const b of stripButtons()) {
            right = Math.max(right, b.getBoundingClientRect().right);
        }

        return right > -Infinity && right <= tb.getBoundingClientRect().right + 0.5;
    };
    // One row only when the strip beside «Thêm công cụ» shows Kiểu · Đậm · Nghiêng · Danh sách whole.
    const needRows2 = () => {
        const cs = getComputedStyle(tbw);
        const inner = tbw.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        const groups = [...tb.children].filter((e) => e.classList.contains('rte-g') && !e.classList.contains('rte-gk'));
        const first = groups[0];
        const list = groups[1];
        const listBtn = list?.querySelector('button');
        if (!first || !listBtn) {
            return false;
        }
        const ls = getComputedStyle(list);
        const sep = parseFloat(ls.marginLeft) + parseFloat(ls.paddingLeft) + parseFloat(ls.borderLeftWidth);
        const need = gk.getBoundingClientRect().width + sep + (listBtn.getBoundingClientRect().right - first.getBoundingClientRect().left);
        const avail = inner - toggle.getBoundingClientRect().width - 8 - 44 - 8;

        return need > avail + 0.5;
    };
    const setRows = (two) => {
        tbw.dataset.rows = two ? '2' : '1';
        if (two && gk.parentElement !== tbw) {
            tbw.insertBefore(gk, tbs);
        } else if (!two && gk.parentElement !== tb) {
            tb.insertBefore(gk, tb.firstChild);
        }
    };
    // Shrink the strip to the edge of the last whole button, so no half button sits beside "›".
    const markCut = () => {
        tb.style.maxWidth = '';
        if (!isNarrow()) {
            return;
        }
        const br = tb.getBoundingClientRect();
        if (br.width < 1) {
            return;
        }
        const R = stripButtons().map((b) => b.getBoundingClientRect());
        const fi = R.findIndex((r) => r.left >= br.left - 0.5);
        if (fi < 0) {
            return;
        }
        let lastR = 0;
        for (let i = fi; i < R.length; i++) {
            if (R[i].right <= br.right + 0.5) {
                lastR = Math.max(lastR, R[i].right);
            }
        }
        const cutNext = R.some((r) => r.left < br.right - 0.5 && r.right > br.right + 0.5);
        if (lastR && cutNext && br.right - lastR > 0.5) {
            tb.style.maxWidth = `${Math.floor(lastR - br.left)}px`;
        }
    };
    const measure = () => {
        const narrow = isNarrow();
        markCut();
        const atEnd = tb.scrollLeft + tb.clientWidth >= tb.scrollWidth - 1.5 || lastIn();
        const l = !(narrow && tb.scrollLeft > 1.5);
        const r = !(narrow && !atEnd);
        const two = narrow && needRows2();
        const changed = arrows.l.hidden !== l || arrows.r.hidden !== r || (tbw.dataset.rows === '2') !== two;
        arrows.l.hidden = l;
        arrows.r.hidden = r;
        setRows(two);
        wrap.dataset.narrow = narrow ? '1' : '0';
        // Arrows appearing or the second row change the strip's width: measure again (at most 3 times).
        if (changed && ++measureN < 4) {
            schedule();
        } else if (!changed) {
            measureN = 0;
        }
    };
    function schedule() {
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(measure);
    }

    // ── lists: a dropdown under the button, or a sheet from the bottom on a narrow/touch bar ──
    const ddOf = (name) => wrap.querySelector(`[data-rte-dd="${name}"]`);
    const trigger = (name) => q(`[data-rte-menu="${name}"]`);
    const menuItems = (box) => [...box.querySelectorAll('button, input')].filter((x) => x.getClientRects().length || x.type === 'color');
    const closeMenu = (focusTrigger) => {
        if (!openName) {
            return;
        }
        const name = openName;
        const dd = ddOf(name);
        openName = null;
        trigger(name).setAttribute('aria-expanded', 'false');
        if (ddHome) {
            ddHome.appendChild(dd);
            ddHome = null;
            if (sheet.open) {
                sheet.close();
            }
        }
        dd.hidden = true;
        if (focusTrigger) {
            trigger(name).focus();
        }
    };
    const openMenu = (name, byKeyboard) => {
        if (openName === name) {
            closeMenu();

            return;
        }
        closeMenu();
        frame.save();
        const dd = ddOf(name);
        openName = name;
        trigger(name).setAttribute('aria-expanded', 'true');
        dd.hidden = false;
        if (isNarrow()) {
            ddHome = dd.parentElement;
            q('.rte-sh-b').appendChild(dd);
            q('.rte-sh-t').textContent = words.sheet?.[name] || '';
            sheet.showModal();
            menuItems(dd)[0]?.focus();
        } else if (byKeyboard) {
            menuItems(dd)[0]?.focus();
        }
    };
    sheet.addEventListener('close', () => closeMenu());

    const setColorTab = (dd, tab) => {
        dd.dataset.tab = tab;
        for (const b of dd.querySelectorAll('[data-rte-ctab]')) {
            b.setAttribute('aria-pressed', String(b.dataset.rteCtab === tab));
        }
        const word = tab === 'bg' ? words.colorBg : words.colorText;
        for (const b of dd.querySelectorAll('[data-rte-color]')) {
            const t = String(word || '').replace(':hex', b.dataset.rteColor);
            b.title = t;
            b.setAttribute('aria-label', t);
        }
    };
    const applyColor = (hex) => {
        const dd = ddOf('color');
        run(dd.dataset.tab === 'bg' ? 'background' : 'forecolor', hex);
    };

    // ── link dialog ──
    const linkState = () => {
        const state = linkVerdict(linkIn.value);
        linkNote.dataset.state = state;
        linkNote.querySelector('span').textContent = { empty: words.linkEmpty, good: words.linkGood, bad: words.linkBad }[state];
        linkOkBtn.setAttribute('aria-disabled', String(state !== 'good'));

        return state === 'good';
    };
    const linkAt = (r) => {
        let n = r ? r.startContainer : null;
        if (n && n.nodeType === 3) {
            n = n.parentNode;
        }
        const a = n?.closest?.('a');

        return a && body.contains(a) ? a : null;
    };
    const openLink = () => {
        if (linkDlg.open) {
            return;
        }
        closeMenu();
        frame.save();
        linkIn.value = linkAt(saved)?.getAttribute('href') || 'https://';
        linkState();
        linkDlg.showModal();
        linkIn.focus();
        linkIn.setSelectionRange(linkIn.value.length, linkIn.value.length);
    };
    const linkOk = () => {
        if (!linkState()) {
            return;
        }
        const v = linkIn.value.trim();
        linkDlg.close();
        frame.restore();
        const r = liveRange();
        const a = linkAt(r);
        if (r && r.collapsed && a) {
            a.setAttribute('href', v);
            editor.synchronizeValues();
            toast(words.linkChanged);
        } else if (!r || r.collapsed) {
            insert(`<a href="${escapeHtml(v)}">${escapeHtml(v)}</a>`);
        } else {
            run('createLink', v);
        }
    };
    const unlink = () => {
        frame.restore();
        const r = liveRange();
        if (r && r.collapsed) {
            const a = linkAt(r);
            if (!a) {
                toast(words.unlinkNone);

                return;
            }
            a.replaceWith(...a.childNodes);
            body.normalize();
            editor.synchronizeValues();

            return;
        }
        run('unlink');
    };

    frame.leaveFullscreen = () => {
        if (frameEl.dataset.full === '1') {
            setFullscreen(false);
        }
    };
    const setFullscreen = (on) => {
        frameEl.dataset.full = on ? '1' : '0';
        document.documentElement.style.overflow = on ? 'hidden' : '';
        const b = q('[data-rte-cmd="fullsize"]');
        b.setAttribute('aria-pressed', String(on));
        b.title = on ? words.fullExit : words.full;
        b.setAttribute('aria-label', b.title);
        schedule();
    };
    const syncSource = () => {
        q('[data-rte-cmd="source"]').setAttribute('aria-pressed', String(editor.getMode() === Jodit.constants.MODE_SOURCE));
    };
    const table = () => {
        const cell = (n) => `<td>${escapeHtml(String(words.cell || 'Ô :n').replace(':n', n))}</td>`;
        insert(`<table><tbody><tr>${cell(1)}${cell(2)}${cell(3)}</tr><tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table><p><br></p>`);
    };

    // Tool name → what it does. Keys are FULL_BUTTONS (the contract test reads them).
    const TOOLS = {
        bold: () => run('bold'),
        italic: () => run('italic'),
        underline: () => run('underline'),
        strikethrough: () => run('strikethrough'),
        superscript: () => run('superscript'),
        subscript: () => run('subscript'),
        ul: () => run('insertUnorderedList'),
        ol: () => run('insertOrderedList'),
        link: openLink,
        unlink,
        libraryImage: () => clickWireAction(frame, 'image'),
        embedVideo: () => clickWireAction(frame, 'video'),
        undo: () => run('undo'),
        redo: () => run('redo'),
        left: () => run('justifyLeft'),
        center: () => run('justifyCenter'),
        right: () => run('justifyRight'),
        justify: () => run('justifyFull'),
        outdent: () => run('outdent'),
        indent: () => run('indent'),
        eraser: () => run('removeFormat'),
        table,
        hr: () => insert('<hr><p><br></p>'),
        symbols: () => {
            frame.save();
            charsDlg.showModal();
            charsDlg.querySelector('button')?.focus();
        },
        source: () => {
            editor.toggleMode();
            syncSource();
        },
        fullsize: () => setFullscreen(frameEl.dataset.full !== '1'),
    };

    // ── events ──
    const onMouseDown = (e) => {
        // Keep the caret in the writing area while a button of the bar or a list is pressed.
        if (e.target.closest('button') && !e.target.closest('dialog.rte-dlg')) {
            e.preventDefault();
        }
    };
    const onClick = (e) => {
        const b = e.target.closest('button');
        const dialog = e.target.closest('dialog');
        // A press on a dialog's backdrop closes it (the dialog element itself, outside its box).
        if (dialog && e.target === dialog) {
            const r = dialog.getBoundingClientRect();
            if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) {
                dialog.close();
            }

            return;
        }
        if (!b || !wrap.contains(b)) {
            return;
        }
        const d = b.dataset;
        if (d.rteCmd) {
            if (b.getAttribute('aria-disabled') !== 'true') {
                TOOLS[d.rteCmd]?.();
            }
        } else if (d.rteMenu) {
            openMenu(d.rteMenu, e.detail === 0);
        } else if (d.rteBlock) {
            closeMenu();
            run('formatblock', d.rteBlock);
        } else if (d.rteSize) {
            closeMenu();
            frame.restore();
            const r = liveRange();
            if (!r || r.collapsed) {
                toast(words.sizeNeed);
            } else {
                run('fontsize', `${d.rteSize}px`);
            }
        } else if (d.rteCtab) {
            setColorTab(b.closest('[data-rte-dd]'), d.rteCtab);
        } else if (d.rteColor) {
            closeMenu();
            applyColor(d.rteColor);
        } else if (d.rteArr) {
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            tb.scrollBy({ left: Number(d.rteArr) * Math.max(120, tb.clientWidth * 0.7), behavior: reduce ? 'auto' : 'smooth' });
            setTimeout(schedule, 320);
        } else if (d.rteToggle !== undefined) {
            const full = toggle.getAttribute('aria-expanded') !== 'true';
            saveToolbarPref(full);
            setFull(full, full && e.detail === 0);
        } else if (d.rteTag !== undefined && tagNode && body.contains(tagNode)) {
            const r = document.createRange();
            r.selectNodeContents(tagNode);
            editor.s.selectRange(r);
            frame.save();
        } else if (d.rteChar) {
            charsDlg.close();
            insert(escapeHtml(d.rteChar));
        } else if (d.rteLinkOk !== undefined) {
            linkOk();
        } else if (d.rteClose !== undefined) {
            b.closest('dialog')?.close();
        }
    };
    const onKeyDown = (e) => {
        const t = e.target;
        // Inside an open list: arrows, Home/End move between its items; Esc closes it.
        const box = t.closest?.('[data-rte-dd]');
        if (box) {
            const L = menuItems(box);
            const i = L.indexOf(t);
            const next = { ArrowDown: i + 1, ArrowUp: i - 1, Home: 0, End: L.length - 1 }[e.key];
            if (next !== undefined && L.length) {
                e.preventDefault();
                L[(next + L.length) % L.length].focus();
            } else if (e.key === 'Escape' && !sheet.open) {
                e.preventDefault();
                closeMenu(true);
            }

            return;
        }
        if (t === linkIn && e.key === 'Enter') {
            e.preventDefault();
            linkOk();

            return;
        }
        if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true' && t.closest?.('[data-rte-more]')) {
            e.preventDefault();
            saveToolbarPref(false);
            setFull(false);
            toggle.focus();

            return;
        }
        // Up / Down walk the buttons of the extra part of the bar.
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && t.closest?.('[data-rte-more]')) {
            const L = [...tb.querySelectorAll('[data-rte-more] button')].filter((x) => x.getClientRects().length && !x.closest('.rte-dd'));
            const i = L.indexOf(t);
            if (i >= 0) {
                e.preventDefault();
                L[(i + (e.key === 'ArrowUp' ? -1 : 1) + L.length) % L.length].focus();
            }
        }
    };
    const onDocDown = (e) => {
        if (openName && !sheet.open && !trigger(openName).parentElement.contains(e.target)) {
            closeMenu();
        }
    };
    const onDocKey = (e) => {
        // An Esc the frame already used (closing a list, folding the extra tools) does not also leave full screen.
        if (e.key === 'Escape' && !e.defaultPrevented && frameEl.dataset.full === '1' && !wrap.querySelector('dialog[open]') && !openName) {
            setFullscreen(false);
        }
    };
    // Only while the writing area has focus: when a dialog's field takes focus (Ctrl+K), the
    // browser can report a caret at the very start of the body, which would replace the kept one.
    const onSel = () => {
        if (document.activeElement === body && liveRange()) {
            frame.save();
            readTag();
        }
    };
    const onScroll = () => {
        tb.style.maxWidth = '';
        clearTimeout(idle);
        idle = setTimeout(schedule, 140);
    };

    wrap.addEventListener('mousedown', onMouseDown);
    wrap.addEventListener('click', onClick);
    wrap.addEventListener('keydown', onKeyDown);
    linkIn.addEventListener('input', linkState);
    const customEl = q('[data-rte-custom]');
    const onCustom = (e) => {
        closeMenu();
        applyColor(e.target.value);
    };
    customEl.addEventListener('change', onCustom);
    tb.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('mousedown', onDocDown, true);
    document.addEventListener('keydown', onDocKey);
    document.addEventListener('selectionchange', onSel);
    const ro = new ResizeObserver(schedule);
    ro.observe(tbw);
    coarse.addEventListener?.('change', schedule);

    editor.events.on('change', (value) => {
        push(value);
        counts();
    });
    // Jodit's change observer is debounced (~100ms) — a type-then-click-Save inside that
    // window would miss the last keystrokes. Blur fires before the Save click completes,
    // so flushing here closes the race.
    editor.events.on(body, 'blur', () => push(editor.value));
    editor.events.on('focus', () => {
        activeFrame = frame;
    });
    // Ctrl+K opens our link dialog (not Jodit's); Ctrl+\ clears formatting; Ctrl+Shift+8 / 7 make lists.
    editor.events.on('beforeCommand', (command) => {
        if (command === 'openlinkdialog') {
            openLink();

            return false;
        }

        return undefined;
    });
    editor.events.on('keydown', (e) => {
        if (!(e.ctrlKey || e.metaKey)) {
            return undefined;
        }
        const k = e.shiftKey ? { Digit8: 'ul', Digit7: 'ol' }[e.code] : { Backslash: 'eraser', KeyK: 'link' }[e.code];
        if (!k) {
            return undefined;
        }
        e.preventDefault();
        TOOLS[k]();

        return false;
    });

    setFull(readToolbarPref(), false);
    counts();
    schedule();
    frames.add(frame);

    frame.destroy = () => {
        frames.delete(frame);
        if (activeFrame === frame) {
            activeFrame = null;
        }
        if (frameEl.dataset.full === '1') {
            document.documentElement.style.overflow = '';
        }
        wrap.removeEventListener('mousedown', onMouseDown);
        wrap.removeEventListener('click', onClick);
        wrap.removeEventListener('keydown', onKeyDown);
        linkIn.removeEventListener('input', linkState);
        customEl.removeEventListener('change', onCustom);
        tb.removeEventListener('scroll', onScroll);
        ro.disconnect();
        cancelAnimationFrame(raf);
        clearTimeout(idle);
        coarse.removeEventListener?.('change', schedule);
        document.removeEventListener('mousedown', onDocDown, true);
        document.removeEventListener('keydown', onDocKey);
        document.removeEventListener('selectionchange', onSel);
        editor.destruct();
    };

    return frame;
}

export function createRichEditors(root) {
    registerLanguages();
    bindGlobal();

    const mounted = [];
    for (const wrap of root.querySelectorAll('[data-rte]')) {
        if (wrap.querySelector('[data-rich-editor]')) {
            mounted.push(mountFrame(wrap));
        }
    }

    return {
        destroy() {
            for (const frame of mounted) {
                frame.destroy();
            }
            mounted.length = 0;
        },
    };
}
