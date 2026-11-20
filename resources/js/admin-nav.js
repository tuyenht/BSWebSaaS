// The sidebar menu (Alpine.data 'bsAdminNav', layouts/app.blade.php).
//
// wire:navigate draws a new sidebar on every page. It starts where the reader left the old one in
// this tab (kept in sessionStorage while they scroll and as they leave), and if the current page's
// link would then sit outside the visible part, it is brought just into view. Moving it there does
// not flash the scrollbar the way a scroll by the reader does. Storage may be blocked by the browser:
// the sidebar then simply starts at the top.
const KEY = 'bs-admin-nav-top';

function read() {
    try {
        return Number(sessionStorage.getItem(KEY)) || 0;
    } catch {
        return 0;
    }
}

function keep(top) {
    try {
        sessionStorage.setItem(KEY, String(top));
    } catch {
        // storage blocked: nothing to keep
    }
}

function bsAdminNav(openGroup) {
    return {
        openGroup,
        t: null,
        quiet: false,

        init() {
            const nav = this.$el;
            const top = read();
            if (top > 0) {
                this.quiet = true;
                nav.scrollTop = top;
            }
            const current = nav.querySelector('[aria-current="page"]');
            if (current) {
                const n = nav.getBoundingClientRect();
                const c = current.getBoundingClientRect();
                if (c.top < n.top || c.bottom > n.bottom) {
                    this.quiet = true;
                    current.scrollIntoView({ block: 'nearest' });
                }
            }
            if (this.quiet) {
                requestAnimationFrame(() => requestAnimationFrame(() => (this.quiet = false)));
            }
        },

        scrolled() {
            const nav = this.$el;
            clearTimeout(this.t);
            if (!this.quiet) {
                nav.classList.add('is-scrolling');
            }
            this.t = setTimeout(() => {
                nav.classList.remove('is-scrolling');
                keep(nav.scrollTop);
            }, 1200);
        },

        leaving() {
            keep(this.$el.scrollTop);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsAdminNav', bsAdminNav);
});
