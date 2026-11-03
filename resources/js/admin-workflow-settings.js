// "Quy trình duyệt" (approval workflow per content type): the picks and switches are only staged
// here, in the page; nothing is written until "Lưu — hiệu lực ngay" sends the whole set to the
// component's save() in one request. The server state arrives as a JSON block inside the component
// ([data-wf-state]) and is read again after every round trip, so a save, a reload or a newer page
// becomes the new baseline while the changes not yet saved stay staged.
//
// Each staged section remembers the saved state it was changed from (`base`); the save sends it as
// `from`, and the server refuses the batch when someone else has saved that section since. "Tải
// lại" then shows the newer version of such a section; the other staged changes stay.
//
// The staged changes are also kept in sessionStorage, per site, next to the saved state they were
// made on: leaving with the browser's back button and coming back restores them as long as nothing
// was saved meanwhile.
//
// Dialogs (picker, confirm, plan, leave) are drawn by the view; this object only says which one is
// open. Escape closes the top one; focus is held and returned by x-trap.

const NONE = 'none';
const STORE = 'bs.wf.staged:';

function clone(rows) {
    return JSON.parse(JSON.stringify(rows));
}

function same(a, b) {
    return Boolean(a && b) && a.template === b.template && a.active === b.active;
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
        base: {},
        site: '',
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
            this.restore();

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
                onError(({ response, preventDefault }) => {
                    // An expired page (419) is Livewire's to handle: it offers to reload the page.
                    // The staged changes survive that reload (sessionStorage).
                    if (response?.status === 419) {
                        return;
                    }
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
                onFinish(() => this.$nextTick(() => this.read(false, action.name === '$refresh')));
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
        // section the member has not touched follows the server, a touched one keeps its pick and
        // the saved state it was made on — except on "Tải lại" when someone else has saved that
        // section meanwhile: the reload shows their version, and the member makes the change again.
        read(first, reload = false) {
            const block = this.$root.querySelector('[data-wf-state]');
            if (!block) {
                return;
            }
            const state = JSON.parse(block.textContent);
            const saved = {};
            const meta = {};
            state.rows.forEach((row) => {
                saved[row.key] = { template: row.template, active: row.active };
                meta[row.key] = { label: row.label, pending: row.pending, movable: row.movable };
            });

            const staged = {};
            const base = {};
            Object.keys(saved).forEach((key) => {
                const was = this.base[key];
                const mine = this.staged[key];
                const touched = !first && was && mine && !same(was, mine);
                const overtaken = reload && !same(was, saved[key]);
                staged[key] = touched && !overtaken ? { ...mine } : { ...saved[key] };
                base[key] = touched && !overtaken ? { ...was } : { ...saved[key] };
            });

            this.site = state.site ?? '';
            this.order = state.rows.map((row) => row.key);
            this.meta = meta;
            this.templates = state.templates;
            this.texts = state.texts;
            this.saved = saved;
            this.staged = staged;
            this.base = base;
            if (!first) {
                this.persist();
            }
        },

        // ---- kept across back/forward (sessionStorage; the page works the same without it) ----

        storeKey() {
            return STORE + this.site;
        },

        snapshot() {
            return JSON.stringify(this.order.map((key) => [key, this.saved[key]?.template ?? null, Boolean(this.saved[key]?.active)]));
        },

        persist() {
            try {
                if (this.dirtyCount() === 0) {
                    window.sessionStorage.removeItem(this.storeKey());

                    return;
                }
                window.sessionStorage.setItem(this.storeKey(), JSON.stringify({ snapshot: this.snapshot(), staged: this.staged, base: this.base }));
            } catch {
                // storage blocked or full: the changes simply are not kept
            }
        },

        // Only on the very state they were made on: anything saved meanwhile drops them.
        restore() {
            let kept = null;
            try {
                kept = JSON.parse(window.sessionStorage.getItem(this.storeKey()) ?? 'null');
                if (kept && kept.snapshot !== this.snapshot()) {
                    window.sessionStorage.removeItem(this.storeKey());
                    kept = null;
                }
            } catch {
                kept = null;
            }
            if (!kept || typeof kept.staged !== 'object' || typeof kept.base !== 'object') {
                return;
            }
            const valid = (row) => Boolean(row) && typeof row.active === 'boolean'
                && (row.template === null || (Object.hasOwn(this.templates, row.template) && !this.templates[row.template].locked));
            this.order.forEach((key) => {
                const mine = kept.staged?.[key];
                const was = kept.base?.[key];
                if (valid(mine) && valid(was) && !same(mine, this.saved[key])) {
                    this.staged[key] = { template: mine.template, active: mine.template !== null && mine.active };
                    this.base[key] = { template: was.template, active: was.active };
                }
            });
            if (this.dirtyCount() > 0) {
                // the first-run card would hide the list holding the restored changes
                this.fromEmpty = true;
            }
        },

        stage(key, row) {
            this.staged[key] = row;
            this.persist();
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
            // every post the save would move: in-flight in the section or its sub-categories,
            // or holding a pending change mid-review
            const moving = this.meta[key]?.movable ?? 0;
            const label = this.meta[key]?.label ?? '';
            const was = this.saved[key]?.template ?? null;

            if (choice === NONE) {
                const go = () => {
                    this.stage(key, { template: null, active: false });
                    this.$wire.staged(key, null);
                };
                this.closePick();
                if (was && moving > 0) {
                    this.ask(fill(this.texts.cf_none_title, { name: label }), fill(this.texts.cf_none_text, { count: moving }), this.texts.cf_none_ok, go);

                    return;
                }
                go();

                return;
            }

            const go = () => {
                this.stage(key, { template: choice, active: true });
                this.$wire.staged(key, choice);
            };
            this.closePick();
            // a different flow sends the posts waiting in the saved one back to its first stage
            if (was && was !== choice && moving > 0) {
                this.ask(fill(this.texts.cf_swap_title, { name: label }), fill(this.texts.swap_confirm, { count: moving }), this.texts.swap_go, go);

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
            const moving = this.meta[key]?.movable ?? 0;
            if (current.active && moving > 0) {
                this.ask(
                    fill(this.texts.cf_off_title, { name: this.meta[key]?.label ?? '' }),
                    fill(this.texts.turn_off_confirm, { count: moving }),
                    this.texts.turn_off,
                    () => { this.stage(key, { ...current, active: false }); },
                );

                return;
            }
            this.stage(key, { ...current, active: !current.active });
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
                .map((key) => ({
                    key,
                    template: this.staged[key].template,
                    active: Boolean(this.staged[key].template) && this.staged[key].active,
                    // the saved state this change was made on: refused if someone saved over it since
                    from: { template: this.base[key]?.template ?? null, active: Boolean(this.base[key]?.active) },
                }));
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
                        this.base = {};
                        this.$nextTick(() => {
                            this.read(true);
                            this.persist();
                        });
                    }
                })
                .catch(() => {})
                .finally(() => { this.busy = false; });
        },

        discard() {
            this.staged = clone(this.saved);
            this.base = clone(this.saved);
            this.persist();
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
            this.discard();
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
