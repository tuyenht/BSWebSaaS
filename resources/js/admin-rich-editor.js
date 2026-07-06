// Admin rich-text editor (lazy chunk — mounted from app.js on any admin page that carries a
// [data-rich-editor] textarea). ONE call-site contract, zero per-page config:
//
//   <x-admin::rich-editor target="description" profile="full|basic" :value="$description" />
//
// `full` = the product/article body toolbar (headings, sub/sup, font/size/color, lists,
// align, link, LIBRARY image, youtube/vimeo video, table, hr, Ω, source, fullsize);
// `basic` = short teaser fields (b/i, lists, link). Both show a slim status bar whose
// char/word counters count plain TEXT, never markup. Content syncs to Livewire DEFERRED
// ($wire.$set) so typing never triggers a round-trip; the server-side sanitizer stays the
// real gate on what gets stored.
//
// Images enter ONLY through the site's media library: the toolbar button calls
// $wire.$call('openImagePicker', 'editor') on the owning component (see the
// WithMediaLibraryPicker trait), which dispatches `admin-editor-insert-image` back with the
// resolved CDN url. Tooltips/dialogs follow the admin locale via <html lang>; Jodit ships no
// Vietnamese, so we register our own pack.

import { Jodit } from 'jodit';
import 'jodit/es2021/jodit.min.css';
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

function bindInsertListener() {
    if (insertListenerBound) {
        return;
    }
    // Livewire dispatch('admin-editor-insert-image', url: …) arrives as a browser event.
    window.addEventListener('admin-editor-insert-image', (event) => {
        const url = event.detail?.url;
        if (typeof url === 'string' && url !== '' && activeEditor) {
            activeEditor.selection.insertImage(url);
        }
    });
    // Server-built fragments (video embeds) — inserted verbatim at the caret; the write-time
    // sanitizer remains the gate on what survives into the DB.
    window.addEventListener('admin-editor-insert-html', (event) => {
        const html = event.detail?.html;
        if (typeof html === 'string' && html !== '' && activeEditor) {
            activeEditor.selection.insertHTML(html);
        }
    });
    insertListenerBound = true;
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
        minHeight: profile === 'full' ? 320 : 150,
        // Paste lands as cleaned HTML without the "keep formatting?" prompt; the server-side
        // sanitizer is the actual gate, this only keeps the UX quiet.
        askBeforePasteHTML: false,
        defaultActionOnPaste: 'insert_clear_html',
        disablePlugins: ['add-new-line'],
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
            // Images enter ONLY through the site's media library (RLS-scoped DAM picker) —
            // no free-URL hotlinking. Requires the owning Livewire component to use the
            // WithMediaLibraryPicker trait.
            libraryImage: {
                icon: 'image',
                tooltip: 'Insert Image',
                exec: (editor) => {
                    activeEditor = editor;
                    const wireId = editor.element.closest('[wire\\:id]')?.getAttribute('wire:id');
                    window.Livewire?.find(wireId)?.$wire?.$call('openImagePicker', 'editor');
                },
            },
            // T5G-style video embed (URL + autoplay) built SERVER-side into the canonical
            // responsive .video-embed markup — the Livewire dialog owns the form.
            embedVideo: {
                icon: 'video',
                tooltip: 'Insert youtube/vimeo video',
                exec: (editor) => {
                    activeEditor = editor;
                    const wireId = editor.element.closest('[wire\\:id]')?.getAttribute('wire:id');
                    window.Livewire?.find(wireId)?.$wire?.$call('openVideoDialog');
                },
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
        const wireId = el.closest('[wire\\:id]')?.getAttribute('wire:id');

        const push = (value) => {
            if (!wireId || !target || typeof value !== 'string') {
                return;
            }
            // Livewire 3 magic API is $set (v2's .set() does not exist — calling it threw
            // inside Jodit's event pipeline and took the other toolbar handlers down with it).
            window.Livewire?.find(wireId)?.$wire?.$set(target, value, false); // deferred
            // Belt-and-braces: mirror into the source textarea and emit input, so the
            // wire:model fallback also carries the value wherever it is bound.
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
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
