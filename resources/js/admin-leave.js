// The one leave guard for an admin screen that holds unsaved work in the browser. Closing the tab
// or reloading asks the browser's own question while dirty() is true. With ask, a link inside the
// admin (wire:navigate opens it in place, so the browser never asks) waits too: ask(go) puts the
// screen's own question, and go() leaves after all. A move back or forward through the history is
// let through, as Livewire has already changed the address by then. Returns the function that
// takes the guard off (the screen's destroy()).
export function guardLeave({ dirty, ask = null, win = window, doc = document, navigate = (url) => win.Livewire.navigate(url) }) {
    let leaving = false;
    const unload = (e) => {
        if (!leaving && dirty()) {
            e.preventDefault();
            e.returnValue = '';
        }
    };
    const nav = (e) => {
        if (leaving || !dirty() || (e.detail && e.detail.history)) {
            return;
        }
        e.preventDefault();
        const url = e.detail && e.detail.url ? (e.detail.url.href ?? String(e.detail.url)) : null;
        ask(() => {
            if (url) {
                leaving = true;
                navigate(url);
            }
        });
    };
    win.addEventListener('beforeunload', unload);
    if (ask) {
        doc.addEventListener('livewire:navigate', nav);
    }
    return () => {
        win.removeEventListener('beforeunload', unload);
        doc.removeEventListener('livewire:navigate', nav);
    };
}
