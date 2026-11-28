// «Mẫu thư» editor on the Email settings screen (design c-email-settings, tab «Mẫu thư»),
// Alpine.data 'bsEmailTpl', loaded through the shared lazy loader (admin-lazy.js). The server holds
// the template (App\Modules\Admin\Livewire\EmailTemplates) and cleans it when saving
// (MailTemplateRenderer::clean, then RichTextSanitizer); this part is the editor around it: the
// subject and body boxes, the toolbar, the link / button box, the variables as chips, a plain
// paste, the preview with sample values, and the question before unsaved text is left.
//
// Both boxes are wire:ignore — Livewire never redraws them. The server redraws the whole editor
// (a new wire:key) when it must take the server's text again: opened, saved, put back, another
// language. Every chip is written back as its {variable} text, so the server stores only words;
// a paste is read in an inert document (DOMParser), never in the page.

import { guardLeave } from './admin-leave.js';

const BLOCKS = ['P', 'DIV', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'TABLE'];
const KEEP = ['B', 'STRONG', 'I', 'EM', 'U', 'A', 'BR', 'P', 'UL', 'OL', 'LI', 'SPAN', 'DIV'];
const ADDRESS = /^(https?:\/\/[^\s<>"]+|mailto:[^\s<>"]+|tel:[+0-9 .-]+)$/i;

window.Alpine.data('bsEmailTpl', (cfg) => {
    const tokenRe = new RegExp('(' + cfg.vars.map((v) => v.replace(/[{}]/g, '\\$&')).join('|') + ')', 'g');
    let off = null;
    let start = { subj: '', body: '' };

    return {
        mode: 'edit',
        dirty: false,
        busy: false,
        testing: false,
        copied: '',
        copyTimer: null,
        range: null,
        last: 'body',
        pv: cfg.preview ?? { subject: '', html: '' },
        pvState: cfg.preview ? 'ok' : 'wait',
        dlg: null,
        dlgText: '',
        dlgUrl: '',
        dlgError: '',
        dlgLink: null,
        guard: null,

        init() {
            if (!cfg.editable) {
                return;
            }
            const sep = (value) => {
                try {
                    document.execCommand('defaultParagraphSeparator', false, value);
                } catch (e) {
                    // an old browser without the command: its own line breaks
                }
            };
            this.$refs.body.addEventListener('focus', () => sep('p'));
            this.$refs.body.addEventListener('blur', () => sep('div'));
            this.$refs.subj.textContent = cfg.subject;
            this.chips(this.$refs.subj);
            this.$refs.body.innerHTML = cfg.body;
            this.chips(this.$refs.body);
            this.mark();
            start = { subj: this.subject(), body: this.serialize() };
            this.aria();
            this.$wire.$hook('morphed', () => this.aria());
            off = guardLeave({ dirty: () => this.dirty, ask: (goOn) => this.ask(cfg.texts.guardBack, goOn) });
        },

        destroy() {
            if (off) {
                off();
            }
            clearTimeout(this.copyTimer);
        },

        // The boxes are wire:ignore: their error state follows the messages Livewire draws below them.
        aria() {
            [
                ['subj', 'tpl-subj-err'],
                ['body', 'tpl-body-err'],
            ].forEach(([ref, id]) => {
                const el = this.$refs[ref];
                if (!el) {
                    return;
                }
                if (document.getElementById(id)) {
                    el.setAttribute('aria-invalid', 'true');
                    el.setAttribute('aria-describedby', id);
                } else {
                    el.removeAttribute('aria-invalid');
                    el.removeAttribute('aria-describedby');
                }
            });
        },

        // {variable} text becomes a chip (text nodes only: a token in a link's address stays text).
        chips(root) {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            const nodes = [];
            while (walker.nextNode()) {
                const n = walker.currentNode;
                tokenRe.lastIndex = 0;
                if (tokenRe.test(n.nodeValue) && !n.parentElement.closest('.em-var')) {
                    nodes.push(n);
                }
            }
            nodes.forEach((node) => {
                const frag = document.createDocumentFragment();
                node.nodeValue.split(tokenRe).forEach((part) => {
                    if (part !== '') {
                        frag.append(cfg.vars.includes(part) ? this.chip(part) : document.createTextNode(part));
                    }
                });
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

        // A paragraph holding only one link is the mail's button (MailTemplateRenderer): drawn so here.
        mark() {
            this.$refs.body.querySelectorAll('p').forEach((p) => {
                const links = p.querySelectorAll('a');
                const words = [...p.childNodes].filter((n) => !(n.nodeType === 1 && n.tagName === 'A') && (n.textContent ?? '').trim() !== '');
                p.classList.toggle('em-cta', links.length === 1 && p.children.length === 1 && words.length === 0);
                if (p.classList.length === 0) {
                    p.removeAttribute('class');
                }
            });
        },

        subject() {
            const d = this.$refs.subj.cloneNode(true);
            d.querySelectorAll('.em-var').forEach((s) => s.replaceWith(document.createTextNode(s.dataset.v ?? '')));
            return (d.textContent ?? '').replace(/\s+/g, ' ').trim();
        },

        // What the server stores: chips back to their {variable}, the editor's marks dropped, and the
        // browser's <div> lines as <p> (the server keeps a paragraph's alignment, not a div's).
        serialize() {
            const d = this.$refs.body.cloneNode(true);
            d.querySelectorAll('.em-var').forEach((s) => s.replaceWith(document.createTextNode(s.dataset.v ?? '')));
            d.querySelectorAll('[class]').forEach((n) => n.removeAttribute('class'));
            [...d.querySelectorAll('div')].reverse().forEach((div) => {
                const align = div.style.textAlign;
                if ([...div.children].some((c) => BLOCKS.includes(c.tagName))) {
                    [...div.children].forEach((c) => {
                        if (align && c.tagName === 'P' && !c.style.textAlign) {
                            c.style.textAlign = align;
                        }
                    });
                    div.replaceWith(...div.childNodes);
                    return;
                }
                const p = document.createElement('p');
                if (align) {
                    p.style.textAlign = align;
                }
                p.append(...div.childNodes);
                div.replaceWith(p);
            });
            const html = d.innerHTML.trim();
            return /^(<p>(<br>)?<\/p>|<br>)?$/i.test(html) ? '' : html;
        },

        changed() {
            this.mark();
            this.dirty = this.subject() !== start.subj || this.serialize() !== start.body;
            if (this.mode === 'preview') {
                this.refresh();
            }
        },

        focusIn(which) {
            this.last = which;
        },

        keep() {
            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) {
                return;
            }
            const r = sel.getRangeAt(0);
            ['body', 'subj'].forEach((which) => {
                if (this.$refs[which].contains(r.commonAncestorContainer)) {
                    this.range = r.cloneRange();
                    this.last = which;
                }
            });
        },

        restore(which = this.last) {
            const box = this.$refs[which];
            box.focus();
            const sel = window.getSelection();
            if (!sel) {
                return;
            }
            if (this.range && box.contains(this.range.commonAncestorContainer)) {
                sel.removeAllRanges();
                sel.addRange(this.range);
                return;
            }
            const r = document.createRange();
            r.selectNodeContents(box);
            r.collapse(false);
            sel.removeAllRanges();
            sel.addRange(r);
        },

        exec(name, value = null) {
            try {
                document.execCommand(name, false, value);
            } catch (e) {
                // an old browser without the command: nothing changes
            }
        },

        insert(html, which = this.last) {
            this.restore(which);
            this.exec('insertHTML', html);
            this.chips(this.$refs[which]);
            this.keep();
            this.changed();
        },

        // A toolbar button: a mark, an alignment, a list, or the link / button box.
        tool(name) {
            if (name === 'link' || name === 'button') {
                this.openDlg(name);
                return;
            }
            this.restore('body');
            this.exec(name);
            this.keep();
            this.changed();
        },

        variable(token) {
            const which = this.last === 'subj' ? 'subj' : 'body';
            this.insert(this.chip(token).outerHTML + '&nbsp;', which);
        },

        async copy(token) {
            try {
                await navigator.clipboard.writeText(token);
            } catch (e) {
                const t = document.createElement('textarea');
                t.value = token;
                document.body.append(t);
                t.select();
                this.exec('copy');
                t.remove();
            }
            this.copied = token;
            clearTimeout(this.copyTimer);
            this.copyTimer = setTimeout(() => {
                this.copied = '';
            }, 1500);
        },

        // ——— the link / button box ———
        openDlg(mode) {
            this.keep();
            this.restore('body');
            const sel = window.getSelection();
            const node = sel && sel.rangeCount ? sel.getRangeAt(0).commonAncestorContainer : null;
            const el = node ? (node.nodeType === 1 ? node : node.parentElement) : null;
            const a = el && this.$refs.body.contains(el) ? el.closest('a') : null;
            const inButton = !!(a && a.parentElement && a.parentElement.classList.contains('em-cta'));
            this.dlgLink = a;
            this.dlg = {
                mode: a ? (inButton ? 'button' : 'link') : mode,
                edit: !!a,
                needText: mode === 'button' || !!a || !sel || sel.isCollapsed,
            };
            this.dlgText = a ? (a.textContent ?? '') : sel && !sel.isCollapsed ? sel.toString() : '';
            this.dlgUrl = a ? (a.getAttribute('href') ?? '').replace(/%7B(\w+)%7D/gi, '{$1}') : (cfg.links[0] ?? '');
            this.dlgError = '';
            this.$nextTick(() => (this.dlg.needText && this.dlgText === '' ? this.$root.querySelector('#tpl-link-text') : this.$root.querySelector('#tpl-link-url'))?.focus());
        },

        closeDlg() {
            this.dlg = null;
            this.dlgLink = null;
            this.restore('body');
        },

        applyDlg() {
            const url = this.dlgUrl.trim();
            const text = this.dlgText.trim();
            if (!(cfg.links.includes(url) || ADDRESS.test(url))) {
                this.dlgError = cfg.texts.linkBad;
                this.$root.querySelector('#tpl-link-url')?.focus();
                return;
            }
            if (this.dlg.needText && text === '') {
                this.dlgError = cfg.texts.linkTextReq;
                this.$root.querySelector('#tpl-link-text')?.focus();
                return;
            }
            const a = document.createElement('a');
            a.setAttribute('href', url);
            a.textContent = this.dlg.needText ? text : '';
            const mode = this.dlg.mode;
            const existing = this.dlgLink;
            this.dlg = null;
            this.dlgLink = null;
            if (existing) {
                existing.setAttribute('href', url);
                existing.textContent = text;
                this.changed();
                return;
            }
            if (mode === 'button') {
                const p = document.createElement('p');
                p.append(a);
                this.insert(p.outerHTML, 'body');
                return;
            }
            if (a.textContent === '') {
                this.restore('body');
                this.exec('createLink', url);
                this.keep();
                this.changed();
                return;
            }
            this.insert(a.outerHTML, 'body');
        },

        removeLink() {
            const a = this.dlgLink;
            this.dlg = null;
            this.dlgLink = null;
            if (a) {
                const p = a.parentElement;
                a.replaceWith(document.createTextNode(a.textContent ?? ''));
                if (p) {
                    p.normalize();
                }
            }
            this.changed();
            this.restore('body');
        },

        // ——— paste: the words and the basic marks, never the source's styles ———
        paste(e) {
            if (!e.clipboardData) {
                return;
            }
            e.preventDefault();
            const html = e.clipboardData.getData('text/html');
            let out;
            if (html) {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                doc.body.querySelectorAll('style,script,meta,link,title,template,iframe,object,embed,svg,math').forEach((n) => n.remove());
                [...doc.body.querySelectorAll('*')].reverse().forEach((n) => {
                    [...n.attributes].forEach((at) => {
                        if (at.name !== 'href' || !ADDRESS.test(at.value)) {
                            n.removeAttribute(at.name);
                        }
                    });
                    if (!KEEP.includes(n.tagName)) {
                        n.replaceWith(doc.createTextNode(n.textContent ?? ''));
                    }
                });
                out = doc.body.innerHTML;
            } else {
                const t = document.createElement('div');
                t.textContent = e.clipboardData.getData('text/plain') ?? '';
                out = t.innerHTML.replace(/\n/g, '<br>');
            }
            this.insert(out, 'body');
        },

        pastePlain(e) {
            e.preventDefault();
            const t = document.createElement('div');
            t.textContent = (e.clipboardData?.getData('text/plain') ?? '').replace(/\s+/g, ' ');
            this.insert(t.innerHTML, 'subj');
        },

        // ——— preview, test, save ———
        setMode(m) {
            this.mode = m;
            if (m === 'preview') {
                this.refresh();
            }
        },

        async refresh() {
            if (!cfg.editable) {
                return;
            }
            this.pvState = 'wait';
            try {
                const r = await this.$wire.preview(this.subject(), this.serialize());
                this.pv = r && typeof r === 'object' ? r : { subject: '', html: '' };
                this.pvState = 'ok';
            } catch (e) {
                this.pvState = 'error';
            }
        },

        async test() {
            if (this.testing) {
                return;
            }
            this.testing = true;
            try {
                await this.$wire.sendTest(cfg.editable ? this.subject() : cfg.subject, cfg.editable ? this.serialize() : cfg.body);
            } finally {
                this.testing = false;
            }
        },

        async save() {
            if (this.busy) {
                return false;
            }
            this.busy = true;
            let ok = false;
            try {
                ok = (await this.$wire.save(this.subject(), this.serialize())) === true;
            } catch (e) {
                ok = false;
            }
            this.busy = false;
            if (ok) {
                this.dirty = false;
            } else {
                this.mode = 'edit';
                this.$nextTick(() => {
                    this.aria();
                    if (document.getElementById('tpl-subj-err')) {
                        this.$refs.subj.focus();
                    } else if (document.getElementById('tpl-body-err')) {
                        this.$refs.body.focus();
                    }
                });
            }
            return ok;
        },

        // ——— leaving with unsaved text ———
        ask(text, goOn) {
            if (!this.dirty) {
                goOn();
                return;
            }
            this.guard = { text, goOn };
        },

        leave(goOn) {
            this.ask(cfg.texts.guardBack, goOn);
        },

        pickLang(code) {
            this.ask(cfg.texts.guardLang, () => this.$wire.pickLang(code));
        },

        async guardSave() {
            const g = this.guard;
            if (!g || this.busy) {
                return;
            }
            const ok = await this.save();
            this.guard = null;
            if (ok) {
                g.goOn();
            }
        },

        guardDiscard() {
            const g = this.guard;
            this.guard = null;
            this.dirty = false;
            if (g) {
                g.goOn();
            }
        },
    };
});
