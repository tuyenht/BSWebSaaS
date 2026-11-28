// The "Thông báo" page (admin::livewire.notifications; design c-thong-bao), Alpine.data
// 'bsNotifications', started by the shared lazy loader (admin-lazy.js):
//  - the tabs: arrow keys, Home and End move to the next tab, choose it and keep the focus on it;
//  - a click anywhere on a row (outside its buttons) opens it like its own button; a plain click on a
//    notice's link marks it read on the server, which then opens it; Ctrl/Cmd/Shift-click keeps the
//    browser's own new tab or window (the notice stays unread);
//  - a lost request (no connection, a server error) inserts the shared error alert, and "Thử lại"
//    sends the same request again. A refusal or an expired page (any 4xx) is left to Livewire
//    (admin-notice-wire.js, shared with the top bar's bell).
//  The platform console's copy has no store: only the tabs ask the server anything there.

import { catchLost, followNotice, tryAgain } from './admin-notice-wire.js';

const LOADS = ['show', 'more', 'clearSearch'];
const SAVES = ['read', 'readAll', 'undo', 'open'];

export function bsNotifications() {
    return {
        failed: '',
        retry: null,

        init() {
            if (!this.$wire) {
                return;
            }
            this.stopIntercept = catchLost(this, (name) => (LOADS.includes(name) ? 'load' : SAVES.includes(name) ? 'save' : ''));
        },

        destroy() {
            this.stopIntercept?.();
        },

        tabKey(e) {
            const tabs = Array.from(this.$root.querySelectorAll('[role="tab"]'));
            const i = tabs.indexOf(document.activeElement);
            if (i < 0) {
                return;
            }
            const to = { ArrowRight: (i + 1) % tabs.length, ArrowLeft: (i + tabs.length - 1) % tabs.length, Home: 0, End: tabs.length - 1 }[e.key];
            if (to === undefined) {
                return;
            }
            e.preventDefault();
            tabs[to].focus();
            tabs[to].click();
        },

        row(e, ulid) {
            if (e.target.closest('a, button, [data-acts]')) {
                return;
            }
            const link = e.currentTarget.querySelector('a[data-open]');
            if (link) {
                link.click();

                return;
            }
            this.$wire.open(ulid);
        },

        follow(e, ulid) {
            followNotice(this, e, ulid);
        },

        again() {
            tryAgain(this);
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsNotifications', bsNotifications);
}
