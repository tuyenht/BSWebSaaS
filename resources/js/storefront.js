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
        if (item !== except) {
            setOpen(item, false);
        }
    });
}

document.querySelectorAll('[data-disclosure]').forEach((item) => {
    const toggle = toggleOf(item);
    if (!toggle) {
        return;
    }

    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
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

    item.addEventListener('focusout', (event) => {
        if (!item.contains(event.relatedTarget)) {
            setOpen(item, false);
        }
    });
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

function drawerFocusables() {
    return Array.from(panel.querySelectorAll('a[href], button:not([disabled])')).filter((el) => el.offsetParent !== null);
}

function setMenu(open, returnFocus = true) {
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? burger.dataset.labelClose : burger.dataset.labelOpen);
    panel.hidden = !open;
    root.classList.toggle('sf-menu-open', open);
    if (open) {
        const first = panel.querySelector('.sf-nav--mob a[href], .sf-nav--mob button') || panel.querySelector('[data-menu-close]');
        if (first) {
            first.focus();
        }
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
            setMenu(false);
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
    // Widening the window until the bar shows the full menu: the drawer has no place any more.
    window.addEventListener('resize', () => {
        if (!panel.hidden && burger.offsetParent === null) {
            setMenu(false, false);
        }
    });
}

// Text size: Vừa → Lớn → Cực lớn, kept in localStorage (the inline boot script in the head applies it before paint).
const sizeButtons = document.querySelectorAll('[data-fs-toggle]');

function showSize(size) {
    sizeButtons.forEach((button) => {
        button.setAttribute('aria-label', button.getAttribute('data-aria-' + size));
        const value = button.querySelector('[data-fs-value]');
        if (value) {
            value.textContent = button.getAttribute('data-label-' + size);
        }
    });
}

if (sizeButtons.length > 0) {
    const current = root.getAttribute('data-fs');
    showSize(SIZES.includes(current) ? current : 'm');

    sizeButtons.forEach((button) => button.addEventListener('click', () => {
        const now = root.getAttribute('data-fs') || 'm';
        const next = SIZES[(SIZES.indexOf(now) + 1) % SIZES.length];
        if (next === 'm') {
            root.removeAttribute('data-fs');
        } else {
            root.setAttribute('data-fs', next);
        }
        try {
            localStorage.setItem('sf-fs', next);
        } catch {
            // Storage blocked (private mode): the size still applies to this page.
        }
        showSize(next);
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

// Blocks below the first screen are drawn on demand (CSS `content-visibility: auto`, storefront.css),
// so the browser sizes the ones it has not drawn by an estimate, and a jump to a #target placed with
// those estimates lands off the target. The page therefore asks to be drawn in full (`sf-anchored`
// turns the deferral off) before such a jump: a click on a link to this page does it before the
// browser scrolls; an address opened with a #target draws everything and places the target again
// once photos and fonts have arrived, unless the reader has scrolled since. A reload or a step back
// or forward keeps the place the browser restores.
document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[href*="#"]') : null;
    if (link && link.origin === location.origin && link.pathname === location.pathname && link.hash.length > 1) {
        root.classList.add('sf-anchored');
    }
}, true);

function hashTarget() {
    let id = location.hash.slice(1);
    try {
        id = decodeURIComponent(id);
    } catch {
        // A malformed escape: use the text as it is.
    }

    return id === '' ? null : document.getElementById(id);
}

let placedAt = null;
function placeTarget() {
    const target = hashTarget();
    if (target) {
        root.classList.add('sf-anchored');
        target.scrollIntoView({ block: 'start', behavior: 'instant' });
        placedAt = window.scrollY;
    }
}

const arrival = performance.getEntriesByType('navigation')[0]?.type;
if (arrival === 'reload' || arrival === 'back_forward') {
    if (hashTarget()) {
        root.classList.add('sf-anchored');
    }
} else {
    placeTarget();
    window.addEventListener('load', () => { // photos and fonts that arrived since moved the blocks above
        if (placedAt !== null && Math.abs(window.scrollY - placedAt) < 2) {
            placeTarget();
        }
    }, { once: true });
}
// A #target typed into the address bar: the browser scrolled with the estimates. After a click (or a
// step through the history of this page) the blocks were already drawn in full, so its place stands.
window.addEventListener('hashchange', () => {
    if (!root.classList.contains('sf-anchored')) {
        placeTarget();
    }
});

// Floating contact: the links work with no script. A click on one with click tracking on announces
// `bs:contact-click` at once (an app link can leave the page before anything else loads); only the
// "Chép số" code is fetched, on the first touch of the widget or once the page has loaded and gone idle.
const fc = document.querySelector('[data-fc-root]');
if (fc) {
    let copy;
    // Its address comes from the page (a Vite entry), so this file carries no loader of its own.
    const more = () => (copy ??= import(/* @vite-ignore */ fc.dataset.fcJs).catch(() => (copy = null)));
    fc.addEventListener('click', (event) => {
        const copyButton = event.target.closest('[data-fc-copy]');
        if (copyButton) {
            more().then(() => document.dispatchEvent(new CustomEvent('bs:fc-copy', { detail: copyButton })));
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
    });
    ['pointerover', 'focusin', 'touchstart'].forEach((type) => fc.addEventListener(type, more, { once: true, passive: true }));
    const idle = () => (window.requestIdleCallback ?? ((f) => setTimeout(f, 3000)))(more);
    if (document.readyState === 'complete') {
        idle();
    } else {
        window.addEventListener('load', idle, { once: true });
    }
}
