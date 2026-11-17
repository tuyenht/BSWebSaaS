// A shared list's request that did not come back (Alpine.data 'bsListState', on <x-bs-admin::list busy="…">).
// `targets` are the list's own requests: the properties (search, tab, filters…) and methods (sort, page…)
// that reload its rows. When one of them never reaches the server (a dropped connection) or comes back
// refused (a server error), the rows give way to «Chưa tải được danh sách … Thử lại» instead of Livewire's
// error box; it stays until a request of this component succeeds (which redraws the rows as they are now).
// Nothing is redrawn on a failure, so what the member typed stays in its fields, and Livewire sends the
// changed values again with the next request. «Thử lại» repeats the failed calls (a page, a sort), or
// asks for the rows again when the failed request only changed values. An expired session (419) is left
// to the frame's own handling (admin-session.js).

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
            this.off = lw.interceptMessage(({ message, onSend, onFailure, onError, onSuccess }) => {
                if (!message || !message.component || message.component.id !== id) {
                    return;
                }
                let mine = false;
                let calls = [];
                onSend(({ payload }) => {
                    calls = Array.isArray(payload && payload.calls) ? payload.calls : [];
                    mine = Object.keys((payload && payload.updates) || {}).some(own) || calls.some((c) => own(c.method));
                });
                const fail = () => {
                    if (!mine) {
                        return;
                    }
                    this.again = calls.filter((c) => own(c.method) && !String(c.method).startsWith('$'));
                    this.failed = true;
                };
                onFailure(fail);
                onError(({ response, preventDefault }) => {
                    if (!mine || (response && response.status === 419)) {
                        return;
                    }
                    preventDefault();
                    fail();
                });
                onSuccess(() => {
                    this.failed = false;
                    this.again = [];
                });
            });
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
