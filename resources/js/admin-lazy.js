// The one shared lazy loader for screen-only Alpine parts (.claude/rules/frontend-assets.md): a
// screen whose module would make app.js too big marks its Alpine root
//     <div x-ignore wire:ignore.self data-bs-lazy="<name>" x-data="<name>(…)">
// and lists its module in PARTS. Alpine skips the element until the module has registered
// Alpine.data('<name>'); the loader then starts it. wire:ignore.self keeps Livewire's redraws from
// putting x-ignore back (the children still redraw). One more round trip for that screen only.
const PARTS = {
    bsCategories: () => import('./admin-categories.js'),
};

function start(el) {
    const load = PARTS[el.dataset.bsLazy];
    if (!load || el._bsLazy) {
        return;
    }
    el._bsLazy = true;
    load().then(
        () => {
            if (!el.isConnected || !el.hasAttribute('x-ignore')) {
                return;
            }
            el.removeAttribute('x-ignore');
            delete el._x_ignore;
            window.Alpine.initTree(el);
        },
        () => {
            el._bsLazy = false; // a lost download: the next navigation tries again
            el.dispatchEvent(new CustomEvent('bs-lazy-failed', { bubbles: true }));
        },
    );
}

function scan() {
    document.querySelectorAll('[data-bs-lazy][x-ignore]').forEach(start);
}

document.addEventListener('alpine:initialized', scan);
document.addEventListener('livewire:navigated', scan);
document.addEventListener('bs-lazy-retry', scan); // "Thử lại" after a lost download
