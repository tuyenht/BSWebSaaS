// Search suggestions while the visitor types (storefront.js fetches this file the first time a search
// box with data-suggest takes focus). Under the field: at most five rows from the site
// (data-suggest: /tim-kiem/goi-y, JSON), each built from plain text (textContent, never HTML from the
// answer), then "Xem tất cả kết quả cho “…”" and the keys hint. Asked 250 ms after typing stops, from two
// characters, never while an input method (Telex, VNI) is still composing a letter; an older answer is
// dropped (AbortController + a counter). The field is a combobox: ↑ ↓ choose a row (it stays the focus),
// Enter opens the chosen row (none chosen: the form goes to the search page as without this script), Esc
// closes the list (a second Esc closes the header's panel). Loading: a thin bar at once, grey rows past
// 300 ms. No row: "Nhấn Enter để xem mọi kết quả". A refused or failed answer counts as no row. The
// texts come from the page (data-suggest-text), the stylesheet from data-suggest-css (sf-search.css).

const NS = 'http://www.w3.org/2000/svg';
const ICONS = {
    folder: ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
    box: ['M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8'],
    briefcase: ['M3 8h18v11H3zM8 8V5h8v3M3 13h18'],
    doc: ['M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6'],
    tag: ['M4 4h7l9 9-7 7-9-9z', null],
    enter: ['M20 5v7a3 3 0 0 1-3 3H6M10 11l-4 4 4 4'],
    all: ['M5 12h14M13 6l6 6-6 6'],
};
const ROWS = 5;
let boxes = 0;

const icon = (doc, name, size, stroke) => {
    const svg = doc.createElementNS(NS, 'svg');
    Object.entries({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': stroke, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' })
        .forEach(([k, v]) => svg.setAttribute(k, String(v)));
    (ICONS[name] ?? ICONS.doc).forEach((d) => {
        const shape = doc.createElementNS(NS, d === null ? 'circle' : 'path');
        Object.entries(d === null ? { cx: '8.5', cy: '8.5', r: '1.5' } : { d }).forEach(([k, v]) => shape.setAttribute(k, v));
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

export function attach(form, env = {}) {
    const input = form.querySelector('input[name="q"]');
    if (!input || !form.dataset.suggest || form.dataset.suggestOn !== undefined) {
        return null;
    }
    form.dataset.suggestOn = '';
    const doc = form.ownerDocument;
    const win = doc.defaultView;
    const fetchFn = env.fetch ?? ((...a) => win.fetch(...a));
    const go = env.go ?? ((href) => win.location.assign(href));
    const wait = env.wait ?? 250;
    const slow = env.slow ?? 300;
    let text = {};
    try {
        text = JSON.parse(form.dataset.suggestText ?? '{}');
    } catch {
        text = {};
    }
    const say = (key, swap = {}) => Object.entries(swap).reduce((s, [k, v]) => s.replace(`:${k}`, v), String(text[key] ?? ''));
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
    const bar = el('div', 'sf-ssug__bar');
    bar.setAttribute('aria-hidden', 'true');
    bar.append(el('i'));
    const skel = el('div', 'sf-ssug__sk');
    skel.setAttribute('aria-hidden', 'true');
    ['55%', '42%', '63%'].forEach((w) => {
        const row = el('div', 'sf-ssug__skrow');
        const line = el('i');
        line.style.width = w;
        row.append(el('i'), line);
        skel.append(row);
    });
    const list = el('ul', 'sf-ssug__lb');
    list.id = `sf-ssug-${n}`;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', say('label'));
    const foot = el('div', 'sf-ssug__foot');
    foot.hidden = true;
    const status = el('span', 'sr-only');
    status.setAttribute('role', 'status');
    box.append(bar, skel, list, foot);
    form.after(box, status);
    if (!panel) {
        // the results page and the 404 page: the box floats over what follows the field
        form.parentElement?.classList.add('sf-ssug--host');
    }
    const quick = panel ? panel.querySelectorAll('[data-search-quick]') : [];

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

    const expanded = () => input.setAttribute('aria-expanded', !box.hidden && !list.hidden && list.children.length ? 'true' : 'false');
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
        // the panel's quick links give way to the list (their own display rule outranks [hidden])
        quick.forEach((q) => {
            q.hidden = on;
            q.style.display = on ? 'none' : '';
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
        asked++;
        close();
        list.replaceChildren();
        foot.replaceChildren();
        foot.hidden = true;
        options = [];
        status.textContent = '';
    };
    const loading = (on) => {
        bar.hidden = !on;
        clearTimeout(slowTimer);
        if (!on) {
            skel.hidden = true;
            return;
        }
        skel.hidden = list.children.length > 0;
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
        }
        status.textContent = say('loading');
    };

    const draw = (data, typed) => {
        const items = Array.isArray(data?.items)
            ? data.items.map((it) => (it ? { ...it, url: local(doc, it.url) } : null)).filter((it) => it?.url).slice(0, ROWS)
            : [];
        list.replaceChildren();
        foot.replaceChildren();
        list.hidden = false;
        options = items.map((item, i) => {
            const li = el('li', 'sf-ssug__op');
            li.id = `${list.id}-${i}`;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.dataset.url = item.url;
            const ic = el('span', 'sf-ssug__ic');
            ic.append(icon(doc, item.icon, 20, 1.8));
            const title = el('span', 'sf-ssug__t');
            const parts = Array.isArray(item.parts) && item.parts.length ? item.parts : [[item.label, false]];
            parts.forEach(([piece, hit]) => title.append(hit ? el('b', '', String(piece)) : doc.createTextNode(String(piece ?? ''))));
            // the group: beside the title on a computer, under it on a phone (the stylesheet shows one)
            title.append(el('small', 'sf-ssug__gs', String(item.group ?? '')));
            const key = el('span', 'sf-ssug__k');
            key.append(icon(doc, 'enter', 18, 2));
            li.append(ic, title, el('span', 'sf-ssug__g', String(item.group ?? '')), key);
            list.append(li);
            return li;
        });
        if (!options.length) {
            const msg = el('li', 'sf-ssug__op sf-ssug__op--msg');
            msg.setAttribute('role', 'option');
            msg.setAttribute('aria-disabled', 'true');
            msg.setAttribute('aria-selected', 'false');
            const ic = el('span', 'sf-ssug__ic');
            ic.append(icon(doc, 'enter', 20, 1.8));
            msg.append(ic, el('span', 'sf-ssug__t', say('enter')));
            list.append(msg);
            foot.hidden = true;
            status.textContent = say('none');
        } else {
            const q = typeof data.q === 'string' && data.q !== '' ? data.q : typed;
            const all = el('a', 'sf-ssug__all');
            all.href = local(doc, data.all_url) ?? form.action;
            all.append(el('span', 'sf-ssug__long', say('all', { q })), el('span', 'sf-ssug__short', say('all_short')), icon(doc, 'all', 18, 2));
            const keys = el('span', 'sf-ssug__keys');
            keys.setAttribute('aria-hidden', 'true');
            keys.append(el('kbd', '', '↑'), ' ', el('kbd', '', '↓'), ` ${say('select')} · `, el('kbd', '', 'Enter'), ` ${say('open')} · `, el('kbd', '', 'Esc'), ` ${say('close')}`);
            foot.append(all, keys);
            foot.hidden = false;
            status.textContent = options.length === 1 ? say('one') : say('count', { count: String(options.length) });
        }
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
        const mine = ++asked;
        const signal = (ctrl = new AbortController()).signal;
        await styled;
        if (mine !== asked) {
            return;
        }
        loading(true);
        show(true);
        let data = {};
        try {
            const res = await fetchFn(`${form.dataset.suggest}?q=${encodeURIComponent(typed)}`, { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            data = res.ok ? await res.json() : {};
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            data = {};
        }
        if (mine !== asked) {
            return;
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
        ctrl?.abort();
        asked++;
        choose(-1);
        if (!composing) {
            timer = setTimeout(run, wait);
        }
    };

    input.addEventListener('compositionstart', () => {
        composing = true;
        clearTimeout(timer);
        // an answer for the letters before the composing never draws in the middle of it
        ctrl?.abort();
        asked++;
    });
    input.addEventListener('compositionend', () => {
        composing = false;
        later();
    });
    input.addEventListener('input', (event) => {
        if (event.isComposing || composing) {
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
    // focus leaving the field and the box closes the list
    const away = () => setTimeout(() => {
        const now = doc.activeElement;
        if (!form.contains(now) && !box.contains(now)) {
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
