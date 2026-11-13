// The top bar's bell (admin::livewire.notification-bell; design 01 "Thông báo"), Alpine.data
// 'bsBell', started by the shared lazy loader (admin-lazy.js):
//  - the bell opens and closes the panel; opening moves the focus into the panel and asks the server
//    for the list the first time (load); Esc, "Đóng" and the bell close it and put the focus back on
//    the bell; a click outside or Tab past its end closes it, leaving the focus where it went;
//  - a lost request (no connection, a server error) shows the panel's error card, and "Thử lại" sends
//    the same request again. An expired page (419) is left to Livewire, which offers a reload.

import '../css/admin-bell-panel.css';

const ACTIONS = ['load', 'open', 'readAll'];

export function bsBell() {
    return {
        open: false,
        failed: '',
        retry: null,

        init() {
            this.stopIntercept = this.$wire.$intercept(({ action, onError, onFailure, onSuccess }) => {
                if (!ACTIONS.includes(action.name)) {
                    return;
                }
                const fail = () => {
                    this.failed = action.name === 'load' ? 'load' : 'save';
                    this.retry = () => this.$wire[action.name](...(action.params || []));
                };
                onFailure(fail);
                onError(({ response, preventDefault }) => {
                    if (response?.status === 419) {
                        return;
                    }
                    preventDefault();
                    fail();
                });
                onSuccess(() => {
                    this.failed = '';
                    // "Đánh dấu đã đọc tất cả" disappears once nothing is unread: keep the focus in the panel.
                    this.$nextTick(() => {
                        if (this.open && !this.$root.contains(document.activeElement)) {
                            this.$refs.panel.focus();
                        }
                    });
                });
            });
        },

        destroy() {
            this.stopIntercept?.();
        },

        toggle() {
            if (this.open) {
                this.close(true);

                return;
            }
            this.open = true;
            this.$nextTick(() => this.$refs.panel.focus());
            if (!this.$wire.loaded && this.failed !== 'load') {
                this.$wire.load();
            }
        },

        again() {
            const run = this.retry || (() => this.$wire.load());
            this.failed = '';
            this.retry = null;
            run();
        },

        close(back) {
            if (!this.open) {
                return;
            }
            this.open = false;
            if (back) {
                this.$refs.bell.focus();
            }
        },

        // Focus moved on past the bell and its panel (Tab past the end): close, leaving the focus where
        // it went. A click outside closes through click.outside; a window losing focus keeps it open.
        leave(e) {
            if (this.open && e.relatedTarget && !this.$root.contains(e.relatedTarget)) {
                this.close(false);
            }
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsBell', bsBell);
}
