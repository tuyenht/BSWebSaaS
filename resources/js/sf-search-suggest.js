// Search suggestions while the visitor types (storefront.js fetches this file as soon as the visitor
// reaches for a search box: a press on the header's magnifier or on a box, or the focus). Under the
// field: at most five rows from the site (data-suggest: /tim-kiem/goi-y, JSON), each built from plain
// text (textContent, never HTML from the answer) under its group's heading (Nhóm, Sản phẩm, a post
// category…): a picture (none: a tinted tile with the row's icon), the title with the part typed in
// bold, a meta line (a code, a day); then "Xem tất cả N kết quả cho “…”" (N from the answer, «50+» past
// 50) and the keys hint. Asked 130 ms after typing stops, from two characters; the words already asked
// (a space typed after a word, a letter typed then rubbed out) are answered from what this page already
// has, or from the request still on its way. An input method's composing counts as typing on a page in
// a language written with letters (a phone keyboard composes every word; Telex and VNI compose a
// letter); on a Japanese, Chinese or Korean page, where it is spelling sounds out, it is waited for. An
// older answer is dropped (AbortController + a counter). The field is a combobox: ↑ ↓ choose a row (it
// stays the focus; the headings are skipped), Enter opens the chosen row (none chosen: the form goes to
// the search page as without this script), Esc closes the list (a second Esc closes the header's panel).
// Loading: a thin bar and "Đang tìm “…”…" at once (the words stay when motion is reduced), grey rows
// past 300 ms. No row: a sentence saying so and how to go on, and the panel's quick links again. A
// refused or failed answer counts as no row. The texts come from the page (data-suggest-text), the
// stylesheet from data-suggest-css (sf-search.css).

const NS = 'http://www.w3.org/2000/svg';
// path data; "c x y r" is a circle
const ICONS = {
    folder: ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
    box: ['M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8'],
    briefcase: ['M3 8h18v11H3zM8 8V5h8v3M3 13h18'],
    doc: ['M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6'],
    none: ['c 11 11 7', 'M20 20l-4-4M8.5 8.5l5 5M13.5 8.5l-5 5'],
    enter: ['M20 5v7a3 3 0 0 1-3 3H6M10 11l-4 4 4 4'],
    all: ['M5 12h14M13 6l6 6-6 6'],
};
const ROWS = 5;
const WAIT = 130;
// The answers this page already has, by the words asked (the latest few).
const KEEP = 30;
let boxes = 0;

const icon = (doc, name, size, stroke) => {
    const svg = doc.createElementNS(NS, 'svg');
    Object.entries({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': stroke, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' })
        .forEach(([k, v]) => svg.setAttribute(k, String(v)));
    (ICONS[name] ?? ICONS.doc).forEach((d) => {
        const c = d.startsWith('c ') && d.split(' ');
        const shape = doc.createElementNS(NS, c ? 'circle' : 'path');
        Object.entries(c ? { cx: c[1], cy: c[2], r: c[3] } : { d }).forEach(([k, v]) => shape.setAttribute(k, v));
        svg.append(shape);
    });
    return svg;
};

// Only an address of this site, never another host or a script address: resolved the way the browser
// resolves it (it drops tabs and line breaks, so "/\t/host" is another host) and kept only on the page's
// own origin; the resolved address is the one opened. null otherwise.
const local = (doc, url) => {
    if (typeof url !== 'string' || url === '') {
        return null;
    }
    try {
        const base = new URL(doc.baseURI);
        const to = new URL(url, base);
        return /^https?:$/.test(to.protocol) && to.origin === base.origin ? to.href : null;
    } catch {
        return null;
    }
};

// A picture's address: https only (the media host may be another), null otherwise.
const picture = (doc, url) => {
    try {
        return typeof url === 'string' && url !== '' && /^https:$/.test(new URL(url, doc.baseURI).protocol) ? new URL(url, doc.baseURI).href : null;
    } catch {
        return null;
    }
};

export function attach(form, env = {}) {
    const input = form.querySelector('input[name="q"]');
    if (!input || !form.dataset.suggest || form.dataset.suggestOn !== undefined) {
        return null;
    }
    form.dataset.suggestOn = '';
    const doc = form.ownerDocument;
    const win = doc.defaultView;
    const fetchFn = env.fetch ?? ((...a) => win.fetch(...a));
    // from the phone's full-screen panel (storefront.js marks it) the row's page takes the panel's history entry
    const go = env.go ?? ((href) => (form.closest('[data-search-sheet]') ? win.location.replace(href) : win.location.assign(href)));
    const wait = env.wait ?? WAIT;
    const slow = env.slow ?? 300;
    let text = {};
    try {
        text = JSON.parse(form.dataset.suggestText ?? '{}');
    } catch {
        text = {};
    }
    const say = (key, swap = {}) => Object.entries(swap).reduce((s, [k, v]) => s.replace(`:${k}`, () => v), String(text[key] ?? ''));
    const el = (tag, cls, content) => {
        const node = doc.createElement(tag);
        if (cls) {
            node.className = cls;
        }
        if (content !== undefined) {
            node.textContent = content;
        }
        return node;
    };

    // The stylesheet, linked once per page when it is not there yet; the box shows only once it applies.
    const css = form.dataset.suggestCss;
    const styled = env.styled ?? new Promise((done) => {
        if (!css) {
            return done();
        }
        const href = new URL(css, doc.baseURI).href;
        const have = [...doc.querySelectorAll('link[rel="stylesheet"]')].find((l) => l.href === href);
        if (have) {
            return done();
        }
        const link = Object.assign(el('link'), { rel: 'stylesheet', href });
        link.addEventListener('load', done, { once: true });
        link.addEventListener('error', done, { once: true });
        doc.head.append(link);
    });

    const n = ++boxes;
    const panel = form.closest('.sf-spanel__box');
    const box = el('div', panel ? 'sf-ssug' : 'sf-ssug sf-ssug--float');
    box.hidden = true;
    const load = el('div', 'sf-ssug__load');
    const bar = el('div', 'sf-ssug__bar');
    bar.setAttribute('aria-hidden', 'true');
    bar.append(el('i'));
    // the words stay when motion is reduced (the bar then stands still)
    const words = el('p', 'sf-ssug__lt');
    words.setAttribute('aria-hidden', 'true');
    load.append(bar, words);
    const skel = el('div', 'sf-ssug__sk');
    skel.setAttribute('aria-hidden', 'true');
    [['72%', '30%'], ['58%', '22%'], ['80%', '26%'], ['50%', '18%']].forEach((widths) => {
        const row = el('div', 'sf-ssug__skrow');
        const lines = el('span');
        widths.forEach((w) => {
            const line = el('i');
            line.style.width = w;
            lines.append(line);
        });
        row.append(el('i', 'sf-ssug__th'), lines);
        skel.append(row);
    });
    const list = el('ul', 'sf-ssug__lb');
    list.id = `sf-ssug-${n}`;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', say('label'));
    const none = el('div', 'sf-ssug__nr');
    none.hidden = true;
    const foot = el('div', 'sf-ssug__foot');
    foot.hidden = true;
    const status = el('span', 'sr-only');
    status.setAttribute('role', 'status');
    box.append(load, skel, list, none, foot);
    form.after(box, status);
    if (!panel) {
        // the results page and the 404 page: the box floats over what follows the field
        form.parentElement?.classList.add('sf-ssug--host');
    }
    const quick = panel ? [...panel.querySelectorAll('[data-search-quick]')] : [];
    const chips = quick.filter((q) => q.matches('ul'));

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');

    let options = [];
    let active = -1;
    let timer = 0;
    let slowTimer = 0;
    let asked = 0;
    let ctrl = null;
    let composing = false;
    // the words the request on its way asks for
    let asking = null;
    // the words the rows on screen answer
    let drawnFor = null;
    const answers = new Map();
    // spelling sounds out (kana, pinyin, Hangul jamo): the composed text is not yet the words
    const sounds = /^(ja|zh|ko)(-|$)/i.test(doc.documentElement.lang ?? '');
    const remember = (typed, data) => {
        answers.delete(typed);
        answers.set(typed, data);
        if (answers.size > KEEP) {
            answers.delete(answers.keys().next().value);
        }
    };

    const expanded = () => input.setAttribute('aria-expanded', !box.hidden && !list.hidden && options.length ? 'true' : 'false');
    const choose = (i) => {
        active = i;
        options.forEach((o, k) => o.setAttribute('aria-selected', k === i ? 'true' : 'false'));
        if (i < 0) {
            input.removeAttribute('aria-activedescendant');
        } else {
            input.setAttribute('aria-activedescendant', options[i].id);
            options[i].scrollIntoView?.({ block: 'nearest' });
        }
    };
    const show = (on) => {
        box.hidden = !on;
        // the panel's quick links give way to the list (their own display rule outranks [hidden]); with
        // no suggestion the links come back under the sentence, without their label
        const back = on && !none.hidden;
        quick.forEach((q) => {
            const shown = !on || (back && chips.includes(q));
            q.hidden = !shown;
            q.style.display = shown ? '' : 'none';
        });
        expanded();
    };
    const close = () => {
        loading(false);
        choose(-1);
        show(false);
    };
    const reset = () => {
        clearTimeout(timer);
        clearTimeout(slowTimer);
        ctrl?.abort();
        asking = null;
        drawnFor = null;
        asked++;
        close();
        list.replaceChildren();
        none.replaceChildren();
        none.hidden = true;
        foot.replaceChildren();
        foot.hidden = true;
        options = [];
        status.textContent = '';
    };
    const loading = (on, typed = '') => {
        load.hidden = !on;
        clearTimeout(slowTimer);
        if (!on) {
            skel.hidden = true;
            return;
        }
        words.textContent = say('loading', { q: typed });
        none.hidden = true;
        skel.hidden = options.length > 0;
        list.hidden = skel.hidden === false;
        if (skel.hidden) {
            // older rows stay under the bar for a moment; past the wait they give way to the grey rows
            slowTimer = setTimeout(() => {
                skel.hidden = false;
                list.hidden = true;
                foot.hidden = true;
                choose(-1);
                expanded();
            }, slow);
        } else {
            foot.hidden = true;
        }
        status.textContent = words.textContent;
    };

    // a text cut into pieces marked matched: the matched ones in bold
    const pieces = (node, parts) =>
        parts.forEach(([piece, hit]) => node.append(hit ? el('b', '', String(piece)) : doc.createTextNode(String(piece ?? ''))));
    const tile = (name, cls = '') => {
        const t = el('span', `sf-ssug__th sf-ssug__tile${cls}`);
        t.setAttribute('aria-hidden', 'true');
        t.append(icon(doc, name, 22, 1.9));
        return t;
    };
    const row = (item, i) => {
        const li = el('li', 'sf-ssug__op');
        li.id = `${list.id}-${i}`;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.dataset.url = item.url;
        const src = picture(doc, item.thumb);
        let th = tile(item.icon);
        if (src) {
            const img = Object.assign(el('img', 'sf-ssug__th'), { src, alt: '', width: 48, height: 48, decoding: 'async' });
            // a picture that does not come: the tile instead
            img.addEventListener('error', () => img.replaceWith(tile(item.icon)), { once: true });
            th = img;
        }
        const text = el('span', 'sf-ssug__tx');
        const title = el('span', 'sf-ssug__t');
        pieces(title, Array.isArray(item.parts) && item.parts.length ? item.parts : [[item.label, false]]);
        text.append(title);
        const meta = Array.isArray(item.meta_parts) && item.meta_parts.length ? item.meta_parts : item.meta ? [[item.meta, false]] : [];
        if (meta.length) {
            const m = el('span', 'sf-ssug__m');
            pieces(m, meta);
            text.append(m);
        }
        const key = el('span', 'sf-ssug__k');
        key.append(icon(doc, 'enter', 18, 2));
        li.append(th, text, key);
        return li;
    };

    const draw = (data, typed) => {
        const items = Array.isArray(data?.items)
            ? data.items.map((it) => (it ? { ...it, url: local(doc, it.url) } : null)).filter((it) => it?.url).slice(0, ROWS)
            : [];
        const q = typeof data?.q === 'string' && data.q !== '' ? data.q : typed;
        list.replaceChildren();
        none.replaceChildren();
        foot.replaceChildren();
        list.hidden = false;
        options = [];
        // the rows under their group's heading, the groups in the order the answer gives them
        let group = null;
        let rows = null;
        items.forEach((item) => {
            const at = String(item.group_key ?? item.group ?? '');
            if (group !== at || rows === null) {
                group = at;
                const g = el('li', 'sf-ssug__grp');
                g.setAttribute('role', 'presentation');
                const h = el('div', 'sf-ssug__gh', String(item.group ?? ''));
                h.id = `${list.id}-g${list.children.length}`;
                h.setAttribute('aria-hidden', 'true');
                rows = el('ul', 'sf-ssug__gl');
                rows.setAttribute('role', 'group');
                rows.setAttribute('aria-labelledby', h.id);
                g.append(h, rows);
                list.append(g);
            }
            const li = row(item, options.length);
            rows.append(li);
            options.push(li);
        });
        if (!options.length) {
            list.hidden = true;
            const message = el('div');
            const hint = el('p', 'sf-ssug__nrd');
            const [before, after = ''] = say(chips.length ? 'none_hint' : 'none_hint_solo').split(':go');
            hint.append(before, el('b', '', say('go')), after);
            message.append(el('p', 'sf-ssug__nrt', say('none_title', { q })), hint);
            none.append(tile('none', ' sf-ssug__tile--n'), message);
            none.hidden = false;
            foot.hidden = true;
            status.textContent = say('none_title', { q });
        } else {
            const total = Number.isInteger(data.total) && data.total > 0 ? data.total : 0;
            const count = total > 50 ? '50+' : data.total_more ? `${total}+` : String(total);
            const one = total === 1 && !data.total_more ? '_one' : '';
            const all = el('a', 'sf-ssug__all');
            all.href = local(doc, data.all_url) ?? form.action;
            all.append(
                el('span', 'sf-ssug__long', total ? say(`all_n${one}`, { count, q }) : say('all', { q })),
                el('span', 'sf-ssug__short', total ? say(`all_n_short${one}`, { count }) : say('all_short')),
                icon(doc, 'all', 18, 2),
            );
            const keys = el('span', 'sf-ssug__keys');
            keys.setAttribute('aria-hidden', 'true');
            keys.append(el('kbd', '', '↑'), ' ', el('kbd', '', '↓'), ` ${say('select')} · `, el('kbd', '', 'Enter'), ` ${say('open')} · `, el('kbd', '', 'Esc'), ` ${say('close')}`);
            foot.append(all, keys);
            foot.hidden = false;
            none.hidden = true;
            status.textContent = options.length === 1 ? say('one') : say('count', { count: String(options.length) });
        }
        drawnFor = typed;
        loading(false);
        choose(-1);
        show(true);
    };

    const run = async () => {
        const typed = input.value.trim();
        if ([...typed].length < 2) {
            reset();
            return;
        }
        ctrl?.abort();
        asking = null;
        const mine = ++asked;
        const known = answers.get(typed);
        let answer = null;
        if (known === undefined) {
            // asked at once; the stylesheet (fetched meanwhile) only has to be there to draw
            const signal = (ctrl = new AbortController()).signal;
            asking = typed;
            answer = Promise.resolve()
                .then(() => fetchFn(`${form.dataset.suggest}?q=${encodeURIComponent(typed)}`, { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' }))
                .then(async (res) => (res.ok ? { data: await res.json(), keep: true } : { data: {}, keep: false }));
            answer.catch(() => {});
        }
        await styled;
        if (mine !== asked) {
            return;
        }
        let data = known ?? {};
        if (answer !== null) {
            loading(true, typed);
            show(true);
            try {
                const got = await answer;
                data = got.data;
                if (got.keep) {
                    remember(typed, data);
                }
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }
                data = {};
            }
            if (mine !== asked) {
                return;
            }
            asking = null;
        }
        if (doc.activeElement === input) {
            draw(data, typed);
        } else {
            // the visitor left the field meanwhile: nothing drawn, and the loading bar stops
            close();
        }
    };
    const later = () => {
        clearTimeout(timer);
        if (composing && sounds) {
            return;
        }
        const typed = input.value.trim();
        if (typed === asking && ctrl !== null) {
            // the same words as the request on its way (a space after the last word): let it answer
            return;
        }
        if (asking === null && typed === drawnFor && !box.hidden) {
            // the rows already answer these words (a keyboard ending its composition, maybe by the tap
            // on a row): redrawing would swap the row under the finger before its click lands
            return;
        }
        ctrl?.abort();
        asking = null;
        asked++;
        choose(-1);
        // words this page already has an answer for are drawn at once
        timer = setTimeout(run, answers.has(typed) ? 0 : wait);
    };

    input.addEventListener('compositionstart', () => {
        composing = true;
        if (!sounds) {
            return;
        }
        clearTimeout(timer);
        // an answer for the letters before the composing never draws in the middle of it
        ctrl?.abort();
        asking = null;
        asked++;
    });
    input.addEventListener('compositionend', () => {
        composing = false;
        later();
    });
    input.addEventListener('input', (event) => {
        if (sounds && (event.isComposing || composing)) {
            return;
        }
        later();
    });
    input.addEventListener('keydown', (event) => {
        if (event.isComposing || composing) {
            return;
        }
        const open = !box.hidden && !list.hidden;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!options.length || list.hidden) {
                return;
            }
            event.preventDefault();
            if (!open) {
                show(true);
            }
            const step = event.key === 'ArrowDown' ? 1 : -1;
            choose(active < 0 ? (step > 0 ? 0 : options.length - 1) : (active + step + options.length) % options.length);
        } else if (event.key === 'Enter' && open && active >= 0) {
            event.preventDefault();
            go(options[active].dataset.url);
        } else if (event.key === 'Escape' && !box.hidden) {
            // the list closes first; the header's panel stays open until the next Esc
            event.preventDefault();
            event.stopPropagation();
            clearTimeout(timer);
            ctrl?.abort();
            asking = null;
            asked++;
            close();
        }
    });
    // a row is chosen with the pointer without taking the focus from the field
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('pointermove', (event) => {
        const row = event.target.closest?.('[role="option"]:not([aria-disabled])');
        if (row && options.indexOf(row) !== active) {
            choose(options.indexOf(row));
        }
    });
    list.addEventListener('click', (event) => {
        const row = event.target.closest?.('[role="option"]:not([aria-disabled])');
        if (row) {
            go(row.dataset.url);
        }
    });
    // focus leaving the field and the box closes the list (not for the quick links under "no suggestion")
    const away = () => setTimeout(() => {
        const now = doc.activeElement;
        if (!form.contains(now) && !box.contains(now) && !(!none.hidden && chips.some((c) => c.contains(now)))) {
            close();
        } else if (now !== input) {
            choose(-1);
        }
    });
    form.addEventListener('focusout', away);
    box.addEventListener('focusout', away);
    // typed before this file arrived (the panel's field): ask now
    if (doc.activeElement === input && input.value !== input.defaultValue) {
        later();
    }

    return { input, box, list, status };
}

document.querySelectorAll('form[data-suggest]').forEach((form) => attach(form));
