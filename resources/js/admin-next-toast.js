// Leaving an editor whose record is gone and the toast that follows it onto the next screen: part of
// the admin's main script (sweet-toast.js wires it), so it stays small.

const NEXT = 'bs-toast-next';

// Leave an editor whose record is gone (moved to the Trash from its ⋮) for `url`, the list, and say so
// there: the copy of its text kept on this device is forgotten, its unsaved-changes guard lets go
// (admin-unsaved.js), and the toast waits in this tab's sessionStorage until the next screen is drawn —
// a toast on this screen would go with it. Where the browser keeps nothing, show() puts it up at once.
export function leaveTo(url, toast, { win = window, show = () => {} } = {}) {
    win.dispatchEvent(new win.CustomEvent('admin-editor-saved'));
    win.dispatchEvent(new win.CustomEvent('admin-editor-leave'));
    if (toast) {
        try {
            win.sessionStorage.setItem(NEXT, JSON.stringify(toast));
        } catch {
            show(toast);
        }
    }
    win.Livewire.navigate(url);
}

/** The toast an earlier screen left for this one (leaveTo), taken once; null when there is none. */
export function takeNextToast(store) {
    try {
        const raw = store.getItem(NEXT);
        if (!raw) {
            return null;
        }
        store.removeItem(NEXT);
        const opts = JSON.parse(raw);

        return opts && typeof opts.text === 'string' ? opts : null;
    } catch {
        return null;
    }
}
