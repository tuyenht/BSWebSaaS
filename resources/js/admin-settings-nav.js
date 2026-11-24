// The settings frame's section menu (components/settings-shell.blade.php, design board «Khung quản trị»).
// - Alpine.data 'bsSetNav': the «Tìm trong cài đặt…» box filters the sections by name and description,
//   ignoring case and Vietnamese marks; a group with no match hides; the folded «Nâng cao» group opens
//   while a search runs. The placeholder is the longest of its forms that fits the box.
// - Below 768px the menu is a page of its own: the address ends in #settings-menu (the main menu's
//   «Cài đặt», the «‹» beside a section's name) and <html> carries [data-set-list] while it does.
//   The attribute lives on <html>, outside what Livewire redraws, and follows wire:navigate and the
//   browser's Back button.

const LIST_HASH = '#settings-menu';

const fold = (text) => String(text || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[đĐ]/g, 'd')
    .toLowerCase()
    .trim();

function bsSetNav() {
    return {
        q: '',

        init() {
            // From 768px the menu stays in view beside the section and, when taller than the room
            // between the top bar and the save bar, scrolls inside itself (re-measured on scroll).
            this._fit = () => {
                this._raf ||= requestAnimationFrame(() => {
                    this._raf = 0;
                    this.fitHeight();
                });
            };
            window.addEventListener('scroll', this._fit, { passive: true });
            window.addEventListener('resize', this._fit, { passive: true });
            // The section, the save bar and the fonts settle after the first paint: measure again then.
            const shell = this.$root.closest('[data-set-shell]');
            this._ro = new ResizeObserver(this._fit);
            if (shell) {
                this._ro.observe(shell);
            }

            // Bring the open section into the menu's own view, once its height is known.
            requestAnimationFrame(() => {
                this.fitHeight();
                const root = this.$root;
                const on = root.querySelector('[aria-current="page"]');
                if (on && root.scrollHeight > root.clientHeight) {
                    const top = on.getBoundingClientRect().top - root.getBoundingClientRect().top;
                    if (top + on.offsetHeight > root.clientHeight) {
                        root.scrollTop += top - (root.clientHeight - on.offsetHeight) / 2;
                    }
                }
            });

            const input = this.$refs.find;
            if (!input) {
                return;
            }
            const forms = (input.dataset.placeholders || input.placeholder).split('|');
            const fit = () => {
                const style = getComputedStyle(input);
                const ctx = (this._ctx ??= document.createElement('canvas').getContext('2d'));
                ctx.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
                const room = input.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
                input.placeholder = forms.find((f) => ctx.measureText(f).width <= room) ?? forms[forms.length - 1];
            };
            fit();
            this._roFind = new ResizeObserver(fit);
            this._roFind.observe(input);
        },

        destroy() {
            window.removeEventListener('scroll', this._fit);
            window.removeEventListener('resize', this._fit);
            this._ro?.disconnect();
            this._roFind?.disconnect();
            cancelAnimationFrame(this._raf);
        },

        // The open section's own row: on the phone's list of sections it shows that section again
        // (only the address ending changes, so nothing typed is lost and no leave check stands in
        // the way); elsewhere it is the page already open.
        openCurrent(event) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return; // a new tab or window of the same section, as with any link
            }
            event.preventDefault();
            if (!document.documentElement.hasAttribute('data-set-list')) {
                return;
            }
            window.location.hash = '';
            history.replaceState(history.state, '', window.location.pathname + window.location.search);
            syncList();
        },

        fitHeight() {
            const root = this.$root;
            if (!window.matchMedia('(min-width: 48rem)').matches) {
                root.style.maxHeight = '';
                return;
            }
            const stick = parseFloat(getComputedStyle(root.parentElement).top) || 0;
            const top = Math.max(root.getBoundingClientRect().top, stick);
            const bar = document.querySelector('[data-set-shell] [data-set-savebar]');
            const barTop = bar ? bar.getBoundingClientRect().top : window.innerHeight;
            const bottom = Math.min(window.innerHeight, barTop) - 16;
            root.style.maxHeight = `${Math.max(240, bottom - top)}px`;
        },

        hit(text) {
            const q = fold(this.q);

            return q === '' || fold(text).includes(q);
        },

        // A group shows while one of its sections matches.
        groupHit(el) {
            return [...el.querySelectorAll('[data-find]')].some((a) => this.hit(a.dataset.find));
        },

        get searching() {
            return fold(this.q) !== '';
        },

        get none() {
            return this.searching && !this.groupHit(this.$root);
        },
    };
}

const syncList = () => {
    const on = window.location.hash === LIST_HASH && document.querySelector('[data-set-shell]') !== null;
    document.documentElement.toggleAttribute('data-set-list', on);

    return on;
};

window.addEventListener('hashchange', () => {
    if (syncList()) {
        window.scrollTo(0, 0);
    }
});
document.addEventListener('livewire:navigated', syncList);
syncList();

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsSetNav', bsSetNav);
});
