/*
 * Storefront header behaviour, no framework: submenus as disclosures (button + panel, no
 * role=menu), the mobile menu panel, the text-size button; and the fade-in of lazy photos.
 * Everything here is an addition — without it the submenus still open on hover and keyboard focus
 * (CSS), the menu panel is shown open (noscript style) and photos show as they arrive.
 */

const SIZES = ['m', 'l', 'xl'];
const root = document.documentElement;

// From here on the script owns the submenus: CSS stops opening them on focus alone.
root.classList.add('sf-js');

function toggleOf(item) {
    return item.querySelector(':scope > button[aria-controls]');
}

function setOpen(item, open) {
    const toggle = toggleOf(item);
    if (!toggle) {
        return;
    }
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    item.classList.toggle('is-open', open);
}

function closeAll(except) {
    document.querySelectorAll('[data-disclosure].is-open').forEach((item) => {
        // A panel nested in another keeps the ones around it: opening it must not close its parent.
        // A click outside every menu closes the bar's submenus, not the groups in the phone menu: the way to the
        // visitor's page is open there from the start, and the menu button's own click is "outside".
        if (item !== except && !(except && item.contains(except)) && !(except === null && item.closest('.sf-drawer'))) {
            setOpen(item, false);
        }
    });
}

// Phone menu: one first-level group is open at a time in each tab (the groups inside it stay as they are).
// A group folding above the tapped row would pull the row up the screen, so the scroll moves by the
// difference and the row stays where the visitor's finger is.
function openDrawerGroup(item, open) {
    if (open && !item.parentElement.closest('[data-disclosure]')) {
        const row = toggleOf(item);
        const top = row.getBoundingClientRect().top;
        item.closest('.sf-drawer__pane')?.querySelectorAll('[data-disclosure].is-open').forEach((other) => {
            if (other !== item && !other.parentElement.closest('[data-disclosure]')) {
                setOpen(other, false);
            }
        });
        const scroller = row.closest('.sf-drawer__scroll');
        const box = scroller && scroller.scrollHeight > scroller.clientHeight ? scroller : row.closest('.sf-drawer__panel');
        if (box) {
            box.scrollTop += row.getBoundingClientRect().top - top;
        }
    }
    setOpen(item, open);
}

document.querySelectorAll('[data-disclosure]').forEach((item) => {
    const toggle = toggleOf(item);
    if (!toggle) {
        return;
    }

    const inDrawer = item.closest('.sf-drawer') !== null;

    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        if (inDrawer) {
            openDrawerGroup(item, open);
            return;
        }
        closeAll(item);
        setOpen(item, open);
    });

    item.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && item.classList.contains('is-open')) {
            event.stopPropagation(); // one Escape closes the submenu only, not the mobile panel around it
            setOpen(item, false);
            toggle.focus();
        }
    });

    // The phone menu's groups are an accordion: moving focus on never folds one away.
    if (!inDrawer) {
        item.addEventListener('focusout', (event) => {
            if (!item.contains(event.relatedTarget)) {
                setOpen(item, false);
            }
        });
    }
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-disclosure]')) {
        closeAll(null);
    }
});

// Mobile menu drawer: a dialog. Opening locks the page's scroll and moves focus in; Tab stays inside;
// Esc, the dimmed area, the ✕ and a followed link close it, and focus goes back to the menu button.
const burger = document.querySelector('[data-menu-toggle]');
const panel = burger ? document.getElementById(burger.getAttribute('aria-controls')) : null;

// The language screen (eight or more languages) and the search in it; null for a menu without one.
const langSub = panel ? panel.querySelector('[data-lang-sub]') : null;
const langHead = panel ? panel.querySelector('[data-lang-head]') : null;

function drawerFocusables() {
    // A radio group is one tab stop: only its chosen radio counts. While the language screen is open it is the whole dialog.
    const scope = langSub && !langSub.hidden ? langSub : panel;
    return Array.from(scope.querySelectorAll('a[href], button:not([disabled]), input[type="search"], input[type="radio"]:checked')).filter((el) => el.offsetParent !== null);
}

let chooseTab = null;

function setLangScreen(open, focusHead = true) {
    if (!langSub || langSub.hidden !== open) {
        return;
    }
    langSub.hidden = !open;
    langHead.setAttribute('aria-expanded', open ? 'true' : 'false');
    // Everything else in the panel sleeps while the screen is open (the close button and the backdrop stay usable).
    Array.from(langSub.parentElement.children).forEach((child) => {
        if (child !== langSub) {
            child.inert = open;
        }
    });
    if (open) {
        panel.scrollTop = 0;
        langSub.scrollTop = 0;
        langSub.querySelector('[data-lang-back]').focus();
    } else {
        const search = langSub.querySelector('[data-lang-q]');
        search.value = '';
        search.dispatchEvent(new Event('input'));
        if (focusHead) {
            langHead.focus();
        }
    }
}

// The item to bring into view when the menu opens: the page the visitor is on, else the end of the way to it.
function drawerHere() {
    const pane = panel.querySelector('.sf-drawer__pane:not([hidden])');
    return pane ? pane.querySelector('[aria-current="page"]') || Array.from(pane.querySelectorAll('[aria-current="true"]')).pop() : null;
}

function setMenu(open, returnFocus = true) {
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? burger.dataset.labelClose : burger.dataset.labelOpen);
    panel.hidden = !open;
    root.classList.toggle('sf-menu-open', open);
    setLangScreen(false, false);
    if (open) {
        // Opens on "Menu", or on "Sản phẩm" when the visitor is on a product category.
        const tabs = chooseTab ? panel.querySelectorAll('[role="tab"]') : [];
        const here = tabs.length > 1 && document.getElementById(tabs[1].getAttribute('aria-controls')).querySelector('[aria-current]');
        if (tabs.length) {
            chooseTab(tabs[here ? 1 : 0]);
        }
        const first = panel.querySelector('.sf-drawer__pane:not([hidden]) :is(a[href], button)') || panel.querySelector('[data-menu-close]');
        if (first) {
            first.focus({ preventScroll: true });
        }
        // Scrolls only when that row is hidden above or below the visible part of the menu.
        drawerHere()?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
    } else if (returnFocus) {
        burger.focus();
    }
}

if (burger && panel) {
    burger.addEventListener('click', () => setMenu(burger.getAttribute('aria-expanded') !== 'true'));
    panel.querySelectorAll('[data-menu-close]').forEach((el) => el.addEventListener('click', () => setMenu(false)));
    panel.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            setMenu(false, false);
        }
    });
    document.addEventListener('keydown', (event) => {
        if (panel.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            if (langSub && !langSub.hidden) {
                setLangScreen(false); // one Escape leaves the language screen only
            } else {
                setMenu(false);
            }
        } else if (event.key === 'Tab') {
            const items = drawerFocusables();
            if (items.length === 0) {
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && (document.activeElement === first || !panel.contains(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !panel.contains(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        }
    });
        // Tabs "Menu | Sản phẩm" (WAI-ARIA tabs: the chosen tab is the one tab stop, arrows, Home and End move
    // between them and choose at once). The menu tab is the chosen one each time the drawer opens.
    const tabList = panel.querySelector('[data-drawer-tabs]');
    const tabs = tabList ? Array.from(tabList.querySelectorAll('[role="tab"]')) : [];

    chooseTab = (chosen, focus = false) => {
        tabs.forEach((tab) => {
            const on = tab === chosen;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !on;
        });
        if (focus) {
            chosen.focus();
        }
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => chooseTab(tab)));
    if (tabList) {
        tabList.addEventListener('keydown', (event) => {
            const at = tabs.indexOf(document.activeElement);
            const step = getComputedStyle(tabList).direction === 'rtl' ? -1 : 1;
            let to = null;
            if (event.key === 'ArrowRight') {
                to = (at + step + tabs.length) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                to = (at - step + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                to = 0;
            } else if (event.key === 'End') {
                to = tabs.length - 1;
            }
            if (to !== null && at !== -1) {
                event.preventDefault();
                chooseTab(tabs[to], true);
            }
        });
    }
    // A page restored from the back/forward cache never shows the menu open (nor leaves the page's scroll locked).
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            setMenu(false, false);
        }
    });
    // Language: four to seven fold a list open below the row; eight or more open a screen of their own with a search
    // that ignores case and diacritics and matches the name, the Vietnamese name and the code.
    if (langHead && langSub) {
        langSub.hidden = true;
        langHead.addEventListener('click', () => setLangScreen(true));
        langSub.querySelector('[data-lang-back]').addEventListener('click', () => setLangScreen(false));
        const fold = (text) => text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/\s+/g, ' ');
        const rows = Array.from(langSub.querySelectorAll('[data-lang-others] > li'), (li) => [
            li,
            fold(Array.from(li.querySelectorAll('.sf-lang-r__n, .sf-lang-r__g'), (el) => el.textContent).join(' ') + ' ' + li.firstElementChild.getAttribute('hreflang')),
        ]);
        const more = langSub.querySelector('[data-lang-more]');
        const none = langSub.querySelector('[data-lang-none]');
        langSub.querySelector('[data-lang-q]').addEventListener('input', (event) => {
            const query = fold(event.target.value.trim());
            let shown = 0;
            rows.forEach(([li, text]) => {
                li.hidden = !text.includes(query);
                shown += li.hidden ? 0 : 1;
            });
            more.hidden = shown === 0;
            // A live region that is always there, filled when nothing matches, so a screen reader announces it.
            none.textContent = shown === 0 ? none.dataset.text : '';
        });
    } else if (langHead) {
        langHead.addEventListener('click', () => langHead.setAttribute('aria-expanded', langHead.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'));
    }
    // Widening the window until the bar shows the full menu: the drawer has no place any more.
    window.addEventListener('resize', () => {
        if (!panel.hidden && burger.offsetParent === null) {
            setMenu(false, false);
        }
    });
}

// Text size: Vừa → Lớn → Cực lớn, kept in localStorage (the inline boot script in the head applies it before paint).
// Two controls set it: the top bar's cycling button (a header with no phone menu) and the phone menu's
// three radio cells; both show the size in force.
const sizeButtons = document.querySelectorAll('[data-fs-toggle]');
const sizeRadios = document.querySelectorAll('[data-fs-radio]');

function showSize(size) {
    sizeButtons.forEach((button) => {
        button.setAttribute('aria-label', button.getAttribute('data-aria-' + size));
        const value = button.querySelector('[data-fs-value]');
        if (value) {
            value.textContent = button.getAttribute('data-label-' + size);
        }
    });
    sizeRadios.forEach((radio) => {
        radio.checked = radio.value === size;
    });
}

function setSize(size) {
    if (size === 'm') {
        root.removeAttribute('data-fs');
    } else {
        root.setAttribute('data-fs', size);
    }
    try {
        localStorage.setItem('sf-fs', size);
    } catch {
        // Storage blocked (private mode): the size still applies to this page.
    }
    showSize(size);
}

if (sizeButtons.length > 0 || sizeRadios.length > 0) {
    const current = root.getAttribute('data-fs');
    showSize(SIZES.includes(current) ? current : 'm');

    sizeButtons.forEach((button) => button.addEventListener('click', () => {
        const now = root.getAttribute('data-fs') || 'm';
        setSize(SIZES[(SIZES.indexOf(now) + 1) % SIZES.length]);
    }));
    sizeRadios.forEach((radio) => radio.addEventListener('change', () => {
        if (radio.checked && SIZES.includes(radio.value)) {
            setSize(radio.value);
        }
    }));
}

// A lazy photo out of view (below the first screen, or further along a rail) that is not loaded yet
// waits transparent over its frame's tint and fades in once decoded (CSS `sf-img--wait` /
// `sf-img--in`); a photo already loaded, in view, hidden, or on a page that asked for reduced motion
// is left as it is. Without this script every photo simply shows when it arrives. The observer
// reports where each photo sits after the browser's own layout, so the script never makes the page
// lay out again just to measure it.
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
    const firstLook = new IntersectionObserver((entries) => {
        entries.forEach(({ target: img, isIntersecting, boundingClientRect: box }) => {
            firstLook.unobserve(img);
            if (img.complete || !box.width || isIntersecting) {
                return;
            }
            const show = () => img.classList.replace('sf-img--wait', 'sf-img--in');
            img.classList.add('sf-img--wait');
            img.addEventListener('load', () => img.decode().then(show, show), { once: true });
            img.addEventListener('error', show, { once: true });
        });
    });
    document.querySelectorAll('img[loading="lazy"]').forEach((img) => firstLook.observe(img));
}

// Floating contact: the links work with no script. A click on one with click tracking on announces
// `bs:contact-click` at once and counts the tap with a beacon (an app link can leave the page before
// anything else loads). Only the
// "Chép số" code is fetched, where the page has that button (data-fc-js): on the first touch of the
// widget, or once the page has loaded and gone idle on a computer (a phone never shows the button).
const fc = document.querySelector('[data-fc-root]');
if (fc) {
    const src = fc.dataset.fcJs;
    let copy;
    let ready;
    // Its address comes from the page (a Vite entry), so this file carries no loader of its own.
    const more = () => src && (copy ??= import(/* @vite-ignore */ src).then(() => (ready = 1), () => (copy = null)));
    fc.addEventListener('click', (event) => {
        const button = event.target.closest('[data-fc-copy]');
        if (button) {
            // Before the copy code has arrived, write while the click still counts as the visitor's.
            const wrote = ready ? 0 : navigator.clipboard?.writeText(button.closest('li').querySelector('a').getAttribute('href').slice(4)).then(() => 1, () => 0);
            // No copy code (the network failed): the click's own write still says "Đã chép" if it worked,
            // otherwise the number is selected, ready for Ctrl/Cmd+C.
            more().then((ok) => (ok ? document.dispatchEvent(new CustomEvent('bs:fc-copy', { detail: { button, wrote } })) : Promise.resolve(wrote).then((did) => {
                const [text, say] = ['.sf-fc__copy-t', '.sf-fc__sr'].map((q) => button.parentNode.querySelector(q));
                if (!did) {
                    return getSelection().selectAllChildren(button.parentNode.querySelector('.sf-fc__num'));
                }
                text.dataset.was ??= text.textContent;
                text.textContent = say.textContent = button.dataset.done;
                setTimeout(() => ((text.textContent = text.dataset.was), (say.textContent = '')), 2000);
            })));
            return;
        }
        const link = event.target.closest('[data-fc]');
        if (!link || document.getElementById(link.getAttribute('popovertarget'))?.matches(':popover-open')) {
            return; // the dial counts its opening only
        }
        document.dispatchEvent(new CustomEvent('bs:contact-click', { detail: {
            channel: link.dataset.fc,
            placement: link.dataset.fcAt,
            device: matchMedia('(max-width: 767.98px)').matches ? 'phone' : 'computer',
            page_path: location.pathname,
            link_type: link.dataset.fcLt,
        } }));
        // The site's own tally (/_click: channel and place only, no cookie), "Mua hàng" included; the
        // button that opens the "Liên hệ" list counts nothing.
        if (link.dataset.fc !== 'dial') {
            navigator.sendBeacon?.('/_click', JSON.stringify({ channel: link.dataset.fc, placement: link.dataset.fcAt }));
        }
    });
    if (src) {
        ['pointerover', 'focusin', 'touchstart'].forEach((type) => fc.addEventListener(type, more, { once: true, passive: true }));
        const idle = () => matchMedia('(min-width: 768px)').matches && (window.requestIdleCallback ?? ((f) => setTimeout(f, 3000)))(more);
        if (document.readyState === 'complete') {
            idle();
        } else {
            window.addEventListener('load', idle, { once: true });
        }
    }
}

// Cloudflare Web Analytics: the server prints the token as data; the beacon script is added once the page has
// loaded and the browser is idle, so it stays out of the load's main-thread work (a visit that ends sooner goes uncounted).
const beacon = document.querySelector('[data-sf-beacon]');
if (beacon) {
    const add = () => {
        const script = document.createElement('script');
        script.defer = true;
        script.src = beacon.dataset.src;
        script.dataset.cfBeacon = beacon.dataset.sfBeacon;
        document.body.append(script);
    };
    const idle = () => (window.requestIdleCallback ? requestIdleCallback(add, { timeout: 4000 }) : setTimeout(add, 2000));
    document.readyState === 'complete' ? idle() : addEventListener('load', idle, { once: true });
}

// Site search panel: every magnifier is a link to the search page that becomes the button opening the
// panel under the header. Focus goes to the field; Esc, the ✕ or a click on the dimmed page closes it
// and focus goes back to the button that opened it. The panel follows the header's bottom edge while
// open (a sticky header moves as the top bar scrolls away).
const searchPanel = document.querySelector('[data-search-panel]');
if (searchPanel) {
    const header = searchPanel.closest('header');
    const openers = document.querySelectorAll('[data-search-open]');
    let opener = null;
    const place = () => searchPanel.style.setProperty('--sf-spanel-top', `${Math.max(0, header.getBoundingClientRect().bottom)}px`);
    const setSearch = (open, from = null, returnFocus = true) => {
        if (open) {
            opener = from;
            place();
        }
        searchPanel.hidden = !open;
        openers.forEach((el) => el.setAttribute('aria-expanded', open && el === opener ? 'true' : 'false'));
        if (open) {
            searchPanel.querySelector('[data-search-input]').focus();
            addEventListener('scroll', place, { passive: true });
        } else {
            removeEventListener('scroll', place);
            if (returnFocus) {
                opener?.focus();
            }
        }
    };
    openers.forEach((el) => {
        el.setAttribute('role', 'button');
        el.setAttribute('aria-expanded', 'false');
        el.setAttribute('aria-haspopup', 'dialog');
        el.addEventListener('click', (event) => {
            event.preventDefault();
            setSearch(searchPanel.hidden, el);
        });
    });
    searchPanel.querySelectorAll('[data-search-close]').forEach((el) => el.addEventListener('click', () => setSearch(false)));
    // a click anywhere outside the box (the dimmed page or the header) closes it; so does moving focus out of it
    document.addEventListener('click', (event) => {
        if (!searchPanel.hidden && !event.target.closest('.sf-spanel__box, [data-search-open]')) {
            setSearch(false);
        }
    });
    searchPanel.addEventListener('focusout', (event) => {
        const to = event.relatedTarget;
        if (to && !searchPanel.contains(to) && !to.closest('[data-search-open]')) {
            setSearch(false, null, false);
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !searchPanel.hidden) {
            setSearch(false);
        }
    });
}

// Search results: "Xem thêm" is a link to the next rows of its group (it works without this script);
// here the next rows are fetched and added under the group's rows, and the link moves on.
document.addEventListener('click', (event) => {
    const more = event.target.closest('[data-search-more]');
    if (!more || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
        return;
    }
    event.preventDefault();
    const rows = more.closest('[data-search-group]').querySelector('[data-search-rows]');
    more.setAttribute('aria-busy', 'true');
    fetch(more.href, { headers: { Accept: 'text/html' } })
        .then((response) => (response.ok ? response.text() : Promise.reject(response.status)))
        .then((html) => {
            const page = new DOMParser().parseFromString(html, 'text/html');
            const group = page.querySelector('[data-search-group]');
            const added = group ? Array.from(group.querySelectorAll('[data-search-rows] > li')) : [];
            rows.append(...added);
            added[0]?.querySelector('a')?.focus();
            const next = group?.querySelector('[data-search-more]');
            next ? more.replaceWith(next) : more.remove();
        })
        .catch(() => {
            window.location.href = more.href;
        });
});
