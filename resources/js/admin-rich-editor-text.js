// The writing frame's pure rules, kept apart from the editor engine so they can be tested
// without a browser (tests/js/admin-rich-editor-text.test.mjs).

const LINK_OK = /^https?:\/\/\S+\.\S+$/;

/** Reading time in whole minutes at 200 words a minute, never under one. */
export function readMinutes(words) {
    return Math.max(1, Math.round((Number(words) || 0) / 200));
}

/** Words in plain text, as the footer counts them. */
export function countWords(text) {
    return String(text || '').split(/\s+/u).filter((word) => word !== '').length;
}

/** The link dialog's verdict on an address: 'empty' (nothing typed yet), 'good' or 'bad'. */
export function linkVerdict(value) {
    const v = String(value || '').trim();
    if (v === '' || v === 'https://') {
        return 'empty';
    }

    return LINK_OK.test(v) ? 'good' : 'bad';
}

const STYLED_INLINE = 'strong[style],em[style],b[style],i[style],u[style],s[style],sub[style],sup[style],a[style]';

/**
 * A colour or size picked on bold, italic or linked text lands on that element's own `style`;
 * the save keeps inline styles only on <span> (and <p>, table cells), so they would be dropped.
 * Move each such style onto a <span> inside the element: same look, and it survives the save.
 */
export function moveInlineStyles(html, doc = globalThis.document) {
    if (!/<(?:strong|em|b|i|u|s|sub|sup|a)\b[^>]*\sstyle=/i.test(html)) {
        return html;
    }
    const tpl = doc.createElement('template');
    tpl.innerHTML = html;
    for (const el of tpl.content.querySelectorAll(STYLED_INLINE)) {
        const span = doc.createElement('span');
        span.setAttribute('style', el.getAttribute('style'));
        el.removeAttribute('style');
        while (el.firstChild) {
            span.appendChild(el.firstChild);
        }
        el.appendChild(span);
    }

    return tpl.innerHTML;
}
