// "Quy trình duyệt" (approval workflow per content type): the picks and switches are only staged
// here, in the page; nothing is written until "Lưu — hiệu lực ngay" sends the whole set to the
// component's save() in one request. The server state arrives as a JSON block inside the component
// ([data-wf-state]) and is read again after every round trip, so a save, a reload or a newer page
// becomes the new baseline while the changes not yet saved stay staged.
//
// Dialogs (picker, confirm, plan, leave) are drawn by the view; this object only says which one is
// open. Escape closes the top one; focus is held and returned by x-trap.

const NONE = 'none';

function clone(rows) {
    return JSON.parse(JSON.stringify(rows));
}

function fill(text, values) {
    return Object.entries(values).reduce((out, [key, value]) => out.split(':' + key).join(String(value)), text);
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('workflowSettings', () => ({
        order: [],
        meta: {},
        templates: {},
        texts: {},
        saved: {},
        staged: {},
        pick: null,
        sel: null,
        confirm: null,
        up: null,
        leave: null,
        failed: false,
        retry: null,
        busy: false,
        fromEmpty: false,
        stopIntercept: null,
        onUnload: null,

        init() {
            this.read(true);

            // A failed request (lost connection, server error) shows the error card with "Thử lại",
            // which sends the same request again; the staged changes are kept meanwhile.
            this.stopIntercept = this.$wire.$intercept(({ action, onError, onFailure, onSuccess, onFinish }) => {
                const again = action.name === 'save'
                    ? () => this.save()
                    : () => this.$wire.$refresh();
                const fail = () => {
                    if (action.name === 'save' || action.name === '$refresh') {
                        this.failed = true;
                        this.retry = again;
                    }
                };
                onFailure(fail);
                onError(({ preventDefault }) => {
                    if (action.name === 'save' || action.name === '$refresh') {
                        preventDefault();
                    }
                    fail();
                });
                onSuccess(() => {
                    if (action.name === 'save' || action.name === '$refresh') {
                        this.failed = false;
                    }
                });
                onFinish(() => this.$nextTick(() => this.read(false)));
            });

            this.onUnload = (event) => {
                if (this.dirtyCount() > 0) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this.onUnload);
        },

        destroy() {
            this.stopIntercept?.();
            window.removeEventListener('beforeunload', this.onUnload);
        },

        // The server's view of the sections. On the first read it is also what is staged; later a
        // section the member has not touched follows the server, a touched one keeps its pick.
        read(first) {
            const block = this.$root.querySelector('[data-wf-state]');
            if (!block) {
                return;
            }
            const state = JSON.parse(block.textContent);
            const saved = {};
            const meta = {};
            state.rows.forEach((row) => {
                saved[row.key] = { template: row.template, active: row.active };
                meta[row.key] = { label: row.label, pending: row.pending };
            });

            const staged = {};
            Object.keys(saved).forEach((key) => {
                const before = this.saved[key];
                const mine = this.staged[key];
                const touched = !first && before && mine
                    && (before.template !== mine.template || before.active !== mine.active);
                staged[key] = touched ? { ...mine } : { ...saved[key] };
            });

            this.order = state.rows.map((row) => row.key);
            this.meta = meta;
            this.templates = state.templates;
            this.texts = state.texts;
            this.saved = saved;
            this.staged = staged;
        },

        row(key) {
            return this.staged[key] ?? { template: null, active: false };
        },

        name(templateKey) {
            return this.templates[templateKey]?.name ?? '';
        },

        isChanged(key) {
            const a = this.saved[key];
            const b = this.staged[key];

            return Boolean(a && b) && (a.template !== b.template || a.active !== b.active);
        },

        dirtyCount() {
            return this.order.filter((key) => this.isChanged(key)).length;
        },

        onCount() {
            return this.order.filter((key) => this.row(key).template && this.row(key).active).length;
        },

        summary(short) {
            return fill(short ? this.texts.summary_short : this.texts.summary, { on: this.onCount(), total: this.order.length });
        },

        switchLabel(key) {
            return fill(this.texts.switch_label, {
                name: this.meta[key]?.label ?? '',
                state: this.row(key).active ? this.texts.enabled : this.texts.disabled,
            });
        },

        toggleTitle(key) {
            return fill(this.row(key).active ? this.texts.toggle_off_title : this.texts.toggle_on_title, { name: this.meta[key]?.label ?? '' });
        },

        // ---- picker ----

        openPick(key) {
            this.pick = key;
            this.sel = this.row(key).template ?? NONE;
        },

        // The first-run card: the list appears and the picker opens on its first section.
        startFirst() {
            this.fromEmpty = true;
            if (this.order.length > 0) {
                this.$nextTick(() => this.openPick(this.order[0]));
            }
        },

        closePick() {
            this.pick = null;
            this.sel = null;
        },

        choose(templateKey) {
            if (this.templates[templateKey]?.locked) {
                this.up = templateKey;

                return;
            }
            this.sel = templateKey;
        },

        pickTitle() {
            return this.pick ? fill(this.texts.pick_title, { name: this.meta[this.pick]?.label ?? '' }) : '';
        },

        applyWhy() {
            if (!this.sel) {
                return this.texts.apply_why_empty;
            }
            if (this.pick && this.sel === (this.row(this.pick).template ?? NONE)) {
                return this.texts.apply_why_same;
            }

            return '';
        },

        applyLabel() {
            if (this.sel === NONE) {
                return this.texts.apply_none;
            }

            return this.pick && this.row(this.pick).template ? this.texts.apply_new : this.texts.apply;
        },

        apply() {
            const key = this.pick;
            const choice = this.sel;
            if (!key || !choice || this.applyWhy() !== '') {
                return;
            }
            const pending = this.meta[key]?.pending ?? 0;
            const label = this.meta[key]?.label ?? '';
            const was = this.saved[key]?.template ?? null;

            if (choice === NONE) {
                const go = () => {
                    this.staged[key] = { template: null, active: false };
                    this.$wire.staged(key, null);
                };
                this.closePick();
                if (was && pending > 0) {
                    this.ask(fill(this.texts.cf_none_title, { name: label }), fill(this.texts.cf_none_text, { count: pending }), this.texts.cf_none_ok, go);

                    return;
                }
                go();

                return;
            }

            const go = () => {
                this.staged[key] = { template: choice, active: true };
                this.$wire.staged(key, choice);
            };
            this.closePick();
            // a different flow sends the posts waiting in the saved one back to its first stage
            if (was && was !== choice && pending > 0) {
                this.ask(fill(this.texts.cf_swap_title, { name: label }), fill(this.texts.swap_confirm, { count: pending }), this.texts.swap_go, go);

                return;
            }
            go();
        },

        // ---- switch ----

        toggle(key) {
            const current = this.row(key);
            if (!current.template) {
                return;
            }
            const pending = this.meta[key]?.pending ?? 0;
            if (current.active && pending > 0) {
                this.ask(
                    fill(this.texts.cf_off_title, { name: this.meta[key]?.label ?? '' }),
                    fill(this.texts.turn_off_confirm, { count: pending }),
                    this.texts.turn_off,
                    () => { this.staged[key] = { ...current, active: false }; },
                );

                return;
            }
            this.staged[key] = { ...current, active: !current.active };
        },

        // ---- confirm ----

        ask(title, text, ok, run) {
            this.confirm = { title, text, ok, run };
        },

        confirmOk() {
            const run = this.confirm?.run;
            this.confirm = null;
            run?.();
        },

        // ---- save bar ----

        changes() {
            return this.order
                .filter((key) => this.isChanged(key))
                .map((key) => ({ key, template: this.staged[key].template, active: Boolean(this.staged[key].template) && this.staged[key].active }));
        },

        save() {
            const changes = this.changes();
            if (changes.length === 0 || this.busy) {
                return;
            }
            this.busy = true;
            this.$wire.save(changes)
                .then((ok) => {
                    if (ok) {
                        // the new server state is the baseline for everything, touched or not
                        this.saved = {};
                        this.$nextTick(() => this.read(true));
                    }
                })
                .catch(() => {})
                .finally(() => { this.busy = false; });
        },

        discard() {
            this.staged = clone(this.saved);
        },

        // ---- leave guard (links inside the admin open in place, so beforeunload never sees them) ----

        guardNavigate(event) {
            if (this.dirtyCount() === 0 || event.detail?.history) {
                return;
            }
            event.preventDefault();
            this.leave = event.detail.url?.href ?? String(event.detail.url);
        },

        leaveNow() {
            const url = this.leave;
            this.leave = null;
            this.staged = clone(this.saved);
            if (url) {
                window.Livewire.navigate(url);
            }
        },

        leaveText() {
            return fill(this.texts.leave_text, { count: this.dirtyCount() });
        },

        // ---- keyboard ----

        escape() {
            if (this.leave) {
                this.leave = null;
            } else if (this.confirm) {
                this.confirm = null;
            } else if (this.up) {
                this.up = null;
            } else if (this.pick) {
                this.closePick();
            }
        },

        doRetry() {
            const again = this.retry;
            this.failed = false;
            this.retry = null;
            again?.();
        },
    }));
});
