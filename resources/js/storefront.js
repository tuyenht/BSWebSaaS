/*
 * Storefront header behaviour, no framework: submenus as disclosures (button + panel, no
 * role=menu), the mobile menu panel and the text-size button. Everything here is an addition —
 * without it the submenus still open on hover and keyboard focus (CSS), and the menu panel is
 * shown open (noscript style).
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

// Mobile menu panel.
const burger = document.querySelector('[data-menu-toggle]');
const panel = burger ? document.getElementById(burger.getAttribute('aria-controls')) : null;

function setMenu(open) {
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? burger.dataset.labelClose : burger.dataset.labelOpen);
    panel.hidden = !open;
}

if (burger && panel) {
    burger.addEventListener('click', () => setMenu(burger.getAttribute('aria-expanded') !== 'true'));
    panel.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            setMenu(false);
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) {
            setMenu(false);
            burger.focus();
        }
    });
}

// Text size: Vừa → Lớn → Cực lớn, kept in localStorage (fs-boot.js applies it before paint).
const sizeButton = document.querySelector('[data-fs-toggle]');

function showSize(size) {
    sizeButton.setAttribute('aria-label', sizeButton.getAttribute('data-aria-' + size));
    const value = sizeButton.querySelector('[data-fs-value]');
    if (value) {
        value.textContent = sizeButton.getAttribute('data-label-' + size);
    }
}

if (sizeButton) {
    const current = root.getAttribute('data-fs');
    showSize(SIZES.includes(current) ? current : 'm');

    sizeButton.addEventListener('click', () => {
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
    });
}
