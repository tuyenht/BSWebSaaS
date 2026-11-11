// Tabs of an article list (the ARIA tabs pattern), loaded only on a page that has them. The server
// already printed every panel as plain content, so without this script the lists stack under their
// headings and nothing points at hidden content. Here the tab roles and their links are added, the
// bar is shown, all panels but the selected one are hidden, and the arrow keys, Home and End move
// between tabs (the tab in focus is the one selected).
// Filter buttons of the job look: one grid, the buttons only hide the cards of the other groups (the count
// line above is the whole list, it does not change). Without this script no button shows and every card stays.
for (const root of document.querySelectorAll('[data-sf-filter]')) {
    const chips = root.querySelector('[data-sf-chips]');
    const buttons = [...root.querySelectorAll('[data-sf-pick]')];
    const cards = [...root.querySelectorAll('[data-sf-group]')];
    if (!chips || buttons.length < 3) {
        continue;
    }
    buttons.forEach((button) => button.addEventListener('click', () => {
        const pick = button.dataset.sfPick;
        buttons.forEach((other) => other.setAttribute('aria-pressed', other === button ? 'true' : 'false'));
        cards.forEach((card) => { card.hidden = pick !== 'all' && card.dataset.sfGroup !== pick; });
    }));
    chips.hidden = false;
}

let blockNo = 0;
for (const root of document.querySelectorAll('[data-sf-tabs]')) {
    const tabs = [...root.querySelectorAll('[data-sf-tab]')];
    const panels = [...root.querySelectorAll('[data-sf-tabpanel]')];
    const bar = root.querySelector('.sf-tabs__bar');
    if (!bar || tabs.length < 2 || tabs.length !== panels.length) {
        continue;
    }

    blockNo += 1;
    bar.setAttribute('role', 'tablist');
    if (bar.dataset.label) {
        bar.setAttribute('aria-label', bar.dataset.label);
    }
    tabs.forEach((tab, i) => {
        tab.setAttribute('role', 'tab');
        tab.id = `sf-tab-${blockNo}-${i + 1}`;
        tab.setAttribute('aria-controls', `sf-tabpanel-${blockNo}-${i + 1}`);
        panels[i].setAttribute('role', 'tabpanel');
        panels[i].id = `sf-tabpanel-${blockNo}-${i + 1}`;
        panels[i].setAttribute('aria-labelledby', tab.id);
    });

    const select = (index, focus) => {
        tabs.forEach((tab, i) => {
            const on = i === index;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            panels[i].hidden = !on;
        });
        if (focus) {
            tabs[index].focus();
        }
    };

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => select(i, false));
        tab.addEventListener('keydown', (event) => {
            const last = tabs.length - 1;
            const to = { ArrowRight: i === last ? 0 : i + 1, ArrowLeft: i === 0 ? last : i - 1, Home: 0, End: last }[event.key];
            if (to !== undefined) {
                event.preventDefault();
                select(to, true);
            }
        });
    });

    bar.hidden = false;
    root.dataset.ready = '';
    select(0, false);
}
