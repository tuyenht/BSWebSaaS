// Catalog product editor (lazy chunk — mounted from app.js only on the catalog editor page).
// Mounts Jodit on every [data-rich-editor] textarea: `full` = the detail description
// (headings, table, font size, color — the Velzon add-product reference toolbar), `basic` =
// the short summary teaser. Content flows back into Livewire DEFERRED ($wire.set(..., false))
// so typing never triggers a network round-trip — the Save action carries the latest HTML,
// and the server-side RichTextSanitizer remains the real gate on what gets stored.

import { Jodit } from 'jodit';
import 'jodit/es2021/jodit.min.css';
import { JODIT_REMIX_ICONS } from './catalog-editor-icons.js';

const FULL_BUTTONS = [
    'paragraph', '|', 'bold', 'italic', 'underline', 'strikethrough', '|',
    'font', 'fontsize', 'brush', '|', 'ul', 'ol', '|',
    'left', 'center', 'right', 'justify', '|', 'outdent', 'indent', '|',
    'link', 'table', 'hr', 'symbols', '|', 'eraser', '|', 'undo', 'redo', '|',
    'source', 'fullsize',
];

const BASIC_BUTTONS = ['bold', 'italic', '|', 'ul', 'ol', '|', 'link', '|', 'eraser', '|', 'undo', 'redo'];

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

function editorOptions(profile) {
    return {
        buttons: profile === 'full' ? FULL_BUTTONS : BASIC_BUTTONS,
        toolbarAdaptive: false, // one stable toolbar on every breakpoint
        statusbar: false,
        minHeight: profile === 'full' ? 320 : 150,
        // Paste lands as cleaned HTML without the "keep formatting?" prompt; the server-side
        // sanitizer is the actual gate, this only keeps the UX quiet.
        askBeforePasteHTML: false,
        defaultActionOnPaste: 'insert_clear_html',
        disablePlugins: ['add-new-line'],
        controls: {
            // Only offer what survives the server-side allowlist (h1 belongs to the product
            // name on the storefront; pre/h5/h6 are stripped) — no silent format loss.
            paragraph: {
                list: Jodit.atom({
                    p: 'Normal',
                    h2: 'Heading 2',
                    h3: 'Heading 3',
                    h4: 'Heading 4',
                    blockquote: 'Quote',
                }),
            },
        },
    };
}

export function createCatalogEditors(root) {
    registerRemixIcons();

    const editors = [];
    for (const el of root.querySelectorAll('[data-rich-editor]')) {
        const profile = el.dataset.richEditor === 'basic' ? 'basic' : 'full';
        const target = el.dataset.richTarget;
        const wireId = el.closest('[wire\\:id]')?.getAttribute('wire:id');

        const push = (value) => {
            if (!wireId || !target || typeof value !== 'string') {
                return;
            }
            window.Livewire?.find(wireId)?.$wire.set(target, value, false); // deferred
        };

        const editor = Jodit.make(el, editorOptions(profile));
        editor.events.on('change', push);
        // Jodit's change observer is debounced (~100ms) — a type-then-click-Save inside that
        // window would miss the last keystrokes. Blur fires before the Save click completes,
        // so flushing here closes the race.
        editor.events.on(editor.editor, 'blur', () => push(editor.value));
        editors.push(editor);
    }

    return {
        destroy() {
            for (const editor of editors) {
                editor.destruct();
            }
            editors.length = 0;
        },
    };
}
