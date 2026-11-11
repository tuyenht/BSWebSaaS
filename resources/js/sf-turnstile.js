// Cloudflare's anti-spam check for the lead form, fetched only when a visitor starts on a form, so a page
// with a form no longer downloads it (about 1.7 MB) on arrival. A send before the check has answered
// waits for it, then goes on; if the check never answers, the form is sent anyway and the server
// decides, as it does without this script.
const API = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
const WAIT_MS = 15000;

let api;
const load = () => (api ??= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = API;
    s.async = true;
    s.onload = () => (window.turnstile ? resolve(window.turnstile) : reject());
    s.onerror = reject;
    document.head.append(s);
}));

// One check per form; it resolves when the first token arrives.
const checks = new WeakMap();
const prepare = (form) => {
    const box = form.querySelector('.cf-turnstile[data-sitekey]');
    if (!box) {
        return null;
    }
    if (!checks.has(form)) {
        checks.set(form, load().then((ts) => new Promise((resolve) => {
            ts.render(box, { sitekey: box.dataset.sitekey, appearance: box.dataset.appearance, callback: resolve });
        })));
    }
    return checks.get(form);
};

const formOf = (e) => e.target.closest?.('form:has(.cf-turnstile[data-sitekey])');
const start = (e) => {
    const form = formOf(e);
    if (form) {
        prepare(form)?.catch(() => {});
    }
};
document.addEventListener('focusin', start);
document.addEventListener('pointerdown', start);
// A page that came back with errors may have put the cursor in the form before this script ran.
if (document.activeElement && document.activeElement !== document.body) {
    start({ target: document.activeElement });
}

const waiting = new WeakSet();
const ready = new WeakSet();
document.addEventListener('submit', (e) => {
    const form = formOf(e);
    if (!form || ready.has(form)) {
        ready.delete(form);
        return;
    }
    if (form.querySelector('[name="cf-turnstile-response"]')?.value) {
        return;
    }
    e.preventDefault();
    if (waiting.has(form)) {
        return;
    }
    waiting.add(form);
    const submitter = e.submitter;
    submitter?.setAttribute('aria-busy', 'true');
    Promise.race([prepare(form), new Promise((r) => setTimeout(r, WAIT_MS))])
        .catch(() => {})
        // A form ignores requestSubmit() while its own submit event is still running, which is the case
        // when the check had already settled (blocked script, expired token): resend from a later task.
        .then(() => new Promise((r) => setTimeout(r)))
        .then(() => {
            waiting.delete(form);
            submitter?.removeAttribute('aria-busy');
            ready.add(form);
            if (form.requestSubmit) {
                form.requestSubmit(submitter?.form === form ? submitter : undefined);
            } else {
                form.submit();
            }
        });
});
