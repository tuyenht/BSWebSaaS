// Admin entry bundle. The only heavyweight admin feature is the DAM uploader (Uppy), so it is
// lazy-loaded as a separate chunk and mounted ONLY on the media library page — every other admin
// page ships none of it. Re-runs on each Livewire SPA navigation (`livewire:navigated` fires on the
// initial page load too), tearing down a leftover instance from a previous visit first.

let uploader = null;

function teardown() {
    if (uploader) {
        // Uppy v4 teardown is destroy(); guard for the older close() alias.
        (uploader.destroy ?? uploader.close)?.call(uploader);
        uploader = null;
    }
}

async function mountMediaUploader() {
    teardown();

    const el = document.querySelector('[data-media-uploader]');
    if (!el) {
        return; // not on the media library page
    }

    const { createMediaUploader } = await import('./media-uppy.js');
    uploader = createMediaUploader(el);
}

document.addEventListener('livewire:navigated', mountMediaUploader);

// Release any modal scroll-lock before a wire:navigate swap, so an open modal that morphs
// away can never leave the body scroll-locked.
document.addEventListener('livewire:navigating', () => {
    document.body.style.overflow = '';
});

// ── Theme Customizer store ───────────────────────────────────────────────────
// Global Alpine store (registered on the Livewire-bundled Alpine via alpine:init —
// never `import Alpine`) so the launcher state survives wire:navigate morphs.
// A toggle applies instantly to <html>, is buffered in localStorage as a PENDING
// write (theme-boot re-applies it pre-paint if the user reloads mid-save), then
// persists per-user through the plane's endpoint; the buffer clears once saved.
// The DB stays the source of truth — only unsaved changes ever live client-side.

const THEME_PENDING_KEY = 'bs-admin-theme-pending';

function themeConfig() {
    return window.__adminTheme ?? { axes: {}, saveUrl: null, resetUrl: null };
}

function readPendingTheme() {
    try {
        const pending = JSON.parse(localStorage.getItem(THEME_PENDING_KEY) || '{}');
        return pending && typeof pending === 'object' ? pending : {};
    } catch {
        return {};
    }
}

function writePendingTheme(pending) {
    if (Object.keys(pending).length) {
        localStorage.setItem(THEME_PENDING_KEY, JSON.stringify(pending));
    } else {
        localStorage.removeItem(THEME_PENDING_KEY);
    }
}

function readThemeFromHtml() {
    const state = {};
    const { axes } = themeConfig();
    for (const [field, spec] of Object.entries(axes)) {
        const value = document.documentElement.getAttribute(spec.attr);
        state[field] = spec.values.includes(value) ? value : spec.values[0];
    }
    return state;
}

function applyThemeAttributes(attributes) {
    const { axes } = themeConfig();
    for (const spec of Object.values(axes)) {
        if (typeof attributes[spec.attr] === 'string') {
            document.documentElement.setAttribute(spec.attr, attributes[spec.attr]);
        }
    }
}

async function postTheme(url, method, body) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    return fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.store('adminTheme', {
        open: false,
        state: readThemeFromHtml(),
        _queue: Promise.resolve(),

        set(field, value) {
            const { axes } = themeConfig();
            const spec = axes[field];
            if (!spec || !spec.values.includes(value)) {
                return;
            }
            this.state[field] = value;
            document.documentElement.setAttribute(spec.attr, value);

            const pending = readPendingTheme();
            pending[field] = value;
            writePendingTheme(pending);
            // Send the WHOLE buffer, not just this field: an axis whose earlier save
            // failed rides along on the next successful request instead of being lost.
            this._persist(pending);
        },

        reset() {
            const { resetUrl } = themeConfig();
            localStorage.removeItem(THEME_PENDING_KEY);
            this._enqueue(async () => {
                const response = await postTheme(resetUrl, 'DELETE');
                if (response.ok) {
                    const { attributes } = await response.json();
                    applyThemeAttributes(attributes);
                    this.state = readThemeFromHtml();
                }
            });
        },

        retryPending() {
            const pending = readPendingTheme();
            if (Object.keys(pending).length) {
                this._persist(pending);
            }
        },

        // One in-flight save at a time: two quick toggles serialize instead of racing
        // last-write-wins over the whole preference blob.
        _persist(fields) {
            const { saveUrl, axes } = themeConfig();
            this._enqueue(async () => {
                const response = await postTheme(saveUrl, 'POST', fields);
                if (!response.ok && response.status !== 422) {
                    return; // network/5xx: keep the buffer; retryPending() retries next load
                }

                // Settle ONLY what this request carried — a toggle made while it was in
                // flight re-buffered a newer value and must survive for the next request.
                const pending = readPendingTheme();
                for (const [field, value] of Object.entries(fields)) {
                    if (pending[field] === value || response.status === 422) {
                        delete pending[field];
                    }
                }
                writePendingTheme(pending);

                if (response.ok) {
                    // Adopt the server's resolved attributes (heals site-default drift),
                    // except on axes that still have a newer unsaved change.
                    const { attributes } = await response.json();
                    for (const [field, spec] of Object.entries(axes)) {
                        if (!(field in pending) && typeof attributes[spec.attr] === 'string') {
                            document.documentElement.setAttribute(spec.attr, attributes[spec.attr]);
                        }
                    }
                    this.state = readThemeFromHtml();
                }
            });
        },

        _enqueue(task) {
            this._queue = this._queue.then(task).catch(() => {
                /* keep the queue alive after a network failure */
            });
        },
    });

    window.Alpine.store('adminTheme').retryPending();
});

// The Appearance Settings page saved a new SITE default — repaint this tab immediately
// (the resolver already folded the user's own override back in).
document.addEventListener('admin-chrome-changed', (event) => {
    const attributes = event.detail?.attributes ?? event.detail?.[0]?.attributes;
    if (attributes) {
        applyThemeAttributes(attributes);
        const store = window.Alpine?.store('adminTheme');
        if (store) {
            store.state = readThemeFromHtml();
        }
    }
});
