// One view of this page or post for the site's "Lượt xem" count, sent by the browser once the page
// has loaded, so a copy served from the edge cache counts as well as one drawn by the server. The
// address comes from the page (<meta name="sf-view">); a browser without sendBeacon sends nothing.
const target = document.querySelector('meta[name="sf-view"]')?.content;

if (target && navigator.sendBeacon) {
    navigator.sendBeacon(target);
}
