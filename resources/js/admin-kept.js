// Text the entry editor keeps on this device (the «Có thay đổi chưa lưu từ …» band). Browser state
// only, never sent until the member presses «Khôi phục».
//
// One record per member, entry and language, under  bs-entry-kept:<member ulid>:<entry ulid>:<locale>
// as { at: ISO time, values: {...} }, so two people on one browser never see each other's text.
// Records older than a week are dropped when a page opens, and every record goes when the member
// signs out (admin-session.js: a form marked data-bs-signout, or the session dialog's own button).
// Loaded with the editor's lazy part, which is the only screen that keeps text.

export const PREFIX = 'bs-entry-kept:';
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

function keys() {
    const found = [];
    try {
        for (let i = 0; i < window.localStorage.length; i += 1) {
            const k = window.localStorage.key(i);
            if (k && k.startsWith(PREFIX)) {
                found.push(k);
            }
        }
    } catch {
        // storage blocked: nothing is kept, so nothing to clear
    }

    return found;
}

export function read(key) {
    try {
        const r = JSON.parse(window.localStorage.getItem(key) || 'null');

        return r && typeof r.at === 'string' && r.values ? r : null;
    } catch {
        return null;
    }
}

/** True when the text really is on this device now (a full or blocked store answers false). */
export function write(key, values) {
    const rec = { at: new Date().toISOString(), values };
    try {
        window.localStorage.setItem(key, JSON.stringify(rec));

        return rec;
    } catch {
        return null;
    }
}

export function remove(key) {
    try {
        window.localStorage.removeItem(key);
    } catch {
        // nothing to forget
    }
}

export function clearAll() {
    keys().forEach(remove);
}

export function prune(now = Date.now()) {
    keys().forEach((k) => {
        const r = read(k);
        const t = r ? Date.parse(r.at) : NaN;
        if (!Number.isFinite(t) || now - t > MAX_AGE_MS) {
            remove(k);
        }
    });
}

/** What a save would send, read from the form's $wire (same fields «Khôi phục» puts back). */
export function valuesOf(w) {
    const values = { title: w.title, slug: w.slug, excerpt: w.excerpt, seoTitle: w.seoTitle, seoDescription: w.seoDescription, coverImageKey: w.coverImageKey, coverAlt: w.coverAlt, categoryId: w.categoryId };
    if (Array.isArray(w.categoryIds)) {
        values.categoryIds = [...w.categoryIds];
    }
    if (w.prose) {
        values.bodyHtml = w.bodyHtml;
    } else {
        values.blocks = JSON.parse(JSON.stringify(w.blocks || []));
    }

    return values;
}

/**
 * «14:05», or «31/12/2026 14:05» when it was not today, in the site's zone and day format.
 * `cfg` = { zone, format: auto|dmy|mdy|iso|long, lang }.
 */
export function when(iso, cfg) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) {
        return '';
    }
    try {
        const zone = cfg.zone || undefined;
        const time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: zone }).format(d);
        const part = (date, opts) => Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: zone, ...opts }).formatToParts(date).map((p) => [p.type, p.value]));
        const day = (date) => {
            const p = part(date, { year: 'numeric', month: '2-digit', day: '2-digit' });

            return `${p.year}-${p.month}-${p.day}`;
        };
        if (day(d) === day(new Date())) {
            return time;
        }
        const p = part(d, { year: 'numeric', month: '2-digit', day: '2-digit' });
        let text = `${p.day}/${p.month}/${p.year}`;
        if (cfg.format === 'mdy') {
            text = `${p.month}/${p.day}/${p.year}`;
        } else if (cfg.format === 'iso') {
            text = `${p.year}-${p.month}-${p.day}`;
        } else if (cfg.format === 'long') {
            text = new Intl.DateTimeFormat(cfg.lang || 'vi', { dateStyle: 'long', timeZone: zone }).format(d);
        }

        return `${text} ${time}`;
    } catch {
        return '';
    }
}

if (typeof window !== 'undefined') {
    window.bsKept = { PREFIX, read, write, remove, clearAll, prune, valuesOf, when };
    prune();
    window.dispatchEvent(new window.CustomEvent('bs-kept-ready'));
}
