// The topbar search box (Alpine.data 'bsTopSearch', layouts/app.blade.php).
// - The placeholder is the longest of its shorter forms that fits the box, so it never clips.
// - Below 640px only the magnifier shows; it opens the same box over the topbar. Esc or ✕ closes it
//   and gives focus back to the magnifier; a click outside or widening to 640px closes it too.

const PLACEHOLDER_SPLIT = '|';

function bsTopSearch() {
    return {
        open: false,
        observer: null,
        canvas: null,
        unlisten: null,

        init() {
            const input = this.$refs.input;
            this.full = (input.dataset.placeholders || input.placeholder).split(PLACEHOLDER_SPLIT);
            this.fit();
            this.observer = new ResizeObserver(() => this.fit());
            this.observer.observe(input);
            const wide = window.matchMedia('(min-width: 40rem)');
            const close = () => {
                if (wide.matches) {
                    this.open = false;
                }
            };
            wide.addEventListener('change', close);
            this.unlisten = () => wide.removeEventListener('change', close);
        },

        destroy() {
            this.observer?.disconnect();
            this.unlisten?.();
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
