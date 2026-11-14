// One view of this page or post for the site's "Lượt xem" count, sent by the browser once the page
// has loaded, so a copy served from the edge cache counts as well as one drawn by the server. The
// address comes from the page (<meta name="sf-view">); a browser without sendBeacon sends nothing.
// A page the browser prepared ahead of a click (a prerender, e.g. from the address bar) runs this
// script while still hidden: the view is sent only when the visitor is shown the page, and never
// when they do not open it.
const target = document.querySelector('meta[name="sf-view"]')?.content;

if (target && navigator.sendBeacon) {
    const send = () => navigator.sendBeacon(target);
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', send, { once: true });
    } else {
        send();
    }
}
