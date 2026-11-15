// The topbar search box (Alpine.data 'bsTopSearch', layouts/app.blade.php).
// - Ctrl K (⌘K on a Mac) focuses the box from anywhere on the page; the hint chip names the keys.
// - The placeholder is the longest of its shorter forms that fits the box, so it never clips.
// - Below 640px only the magnifier shows; it opens the same box over the topbar, Esc or ✕ closes it.

const PLACEHOLDER_SPLIT = '|';

function bsTopSearch() {
    return {
        open: false,
        isMac: /Mac|iPhone|iPad/.test(navigator.platform || ''),
        observer: null,
        canvas: null,

        init() {
            const input = this.$refs.input;
            this.full = (input.dataset.placeholders || input.placeholder).split(PLACEHOLDER_SPLIT);
            this.fit();
            this.observer = new ResizeObserver(() => this.fit());
            this.observer.observe(input);
        },

        destroy() {
            this.observer?.disconnect();
        },

        get kbdLabel() {
            return this.isMac ? '⌘K' : 'Ctrl K';
        },

        // Pick the longest placeholder that fits the text area of the field.
        fit() {
            const input = this.$refs.input;
            if (input.clientWidth < 1) {
                return;
            }
            const style = getComputedStyle(input);
            this.canvas ??= document.createElement('canvas');
            const ctx = this.canvas.getContext('2d');
            ctx.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
            const room = input.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) - 2;
            input.placeholder = this.full.find((text) => ctx.measureText(text).width <= room) ?? this.full[this.full.length - 1];
        },

        shortcut(event) {
            if (event.key.toLowerCase() !== 'k' || ! (event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) {
                return;
            }
            event.preventDefault();
            if (! this.$refs.input.offsetParent) {
                this.open = true; // phone width: the box lives behind the magnifier
            }
            this.$nextTick(() => {
                this.$refs.input.focus();
                this.fit();
            });
        },

        show() {
            this.open = true;
            this.$nextTick(() => {
                this.$refs.input.focus();
                this.fit();
            });
        },

        hide() {
            if (! this.open) {
                return;
            }
            this.open = false;
            this.$nextTick(() => document.getElementById('admin-search-trigger')?.focus());
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsTopSearch', bsTopSearch);
});
