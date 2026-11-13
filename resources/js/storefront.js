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
