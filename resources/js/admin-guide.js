// The admin guide («Hướng dẫn», x-bs-admin::guide): the drawer listing a screen's guide in parts, «Chỉ
// cho tôi» ringing one element, a part's tour across the screen, and the bubble offering the guide on
// a member's first visit to a screen. Loaded on the first press of a [data-guide-open] button, or on
// entering a screen whose guide this member has not been offered yet (app.js), so a screen pays
// nothing until then.
//
// A port of the design canvas guide engine: the same drawer,
// cards, card placement, focus return and memory. Only the data differs: the canvas read the screen's
// prototype state; here the server prints the member's guide (role, workflow, what they may do, the
// labels the screen draws) as JSON in script[data-guide-ctx], with every word in the admin's language
// (lang/*.json, guide.*), so this file holds no text of its own. A step names the element it points at
// by data-guide="<target>"; the first of its variants whose element shows is read, and a step whose
// element does not show is left out (in the drawer) or skipped (on the screen).
import '../css/admin-guide.css';

const KEY = 'bs-guide-seen';
const CKEY = 'bs-guide-ch';
const GUI_SEL = '.bg-panel,.bg-scrim,.bg-card,.bg-hole,.bg-offer,.bg-offer-catch,.bg-offer-hole';
const QV_SEL =
    'button, a[href], input, select, textarea, [role=button], [role=tab], [role=switch], [role=checkbox], [role=radio], [role=link], [role=combobox], [tabindex="0"], [draggable=true], [role=menuitem], [role=menuitemradio], [role=menuitemcheckbox], [role=option]';

const vis = (e) => {
    if (!e) {
        return false;
    }
    const r = e.getBoundingClientRect();
    const cs = getComputedStyle(e);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
};
const nrm = (s) => String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const lower = (s) => s.charAt(0).toLowerCase() + s.slice(1);
// a control's label: its first line of text, without a count or a ▾ after it
const lab = (e) => {
    const t =
        String((e && (e.innerText || e.textContent)) || '')
            .split('\n')
            .map((x) => x.trim())
            .filter(Boolean)[0] || '';
    return t.split(' · ')[0].replace(/[\s·]*\(?\d[\d.,]*\)?$/, '').replace(/\s*▾$/, '').trim();
};
const hasLabel = (s) => {
    s = nrm(s);
    if (!s) {
        return false;
    }
    const l = document.querySelectorAll(QV_SEL);
    for (let i = 0; i < l.length; i++) {
        const e = l[i];
        if (e.closest(GUI_SEL) || e.closest('[aria-hidden=true]') || !vis(e)) {
            continue;
        }
        if (nrm(e.innerText) === s || lab(e) === s || nrm(e.getAttribute('aria-label')) === s || nrm(e.title) === s) {
            return true;
        }
    }
    return false;
};
const reduce = () =>
    document.documentElement.getAttribute('data-motion') === 'reduce' ||
    (typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

const load = (k) => {
    try {
        const o = JSON.parse(localStorage.getItem(k) || '{}');
        return o && typeof o === 'object' ? o : {};
    } catch {
        return {};
    }
};
const save = (k, o) => {
    try {
        localStorage.setItem(k, JSON.stringify(o));
    } catch {
        // private mode or full storage: the guide works, it only forgets
    }
};

// ---------- the guide printed by the server ----------
let G = null;
function guide() {
    const s = document.querySelector('script[data-guide-ctx]');
    if (!s) {
        return null;
    }
    if (G && G.src === s) {
        return G;
    }
    try {
        G = Object.assign(JSON.parse(s.textContent || 'null') || {}, { src: s });
    } catch {
        G = null;
    }
    return G && Array.isArray(G.parts) ? G : null;
}
// the engine's words: "Phần :n · :title" with its parts
const say = (k, rep = {}) => {
    const g = guide();
    const t = String((g && g.ui && g.ui[k]) || '');
    return t.replace(/:([a-z_]+)/g, (m, n) => (n in rep ? String(rep[n]) : m));
};
const memo = (k) => k + ':' + (guide()?.member || '');
const slot = () => {
    const g = guide();
    return g ? g.screen + '|' + g.scenario : '';
};
const quote = (s) => say('quote', { x: s });
const qv = (s) => {
    s = nrm(s);
    return hasLabel(s) ? quote(s) : lower(s);
};
// the items the menu `el` opens holds now (those it shows once open: a row's «Khác» holds the groups
// that do not fit), each lowered at its start, in the language's list
const menuWords = (el) => {
    const m = el && document.getElementById(el.getAttribute('aria-controls') || '');
    if (!m) {
        return '';
    }
    return Array.from(m.querySelectorAll('[role^="menuitem"]'))
        .filter((x) => !x.hidden && x.style.display !== 'none')
        .map((x) => lower(lab(x.querySelector('span') || x)))
        .filter(Boolean)
        .join(say('list_sep'));
};
// a step's words as HTML: plain text escaped, each [[Label]] quoted when a control on the screen
// carries it ([[@]] = the label of the element the step points at, [[@menu]] = what its menu holds)
function rich(text, el) {
    return String(text || '')
        .split(/(\[\[[^\]]+\]\])/)
        .map((part) => {
            const m = /^\[\[([^\]]+)\]\]$/.exec(part);
            if (!m) {
                return esc(part);
            }
            if (m[1] === '@menu') {
                return esc(menuWords(el));
            }
            return esc(qv(m[1] === '@' ? lab(el) : m[1]));
        })
        .join('');
}
const icon = (n) => {
    const t = document.querySelector('template[data-guide-icons]');
    const e = t && t.content.querySelector('[data-i="' + n + '"]');
    return e ? e.innerHTML : '';
};
// the element a target names: the first one of them that shows
const target = (t) => {
    const l = document.querySelectorAll('[data-guide="' + t + '"]');
    for (let i = 0; i < l.length; i++) {
        if (vis(l[i])) {
            return l[i];
        }
    }
    return null;
};

function build() {
    const g = guide();
    if (!g) {
        return { list: [], chapters: [] };
    }
    const chs = [];
    g.parts.forEach((ch) => {
        const l = (ch.steps || [])
            .map((s) => {
                for (const v of s.v || []) {
                    const e = target(v.t);
                    if (e) {
                        return { e, raw: nrm(v.title), title: rich(v.title, e), text: rich(v.text, e), tour: true, ch: ch.id, intro: !!s.intro };
                    }
                }
                return null;
            })
            .filter(Boolean);
        if (l.length) {
            chs.push({ id: ch.id, title: esc(ch.title), list: l });
        }
    });
    return { list: chs.flatMap((c) => c.list), chapters: chs };
}
// the steps listed in the drawer: the numbered ones (the opening one is the role box, said above)
const listed = (ch) => ch.list.filter((s) => !s.intro);
const chIdx = (chs, id) => chs.findIndex((c) => c.id === id);
const chSeen = () => {
    const v = load(memo(CKEY))[slot()];
    return Array.isArray(v) ? v : [];
};
const markSeen = (id) => {
    if (!id || !guide()) {
        return;
    }
    const o = load(memo(CKEY));
    const k = slot();
    const v = Array.isArray(o[k]) ? o[k] : [];
    if (v.indexOf(id) < 0) {
        v.push(id);
    }
    o[k] = v;
    save(memo(CKEY), o);
};

// ---------- where focus goes back ----------
let homeScroll = null;
function viewBox() {
    const vv = window.visualViewport;
    const L = vv ? vv.offsetLeft : 0;
    const T = vv ? vv.offsetTop : 0;
    const W = vv ? vv.width : innerWidth;
    const H = vv ? vv.height : innerHeight;
    let top = T;
    let bot = T + H;
    document.querySelectorAll('[data-sticky-savebar],.save-bar').forEach((b) => {
        if (!vis(b)) {
            return;
        }
        const r = b.getBoundingClientRect();
        if (r.bottom >= T + H - 1 && r.top < bot) {
            bot = r.top;
        }
    });
    document.querySelectorAll('.admin-topbar,header').forEach((b) => {
        if (!vis(b)) {
            return;
        }
        const ps = getComputedStyle(b).position;
        if (ps !== 'sticky' && ps !== 'fixed') {
            return;
        }
        const r = b.getBoundingClientRect();
        if (r.top <= top + 1 && r.bottom > top && r.height < H / 2) {
            top = r.bottom;
        }
    });
    return { L, T: top, R: L + W, B: bot };
}
function okFull(e) {
    if (!e || !e.focus || !document.contains(e) || e === document.body || e === document.documentElement || !vis(e)) {
        return false;
    }
    if (e.disabled || e.closest('[inert],[aria-hidden="true"],.bg-panel,.bg-scrim,.bg-offer,.bg-offer-catch')) {
        return false;
    }
    const r = e.getBoundingClientRect();
    const V = viewBox();
    if (!(r.top >= V.T - 0.5 && r.left >= V.L - 0.5 && r.right <= V.R + 0.5 && r.bottom <= V.B + 0.5)) {
        return false;
    }
    if (typeof document.elementFromPoint !== 'function') {
        return true;
    }
    const x = Math.min(Math.max(r.left + r.width / 2, V.L + 1), V.R - 1);
    const y = Math.min(Math.max(r.top + r.height / 2, V.T + 1), V.B - 1);
    const top = document.elementFromPoint(x, y);
    return !!top && (top === e || e.contains(top) || top.contains(e));
}
function tabFirst() {
    const all = document.querySelectorAll('a[href],button,input:not([type="hidden"]),select,textarea,summary,[tabindex],[contenteditable="true"]');
    const pos = [];
    const zero = [];
    all.forEach((e) => {
        if (e.tabIndex >= 0) {
            (e.tabIndex > 0 ? pos : zero).push(e);
        }
    });
    pos.sort((a, b) => a.tabIndex - b.tabIndex);
    const L = pos.concat(zero);
    const M = document.querySelector('main');
    if (M) {
        const f = L.find((e) => M.contains(e) && okFull(e));
        if (f) {
            return f;
        }
    }
    return L.find(okFull) || null;
}
function guideHome() {
    homeScroll = null;
    const ls = document.querySelectorAll('[data-guide-open]');
    for (let i = 0; i < ls.length; i++) {
        if (okFull(ls[i])) {
            return ls[i];
        }
    }
    const T = tabFirst();
    if (T) {
        return T;
    }
    const h = document.querySelector('h1');
    if (h && vis(h)) {
        if (!h.hasAttribute('tabindex')) {
            h.setAttribute('tabindex', '-1');
        }
        homeScroll = h;
        return h;
    }
    return null;
}
function focusTo(p) {
    if (!p || !p.focus) {
        return;
    }
    const sc = p === homeScroll;
    homeScroll = null;
    try {
        if (sc) {
            p.focus();
        } else {
            p.focus({ preventScroll: true });
        }
    } catch {
        p.focus();
    }
}
function focusBack(o) {
    focusTo(okFull(o) ? o : guideHome());
}

// ---------- the drawer ----------
let panel = null;
let opener = null;
export function closePanel() {
    if (!panel) {
        return;
    }
    panel.forEach((n) => n.remove());
    panel = null;
    if (opener && opener.setAttribute) {
        opener.setAttribute('aria-expanded', 'false');
    }
    focusBack(opener);
}
const centerEl = (e) => {
    let p = e.parentElement;
    while (p && p !== document.body) {
        const cs = getComputedStyle(p);
        if (/(auto|scroll)/.test(cs.overflowY) && p.scrollHeight > p.clientHeight) {
            break;
        }
        p = p.parentElement;
    }
    const r = e.getBoundingClientRect();
    const behavior = reduce() ? 'auto' : 'smooth';
    try {
        if (p && p !== document.body) {
            const pr = p.getBoundingClientRect();
            p.scrollTo({ top: p.scrollTop + (r.top - pr.top) - (pr.height - r.height) / 2, behavior });
        }
    } catch {
        // no scrolling here: the step is still focused
    }
};
const showBtn = (chId, i, t) =>
    ' <button class="bg-show" type="button" data-ch="' + esc(chId) + '" data-i="' + i + '" aria-label="' + esc(say('show_named', { step: t })) + '">' +
    icon('cursor') + esc(say('show')) + '</button>';
const stepsOf = (n) => (n === 1 ? say('steps_one') : say('steps', { n }));
const runOf = (n) => (n === 1 ? say('run_one') : say('run', { n }));

/** Open the drawer from `btn`; opt.ch = the part shown open, opt.step = the step whose «Chỉ cho tôi» takes focus. */
export function openPanel(btn, opt = {}) {
    closePanel();
    hideOffer(true);
    opener = btn || document.activeElement;
    const g = guide();
    if (!g) {
        return;
    }
    const b = build();
    const scrim = document.createElement('div');
    scrim.className = 'bg-scrim';
    scrim.onclick = closePanel;
    const p = document.createElement('aside');
    p.className = 'bg-panel';
    p.setAttribute('role', 'dialog');
    p.setAttribute('aria-modal', 'true');
    p.setAttribute('aria-labelledby', 'bg-h');
    let h = '<div class="bg-hd"><h2 id="bg-h">' + esc(say('heading', { title: g.title })) + '</h2><button class="bg-x" type="button" aria-label="' + esc(say('close')) + '" title="' + esc(say('close_tip')) + '">' + icon('close') + '</button></div><div class="bg-bd">';
    if (g.role) {
        h += '<div class="bg-who">' + esc(say('role', { role: '\u0000', may: g.role.may })).replace('\u0000', '<b>' + esc(g.role.name) + '</b>') + '<br>' + esc(g.role.note) + '</div>';
    }
    h += '<p>' + esc(g.purpose || '') + '</p>';
    const chs = b.chapters;
    if (chs.length) {
        const seen = chSeen();
        const openId = opt.ch && chIdx(chs, opt.ch) >= 0 ? opt.ch : chs[0].id;
        chs.forEach((ch, ci) => {
            const st = listed(ch);
            const N = st.length;
            const on = ch.id === openId;
            const bid = 'bg-ch-' + ci;
            h += '<section class="bg-ch"><h3 class="bg-chh"><button type="button" class="bg-cht" aria-expanded="' + on + '" aria-controls="' + bid + '"><span>' +
                esc(say('part', { n: ci + 1, title: '\u0000' })).replace('\u0000', ch.title) + ' · ' + esc(stepsOf(N)) +
                (seen.indexOf(ch.id) >= 0 ? '<span class="bg-seen">' + icon('check') + esc(say('seen')) + '</span>' : '') + '</span>' + icon('down') + '</button></h3><div class="bg-chb" id="' + bid + '"' + (on ? '' : ' hidden') + '>';
            h += '<ol>' + st.map((s, i) => '<li><b>' + say('title_sep', { title: s.title }) + '</b> ' + s.text + showBtn(ch.id, i, s.raw) + '</li>').join('') + '</ol>';
            if (N) {
                h += '<button class="bg-go" type="button" data-ch="' + esc(ch.id) + '">' + icon('cursor') + esc(runOf(N)) + '</button>';
            }
            h += '</div></section>';
        });
    }
    h += '</div>';
    p.innerHTML = h;
    p.querySelector('.bg-x').onclick = closePanel;
    p.querySelectorAll('.bg-cht').forEach((t) => {
        t.onclick = () => {
            const o = t.getAttribute('aria-expanded') === 'true';
            const bd = document.getElementById(t.getAttribute('aria-controls'));
            t.setAttribute('aria-expanded', String(!o));
            if (bd) {
                bd.hidden = o;
            }
        };
    });
    p.querySelectorAll('.bg-go').forEach((x) => {
        x.onclick = () => {
            const o = opener;
            const id = x.getAttribute('data-ch');
            closePanel();
            opener = o;
            startTour(id);
        };
    });
    p.querySelectorAll('.bg-show').forEach((x) => {
        x.onclick = () => show(x.getAttribute('data-ch'), +x.getAttribute('data-i'));
    });
    document.body.appendChild(scrim);
    document.body.appendChild(p);
    panel = [scrim, p];
    if (opener && opener.setAttribute) {
        opener.setAttribute('aria-expanded', 'true');
    }
    // opening the guide on a screen = the member knows where it is
    const sk = load(memo(KEY));
    if (!sk[slot()]) {
        sk[slot()] = 'later';
        save(memo(KEY), sk);
    }
    const fb = opt.step != null ? p.querySelector('.bg-show[data-ch="' + esc(opt.ch) + '"][data-i="' + opt.step + '"]') : null;
    if (fb) {
        centerEl(fb.closest('li') || fb);
        focusTo(fb);
    } else {
        focusTo(p.querySelector('.bg-x'));
    }
}

// ---------- on the screen: a part's tour, or «Chỉ cho tôi» ----------
let cur = 0;
let list = [];
let hole = null;
let tourEl = null;
let tourChs = [];
let chI = 0;
let showMode = false;
let showRef = null;
let pl = null;
function dropTour() {
    unfollow();
    pl = null;
    if (tourEl) {
        tourEl.remove();
        tourEl = null;
    }
    if (hole) {
        hole.remove();
        hole = null;
    }
}
// the card and the ring are built hidden and show once the card holds its words and has its place;
// between two steps only the card hides, the ring and the dimming stay
function mkTourEls() {
    unfollow();
    pl = null;
    if (hole && tourEl && document.contains(hole) && document.contains(tourEl)) {
        tourEl.style.visibility = 'hidden';
        tourEl.innerHTML = '';
        return;
    }
    dropTour();
    hole = document.createElement('div');
    hole.className = 'bg-hole';
    hole.setAttribute('aria-hidden', 'true');
    hole.style.visibility = 'hidden';
    tourEl = document.createElement('div');
    tourEl.className = 'bg-card';
    tourEl.setAttribute('role', 'dialog');
    tourEl.setAttribute('aria-labelledby', 'bg-t');
    tourEl.style.visibility = 'hidden';
    document.body.appendChild(hole);
    document.body.appendChild(tourEl);
}
export function endTour(done) {
    if (showMode) {
        closeShow(false); // closing «Chỉ cho tôi» writes nothing
        return;
    }
    dropTour();
    const o = load(memo(KEY));
    if (guide()) {
        o[slot()] = done ? 'done' : 'skip';
        save(memo(KEY), o);
    }
    roleBar('rehide');
    focusBack(opener);
}

// Placing the card: the card and the ring never overlap. The free area is the viewport inside the
// element's nearest scroller, less the sticky bars over it. From 600px wide: below, above, right or
// left of the ring (12px off); if none fits, the element is scrolled to the top of the free area and
// tried again; then the screen is split. Narrower (or no way left): split in two — 480px tall or more,
// a band for the element (96px…45% of the height) with the card above or below; lower, a 296px card
// column beside it. A card short of room keeps its top row and buttons still and scrolls in between.
const VV = () => {
    const v = window.visualViewport;
    return v ? { l: v.offsetLeft, t: v.offsetTop, r: v.offsetLeft + v.width, b: v.offsetTop + v.height } : { l: 0, t: 0, r: innerWidth, b: innerHeight };
};
const scrollerOf = (e) => {
    let p = e.parentElement;
    while (p && p !== document.body && p !== document.documentElement) {
        const cs = getComputedStyle(p);
        if (/(auto|scroll)/.test(cs.overflowY) && p.scrollHeight > p.clientHeight) {
            return p;
        }
        p = p.parentElement;
    }
    return null;
};
const pinnedEl = (e) => {
    for (let p = e; p && p.nodeType === 1 && p !== document.body; p = p.parentElement) {
        const ps = getComputedStyle(p).position;
        if (ps === 'fixed' || ps === 'sticky') {
            return p;
        }
    }
    return null;
};
function freeArea(e) {
    const V = VV();
    const A = { l: V.l, t: V.t, r: V.r, b: V.b };
    const sc = scrollerOf(e);
    const H = V.b - V.t;
    if (sc) {
        const sr = sc.getBoundingClientRect();
        A.l = Math.max(A.l, sr.left + sc.clientLeft);
        A.t = Math.max(A.t, sr.top + sc.clientTop);
        A.r = Math.min(A.r, sr.left + sc.clientLeft + sc.clientWidth);
        A.b = Math.min(A.b, sr.top + sc.clientTop + sc.clientHeight);
    }
    const er = e.getBoundingClientRect();
    const xs = () => {
        const l = Math.max(A.l + 2, Math.min(er.left + 2, A.r - 2));
        const r = Math.min(A.r - 2, Math.max(er.right - 2, A.l + 2));
        return [l, (l + r) / 2, r];
    };
    const barAt = (x, y) => {
        const l = typeof document.elementsFromPoint === 'function' ? document.elementsFromPoint(x, y) : [];
        for (const el of l) {
            if (el.closest && el.closest(GUI_SEL)) {
                continue;
            }
            if (el === document.documentElement || el === document.body) {
                return null;
            }
            let p = el;
            let bar = null;
            while (p && p.nodeType === 1 && p !== document.body) {
                const ps = getComputedStyle(p).position;
                if (ps === 'sticky' || ps === 'fixed') {
                    bar = p;
                    break;
                }
                p = p.parentElement;
            }
            if (!bar || bar.contains(e)) {
                return null;
            }
            const br = bar.getBoundingClientRect();
            if (br.height > H / 2) {
                continue;
            }
            return br;
        }
        return null;
    };
    for (let k = 0; k < 6; k++) {
        let m = null;
        xs().forEach((x) => {
            const b = barAt(x, A.t + 2);
            if (b && b.bottom > A.t + 1 && (m == null || b.bottom > m)) {
                m = b.bottom;
            }
        });
        if (m == null || m >= A.b - 44) {
            break;
        }
        A.t = m;
    }
    for (let k = 0; k < 6; k++) {
        let n = null;
        xs().forEach((x) => {
            const b = barAt(x, A.b - 2);
            if (b && b.top < A.b - 1 && (n == null || b.top < n)) {
                n = b.top;
            }
        });
        if (n == null || n <= A.t + 44) {
            break;
        }
        A.b = n;
    }
    return { V, A, sc };
}
function cardM() {
    tourEl.classList.remove('bg-lim');
    tourEl.style.maxHeight = '';
    limA(false);
    const nat = Math.max(tourEl.offsetHeight, tourEl.scrollHeight);
    const nav = tourEl.querySelector('.bg-nav');
    const bk = tourEl.querySelector('.bg-back');
    const tx = tourEl.querySelector('.bg-cb > div');
    const lh = tx ? parseFloat(getComputedStyle(tx).lineHeight) || 22 : 22;
    const rows = 32 + (nav ? nav.offsetHeight + 12 : 56) + Math.max(34, bk ? bk.offsetHeight - 8 : 0);
    return { nat, rows, lh, min1: rows + lh, min2: rows + 2 * lh };
}
function limA(on) {
    const cb = tourEl && tourEl.querySelector('.bg-cb');
    if (!cb) {
        return;
    }
    if (on) {
        cb.setAttribute('tabindex', '0');
        cb.setAttribute('role', 'region');
        cb.setAttribute('aria-labelledby', 'bg-t');
    } else {
        cb.removeAttribute('tabindex');
        cb.removeAttribute('role');
        cb.removeAttribute('aria-labelledby');
    }
}
function setLim(maxH, nat) {
    const lim = nat > maxH + 0.5;
    tourEl.classList.toggle('bg-lim', lim);
    tourEl.style.maxHeight = lim ? Math.floor(maxH) + 'px' : '';
    limA(lim);
    return lim ? Math.floor(maxH) : nat;
}
// returns how far to scroll (0 = no scrolling); the card and the ring are placed for the element after it
function layout(p, scroll) {
    const e = p.e;
    if (!tourEl || !hole || !document.contains(e)) {
        return 0;
    }
    const F = freeArea(e);
    const V = F.V;
    const A = F.A;
    const W = V.r - V.l;
    const H = V.b - V.t;
    const r = e.getBoundingClientRect();
    const st = p.st;
    const canS = scroll && !pinnedEl(e);
    const proj = (top) => {
        if (!canS) {
            return 0;
        }
        let d = r.top - top;
        const sc = F.sc;
        if (sc) {
            d = Math.max(-sc.scrollTop, Math.min(d, sc.scrollHeight - sc.clientHeight - sc.scrollTop));
        } else {
            const se = document.scrollingElement || document.documentElement;
            d = Math.max(-scrollY, Math.min(d, se.scrollHeight - innerHeight - scrollY));
        }
        return Math.abs(d) < 2 ? 0 : d;
    };
    const mid = (Z) => {
        const zh = Z.b - Z.t;
        return r.height + 24 <= zh ? Z.t + (zh - r.height) / 2 : Z.t + 12;
    };
    const at = (d) => ({ left: r.left, right: r.right, top: r.top - d, bottom: r.bottom - d });
    const holeOf = (q, Z) => {
        const h = { l: Math.max(q.left - 6, Z.l + 3), t: Math.max(q.top - 6, Z.t + 3), r: Math.min(q.right + 6, Z.r - 3), b: Math.min(q.bottom + 6, Z.b - 3) };
        if (h.r < h.l) {
            h.r = h.l;
        }
        if (h.b < h.t) {
            h.b = h.t;
        }
        return h;
    };
    const drawHole = (h) => {
        hole.style.left = h.l + 'px';
        hole.style.top = h.t + 'px';
        hole.style.width = h.r - h.l + 'px';
        hole.style.height = h.b - h.t + 'px';
    };
    const put = (x, y) => {
        tourEl.style.left = Math.round(x) + 'px';
        tourEl.style.top = Math.round(y) + 'px';
    };
    const ecx = r.left + r.width / 2;
    const ecy = r.top + r.height / 2;
    let d;
    let m;
    let cw;
    if (W >= 600 && !st.split) {
        cw = Math.min(380, W - 24);
        tourEl.style.width = cw + 'px';
        m = cardM();
        const hg = m.nat;
        if (hg <= H - 24) {
            const tryAt = (q) => {
                const h = holeOf(q, A);
                const R = { l: h.l - 3, t: h.t - 3, r: h.r + 3, b: h.b + 3 };
                const hx = Math.max(V.l + 12, Math.min(q.left, V.r - cw - 12));
                const vy = Math.max(V.t + 12, Math.min(q.top, V.b - hg - 12));
                const c = [
                    [hx, R.b + 12],
                    [hx, R.t - 12 - hg],
                    [R.r + 12, vy],
                    [R.l - 12 - cw, vy],
                ];
                for (const [x, y] of c) {
                    const b = { l: x, t: y, r: x + cw, b: y + hg };
                    if (b.l >= V.l + 8 && b.t >= V.t + 8 && b.r <= V.r - 8 && b.b <= V.b - 8 && (b.r <= R.l || b.l >= R.r || b.b <= R.t || b.t >= R.b)) {
                        return { c: b, h };
                    }
                }
                return null;
            };
            d = proj(mid(A));
            let res = tryAt(at(d));
            if (!res) {
                const d2 = proj(A.t + 12);
                res = tryAt(at(d2));
                if (res) {
                    d = d2;
                }
            }
            if (res) {
                setLim(1e9, hg);
                put(res.c.l, res.c.t);
                drawHole(res.h);
                return d;
            }
        }
    }
    const corner = W >= 600;
    if (H >= 480) {
        cw = corner ? Math.min(380, W - 24) : W - 24;
        tourEl.style.width = cw + 'px';
        m = cardM();
        if (!st.side) {
            const okB = V.b - 12 - (A.t + 96) - 8 >= m.min2;
            const okT = A.b - 96 - (V.t + 12) - 8 >= m.min2;
            st.side = ecy > V.t + H / 2 ? 'top' : !okB && okT ? 'top' : 'bottom';
        }
        let bh = Math.max(96, Math.min(r.height + 24, H * 0.45));
        const top = st.side === 'top';
        const tot = top ? A.b - (V.t + 12) - 8 : V.b - 12 - A.t - 8;
        let mh = tot - bh;
        if (mh < m.min1) {
            bh = Math.max(56, tot - m.min1);
            mh = tot - bh;
            if (mh < m.rows) {
                mh = m.rows;
                bh = tot - mh;
            }
        }
        const band = top ? { l: A.l, t: A.b - bh, r: A.r, b: A.b } : { l: A.l, t: A.t, r: A.r, b: A.t + bh };
        d = proj(mid(band));
        const ch = setLim(mh, m.nat);
        put(corner ? (ecx > V.l + W / 2 ? V.l + 12 : V.r - 12 - cw) : V.l + 12, top ? V.t + 12 : V.b - 12 - ch);
        drawHole(holeOf(at(d), band));
        return d;
    }
    cw = Math.min(corner ? 380 : 296, W - 24);
    tourEl.style.width = cw + 'px';
    m = cardM();
    if (!st.side) {
        st.side = ecx > V.l + W / 2 ? 'left' : 'right';
    }
    const cx = st.side === 'left' ? V.l + 12 : V.r - 12 - cw;
    let mh2 = H - 24;
    const Z = st.side === 'left' ? { l: Math.max(A.l, cx + cw + 12), t: A.t, r: A.r, b: A.b } : { l: A.l, t: A.t, r: Math.min(A.r, cx - 12), b: A.b };
    if (mh2 < m.rows) {
        mh2 = m.rows;
    }
    setLim(mh2, m.nat);
    put(cx, V.t + 12);
    d = proj(mid(A));
    drawHole(holeOf(at(d), Z));
    return d;
}
// scroll the element into its part; once still (scrollend, or 2 frames unmoved, at most 1.2 s) place again; at most 3 rounds
function doScroll(p, d, done) {
    const sc = scrollerOf(p.e);
    const tg = sc || window;
    let fin = false;
    const t0 = Date.now();
    let last = null;
    let still = 0;
    p.auto = true;
    try {
        tg.scrollBy({ top: d, behavior: reduce() ? 'auto' : 'smooth' });
    } catch {
        if (sc) {
            sc.scrollTop += d;
        }
    }
    const end = () => {
        if (fin) {
            return;
        }
        fin = true;
        tg.removeEventListener('scrollend', end);
        p.auto = false;
        done();
    };
    if ('onscrollend' in window) {
        tg.addEventListener('scrollend', end);
    }
    const tick = () => {
        if (fin) {
            return;
        }
        if (pl !== p) {
            fin = true;
            p.auto = false;
            return;
        }
        const q = p.e.getBoundingClientRect();
        const k = Math.round(q.top) + ',' + Math.round(q.left);
        if (k === last) {
            still++;
        } else {
            still = 0;
            last = k;
        }
        if ((still >= 2 && Date.now() - t0 > 120) || Date.now() - t0 > 1200) {
            end();
            return;
        }
        requestAnimationFrame(tick);
    };
    tick();
}
const plKey = (p) => {
    const q = p.e.getBoundingClientRect();
    const V = VV();
    return [q.top, q.left, q.width, q.height, tourEl ? tourEl.offsetWidth : 0, tourEl ? tourEl.offsetHeight : 0, V.r - V.l, V.b - V.t].map(Math.round).join(',');
};
function seat(scroll) {
    const p = pl;
    if (!p || !tourEl || !hole) {
        return;
    }
    const d = layout(p, scroll && p.round < 3);
    if (!p.shown) {
        p.shown = true;
        hole.style.visibility = '';
        tourEl.style.visibility = '';
        const nb = tourEl.querySelector('.bg-next');
        if (nb) {
            focusTo(nb);
        }
    }
    p.k = plKey(p);
    if (d) {
        p.round++;
        doScroll(p, d, () => {
            if (pl === p) {
                seat(true);
            }
        });
    }
}
// placed again on a resize or a turn of the device, when the member scrolls, or when the element or
// the card changes (checked every 200 ms) — never scrolled back
let flw = null;
function unfollow() {
    if (!flw) {
        return;
    }
    window.removeEventListener('scroll', flw.on, true);
    window.removeEventListener('resize', flw.rz);
    window.removeEventListener('orientationchange', flw.rz);
    if (window.visualViewport) {
        window.visualViewport.removeEventListener('resize', flw.rz);
        window.visualViewport.removeEventListener('scroll', flw.on);
    }
    clearInterval(flw.t);
    if (flw.raf) {
        cancelAnimationFrame(flw.raf);
    }
    flw = null;
}
function follow() {
    unfollow();
    const f = { raf: 0, w: Math.round(VV().r - VV().l) };
    f.on = (ev) => {
        if (ev && ev.type === 'scroll' && tourEl && ev.target && ev.target.nodeType === 1 && tourEl.contains(ev.target)) {
            return; // scrolling inside the card
        }
        if (f.raf) {
            return;
        }
        f.raf = requestAnimationFrame(() => {
            f.raf = 0;
            if (pl && !pl.auto) {
                seat(false);
            }
        });
    };
    f.rz = () => {
        const w = Math.round(VV().r - VV().l);
        if (pl && w !== f.w) {
            f.w = w;
            pl.st = {};
            pl.round = 0;
            if (!pl.auto) {
                seat(true);
            }
        } else {
            f.on();
        }
    };
    window.addEventListener('scroll', f.on, true);
    window.addEventListener('resize', f.rz);
    window.addEventListener('orientationchange', f.rz);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', f.rz);
        window.visualViewport.addEventListener('scroll', f.on);
    }
    f.t = setInterval(() => {
        if (pl && !pl.auto && tourEl && document.contains(pl.e) && plKey(pl) !== pl.k) {
            seat(false);
        }
    }, 200);
    flw = f;
}
function arm(e) {
    pl = { e, st: {}, round: 0, shown: false, auto: false };
    follow();
    seat(true);
}
const xBtn = () => '<button class="bg-x" type="button" aria-label="' + esc(say('exit')) + '" title="' + esc(say('exit_tip')) + '">' + icon('close') + '</button>';
const okS = (x) => !!(x && x.e && document.contains(x.e) && vis(x.e));
const prevBtn = () => '<button class="bg-prev bg-sp" type="button" data-a="prev" aria-label="' + esc(say('prev_aria')) + '">' + icon('left') + esc(say('prev')) + '</button>';
const fwdBtn = () => '<button class="bg-next bg-sn" type="button" data-a="fwd" aria-label="' + esc(say('next_aria')) + '">' + esc(say('next')) + icon('right') + '</button>';
// a part on the screen: a step whose element no longer shows is skipped (in the direction of travel
// first, then the other way); none left → closed as Esc does
function place(dir) {
    if (!tourEl) {
        return;
    }
    let k;
    let f = -1;
    if (!okS(list[cur])) {
        const ord = dir < 0 ? [-1, 1] : [1, -1];
        for (let o = 0; o < 2 && f < 0; o++) {
            if (ord[o] > 0) {
                for (k = cur + 1; k < list.length; k++) {
                    if (okS(list[k])) {
                        f = k;
                        break;
                    }
                }
            } else {
                for (k = cur - 1; k >= 0; k--) {
                    if (okS(list[k])) {
                        f = k;
                        break;
                    }
                }
            }
        }
        if (f < 0) {
            endTour(false);
            return;
        }
        cur = f;
    }
    const s = list[cur];
    let later = false;
    let earlier = false;
    for (k = cur + 1; k < list.length; k++) {
        if (okS(list[k])) {
            later = true;
            break;
        }
    }
    for (k = cur - 1; k >= 0; k--) {
        if (okS(list[k])) {
            earlier = true;
            break;
        }
    }
    const n = tourChs.length;
    const last = !later;
    const nx = last && chI < n - 1 ? tourChs[chI + 1] : null;
    const nums = list.filter((x) => !x.intro);
    const cnt = s.intro ? say('count_intro', { i: chI + 1, n }) : say('count', { i: chI + 1, n, k: nums.indexOf(s) + 1, m: nums.length });
    const bt =
        (earlier ? prevBtn() : '') +
        (nx
            ? '<button class="bg-prev" type="button" data-a="done">' + esc(say('done')) + '</button><button class="bg-next" type="button" data-a="next">' + esc(say('next_part', { title: '\u0000' })).replace('\u0000', nx.title) + '</button>'
            : last
              ? '<button class="bg-next" type="button" data-a="done">' + esc(say('done')) + '</button>'
              : fwdBtn());
    unfollow();
    pl = null;
    tourEl.style.visibility = 'hidden';
    tourEl.innerHTML = '<div class="bg-ctop">' + xBtn() + '</div><div class="bg-cb"><h3 id="bg-t">' + s.title + '</h3><div aria-live="polite">' + s.text + '</div></div><div class="bg-nav"><span class="n">' + esc(cnt) + '</span>' + bt + '</div>';
    tourEl.querySelector('.bg-x').onclick = () => endTour(false);
    tourEl.querySelectorAll('.bg-nav button').forEach((x) => {
        x.onclick = () => {
            const a = x.getAttribute('data-a');
            if (a === 'prev') {
                cur--;
                place(-1);
            } else if (a === 'fwd') {
                cur++;
                place(1);
            } else if (a === 'done') {
                markSeen(tourChs[chI].id);
                endTour(true);
            } else if (a === 'next') {
                markSeen(tourChs[chI].id);
                unfollow();
                pl = null;
                if (tourEl) {
                    tourEl.style.visibility = 'hidden'; // the dimming stays between two parts
                }
                startTour(nx.id);
            }
        };
    });
    arm(s.e);
}
function runChapter(b, i) {
    const l = b.chapters[i].list;
    list = l.filter((x) => x.intro).concat(l.filter((x) => !x.intro)); // the opening step runs before step 1
    if (!list.length) {
        dropTour();
        focusBack(opener);
        return;
    }
    tourChs = b.chapters;
    chI = i;
    cur = 0;
    showMode = false;
    mkTourEls();
    place(1);
}
/** Run the part `id` (none: the first) on the screen. */
export function startTour(id) {
    hideOffer();
    if (!guide()) {
        dropTour();
        return;
    }
    if (showMode) {
        dropTour();
        showMode = false;
    }
    // a part whose steps point at the role card opens it again if it was put away; other parts leave it
    const g = guide();
    const part = (typeof id === 'string' && g.parts.find((p) => p.id === id)) || g.parts[0];
    if (part && (part.steps || []).some((s) => (s.v || []).some((v) => v.t === 'role'))) {
        roleBar('show');
    }
    setTimeout(
        () => {
            const b = build();
            const chs = b.chapters;
            if (!chs.length) {
                dropTour();
                roleBar('rehide');
                focusBack(opener);
                return;
            }
            let i = typeof id === 'string' ? chIdx(chs, id) : 0;
            if (i < 0) {
                i = 0;
            }
            runChapter(b, i);
        },
        reduce() ? 30 : 300,
    );
}
// «Chỉ cho tôi»: one element only; writes nothing to the memory
export function show(chId, i) {
    const b = build();
    const ch = b.chapters[chIdx(b.chapters, chId)];
    const s = ch ? listed(ch)[i] : null;
    const o = opener;
    closePanel();
    opener = o;
    if (!s || !document.contains(s.e) || !vis(s.e)) {
        openPanel(o, { ch: chId });
        return;
    }
    showMode = true;
    showRef = { ch: chId, i };
    mkTourEls();
    showAt(chId, i);
}
// its card: «Trước» / «Tiếp» go within the same part, skipping a step whose element is not on the screen
function showAt(chId, i) {
    if (!showMode || !tourEl) {
        return;
    }
    const b = build();
    const ci = chIdx(b.chapters, chId);
    const ch = b.chapters[ci];
    const st = ch ? listed(ch) : [];
    const s = st[i];
    if (!s || !document.contains(s.e) || !vis(s.e)) {
        closeShow(true);
        return;
    }
    const ok = (x) => x && x.tour && x.e && document.contains(x.e) && vis(x.e);
    let pv = -1;
    let nx = -1;
    for (let k = i - 1; k >= 0; k--) {
        if (ok(st[k])) {
            pv = k;
            break;
        }
    }
    for (let k = i + 1; k < st.length; k++) {
        if (ok(st[k])) {
            nx = k;
            break;
        }
    }
    showRef = { ch: chId, i };
    const cnt = say('count', { i: ci + 1, n: b.chapters.length, k: i + 1, m: st.length });
    const bt = (pv >= 0 ? prevBtn() : '') + (nx >= 0 ? fwdBtn() : '<button class="bg-next" type="button" data-a="done">' + esc(say('done')) + '</button>');
    unfollow();
    pl = null;
    tourEl.style.visibility = 'hidden';
    tourEl.innerHTML = '<div class="bg-ctop"><button class="bg-back" type="button">' + icon('left') + esc(say('back')) + '</button>' + xBtn() + '</div><div class="bg-cb"><h3 id="bg-t">' + s.title + '</h3><div aria-live="polite">' + s.text + '</div></div><div class="bg-nav bg-snav"><span class="n">' + esc(cnt) + '</span><span class="bg-btns">' + bt + '</span></div>';
    tourEl.querySelector('.bg-x').onclick = () => closeShow(false);
    tourEl.querySelector('.bg-back').onclick = () => closeShow(true);
    tourEl.querySelectorAll('.bg-btns button').forEach((x) => {
        x.onclick = () => {
            const a = x.getAttribute('data-a');
            if (a === 'prev') {
                showAt(chId, pv);
            } else if (a === 'fwd') {
                showAt(chId, nx);
            } else {
                closeShow(true);
            }
        };
    });
    arm(s.e);
}
function closeShow(back) {
    dropTour();
    showMode = false;
    const r = showRef;
    showRef = null;
    if (back && r) {
        openPanel(opener, { ch: r.ch, step: r.i });
    } else {
        focusBack(opener);
    }
}

// ---------- the first-visit offer: a dialog under «Hướng dẫn», never a tour of its own accord ----------
// On entering a screen this member has not been offered its guide on (per member, website, screen and
// scenario, as the parts seen), a bubble under the button offers it: «Xem ngay» runs the first part,
// «Để sau» puts it away for good — the button stays. While it shows, the rest of the screen is dimmed so
// the member knows where to look: a ring of light leaves «Hướng dẫn» clear, and the page neither scrolls
// nor takes a press under it. It is a dialog: focus moves into it, Tab goes round its two buttons, and
// Esc or a press on the dimmed page is «Để sau»; a press on the ringed button opens the drawer. Focus then
// goes back where it was, or to «Hướng dẫn». It is tried for 4 seconds, given up once the member
// presses, types or scrolls; it goes away (remembering nothing) when another dialog opens or the button
// leaves the screen. Three layers at the end of the page: one catching presses (1498), the ring whose
// shadow is the dim (1499), the bubble (1500).
let offerEl = null;
let offCatch = null;
let offHole = null;
let offPrev = null;
let offerT = null;
let tryT = null;
let lastAct = 0;
['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((ev) =>
    document.addEventListener(
        ev,
        () => {
            lastAct = Date.now();
        },
        { passive: true, capture: true },
    ),
);
const openBtn = () => {
    const ls = document.querySelectorAll('[data-guide-open]');
    for (let i = 0; i < ls.length; i++) {
        if (vis(ls[i])) {
            return ls[i];
        }
    }
    return null;
};
const inView = (r) => r.width > 0 && r.height > 0 && r.bottom > 0 && r.right > 0 && r.top < innerHeight && r.left < innerWidth;
/** Take the bubble away, remembering nothing; focus inside it goes to «Hướng dẫn» unless the caller places it. */
export function hideOffer(keep) {
    const had = !!(offerEl && document.activeElement && offerEl.contains(document.activeElement));
    [offerEl, offCatch, offHole].forEach((n) => n && n.remove());
    offerEl = offCatch = offHole = null;
    if (had && !keep) {
        focusTo(guideHome());
    }
    clearInterval(offerT);
    clearInterval(tryT);
    offerT = tryT = null;
}
// «Để sau»: remembered, so the screen does not offer it again; focus goes back where it was when that
// control still shows whole, else to «Hướng dẫn»
function dismissOffer() {
    if (!offerEl) {
        return;
    }
    if (guide()) {
        const s = load(memo(KEY));
        s[slot()] = 'later';
        save(memo(KEY), s);
    }
    const p = offPrev;
    offPrev = null;
    hideOffer(true);
    focusTo(okFull(p) ? p : guideHome());
}
// another dialog open (not the guide's own): the bubble would cover it
function offerBlocked() {
    const ds = document.querySelectorAll('[role=dialog],[role=alertdialog],[aria-modal=true]');
    for (let i = 0; i < ds.length; i++) {
        const d = ds[i];
        if ((offerEl && offerEl.contains(d)) || d.classList.contains('bg-panel')) {
            continue;
        }
        if (vis(d)) {
            return true;
        }
    }
    return false;
}
function placeOffer() {
    if (!offerEl) {
        return;
    }
    const l = openBtn();
    if (offerBlocked()) {
        // another dialog took the screen: step aside and leave focus where that dialog put it
        hideOffer(true);
        return;
    }
    if (!l || !inView(l.getBoundingClientRect())) {
        hideOffer();
        return;
    }
    const r = l.getBoundingClientRect();
    const w = offerEl.offsetWidth;
    const left = Math.max(12, Math.min(r.right - w, innerWidth - w - 12));
    const top = r.bottom + 10;
    offerEl.style.left = left + 'px';
    offerEl.style.top = top + 'px';
    offerEl.style.maxHeight = Math.max(120, innerHeight - top - 8) + 'px';
    offerEl.style.setProperty('--ax', Math.max(12, Math.min(w - 24, r.left + r.width / 2 - left - 6)) + 'px');
    // the ring follows the button's own corners (a round button: a round ring)
    const br = parseFloat(getComputedStyle(l).borderTopLeftRadius) || 0;
    const pad = 4;
    offHole.style.left = r.left - pad + 'px';
    offHole.style.top = r.top - pad + 'px';
    offHole.style.width = r.width + 2 * pad + 'px';
    offHole.style.height = r.height + 2 * pad + 'px';
    offHole.style.borderRadius = br >= r.height / 2 - 1 ? '999px' : br + pad + 'px';
}
// the dimmed page takes the press: on the ringed «Hướng dẫn» it opens the drawer, anywhere else it is «Để sau»
function catchPresses(layer) {
    const stop = (e) => {
        e.preventDefault();
        e.stopPropagation();
    };
    ['pointerdown', 'mousedown', 'touchstart', 'wheel', 'touchmove', 'contextmenu'].forEach((ev) => layer.addEventListener(ev, stop, { passive: false }));
    let down = null;
    let touchT = 0;
    let done = false;
    const act = (x, y) => {
        if (done || !offerEl) {
            return;
        }
        done = true;
        const b = openBtn();
        const r = b && b.getBoundingClientRect();
        if (r && x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
            offPrev = null;
            hideOffer(true);
            openPanel(b);
        } else {
            dismissOffer();
        }
    };
    // a mouse or a pen: the click. A finger: its pointerdown is held back (no scroll, no press through),
    // so the browser sends no click; the press is read when that same finger lifts off the layer.
    layer.addEventListener('click', (e) => {
        stop(e);
        if (e.pointerType === 'touch' || Date.now() - touchT < 800) {
            return;
        }
        act(e.clientX, e.clientY);
    });
    layer.addEventListener('pointerdown', (e) => {
        down = e.pointerType === 'touch' ? e.pointerId : null;
    });
    layer.addEventListener('pointercancel', () => {
        down = null;
    });
    layer.addEventListener('pointerup', (e) => {
        if (e.pointerType !== 'touch' || down === null || e.pointerId !== down) {
            return;
        }
        down = null;
        stop(e);
        touchT = Date.now();
        act(e.clientX, e.clientY);
    });
}
// shown now when it can be; 'stop' when no more tries are needed (remembered, or the guide is open)
function tryOffer() {
    if (offerEl || tourEl || panel) {
        return 'stop';
    }
    const g = guide();
    if (!g) {
        return false;
    }
    const o = load(memo(KEY));
    if (o[slot()]) {
        return 'stop';
    }
    const l = openBtn();
    if (!l || !inView(l.getBoundingClientRect()) || offerBlocked()) {
        return false;
    }
    // remembered for another scenario of this screen: their role or workflow has changed since
    const changed = Object.keys(o).some((k) => k.indexOf(g.screen + '|') === 0);
    const title = say(changed ? 'offer_title_changed' : 'offer_title');
    const text = (changed ? say('offer_changed') + ' ' : '') + say('offer_text');
    const nm = reduce() ? ' bg-nm' : '';
    offPrev = document.activeElement;
    offCatch = document.createElement('div');
    offCatch.className = 'bg-offer-catch';
    offHole = document.createElement('div');
    offHole.className = 'bg-offer-hole' + nm;
    offHole.setAttribute('aria-hidden', 'true');
    offerEl = document.createElement('div');
    offerEl.className = 'bg-offer' + nm;
    offerEl.setAttribute('role', 'dialog');
    offerEl.setAttribute('aria-modal', 'true');
    offerEl.setAttribute('aria-labelledby', 'bg-o-t');
    offerEl.setAttribute('aria-describedby', 'bg-o-d');
    offerEl.tabIndex = -1;
    offerEl.innerHTML =
        '<div class="bg-offer-in"><b id="bg-o-t">' + esc(title) + '</b><span id="bg-o-d">' + esc(text) + '</span></div><div class="bg-nav"><span class="n"></span><button class="bg-prev" type="button">' +
        esc(say('offer_later')) + '</button><button class="bg-next" type="button">' + esc(say('offer_now')) + '</button></div>';
    offerEl.querySelector('.bg-prev').onclick = dismissOffer;
    offerEl.querySelector('.bg-next').onclick = () => {
        opener = openBtn();
        offPrev = null;
        hideOffer(true);
        startTour();
    };
    catchPresses(offCatch);
    // a wheel over the bubble scrolls its own words when they overflow, never the page under it
    offerEl.addEventListener(
        'wheel',
        (e) => {
            const inn = offerEl && offerEl.querySelector('.bg-offer-in');
            if (!inn || !inn.contains(e.target) || inn.scrollHeight <= inn.clientHeight) {
                e.preventDefault();
            }
        },
        { passive: false },
    );
    document.body.append(offCatch, offHole, offerEl);
    placeOffer();
    if (!offerEl) {
        return false;
    }
    offerT = setInterval(placeOffer, 300);
    try {
        offerEl.focus({ preventScroll: true });
    } catch {
        offerEl.focus();
    }
    return 'stop';
}
/** On entering a screen: offer its guide, tried every 200 ms for 4 seconds until the member acts. */
export function offer() {
    hideOffer();
    const t0 = Date.now();
    const step = () => {
        if (lastAct > t0 || Date.now() - t0 > 4000 || tryOffer() === 'stop') {
            clearInterval(tryT);
            tryT = null;
        }
    };
    tryT = setInterval(step, 200);
    step();
}
// focus that leaves the bubble (a screen reader's own move, a script) is brought back into it
document.addEventListener(
    'focusin',
    (e) => {
        // a dialog the page opens itself keeps the focus it took
        if (offerEl && e.target instanceof Element && !offerEl.contains(e.target) && !e.target.closest('[role=dialog],[role=alertdialog],[aria-modal=true]')) {
            try {
                offerEl.focus({ preventScroll: true });
            } catch {
                offerEl.focus();
            }
        }
    },
    true,
);
document.addEventListener('scroll', placeOffer, true);
window.addEventListener('resize', placeOffer);

// ---------- the role card during a tour ----------
// A post list whose role card the member put away prints it once more, hidden ([data-role-copy]): a
// tour opens it for its first step and closes it again when it ends. The card on show is left alone.
let rbEl = null;
function roleBar(mode) {
    if (mode === 'show') {
        const el = document.querySelector('[data-role-copy]');
        if (rbEl || !el || target('role')) {
            return;
        }
        rbEl = el;
        clearTimeout(el._bgT);
        el.hidden = false;
        if (reduce()) {
            return;
        }
        el.classList.add('bg-rb', 'bg-rb-gone');
        void el.offsetHeight;
        el.style.maxHeight = el.scrollHeight + 'px';
        el.classList.remove('bg-rb-gone');
        el._bgT = setTimeout(() => {
            el.style.maxHeight = '';
        }, 300);
        return;
    }
    const el = rbEl;
    rbEl = null;
    if (!el || !document.contains(el)) {
        return;
    }
    clearTimeout(el._bgT);
    if (reduce()) {
        el.hidden = true;
        return;
    }
    el.classList.add('bg-rb');
    el.style.maxHeight = el.scrollHeight + 'px';
    void el.offsetHeight;
    el.classList.add('bg-rb-gone');
    el._bgT = setTimeout(() => {
        el.hidden = true;
        el.classList.remove('bg-rb-gone');
        el.style.maxHeight = '';
    }, 300);
}

const SCROLL_KEYS = [' ', 'PageDown', 'PageUp', 'ArrowDown', 'ArrowUp', 'Home', 'End'];
// The first-visit bubble holds the keyboard: Esc is «Để sau», Tab goes round its two buttons and no key
// reaches the page. Otherwise Esc leaves the card, else closes the drawer; Tab stays inside the open drawer.
document.addEventListener(
    'keydown',
    (e) => {
        if (offerEl) {
            e.stopPropagation();
            if (e.key === 'Escape') {
                e.preventDefault();
                dismissOffer();
                return;
            }
            if (e.key === 'Tab') {
                e.preventDefault();
                const bs = [offerEl.querySelector('.bg-prev'), offerEl.querySelector('.bg-next')];
                const i = bs.indexOf(document.activeElement);
                bs[i < 0 ? (e.shiftKey ? 1 : 0) : (i + 1) % 2].focus();
                return;
            }
            // the keys that scroll the page would move «Hướng dẫn» out of view (a button keeps Space: it presses it)
            if (SCROLL_KEYS.includes(e.key) && !(e.key === ' ' && document.activeElement instanceof Element && document.activeElement.tagName === 'BUTTON')) {
                e.preventDefault();
            }
            if (!offerEl.contains(document.activeElement)) {
                e.preventDefault();
                offerEl.focus();
            }
            return;
        }
        if (e.key === 'Tab' && panel && !tourEl) {
            const f = Array.from(panel[1].querySelectorAll('button, a[href], [tabindex="0"]')).filter((x) => !x.closest('[hidden]'));
            if (!f.length) {
                return;
            }
            const i = f.indexOf(document.activeElement);
            if (e.shiftKey && i <= 0) {
                e.preventDefault();
                f[f.length - 1].focus();
            } else if (!e.shiftKey && (i < 0 || i === f.length - 1)) {
                e.preventDefault();
                f[0].focus();
            }
            return;
        }
        if (e.key !== 'Escape') {
            return;
        }
        if (tourEl) {
            e.stopPropagation();
            endTour(false);
        } else if (panel) {
            e.stopPropagation();
            closePanel();
        }
    },
    true,
);
// leaving the screen takes the guide away with it, writing nothing
document.addEventListener('livewire:navigating', () => {
    hideOffer(true);
    dropTour();
    rbEl = null;
    showMode = false;
    if (panel) {
        panel.forEach((n) => n.remove());
        panel = null;
    }
    G = null;
});

/** For the tests: what is open. */
export const state = () => ({ panel: panel ? panel[1] : null, card: tourEl, hole, opener, offer: offerEl });
