// The page builder's preview frame (admin.entries.builder-preview): runs only in a page that names the
// frame's nonce (<meta name="bs-pb">) inside a frame of the same origin, and speaks only to that parent,
// with the nonce in every message (the editor drops anything else). The editor cannot read the status
// of a form it sent into a frame, so a refused preview is a small page naming its code
// (<meta name="bs-pb-error">, with the seconds to wait for a 429): this passes it on as `error`.
const nonce = document.querySelector('meta[name="bs-pb"]')?.content;
const error = document.querySelector('meta[name="bs-pb-error"]');

if (nonce && window.parent !== window && error) {
    const retry = Number.parseInt(error.dataset.retry ?? '', 10);
    window.parent.postMessage(
        { type: 'error', nonce, code: Number.parseInt(error.content, 10), retry: Number.isNaN(retry) ? 0 : retry },
        window.location.origin,
    );
}
