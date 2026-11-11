// Pure index and offset arithmetic of the sliders (no DOM), unit-tested in tests/js/sf-slider-math.test.mjs.

// Wraps a slide index into 0..len-1, both ways round.
export const wrap = (n, len) => ((n % len) + len) % len;

// Where a list rail scrolls to for one arrow press: Next at the end goes back to the start and Prev at
// the start goes to the end, otherwise one step along (the browser clamps the ends).
export function railTarget(dir, left, client, scroll, step) {
    const max = Math.max(0, scroll - client);
    if (dir > 0 && left >= max - 2) {
        return 0;
    }
    if (dir < 0 && left <= 2) {
        return max;
    }
    return Math.min(max, Math.max(0, left + dir * step));
}

// How many dots a rail shows (one per screenful) and which one is lit.
export const railPages = (client, scroll) => (client > 0 ? Math.max(1, Math.ceil((scroll - 2) / client)) : 1);
export function railDot(left, client, scroll) {
    const pages = railPages(client, scroll);
    const max = scroll - client;
    return pages < 2 || max <= 0 ? 0 : Math.min(pages - 1, Math.max(0, Math.round((left / max) * (pages - 1))));
}

// A horizontal swipe: at least `min` px along x and further along x than y. Returns -1 (towards the
// next slide: finger moved left), 1 (previous) or 0 (not a swipe).
export function swipe(dx, dy, min = 40) {
    return Math.abs(dx) >= min && Math.abs(dx) > Math.abs(dy) ? (dx < 0 ? -1 : 1) : 0;
}
