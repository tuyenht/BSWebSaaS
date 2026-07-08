// Admin rich-text editor (lazy chunk — mounted from app.js on any admin page that carries a
// [data-rich-editor] textarea). ONE call-site contract, zero per-page config:
//
//   <x-admin::rich-editor target="description" profile="full|basic" :value="$description" />
//
// `full` = the product/article body toolbar (headings, sub/sup, font/size/color, lists,
// align, link, LIBRARY image, youtube/vimeo video, table, hr, Ω, source, fullsize);
// `basic` = short teaser fields (b/i, lists, link). Both show a slim status bar whose
// char/word counters count plain TEXT, never markup.
//
// Livewire 4 bridge (verified against v4.3.3 source). Component writes stay DOM-only on
// purpose: Livewire.find(id) returns the $wire proxy itself, and $wire.$set(name, value,
// live = true) defaults to LIVE — a network round-trip per keystroke — so editors never
// talk to $wire directly:
//   · content OUT: mirror HTML into the component's hidden [data-rich-sync] input + `input`
//     event — plain wire:model (deferred by default in LW4) carries it with the next
//     request (Save).
//   · actions IN:  toolbar image/video buttons .click() the hidden [data-rich-action]
//     wire:click buttons rendered by the blade component (host component must use the
//     WithMediaLibraryPicker trait, or the wire:click fails server-side).
//   · results back: PHP dispatch() → Livewire.on('admin-editor-insert-image|html') — the
//     official LW4 bus (a __livewire-guarded window listener; the callback receives the
//     dispatch params object directly). Applied to the editor that owned the request.
// Tooltips/dialogs follow the admin locale via <html lang>; Jodit ships no Vietnamese, so
// we register our own pack.

import { Jodit } from 'jodit';
import 'jodit/es2021/jodit.min.css';
// The bare `jodit` entry (esm/index.js → esm/plugins/index.js) ships only the 21 "essential"
// plugins. Every toolbar button outside that set must import its plugin here, or the button
// still renders (our icon map registers icons globally) but silently no-ops on click.
// tests/Feature/Admin/RichEditorContractTest pins button↔plugin↔sanitizer consistency.
import 'jodit/esm/plugins/justify/justify.js'; // left/center/right/justify
import 'jodit/esm/plugins/indent/indent.js'; // indent/outdent (margin-left; padding-left in cells)
import 'jodit/esm/plugins/hr/hr.js';
import 'jodit/esm/plugins/symbols/symbols.js';
import 'jodit/esm/plugins/clean-html/clean-html.js'; // eraser
import 'jodit/esm/plugins/source/source.js';
import 'jodit/esm/plugins/fullsize/fullsize.js';
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
import { JODIT_REMIX_ICONS } from './admin-rich-editor-icons.js';

// Deliberately "just enough" (owner, benchmarked against the old T5G CKEditor set): NO
// templates/print/preview/find-replace/anchor/emoji — rare needs stay reachable via source view.
const FULL_BUTTONS = [
    'paragraph', '|', 'bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', '|',
    'font', 'fontsize', 'brush', '|', 'ul', 'ol', '|',
    'left', 'center', 'right', 'justify', '|', 'outdent', 'indent', '|',
    'link', 'unlink', '|', 'libraryImage', 'embedVideo', 'table', 'hr', 'symbols', '|', 'eraser', '|',
    'undo', 'redo', '|', 'source', 'fullsize',
];

const BASIC_BUTTONS = ['bold', 'italic', '|', 'ul', 'ol', '|', 'link', 'unlink', '|', 'eraser', '|', 'undo', 'redo'];

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

let iconsRegistered = false;

function registerRemixIcons() {
    if (iconsRegistered) {
        return;
    }
    for (const [name, svg] of Object.entries(JODIT_REMIX_ICONS)) {
        Jodit.modules.Icon.set(name, svg);
    }
    iconsRegistered = true;
}

// The editor that owns the library-picker request — the Livewire insert event lands here.
let activeEditor = null;
let insertListenerBound = false;

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

function bindInsertListener() {
    if (insertListenerBound) {
        return;
    }
    onLivewire('admin-editor-insert-image', (params) => {
        const url = params?.url;
        if (typeof url === 'string' && url !== '' && activeEditor) {
            activeEditor.selection.insertImage(url);
        }
    });
    // Server-built fragments (video embeds) — inserted verbatim at the caret; the write-time
    // sanitizer remains the gate on what survives into the DB.
    onLivewire('admin-editor-insert-html', (params) => {
        const html = params?.html;
        if (typeof html === 'string' && html !== '' && activeEditor) {
            activeEditor.selection.insertHTML(html);
        }
    });
    insertListenerBound = true;
}

// Fire the hidden wire:click button the blade component renders next to the editor — the only
// cross-version-safe way to call a Livewire action from third-party JS.
function clickWireAction(editor, action) {
    activeEditor = editor;
    editor.element.closest('[wire\\:id]')?.querySelector(`[data-rich-action="${action}"]`)?.click();
}

function editorOptions(profile) {
    return {
        buttons: profile === 'full' ? FULL_BUTTONS : BASIC_BUTTONS,
        language: editorLanguage(),
        toolbarAdaptive: false, // one stable toolbar on every breakpoint
        // Slim status bar = char/word counters over plain TEXT (countHTMLChars: false).
        statusbar: true,
        showCharsCounter: true,
        showWordsCounter: true,
        countHTMLChars: false,
        showXPathInStatusbar: false,
        hidePoweredByJodit: true, // MIT license asks for the LICENSE file, not a UI credit
        minHeight: profile === 'full' ? 320 : 150,
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
        controls: {
            // Only offer what survives the server-side allowlist — no silent format loss.
            // h1 is deliberately absent (owner 06/07): the system owns the page/product H1.
            paragraph: {
                list: Jodit.atom({
                    p: 'Normal',
                    h2: 'Heading 2',
                    h3: 'Heading 3',
                    h4: 'Heading 4',
                    h5: 'Heading 5',
                    blockquote: 'Quote',
                }),
            },
            // Images enter through the site's media library (RLS-scoped DAM picker) or a
            // pasted URL — both live in the Livewire dialog, not here.
            libraryImage: {
                icon: 'image',
                tooltip: 'Insert Image',
                exec: (editor) => clickWireAction(editor, 'image'),
            },
            // T5G-style video embed (URL + autoplay) built SERVER-side into the canonical
            // responsive .video-embed markup — the Livewire dialog owns the form.
            embedVideo: {
                icon: 'video',
                tooltip: 'Insert youtube/vimeo video',
                exec: (editor) => clickWireAction(editor, 'video'),
            },
        },
    };
}

export function createRichEditors(root) {
    registerLanguages();
    registerRemixIcons();
    bindInsertListener();

    const editors = [];
    for (const el of root.querySelectorAll('[data-rich-editor]')) {
        const profile = el.dataset.richEditor === 'basic' ? 'basic' : 'full';
        const target = el.dataset.richTarget;
        // The wire:model carrier lives OUTSIDE the wire:ignore wrapper (morph-safe) — writing
        // into it never touches the editor's DOM, so IME composition stays intact.
        const syncEl = el.closest('[wire\\:id]')?.querySelector(`[data-rich-sync="${target}"]`);

        const push = (value) => {
            if (!syncEl || typeof value !== 'string' || syncEl.value === value) {
                return;
            }
            syncEl.value = value;
            syncEl.dispatchEvent(new Event('input', { bubbles: true }));
        };

        const editor = Jodit.make(el, editorOptions(profile));
        editor.events.on('change', push);
        // Jodit's change observer is debounced (~100ms) — a type-then-click-Save inside that
        // window would miss the last keystrokes. Blur fires before the Save click completes,
        // so flushing here closes the race.
        editor.events.on(editor.editor, 'blur', () => push(editor.value));
        editor.events.on('focus', () => {
            activeEditor = editor;
        });
        editors.push(editor);
    }

    return {
        destroy() {
            for (const editor of editors) {
                if (activeEditor === editor) {
                    activeEditor = null;
                }
                editor.destruct();
            }
            editors.length = 0;
        },
    };
}
