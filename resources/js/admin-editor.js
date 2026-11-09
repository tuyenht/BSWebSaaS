// The product editor's save state (Alpine.data 'bsEditorSave', started by the shared lazy loader,
// admin-lazy.js). The server draws the state at rest beside the title (never saved, saved at a time,
// saved by itself, a field to fix); this part shows over it what only the browser knows:
//  - "N thay đổi chưa lưu", counted as the person types against the saved values the server keeps
//    ($wire.saved), with what happens next: a draft saves itself 3 seconds after the last change, a
//    published product waits for "Cập nhật";
//  - "Đang lưu…" while a save is on its way (a button, the leave dialog or the draft's own save);
//  - "Chưa lưu được — mất kết nối" with "Thử lại" when a save did not reach the server. What was
//    typed stays in the form.
// The buttons are plain wire:click buttons, so they work before this part has loaded.

const SAVES = ['save', 'publish', 'autosave'];

/** A field's value as the server writes it in `saved` (Editor::tracked). */
export function valueText(value) {
    if (Array.isArray(value)) {
        return JSON.stringify([...new Set(value.map((v) => Number.parseInt(v, 10) || 0))]);
    }
    if (value === null || value === undefined || value === false) {
        return '';
    }

    return value === true ? '1' : String(value);
}

/** How many fields differ from the saved values. */
export function countChanges(fields, read, saved) {
    return fields.filter((f) => valueText(read(f)) !== String((saved && saved[f]) ?? '')).length;
}

/** What to show over the server's state, or null to let it show: saving · offline · dirty. */
export function overState({ busy, offline, failed, typed, n }) {
    if (busy) {
        return 'saving';
    }
    if (offline) {
        return 'offline';
    }
    if (failed && !typed) {
        return null; // the server's "còn ô cần sửa" until the person types again
    }

    return n > 0 ? 'dirty' : null;
}

/** The words of a state. */
export function stateText(state, words, n, status) {
    if (state === 'saving') {
        return words.saving;
    }
    if (state === 'offline') {
        return words.offline;
    }
    const line = status === 'draft' ? words.dirtyDraft : status === 'published' ? words.dirtyLive : words.dirty;

    return String(line).replace(':n', String(n));
}

/**
 * @param {{ fields: string[], words: Record<string, string>, delay?: number }} cfg the counted
 *   fields, the words of each state, the wait before a draft saves itself (ms).
 */
export function editorSave(cfg) {
    const delay = cfg.delay ?? 3000;
    let timer = null;
    let offIntercept = null;
    let effect = null;
    let page = null;
    let onInput = null;

    return {
        busy: false,
        offline: false,
        typed: false,
        n: 0,
        last: 'save',

        init() {
            page = this.$root.closest('[wire\\:id]') || document.body;
            onInput = () => {
                this.typed = true;
            };
            page.addEventListener('input', onInput, true);

            const id = this.$wire ? this.$wire.$id : null;
            const lw = window.Livewire;
            if (lw && typeof lw.interceptMessage === 'function') {
                offIntercept = lw.interceptMessage(({ message, onSuccess, onFailure, onFinish }) => {
                    if (!message || !message.component || message.component.id !== id) {
                        return;
                    }
                    const names = Array.from(message.actions || []).map((a) => a.name).filter((name) => SAVES.includes(name));
                    if (!names.length) {
                        return;
                    }
                    clearTimeout(timer);
                    this.busy = true;
                    const lost = () => {
                        this.offline = true;
                        this.last = names.includes('publish') ? 'publish' : 'save';
                    };
                    // Only a request that never reached the server is "no connection"; a refusal
                    // (permission, expired session, server error) is Livewire's own to show.
                    onFailure(lost);
                    onSuccess(() => {
                        this.offline = false;
                        this.typed = false;
                    });
                    onFinish(() => {
                        this.busy = false;
                    });
                });
            }

            // Every change (typed, picked, an image moved) counts again and, on a draft, waits
            // `delay` from the last one before saving by itself.
            const alpine = window.Alpine;
            if (alpine && typeof alpine.effect === 'function') {
                effect = alpine.effect(() => {
                    const wire = this.$wire;
                    const values = cfg.fields.map((f) => valueText(wire.$get(f))).join('\u0000');
                    const n = countChanges(cfg.fields, (f) => wire.$get(f), wire.saved);
                    this.n = n;
                    this.schedule(n, wire.status, values);
                });
            }
        },

        destroy() {
            clearTimeout(timer);
            if (page && onInput) {
                page.removeEventListener('input', onInput, true);
            }
            if (typeof offIntercept === 'function') {
                offIntercept();
            }
            if (effect && window.Alpine && typeof window.Alpine.release === 'function') {
                window.Alpine.release(effect);
            }
        },

        schedule(n, status) {
            clearTimeout(timer);
            if (n === 0 || status !== 'draft') {
                return;
            }
            const fire = () => {
                if (this.busy) {
                    timer = setTimeout(fire, delay);

                    return;
                }
                if (this.n > 0 && this.$wire.status === 'draft') {
                    Promise.resolve(this.$wire.autosave()).catch(() => {});
                }
            };
            timer = setTimeout(fire, delay);
        },

        state() {
            return overState({ busy: this.busy, offline: this.offline, failed: !!this.$wire.saveFailed, typed: this.typed, n: this.n });
        },

        text() {
            return stateText(this.state(), cfg.words, this.n, this.$wire.status);
        },

        /** "Thử lại": the save that did not reach the server ("Đăng" again, otherwise a plain save). */
        retry() {
            Promise.resolve(this.$wire[this.last]()).catch(() => {});
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsEditorSave', editorSave);
}
