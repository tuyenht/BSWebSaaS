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

// A looping rail holds three copies of its cards in a row: copies, the real cards, copies again. One copy
// is `period` px wide (the cards and their gaps), so the real cards start at scrollLeft = period. Where a
// settled scroll position is moved to, to land on the real cards again: the same view, one period over.
export function loopHome(left, period) {
    if (period <= 0) {
        return left;
    }
    if (left < period - 1) {
        return left + period;
    }
    return left >= 2 * period - 1 ? left - period : left;
}

// The lit dot of a looping rail: the position within the real cards (`span` px of cards, without the
// trailing gap), counted like a rail that does not loop.
export function loopDot(left, period, client, span) {
    if (period <= 0) {
        return 0;
    }
    // Measured widths carry fractions of a pixel: a view up to 2px short of a copy's start counts as at it.
    const inside = Math.max(0, ((((left - period + 2) % period) + period) % period) - 2);
    return railDot(Math.min(inside, Math.max(0, span - client)), client, span);
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
