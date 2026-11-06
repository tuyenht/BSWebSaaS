// Tabs of an article list (the ARIA tabs pattern), loaded only on a page that has them. The server
// already printed every panel, so without this script the lists stack under their headings. Here the
// bar is shown, all panels but the selected one are hidden, and the arrow keys, Home and End move
// between tabs (the tab in focus is the one selected).
for (const root of document.querySelectorAll('[data-sf-tabs]')) {
    const tabs = [...root.querySelectorAll('[data-sf-tab]')];
    const panels = [...root.querySelectorAll('[data-sf-tabpanel]')];
    if (tabs.length < 2 || tabs.length !== panels.length) {
        continue;
    }

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

    root.querySelector('[role="tablist"]').hidden = false;
    root.dataset.ready = '';
    select(0, false);
}
