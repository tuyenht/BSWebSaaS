// «Chữ ký email» on the Email settings screen (design c-email-settings, tab «Chữ ký & thương hiệu»),
// Alpine.data 'bsEmailSig', loaded through the shared lazy loader (admin-lazy.js). The server holds
// the signature (App\Modules\Admin\Livewire\EmailSettings: sigLayout, sigHtml — '' is the layout's
// own) and cleans it when saving (RichTextSanitizer); this part is the editor around it: the
// toolbar, the website's variables as chips, a plain paste, and what each layout held while the
// member switched between them on this page.
//
// The editor is wire:ignore — Livewire never redraws it. It is filled from the server at the start
// and when the server sends `email-signature-load` (after a save, «Bỏ thay đổi» or «Về mặc định»).
// Every chip is written back as its {variable} text, so the server stores only tokens; values
// (names, addresses) are only ever set as text.

const VAR_RE = /\{(site_name|site_tagline|hotline|contact_email|address|site_url)\}/g;
const KEEP = ['B', 'STRONG', 'I', 'EM', 'U', 'A', 'BR', 'P', 'UL', 'OL', 'LI', 'SPAN', 'DIV'];

window.Alpine.data('bsEmailSig', (cfg) => ({
    layout: cfg.layout,
    // What each layout showed when the member left it on this page ('' = its own).
    mem: {},
    range: null,
    pop: '', // 'link' | 'color' | 'social' | ''
    linkUrl: '',
    linkError: '',
    sendTimer: null,

    init() {
        this.fill(cfg.html);
        window.addEventListener('email-signature-load', (e) => {
            const d = e.detail ?? {};
            clearTimeout(this.sendTimer);
            this.layout = d.layout ?? this.layout;
            if (d.saved) {
                // Saved or put back as saved: what the layouts held on this page is gone with it.
                this.mem = {};
                cfg.savedLayout = this.layout;
                cfg.savedHtml = d.html ?? '';
            }
            this.fill(d.html ?? '');
        });
        window.addEventListener('email-signature-image', (e) => {
            const d = e.detail ?? {};
            if (typeof d.url !== 'string' || d.url === '') {
                return;
            }
            const img = document.createElement('img');
            img.src = d.url;
            img.alt = typeof d.alt === 'string' ? d.alt : '';
            img.style.maxWidth = '200px';
            this.insert(img.outerHTML);
        });
    },

    // The editor shows `html`, or the layout's own signature for ''.
    fill(html) {
        const ed = this.$refs.ed;
        ed.innerHTML = html !== '' ? html : cfg.defaults[this.layout] ?? '';
        this.chips(ed);
    },

    // {variable} text becomes a chip (text nodes only: a token inside a link's address stays text).
    chips(root) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const nodes = [];
        while (walker.nextNode()) {
            if (VAR_RE.test(walker.currentNode.nodeValue) && !walker.currentNode.parentElement.closest('.em-var')) {
                nodes.push(walker.currentNode);
            }
            VAR_RE.lastIndex = 0;
        }
        nodes.forEach((node) => {
            const frag = document.createDocumentFragment();
            let last = 0;
            node.nodeValue.replace(VAR_RE, (token, name, at) => {
                frag.append(node.nodeValue.slice(last, at), this.chip(token));
                last = at + token.length;
                return token;
            });
            frag.append(node.nodeValue.slice(last));
            node.replaceWith(frag);
        });
    },

    chip(token) {
        const s = document.createElement('span');
        s.className = 'em-var';
        s.contentEditable = 'false';
        s.dataset.v = token;
        s.textContent = token;
        return s;
    },

    // What the server stores: chips back to their {variable}, the editor's own marks dropped.
    serialize() {
        const d = this.$refs.ed.cloneNode(true);
        d.querySelectorAll('.em-var').forEach((s) => s.replaceWith(document.createTextNode(s.dataset.v ?? '')));
        const html = d.innerHTML.trim();
        return /^(<p>(<br>)?<\/p>|<br>)?$/i.test(html) ? '' : html;
    },

    // Typing: the save bar learns of it a moment after the typing stops, or when the editor is left.
    changed(now = false) {
        clearTimeout(this.sendTimer);
        const send = () => this.$wire.$set('sigHtml', this.html(), true);
        if (now) {
            send();
        } else {
            this.sendTimer = setTimeout(send, 500);
        }
    },

    // The editor's signature as the server reads it: '' while it still shows the layout's own.
    html() {
        const html = this.serialize();
        const tmp = document.createElement('div');
        tmp.innerHTML = cfg.defaults[this.layout] ?? '';
        return html === tmp.innerHTML.trim() ? '' : html;
    },

    pickLayout(layout) {
        if (layout === this.layout) {
            return;
        }
        clearTimeout(this.sendTimer);
        this.mem[this.layout] = this.html();
        this.layout = layout;
        const html = this.mem[layout] ?? (layout === cfg.savedLayout ? cfg.savedHtml : '');
        this.fill(html);
        this.$wire.setSigLayout(layout, html);
    },

    // Toolbar buttons keep the caret in the editor (mousedown.prevent); popovers remember it.
    keep() {
        const sel = window.getSelection();
        if (sel && sel.rangeCount > 0 && this.$refs.ed.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            this.range = sel.getRangeAt(0).cloneRange();
        }
    },

    restore() {
        const ed = this.$refs.ed;
        ed.focus();
        const sel = window.getSelection();
        if (this.range && sel) {
            sel.removeAllRanges();
            sel.addRange(this.range);
        } else if (sel) {
            const r = document.createRange();
            r.selectNodeContents(ed);
            r.collapse(false);
            sel.removeAllRanges();
            sel.addRange(r);
        }
    },

    cmd(name) {
        this.restore();
        try {
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand(name, false, null);
        } catch (e) {
            // An old browser without the command: nothing changes.
        }
        this.keep();
        this.changed();
    },

    insert(html) {
        this.restore();
        try {
            document.execCommand('insertHTML', false, html);
        } catch (e) {
            this.$refs.ed.insertAdjacentHTML('beforeend', html);
        }
        this.chips(this.$refs.ed);
        this.keep();
        this.changed();
    },

    hr() {
        this.insert('<hr>');
    },

    variable(token) {
        this.insert(this.chip(token).outerHTML + '&nbsp;');
    },

    toggle(name) {
        this.keep();
        this.pop = this.pop === name ? '' : name;
        this.linkError = '';
        if (this.pop === 'link') {
            const a = this.range?.commonAncestorContainer?.parentElement?.closest('a');
            this.linkUrl = a ? a.getAttribute('href') ?? '' : '';
            this.$nextTick(() => this.$refs.linkInput?.focus());
        }
    },

    close() {
        this.pop = '';
        this.restore();
    },

    link() {
        const url = this.linkUrl.trim();
        if (!/^(https?:\/\/[^\s]+|mailto:[^\s]+|tel:[+0-9 .-]+|\{site_url\})$/i.test(url)) {
            this.linkError = cfg.texts.linkBad;
            this.$refs.linkInput?.focus();
            return;
        }
        this.pop = '';
        this.restore();
        const sel = window.getSelection();
        if (sel && sel.isCollapsed) {
            const a = document.createElement('a');
            a.href = url;
            a.textContent = url;
            this.insert(a.outerHTML);
            return;
        }
        try {
            document.execCommand('createLink', false, url);
        } catch (e) {
            // see cmd()
        }
        this.keep();
        this.changed();
    },

    color(hex) {
        this.pop = '';
        this.restore();
        try {
            document.execCommand('styleWithCSS', false, true);
            document.execCommand('foreColor', false, hex);
            document.execCommand('styleWithCSS', false, false);
        } catch (e) {
            // see cmd()
        }
        this.keep();
        this.changed();
    },

    social(item) {
        this.pop = '';
        const a = document.createElement('a');
        a.href = item.url;
        a.textContent = item.name;
        this.insert(a.outerHTML + '&nbsp;');
    },

    // A paste keeps the words and the basic marks (the board's rule), never the source's styles.
    paste(e) {
        if (!e.clipboardData) {
            return;
        }
        e.preventDefault();
        const html = e.clipboardData.getData('text/html');
        let out = '';
        if (html) {
            const d = document.createElement('div');
            d.innerHTML = html;
            d.querySelectorAll('style,script,meta,link,title').forEach((n) => n.remove());
            [...d.querySelectorAll('*')].reverse().forEach((n) => {
                [...n.attributes].forEach((a) => {
                    if (a.name !== 'href' || !/^(https?:|mailto:|tel:)/i.test(a.value)) {
                        n.removeAttribute(a.name);
                    }
                });
                if (!KEEP.includes(n.tagName)) {
                    n.replaceWith(document.createTextNode(n.textContent ?? ''));
                }
            });
            out = d.innerHTML;
        } else {
            const t = document.createElement('div');
            t.textContent = e.clipboardData.getData('text/plain') ?? '';
            out = t.innerHTML.replace(/\n/g, '<br>');
        }
        this.keep();
        this.insert(out);
    },
}));
