/*
 * Floating contact, "Chép số" on a computer: copies the call button's number and says "Đã chép" for
 * about two seconds (the button's text and a polite live region). Fetched by storefront.js once the
 * page has loaded on a computer, or on the first touch of the widget; storefront.js hands each click
 * over with a `bs:fc-copy` event once this file has run ({button, wrote}: `wrote` says whether its own
 * write, made in the click before this file arrived, already succeeded). When no way of copying works,
 * the number is selected for Ctrl/Cmd+C and the button keeps saying "Chép số".
 */
const timers = new WeakMap();

function write(text) {
    // The clipboard API can refuse (an unfocused page, an older WebView): fall back to a hidden field.
    return navigator.clipboard?.writeText ? navigator.clipboard.writeText(text).catch(() => fallback(text)) : fallback(text);
}

function fallback(text) {
    const back = document.activeElement;
    const area = Object.assign(document.createElement('textarea'), { value: text, readOnly: true });
    area.style.cssText = 'position:fixed;opacity:0';
    document.body.append(area);
    area.select();
    let done = false;
    try {
        done = document.execCommand('copy');
    } catch {
        // an engine without the old command: done stays false
    }
    area.remove();
    back?.focus?.();
    return done ? Promise.resolve() : Promise.reject(new Error('copy'));
}

function say(button, label, status, text) {
    label.textContent = text;
    if (status) {
        status.textContent = text === label.dataset.was ? '' : text;
    }
}

function copy({ button, wrote }) {
    const call = button.closest('.sf-fc__item')?.querySelector('a[href^="tel:"]');
    const label = button.querySelector('.sf-fc__copy-t');
    const status = button.parentElement.querySelector('[role="status"]');
    if (!call || !label) {
        return;
    }
    label.dataset.was ??= label.textContent;
    clearTimeout(timers.get(button));
    Promise.resolve(wrote)
        .then((ok) => (ok ? 0 : write(call.getAttribute('href').slice(4))))
        .then(() => {
            say(button, label, status, button.dataset.done);
            timers.set(button, setTimeout(() => say(button, label, status, label.dataset.was), 2000));
        }, () => {
            // Nothing could copy: say nothing untrue, and leave the number selected for Ctrl/Cmd+C.
            say(button, label, status, label.dataset.was);
            const number = button.parentElement.querySelector('.sf-fc__num');
            if (number) {
                getSelection()?.selectAllChildren(number);
            }
        });
}

document.addEventListener('bs:fc-copy', (event) => copy(event.detail));
