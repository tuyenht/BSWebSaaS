/*
 * Floating contact, "Chép số" on a computer: copies the call button's number and says "Đã chép" for
 * about two seconds (the button's text and a polite live region). Fetched by storefront.js after the
 * page has loaded, or on the first touch of the widget; storefront.js hands each click over with a
 * `bs:fc-copy` event once this file has run.
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
    const done = document.execCommand('copy');
    area.remove();
    back?.focus?.();
    return done ? Promise.resolve() : Promise.reject(new Error('copy'));
}

function copy(button) {
    const call = button.closest('.sf-fc__item')?.querySelector('a[href^="tel:"]');
    const label = button.querySelector('.sf-fc__copy-t');
    const status = button.parentElement.querySelector('[role="status"]');
    if (!call || !label) {
        return;
    }
    label.dataset.was ??= label.textContent;
    write(call.getAttribute('href').slice(4)).then(() => {
        label.textContent = button.dataset.done;
        if (status) {
            status.textContent = button.dataset.done;
        }
        clearTimeout(timers.get(button));
        timers.set(button, setTimeout(() => {
            label.textContent = label.dataset.was;
            if (status) {
                status.textContent = '';
            }
        }, 2000));
    }, () => {});
}

document.addEventListener('bs:fc-copy', (event) => copy(event.detail));
