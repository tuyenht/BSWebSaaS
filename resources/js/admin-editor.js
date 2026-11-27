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

import * as kept from './admin-kept.js';

const SAVES = ['save', 'publish', 'autosave'];

/** A field's value as the server writes it in `saved` (Editor::tracked). */
export function valueText(value) {
    if (Array.isArray(value) && value.some((v) => v !== null && typeof v === 'object')) {
        return JSON.stringify(value); // a list of rows (the job card's boxes): as the server writes it
    }
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

/**
 * Whether a record in this status saves itself: a draft; with `autosaveNew`, also a record not saved yet
 * (no status). With `needs` (field names) and `get`, only while each of those fields has text: the server
 * cannot store the record without them, so the line does not promise a save that will not come.
 */
export function savesItself(cfg, status, get = null) {
    if (cfg.autosave === false) {
        return false;
    }
    const fresh = status === null || status === undefined || status === '';
    if (status !== 'draft' && !(cfg.autosaveNew === true && fresh)) {
        return false;
    }

    return !(Array.isArray(cfg.needs) && get) || cfg.needs.every((field) => String(get(field) ?? '').trim() !== '');
}

/** The words of a state; `auto` when the save on its way is the record's own (words.autosaving, when given). */
export function stateText(state, words, n, status, auto = false) {
    if (state === 'saving') {
        return auto && words.autosaving ? words.autosaving : words.saving;
    }
    if (state === 'offline') {
        return words.offline;
    }
    const line = status === 'draft' ? words.dirtyDraft : status === 'published' ? words.dirtyLive : words.dirty;

    return String(line).replace(':n', String(n));
}

/**
 * The short line of a state, for the phone's one bar of an editor (design 07: «Đang lưu…» · «Chưa lưu»),
 * where the long line would not fit on one line; the long one stays its title and what is read out.
 * Without `dirtyShort` among the words it is the long line.
 */
export function shortStateText(state, words, long) {
    if (state === 'saving') {
        return words.saving;
    }

    return words.dirtyShort ?? long;
}

/**
 * @param {{ fields: string[], words: Record<string, string>, delay?: number, autosave?: boolean, autosaveNew?: boolean, extra?: string, keep?: { key: string } }} cfg
 *   the counted fields, the words of each state, the wait before a draft saves itself (ms);
 *   `autosave: false` for an editor whose drafts do not save themselves; `autosaveNew` for one whose new
 *   record (no status yet) saves itself too, as a draft; `needs` lists the fields it cannot be saved without
 *   (no save by itself while one is empty); `noAuto` lists the fields whose change alone never starts the
 *   record's own save (the save does not store them); `extra` names a $wire
 *   property holding a count of changes the server keeps itself (a list the browser cannot write
 *   the way the server does), added to the browser's count; `keep` (the editor of live content) names the
 *   key under which unsaved changes are copied to this device (admin-kept.js).
 */
export function editorSave(cfg) {
    const delay = cfg.delay ?? 3000;
    let timer = null;
    let offIntercept = null;
    let effect = null;
    let page = null;
    let onInput = null;
    let keepTimer = null;
    let onSaved = null;
    let onHide = null;

    return {
        busy: false,
        offline: false,
        typed: false,
        n: 0,
        last: 'save',
        auto: false,
        own: 0,
        get: null,

        init() {
            this.get = (field) => this.$wire.$get(field);
            page = this.$root.closest('[wire\\:id]') || document.body;
            onInput = () => {
                this.typed = true;
            };
            page.addEventListener('input', onInput, true);

            // Live content with unsaved changes also lives on this device (the band «Có thay đổi chưa
            // lưu từ …» offers it back): written a second after the last change and when the page is
            // left, forgotten once a save really stored the text.
            if (cfg.keep) {
                onSaved = () => {
                    clearTimeout(keepTimer);
                    kept.remove(cfg.keep.key);
                };
                onHide = () => this.keepNow();
                window.addEventListener('admin-editor-saved', onSaved);
                window.addEventListener('pagehide', onHide);
            }

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
                    this.auto = names.every((name) => name === 'autosave');
                    const lost = () => {
                        const wasOnline = !this.offline;
                        this.offline = true;
                        if (wasOnline && cfg.words.offlineToast && typeof window.bsToast === 'function') {
                            window.bsToast({ text: cfg.words.offlineToast, icon: cfg.words.offlineToastIcon });
                        }
                        // the same save again: a lost autosave stays an autosave (a save would also apply a
                        // status picked but not yet saved)
                        this.last = names.includes('publish') ? 'publish' : names.includes('save') ? 'save' : 'autosave';
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
                    const n = countChanges(cfg.fields, (f) => wire.$get(f), wire.saved) + (cfg.extra ? Number(wire.$get(cfg.extra)) || 0 : 0);
                    this.n = n;
                    // a change the record's own save does not store (`noAuto`: a choice applied only by a button)
                    // counts as unsaved but never starts that save
                    this.own = cfg.noAuto ? n - countChanges(cfg.noAuto, (f) => wire.$get(f), wire.saved) : n;
                    this.schedule(this.own, wire.status, values);
                    this.keepSoon(n, wire.status);
                });
            }
        },

        destroy() {
            clearTimeout(timer);
            clearTimeout(keepTimer);
            if (onSaved) {
                window.removeEventListener('admin-editor-saved', onSaved);
            }
            if (onHide) {
                window.removeEventListener('pagehide', onHide);
            }
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

        keepSoon(n, status) {
            clearTimeout(keepTimer);
            if (!cfg.keep || n === 0 || status !== 'published') {
                return;
            }
            keepTimer = setTimeout(() => this.keepNow(), 1000);
        },

        keepNow() {
            clearTimeout(keepTimer);
            if (!cfg.keep || this.n === 0 || this.$wire.status !== 'published') {
                return;
            }
            kept.write(cfg.keep.key, kept.valuesOf(this.$wire));
        },

        schedule(n, status) {
            clearTimeout(timer);
            if (n === 0 || !savesItself(cfg, status, this.get)) {
                return;
            }
            const fire = () => {
                if (this.busy) {
                    timer = setTimeout(fire, delay);

                    return;
                }
                if (this.own > 0 && savesItself(cfg, this.$wire.status, this.get)) {
                    Promise.resolve(this.$wire.autosave()).catch(() => {});
                }
            };
            timer = setTimeout(fire, delay);
        },

        state() {
            return overState({ busy: this.busy, offline: this.offline, failed: !!this.$wire.saveFailed, typed: this.typed, n: this.n });
        },

        text() {
            const status = this.$wire.status;

            const itself = this.own > 0 && savesItself(cfg, status, this.get);

            return stateText(this.state(), cfg.words, this.n, itself ? 'draft' : (status === 'draft' || savesItself(cfg, status, this.get) ? 'none' : status), this.auto);
        },

        short() {
            return shortStateText(this.state(), cfg.words, this.text());
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
