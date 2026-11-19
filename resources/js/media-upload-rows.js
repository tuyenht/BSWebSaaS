// The upload list under the "Thiết bị của tôi" dropzone (design board 13): one row per file with
// its state (a percentage while it runs, «Xong» with its size and pixels, «Chưa tải được» with the
// reason in plain words), «Thử lại» on the failures a second try can fix, × to stop or drop a row,
// and the summary line above the list with «Ẩn tệp đã xong». Kept apart from Uppy so the rules can
// be tested on their own (tests/js/media-upload-rows.test.mjs).

/** The failures a second try can fix; every other reason needs a different file or address. */
export const RETRY = ['upload_session_invalid', 'upload_missing', 'storage_failed', 'fetch_unreachable', 'unexpected'];

const EXT = {
    'image/jpeg': ['jpg', 'jpeg'],
    'image/png': ['png'],
    'image/webp': ['webp'],
    'image/gif': ['gif'],
    'application/pdf': ['pdf'],
};

/** A file size the way the board writes it: "12,4 MB", "48 KB". */
export function fmtSize(bytes, locale = 'vi') {
    const b = Number(bytes) || 0;
    if (b >= 1048576) {
        return new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(b / 1048576) + ' MB';
    }

    return Math.max(1, Math.round(b / 1024)) + ' KB';
}

/**
 * Why a file is refused before it leaves the device, or null when it may go up.
 *
 * @param {{name?: string, type?: string, size?: number}} file
 * @param {{maxBytes?: number|null, allowed?: string[]}} rules  allowed = MIME types
 */
export function classify(file, { maxBytes = null, allowed = [] } = {}) {
    const name = String(file.name || '');
    const dot = name.lastIndexOf('.');
    const ext = dot > 0 ? name.slice(dot + 1).toLowerCase() : '';
    if (maxBytes && (file.size || 0) > maxBytes) {
        return 'too_large';
    }
    if (ext === 'svg' || file.type === 'image/svg+xml') {
        return 'unsupported_svg';
    }
    // a file the browser could not type (type '') is judged by its extension
    const ok = file.type ? allowed.includes(file.type) : allowed.some((m) => (EXT[m] || []).includes(ext));
    if (allowed.length && !ok) {
        return 'unsupported_type';
    }
    if (!file.size) {
        return 'file_invalid'; // an empty file: the server would refuse it, and a second try cannot help
    }

    return null;
}

/** The reason an error carries (set by the upload pipeline from the server's answer), or the fallback. */
export function reasonOf(error, fallback = 'unexpected') {
    const r = error && typeof error.reason === 'string' ? error.reason : '';

    return r !== '' ? r : fallback;
}

/** Put `value` in place of `:key` (a function, so "$&" in a file name stays as typed). */
function fill(text, key, value) {
    return String(text).replace(':' + key, () => String(value));
}

/** The plain-language sentence for a reason; an unknown reason with a server sentence keeps that sentence. */
export function messageFor(code, { msgs, size = 0, serverMessage = '', locale = 'vi' }) {
    const known = Object.prototype.hasOwnProperty.call(msgs, code);
    const text = known ? msgs[code] : serverMessage || msgs.unexpected || '';

    return fill(text, 'size', fmtSize(size, locale));
}

/** The line above the list: "Đang tải 2 tệp · 1 tệp xong · 1 tệp chưa tải được". */
export function summary(rows, labels) {
    let run = 0;
    let done = 0;
    let err = 0;
    for (const r of rows) {
        if (r.st === 'run') run++;
        else if (r.st === 'done') done++;
        else err++;
    }

    return [
        run ? fill(labels.headRun, 'n', run) : '',
        done ? fill(labels.headDone, 'n', done) : '',
        err ? fill(labels.headErr, 'n', err) : '',
    ].filter(Boolean).join(' · ');
}

/**
 * The list itself, drawn into `listEl` with the head (`headEl`: a status span and the clear button).
 * Rows are keyed by an id the caller picks (the Uppy file id, or a local one for a refused file).
 *
 * @param {{listEl: HTMLElement, headEl: HTMLElement|null, msgs: Record<string,string>, labels: Record<string,string>,
 *          icon: (name: string) => Node|null, locale?: string, fallbackFocus?: () => HTMLElement|null, onRetry: (id: string) => void, onRemove: (id: string, running: boolean) => void}} o
 */
export function createUploadList(o) {
    const doc = o.listEl.ownerDocument;
    const locale = o.locale || 'vi';
    /** @type {Map<string, {st: string, code: string|null, name: string, size: number, el: HTMLElement}>} */
    const rows = new Map();
    const statusEl = o.headEl?.querySelector('[data-up-summary]') ?? null;
    const clearEl = o.headEl?.querySelector('[data-up-clear]') ?? null;

    function head() {
        if (!o.headEl) return;
        if (statusEl) statusEl.textContent = summary(rows.values(), o.labels);
        if (clearEl) clearEl.hidden = ![...rows.values()].some((r) => r.st === 'done');
    }

    function iconEl(name) {
        const n = o.icon(name);
        if (n && n.setAttribute) n.setAttribute('aria-hidden', 'true');

        return n;
    }

    function button(cls, iconName, text, label) {
        const b = doc.createElement('button');
        b.type = 'button';
        b.className = cls;
        const i = iconName ? iconEl(iconName) : null;
        if (i) b.append(i);
        if (text) b.append(doc.createTextNode(text));
        if (label) {
            b.setAttribute('aria-label', label);
            b.title = label;
        }

        return b;
    }

    // one row drawn from its state; the element is replaced whole, so no stale part survives a change
    function draw(id) {
        const r = rows.get(id);
        if (!r) return;
        const el = doc.createElement('div');
        el.className = 'mp-up';
        el.dataset.st = r.st;
        el.dataset.upId = id;

        const th = doc.createElement('span');
        th.className = 'mp-upth';
        th.setAttribute('aria-hidden', 'true');
        const thIcon = iconEl(r.st === 'err' ? 'err' : /\.pdf$/i.test(r.name) ? 'pdf' : 'image');
        if (thIcon) th.append(thIcon);

        const main = doc.createElement('div');
        main.className = 'mp-upmain';
        const top = doc.createElement('div');
        top.className = 'mp-upr';
        const nm = doc.createElement('span');
        nm.className = 'mp-upnm';
        const dot = r.name.lastIndexOf('.');
        const base = doc.createElement('span');
        base.textContent = dot > 0 ? r.name.slice(0, dot) : r.name;
        const ext = doc.createElement('span');
        ext.textContent = dot > 0 ? r.name.slice(dot) : '';
        nm.append(base, ext);
        const st = doc.createElement('span');
        st.className = 'mp-ups';
        const stIcon = iconEl(r.st === 'err' ? 'failed' : r.st === 'done' ? 'done' : 'running');
        if (stIcon) st.append(stIcon);
        st.append(doc.createTextNode(r.st === 'err' ? o.labels.failed : r.st === 'done' ? o.labels.done : r.pct + '%'));
        top.append(nm, st);
        main.append(top);

        if (r.st === 'run') {
            const bar = doc.createElement('div');
            bar.className = 'mp-upbar';
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-label', r.name);
            bar.setAttribute('aria-valuemin', '0');
            bar.setAttribute('aria-valuemax', '100');
            bar.setAttribute('aria-valuenow', String(r.pct));
            const fill = doc.createElement('span');
            fill.style.width = r.pct + '%';
            bar.append(fill);
            main.append(bar);
        } else if (r.st === 'err') {
            const p = doc.createElement('p');
            p.className = 'mp-upmsg';
            p.textContent = r.msg;
            main.append(p);
        } else {
            const p = doc.createElement('p');
            p.className = 'mp-upmeta';
            p.textContent = fill(r.w && r.h ? fill(fill(o.labels.meta, 'w', r.w), 'h', r.h) : o.labels.metaNoDim, 'size', fmtSize(r.size, locale));
            main.append(p);
        }

        el.append(th, main);
        if (r.st === 'err' && r.code !== null && RETRY.includes(r.code)) {
            const rt = button('mp-upx rt', 'retry', o.labels.retry, null);
            rt.addEventListener('click', () => o.onRetry(id));
            el.append(rt);
        }
        const running = r.st === 'run' && !r.sent;
        const rm = button('mp-upx', 'remove', '', fill(running ? o.labels.stop : o.labels.drop, 'name', r.name));
        rm.addEventListener('click', () => o.onRemove(id, running));
        el.append(rm);

        if (r.el && r.el.parentNode) {
            // keep a keyboard user where they were: the same button in the new row, else its ×
            const had = r.el.contains(doc.activeElement) ? doc.activeElement : null;
            r.el.replaceWith(el);
            if (had) {
                ((had.classList.contains('rt') ? el.querySelector('.mp-upx.rt') : null) ?? rm).focus();
            }
        } else {
            o.listEl.prepend(el); // newest first, as the board adds them
        }
        r.el = el;
        head();
    }

    // a row leaves the list: focus moves to the next row's ×, else the previous one's, else the summary
    function drop(r) {
        const el = r.el;
        if (!el) return;
        if (el.contains(doc.activeElement)) {
            const near = el.nextElementSibling ?? el.previousElementSibling;
            const to = near?.querySelector('.mp-upx:last-child');
            if (to) {
                to.focus();
            } else {
                o.fallbackFocus?.()?.focus();
            }
        }
        el.remove();
    }

    return {
        rows,
        add(id, { name, size }) {
            // the same file picked again after it left Uppy comes back under the same id: its old row goes
            const old = rows.get(id);
            if (old) drop(old);
            rows.set(id, { st: 'run', code: null, msg: '', name: String(name || ''), size: Number(size) || 0, pct: 0, w: 0, h: 0, sent: false, el: null });
            draw(id);
        },
        progress(id, pct) {
            const r = rows.get(id);
            if (!r || r.st !== 'run') return;
            const p = Math.max(0, Math.min(100, Math.round(pct)));
            if (r.el) {
                // only the moving parts: redrawing the whole row on every tick would steal focus from ×
                r.pct = p;
                const fill = r.el.querySelector('.mp-upbar > span');
                const bar = r.el.querySelector('.mp-upbar');
                const st = r.el.querySelector('.mp-ups');
                if (fill) fill.style.width = p + '%';
                if (bar) bar.setAttribute('aria-valuenow', String(p));
                if (st && st.lastChild) st.lastChild.textContent = p + '%';
            }
        },
        /** The bytes are in storage and the file is being recorded: it can no longer be stopped. */
        sent(id) {
            const r = rows.get(id);
            if (!r || r.st !== 'run' || r.sent) return;
            r.sent = true;
            draw(id);
        },
        done(id, { width = 0, height = 0 } = {}) {
            const r = rows.get(id);
            if (!r) return;
            Object.assign(r, { st: 'done', code: null, msg: '', pct: 100, w: Number(width) || 0, h: Number(height) || 0 });
            draw(id);
        },
        fail(id, code, serverMessage = '') {
            const r = rows.get(id);
            if (!r) return;
            Object.assign(r, { st: 'err', code, msg: messageFor(code, { msgs: o.msgs, size: r.size, serverMessage, locale }) });
            draw(id);
        },
        /** A retried file comes back under a new id: the row keeps its place and starts again. */
        rekey(oldId, newId) {
            const r = rows.get(oldId);
            if (!r) return;
            rows.delete(oldId);
            Object.assign(r, { st: 'run', code: null, msg: '', pct: 0, sent: false });
            rows.set(newId, r);
            draw(newId);
        },
        remove(id) {
            const r = rows.get(id);
            if (!r) return;
            drop(r);
            rows.delete(id);
            head();
        },
        /** Takes the finished rows off the list; returns their ids. */
        clearDone() {
            // the clear button hides itself below: a keyboard user on it goes to the first row left
            if (clearEl && clearEl.contains(doc.activeElement)) {
                // the top-most row left on screen (newest first)
                (o.listEl.querySelector('.mp-up:not([data-st="done"]) .mp-upx:last-child') ?? o.fallbackFocus?.())?.focus();
            }
            const gone = [];
            for (const [id, r] of [...rows]) {
                if (r.st === 'done') {
                    drop(r);
                    rows.delete(id);
                    gone.push(id);
                }
            }
            head();

            return gone;
        },
    };
}
