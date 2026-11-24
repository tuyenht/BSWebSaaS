// «Đăng nhập nhanh» (SocialSettings): the key form of one provider, on the platform face (the shared
// app: guide, values to copy, quick paste, «Kiểm tra & lưu») and on the website face (its own app:
// «Dùng khóa riêng»). The keys stay in the browser until an action sends them as its arguments, so a
// secret never travels back in the page's state. Loaded by the shared lazy loader (admin-lazy.js).
// `node --test tests/js/admin-social.test.mjs`.

const SHAPES = {
    google: { id: /\.apps\.googleusercontent\.com$/i, secret: /^GOCSPX-/ },
    facebook: { id: /^\d{13,18}$/, secret: /^[a-f0-9]{32}$/i },
};

// Which typed value is not in the provider's shape (an empty value is not judged).
export function badShape(provider, id, secret) {
    const s = SHAPES[provider] || SHAPES.google;

    return { id: id !== '' && !s.id.test(id), secret: secret !== '' && !s.secret.test(secret) };
}

// The keys found in what was pasted: a downloaded JSON file (Google's {web|installed: {client_id,
// client_secret}}, or a flat object with app_id/app_secret), else the provider's patterns in free text.
export function findKeys(provider, raw) {
    const t = (raw || '').trim();
    let id = '';
    let secret = '';
    if (!t) {
        return { id, secret };
    }
    try {
        const j = JSON.parse(t);
        const o = (j && (j.web || j.installed)) || j || {};
        id = String(o.client_id || o.app_id || '');
        secret = String(o.client_secret || o.app_secret || '');
    } catch {
        // not JSON: the patterns below
    }
    const pick = (re) => (t.match(re) || [''])[0];
    if (provider === 'google') {
        id = id || pick(/[0-9]{4,}-[a-z0-9._-]*\.apps\.googleusercontent\.com/i);
        secret = secret || pick(/GOCSPX-[A-Za-z0-9_-]{6,}/);
    } else {
        id = id || pick(/\b\d{13,18}\b/);
        secret = secret || pick(/\b[a-f0-9]{32}\b/i);
    }

    return { id, secret };
}

const fill = (text, values) => Object.keys(values).reduce((s, k) => s.split(':' + k).join(values[k]), text);

export function socialKeys(cfg) {
    const t = cfg.t || {};

    return {
        id: cfg.id || '',
        secret: '',
        saved: !!cfg.saved,
        replacing: false,
        guide: false,
        trouble: false,
        paste: '',
        pasteMsg: null,
        testing: false,
        server: { id: '', secret: '' },
        copiedKey: null,

        get shape() {
            return badShape(cfg.provider, this.id, this.secret);
        },
        get idError() {
            return this.server.id || (this.shape.id ? t.idInvalid : '');
        },
        get secretError() {
            return this.server.secret || (this.shape.secret ? t.secretInvalid : '');
        },
        // the secret field shows while none is saved, or after «Thay»
        get editingSecret() {
            return !this.saved || this.replacing;
        },
        get testDisabled() {
            return !this.id || (!this.secret && !this.saved) || this.testing || !!this.idError || !!this.secretError;
        },

        typedId(value) {
            this.id = String(value || '').trim();
            this.server.id = '';
        },
        typedSecret(value) {
            this.secret = String(value || '').trim();
            this.server.secret = '';
        },
        replace() {
            this.replacing = true;
            this.$nextTick(() => this.$refs.secret && this.$refs.secret.focus());
        },

        pasted(raw) {
            this.paste = raw;
            const k = findKeys(cfg.provider, raw);
            if (k.id && k.secret) {
                this.pasteMsg = { ok: true, text: t.pasteBoth };
            } else if (k.id || k.secret) {
                this.pasteMsg = { ok: false, text: fill(t.pasteOne, { label: k.id ? t.idLabel : t.secretLabel }) };
            } else {
                this.pasteMsg = String(raw || '').trim() ? { ok: false, text: t.pasteNone } : null;
            }
            if (k.id) {
                this.typedId(k.id);
            }
            if (k.secret) {
                this.typedSecret(k.secret);
                this.replacing = true;
            }
        },

        copy(key, value) {
            const done = () => {
                this.copiedKey = key;
                setTimeout(() => {
                    if (this.copiedKey === key) {
                        this.copiedKey = null;
                    }
                }, 1600);
            };
            const write = navigator.clipboard ? navigator.clipboard.writeText(value) : Promise.reject(new Error('no clipboard'));

            return write.then(done, () => this.$wire.copied(cfg.provider, 0));
        },
        copyAll() {
            const rows = cfg.rows || [];
            const text = rows.map((r) => r.label + ': ' + r.value).join('\n');

            return this.copy('all', text).then(() => {
                if (this.copiedKey === 'all') {
                    this.$wire.copied(cfg.provider, rows.length);
                }
            });
        },

        // after an answer, the first field in error takes the focus
        focusError() {
            this.$nextTick(() => {
                const el = this.idError ? this.$refs.id : this.secretError ? this.$refs.secret : null;
                if (el) {
                    el.focus();
                }
            });
        },

        // platform: «Kiểm tra & lưu»
        async test() {
            if (this.testDisabled) {
                return;
            }
            this.testing = true;
            try {
                const r = (await this.$wire.check(cfg.provider, this.id, this.secret)) || {};
                if (r.ok) {
                    this.secret = '';
                    this.saved = true;
                    this.replacing = false;
                    this.paste = '';
                    this.pasteMsg = null;
                    this.$dispatch('bs-social-saved', { provider: cfg.provider });
                } else {
                    this.server = { id: r.id || '', secret: r.secret || '' };
                    this.focusError();
                }
            } finally {
                this.testing = false;
            }
        },

        // website: «Dùng khóa riêng»
        async own() {
            if (await this.$wire.useOwn(cfg.provider, this.id, this.secret)) {
                if (this.secret) {
                    this.saved = true;
                }
                this.secret = '';
                this.replacing = false;
            }
        },
    };
}

if (typeof window !== 'undefined' && window.Alpine) {
    window.Alpine.data('bsSocialKeys', socialKeys);
}
