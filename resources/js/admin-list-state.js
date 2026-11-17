// A shared list's request that did not come back (Alpine.data 'bsListState', on <x-bs-admin::list busy="…">).
// `targets` are the list's own requests: the properties (search, tab, filters…) and methods (sort, page…)
// that reload its rows. When one of them never reaches the server (a dropped connection) or comes back
// refused (a server error or too many requests), the rows give way to «Chưa tải được danh sách … Thử lại» instead of Livewire's
// error box; it stays until a request of this component succeeds (which redraws the rows as they are now).
// Nothing is redrawn on a failure, so what the member typed stays in its fields, and Livewire sends the
// changed values again with the next request. «Thử lại» repeats the failed calls (a page, a sort), or
// asks for the rows again when the failed request only changed values. Any other status (403, 404, 419, …) is left
// to Livewire's own handling, so the admin error page and its reference still show. A request that also
// carries a call that is not the list's own (a bulk action, a trash) is never taken over.

function listState(targets) {
    const own = (name) => targets.includes(String(name).split('.')[0]);

    return {
        failed: false,
        again: [],
        off: null,

        init() {
            const id = this.$wire ? this.$wire.$id : null;
            const lw = window.Livewire;
            if (!id || !lw || typeof lw.interceptMessage !== 'function') {
                return;
            }
            this.$watch('failed', (v) => window.dispatchEvent(new CustomEvent('bs-list-failed', { detail: v })));
            this.off = lw.interceptMessage(({ message, onSend, onFailure, onError, onSuccess }) => {
                if (!message || !message.component || message.component.id !== id) {
                    return;
                }
                let mine = false;
                let calls = [];
                let held = false;
                onSend(({ payload }) => {
                    calls = Array.isArray(payload && payload.calls) ? payload.calls : [];
                    // The list's own request: every call in it is one of the list's own (a request that also
                    // carries a bulk action, a trash or any other call is never relabelled), and it reloads rows.
                    mine = calls.every((c) => own(c.method)) && (calls.length > 0 || Object.keys((payload && payload.updates) || {}).some(own));
                    held = !!this.$el && !!document.activeElement && this.$el.contains(document.activeElement);
                });
                const fail = () => {
                    this.again = calls.filter((c) => !String(c.method).startsWith('$'));
                    this.failed = true;
                };
                // a dropped connection
                onFailure(() => {
                    if (mine) {
                        fail();
                    }
                });
                // only a refused request (a server error or too many requests); 403, 404, 419 and the rest stay
                // with Livewire's own handling (the admin error page and its reference)
                onError(({ response, preventDefault }) => {
                    const status = response ? Number(response.status) : 0;
                    if (!mine || !(status >= 500 || status === 429)) {
                        return;
                    }
                    preventDefault();
                    fail();
                });
                onSuccess(({ onMorphed } = {}) => {
                    const wasFailed = this.failed;
                    this.failed = false;
                    this.again = [];
                    const settle = () => {
                        if (mine && (wasFailed || held)) {
                            this.focusStable();
                        }
                    };
                    typeof onMorphed === 'function' ? onMorphed(settle) : settle();
                });
            });
        },

        // «Thử lại» and «Bỏ lọc» are gone once the rows are back: keep the focus in the list (the search
        // field, else the table) unless it already rests on something that is still there.
        focusStable() {
            const root = this.$el;
            const a = document.activeElement;
            if (!root || (a && a !== document.body && a.isConnected)) {
                return;
            }
            let target = null;
            for (let up = root.parentElement; up && !target && up !== document.body; up = up.parentElement) {
                target = up.querySelector('input[type="search"]');
            }
            target = target || root.querySelector('[role="table"]');
            if (!target) {
                return;
            }
            if (target.getAttribute('role') === 'table' && !target.hasAttribute('tabindex')) {
                target.setAttribute('tabindex', '-1');
            }
            target.focus({ preventScroll: false });
        },

        destroy() {
            if (typeof this.off === 'function') {
                this.off();
            }
        },

        retry() {
            const wire = this.$wire;
            const again = this.again;
            if (again.length === 0) {
                wire.$refresh();
                return;
            }
            again.forEach((c) => wire[c.method](...(Array.isArray(c.params) ? c.params : [])));
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsListState', listState);
});
