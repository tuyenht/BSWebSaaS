// The one shared lazy loader for screen-only Alpine parts (.claude/rules/frontend-assets.md): a
// screen whose module would make app.js too big marks its Alpine root
//     <div x-ignore wire:ignore.self data-bs-lazy="<name>" x-data="<name>(…)">
// and lists its module in PARTS. Alpine skips the element until the module has registered
// Alpine.data('<name>'); the loader then starts it. wire:ignore.self keeps Livewire's redraws from
// putting x-ignore back (the children still redraw). One more round trip for that screen only.
//
// Until the part starts nothing inside the root runs, so its waiting and failed states work without
// Alpine: the loader marks the root `data-bs-lazy-failed` when the download is lost, and the shared
// CSS (app.css) then hides the root's [data-bs-lazy-wait] block and shows its
// [data-bs-lazy-error] block. A [data-bs-lazy-retry] button inside the error block tries again; when
// the browser keeps the failed download (it may answer a second import() of the same file with
// the same failure), the second try reloads the page.
const PARTS = {
    bsCategories: () => import('./admin-categories.js'),
    bsEditorSave: () => import('./admin-editor.js'),
    bsUnsaved: () => import('./admin-unsaved.js'),
};

export function makeLazy({ parts, alpine, reload }) {
    function start(el, again = false) {
        const load = parts[el.dataset.bsLazy];
        if (!load || el._bsLazy) {
            return;
        }
        el._bsLazy = true;
        el.removeAttribute('data-bs-lazy-failed');
        load().then(
            () => {
                if (!el.isConnected || !el.hasAttribute('x-ignore')) {
                    return;
                }
                el.removeAttribute('x-ignore');
                delete el._x_ignore;
                alpine().initTree(el);
            },
            () => {
                el._bsLazy = false; // a lost download: "Thử lại", or the next navigation, tries again
                if (again) {
                    reload();
                    return;
                }
                el.setAttribute('data-bs-lazy-failed', '');
            },
        );
    }

    return {
        start,
        scan(doc) {
            doc.querySelectorAll('[data-bs-lazy][x-ignore]').forEach((el) => start(el));
        },
        // A click anywhere: "Thử lại" inside a failed root starts it again.
        click(e) {
            const btn = e.target && e.target.closest ? e.target.closest('[data-bs-lazy-retry]') : null;
            const el = btn ? btn.closest('[data-bs-lazy][data-bs-lazy-failed]') : null;
            if (el) {
                start(el, true);
            }
        },
    };
}

if (typeof document !== 'undefined' && typeof window !== 'undefined' && window.document === document) {
    const lazy = makeLazy({ parts: PARTS, alpine: () => window.Alpine, reload: () => window.location.reload() });
    document.addEventListener('alpine:initialized', () => lazy.scan(document));
    document.addEventListener('livewire:navigated', () => lazy.scan(document));
    document.addEventListener('click', (e) => lazy.click(e));
}
