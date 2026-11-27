// «Xem trước trên website» (components/preview-dialog.blade.php, design 07 and content-ui.js previewSite):
// the record's saved version in a frame at a computer's width or a phone's. Alpine.data 'bsSitePreview',
// started by the shared lazy loader (admin-lazy.js); the editor frame's «Xem trước» opens it (the
// bs-site-preview event). Closing gives the focus back to what opened it, or to the ⋮ when that was
// the phone's menu item (gone by then), and unlocks the page once no other dialog is open.

export function sitePreview() {
    return {
        open: false,
        dev: 'd',
        opener: null,
        show() {
            this.opener = document.activeElement;
            this.dev = 'd';
            this.open = true;
            document.body.style.overflow = 'hidden';
            // as the board: «Đóng» takes the focus (after the trap has put it on the first button)
            setTimeout(() => this.$root.querySelector('[data-site-preview-close]')?.focus(), 30);
        },
        // Escape pressed inside the frame (same origin) never reaches this window's listeners. One
        // Escape closes one thing: while the website's phone menu or search panel is open (its opener,
        // drawn or not, says aria-expanded="true" before the key reaches it), or the website took the key (a bar
        // submenu stops it), the website closes its own part and the preview stays. Only those two
        // openers count: a menu branch the server draws open for the current page is not an overlay.
        frameKeys(frame) {
            try {
                const win = frame.contentWindow;
                let siteOpen = false;
                win?.addEventListener('keydown', (e) => {
                    // the opener may be hidden by then (search opened at a computer's width, then the
                    // preview switched to a phone's): the website still closes its panel on this key
                    siteOpen = e.key === 'Escape'
                        && !!win.document.querySelector('[data-menu-toggle][aria-expanded="true"], [data-search-open][aria-expanded="true"]');
                }, true);
                win?.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.open && !siteOpen && !e.defaultPrevented) {
                        e.preventDefault();
                        this.hide();
                    }
                });
            } catch {
                // a page of another origin inside the frame: Escape works from the dialog's own buttons
            }
        },
        hide() {
            this.open = false;
            // the page stays locked while another dialog (the shared modal's data-bs-modal) is still open
            requestAnimationFrame(() => {
                if (![...document.querySelectorAll('[data-bs-modal]')].some((m) => window.Alpine.$data(m).open)) {
                    document.body.style.overflow = '';
                }
            });
            const back = this.opener;
            this.opener = null;
            this.$nextTick(() => {
                const shown = back && back.isConnected && back.getClientRects().length > 0;
                (shown ? back : this.$root.closest('[data-entry-editor]')?.querySelector('[data-guide=more]'))?.focus();
            });
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsSitePreview', sitePreview);
}
