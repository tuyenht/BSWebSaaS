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
//
// A Livewire redraw morphs the started root against the server's HTML, which still carries
// x-ignore. Alpine's morph first prepares that new HTML with the live state (Alpine.cloneNode),
// and an x-ignore there makes it skip the whole subtree: every element Alpine had hidden (x-show)
// then lost its display:none and showed for good, with nothing left to hide it (an empty "Chuyển
// đến…" dialog over the Trình đơn screen, its error card beside the data). So once a part has
// started, each redraw drops x-ignore from the incoming root too (keepStarted below).
const PARTS = {
    bsCategories: () => import('./admin-categories.js'),
    bsMenus: () => import('./admin-menus.js'),
    bsEditorSave: () => import('./admin-editor.js'),
    bsUnsaved: () => import('./admin-unsaved.js'),
    bsBell: () => import('./admin-bell.js'),
    bsNotifications: () => import('./admin-notifications.js'),
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
        // Livewire's morph.updating: a started root (x-ignore gone) takes the server's root without
        // x-ignore, so Alpine prepares the new children with the live state. A root still waiting or
        // failed keeps it.
        keepStarted(el, toEl) {
            if (el && toEl && el.nodeType === 1 && el.hasAttribute('data-bs-lazy') && !el.hasAttribute('x-ignore')
                && toEl.nodeType === 1 && toEl.hasAttribute('x-ignore')) {
                toEl.removeAttribute('x-ignore');
            }
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
    const hook = () => window.Livewire.hook('morph.updating', ({ el, toEl }) => lazy.keepStarted(el, toEl));
    if (window.Livewire?.hook) {
        hook();
    } else {
        document.addEventListener('livewire:init', hook, { once: true });
    }
}
