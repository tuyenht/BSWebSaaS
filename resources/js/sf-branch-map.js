// The branches' map beside a form (the ARIA tabs pattern), loaded only on a page that draws it. The server
// already printed the first branch, its card and plain links, so without this script nothing is broken.
// Here the chooser is shown and given its roles; the arrow keys, Home and End move between its buttons and
// Enter or Space picks one (the map is heavy, so focus alone does not load it). A pick swaps the map's
// address and the card's text for the values printed on the button (data-*): no address is built here.
// Copy uses the clipboard, else a hidden text box, else says so; the outcome is announced politely.
let blockNo = 0;
for (const root of document.querySelectorAll('[data-sf-bmap]')) {
    const bar = root.querySelector('[data-sf-bmap-tabs]');
    const tabs = bar ? [...bar.querySelectorAll('[data-sf-bmap-tab]')] : [];
    const panel = root.querySelector('[data-sf-bmap-panel]');
    const frame = root.querySelector('[data-sf-bmap-frame]');
    const copy = root.querySelector('[data-sf-bmap-copy]');
    const status = root.querySelector('[data-sf-bmap-status]');
    const part = (name) => root.querySelector(`[data-sf-bmap-${name}]`);

    blockNo += 1;
    let address = tabs[0]?.dataset.addr ?? '';
    let timer = 0;

    const say = (text) => {
        if (status) {
            status.textContent = text;
        }
    };

    const show = (tab) => {
        const d = tab.dataset;
        frame.src = d.embed;
        frame.title = d.title;
        part('dir').href = d.dir;
        part('open').href = d.open;
        part('name').textContent = d.name;
        part('meta').textContent = d.meta;
        part('addr').textContent = d.addr;
        part('addr').hidden = !d.addr;
        part('approx').hidden = !d.approx;
        address = d.addr;
        if (copy) {
            copy.hidden = !address;
        }
    };

    if (tabs.length > 1 && panel && frame) {
        bar.setAttribute('role', 'tablist');
        bar.setAttribute('aria-label', bar.dataset.label ?? '');
        panel.setAttribute('role', 'tabpanel');
        panel.id = `sf-bmap-panel-${blockNo}`;
        let selected = 0;

        const mark = (focused) => {
            tabs.forEach((tab, i) => {
                tab.setAttribute('aria-selected', i === selected ? 'true' : 'false');
                tab.tabIndex = i === focused ? 0 : -1;
            });
        };

        tabs.forEach((tab, i) => {
            tab.id = `sf-bmap-tab-${blockNo}-${i + 1}`;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            tab.addEventListener('click', () => {
                selected = i;
                panel.setAttribute('aria-labelledby', tab.id);
                mark(i);
                show(tab);
                say('');
            });
            tab.addEventListener('keydown', (event) => {
                const last = tabs.length - 1;
                const to = {
                    ArrowRight: i === last ? 0 : i + 1,
                    ArrowDown: i === last ? 0 : i + 1,
                    ArrowLeft: i === 0 ? last : i - 1,
                    ArrowUp: i === 0 ? last : i - 1,
                    Home: 0,
                    End: last,
                }[event.key];
                if (to !== undefined) {
                    event.preventDefault();
                    mark(to);
                    tabs[to].focus();
                }
            });
        });
        // Leaving the chooser puts its one tab stop back on the chosen branch.
        bar.addEventListener('focusout', (event) => {
            if (!bar.contains(event.relatedTarget)) {
                mark(selected);
            }
        });

        panel.setAttribute('aria-labelledby', tabs[0].id);
        mark(0);
        bar.hidden = false;
    }

    if (copy) {
        const label = root.dataset.copyLabel ?? copy.textContent;
        const done = (text) => {
            copy.textContent = text;
            say(text);
            clearTimeout(timer);
            timer = setTimeout(() => {
                copy.textContent = label;
                say('');
            }, 2000);
        };
        const fallback = () => {
            const box = document.createElement('textarea');
            box.value = address;
            box.setAttribute('readonly', '');
            box.style.position = 'fixed';
            box.style.opacity = '0';
            document.body.appendChild(box);
            box.select();
            let ok = false;
            try {
                ok = document.execCommand('copy');
            } catch {
                ok = false;
            }
            document.body.removeChild(box);
            done(ok ? (root.dataset.copiedLabel ?? '') : (root.dataset.copyFailed ?? ''));
        };

        copy.addEventListener('click', () => {
            if (address === '') {
                return;
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(address).then(() => done(root.dataset.copiedLabel ?? ''), fallback);
            } else {
                fallback();
            }
        });
        copy.hidden = address === '';
    }
}
