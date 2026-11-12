// The admin's error pages and the frame's connection notices (design board 25 "Trang lỗi quản trị").
//
// 1. Alpine.data('bsSession') — partials/session-guard.blade.php, in every admin frame:
//    - the session ends after `lifetime` seconds without a request to the server. Every page load,
//      every Livewire answer and every "Tiếp tục làm việc" writes the time to localStorage, so work
//      in any tab of this admin counts. Two minutes before the end a dialog counts down; Esc or
//      "Tiếp tục làm việc" keeps the session (one small request), "Đăng xuất" signs out. At 0:00 the
//      dialog says the session ended and its button opens the sign-in, coming back here after.
//      Tab stays inside the dialog; focus goes back where it was when it closes.
//    - offline: the strip "Mất kết nối…" with "Kiểm tra lại"; back online "Đã kết nối lại." for 3s.
//    - a notice the server left for this page (an unknown address sent here) shows once as a toast.
// 2. Livewire: a 419 answer (the page's form token expired) opens "Phiên đã hết hạn" instead of
//    the browser's own "This page has expired" box.
// 3. Error pages (errors/partials/view): "Tải lại trang", the 429 countdown that keeps "Thử lại" off
//    until the wait is over, and "Sao chép mã" for the fault's reference.

const ACTIVITY_KEY = 'bs-admin-activity';
const WARN_SECONDS = 120;

function readActivity() {
    try {
        const at = Number(localStorage.getItem(ACTIVITY_KEY));
        return Number.isFinite(at) && at > 0 ? at : null;
    } catch {
        return null;
    }
}

function markActivity() {
    const now = Date.now();
    try {
        localStorage.setItem(ACTIVITY_KEY, String(now));
    } catch {
        // Private mode / blocked storage: this tab still keeps its own clock.
    }
    document.dispatchEvent(new CustomEvent('bs-session-activity', { detail: { at: now } }));
    return now;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function returnPath() {
    return window.location.pathname + window.location.search;
}

function expiredUrl(base) {
    return `${base}?to=${encodeURIComponent(returnPath())}`;
}

function focusables(root) {
    return Array.from(
        root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'),
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function sessionGuard(cfg) {
    return {
        open: false,
        ended: false,
        clock: '2:00',
        net: 'none',
        live: '',
        lastAt: Date.now(),
        _timer: null,
        _okTimer: null,
        _returnFocus: null,
        _busy: false,

        init() {
            this.lastAt = markActivity();

            const onActivity = (event) => this.seen(event.detail?.at ?? Date.now());
            const onStorage = (event) => {
                if (event.key === ACTIVITY_KEY) {
                    this.seen(readActivity() ?? Date.now());
                }
            };
            const onOffline = () => this.offline();
            const onOnline = () => this.online();
            document.addEventListener('bs-session-activity', onActivity);
            window.addEventListener('storage', onStorage);
            window.addEventListener('offline', onOffline);
            window.addEventListener('online', onOnline);

            if (cfg.warn) {
                this._timer = window.setInterval(() => this.tick(), 1000);
            }
            if (navigator.onLine === false) {
                this.net = 'off';
            }
            if (cfg.words?.notice && typeof window.bsToast === 'function') {
                window.bsToast({ text: cfg.words.notice });
            }

            this.destroy = () => {
                window.clearInterval(this._timer);
                window.clearTimeout(this._okTimer);
                document.removeEventListener('bs-session-activity', onActivity);
                window.removeEventListener('storage', onStorage);
                window.removeEventListener('offline', onOffline);
                window.removeEventListener('online', onOnline);
            };
        },

        // Activity here or in another tab: the countdown starts over and an open warning closes.
        seen(at) {
            if (at <= this.lastAt) {
                return;
            }
            this.lastAt = at;
            if (this.open && !this.ended) {
                this.close();
            }
        },

        tick() {
            const left = Math.ceil(cfg.lifetime - (Date.now() - this.lastAt) / 1000);
            if (left > WARN_SECONDS) {
                return;
            }
            const shown = Math.max(0, left);
            this.clock = `${Math.floor(shown / 60)}:${String(shown % 60).padStart(2, '0')}`;
            if (shown === 0 && !this.ended) {
                this.ended = true;
                window.clearInterval(this._timer);
            }
            if (!this.open) {
                this.show();
            }
        },

        show() {
            this._returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            this.open = true;
            this.$nextTick(() => this.$refs.dlg?.querySelector('[data-te-first]')?.focus());
        },

        close() {
            this.open = false;
            this.ended = false;
            const back = this._returnFocus;
            this._returnFocus = null;
            this.$nextTick(() => back?.isConnected && back.focus());
        },

        async extend() {
            if (this.ended || !cfg.ping) {
                window.location.assign(expiredUrl(cfg.expired));
                return;
            }
            if (this._busy) {
                return;
            }
            this._busy = true;
            try {
                const response = await fetch(cfg.ping, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (response.status === 401 || response.status === 419) {
                    this.ended = true;
                    window.clearInterval(this._timer);
                    return;
                }
                if (response.ok) {
                    this.lastAt = markActivity();
                    this.close();
                    this.announce(cfg.words?.extended);
                }
            } catch {
                this.offline();
            } finally {
                this._busy = false;
            }
        },

        logout() {
            this.announce(cfg.words?.logout);
            this.$refs.logoutForm?.submit();
        },

        onKey(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                if (!this.ended) {
                    this.extend();
                }
                return;
            }
            if (event.key !== 'Tab' || !this.$refs.dlg) {
                return;
            }
            const items = focusables(this.$refs.dlg);
            if (items.length === 0) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            } else if (!this.$refs.dlg.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        },

        offline() {
            window.clearTimeout(this._okTimer);
            this.net = 'off';
        },

        online() {
            if (this.net !== 'off') {
                return;
            }
            this.net = 'ok';
            this.announce(cfg.words?.back);
            window.clearTimeout(this._okTimer);
            this._okTimer = window.setTimeout(() => {
                this.net = 'none';
            }, 3000);
        },

        // "Kiểm tra lại": one small request; the strip stays (and says so) while the line is down.
        async recheck() {
            try {
                const response = await fetch(cfg.ping ?? window.location.href, {
                    method: cfg.ping ? 'POST' : 'HEAD',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (response.status < 500) {
                    if (response.ok) {
                        this.lastAt = markActivity();
                    }
                    this.online();
                    return;
                }
            } catch {
                // Still offline.
            }
            this.announce(cfg.words?.still);
        },

        announce(text) {
            if (!text) {
                return;
            }
            this.live = '';
            this.$nextTick(() => {
                this.live = text;
            });
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsSession', sessionGuard);
});

// A Livewire answer is a request the server counted; a 419 means the form token expired with the
// session — open "Phiên đã hết hạn" (which signs in again and comes back) instead of Livewire's box.
document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onResponse, onError }) => {
        onResponse(({ response }) => {
            if (response.status !== 419) {
                markActivity();
            }
        });
        onError(({ response, preventDefault }) => {
            if (response.status !== 419) {
                return;
            }
            const base = document.querySelector('meta[name="bs-session-expired"]')?.content;
            if (base) {
                preventDefault();
                window.location.assign(expiredUrl(base));
            }
        });
    });
});

// ── Error pages ─────────────────────────────────────────────────────────────────────────────────

function liveRegion() {
    return document.querySelector('[data-te-live]') ?? document.querySelector('.te-in [aria-live]');
}

function sayOnPage(text) {
    const region = liveRegion();
    if (!region || !text) {
        return;
    }
    region.textContent = '';
    window.setTimeout(() => {
        region.textContent = text;
    }, 50);
}

// 429: the line, the button's tooltip and its state follow the wait; at 0 "Thử lại" turns on.
function startCountdown(view) {
    let left = Math.max(0, Number(view.dataset.teRetry) || 0);
    const line = view.querySelector('[data-te-line]');
    const button = view.querySelector('[data-te-action="retry"]');
    const why = view.querySelector('[data-te-why]');
    const fill = (template) => (template ?? '').replace('__N__', String(left));

    const draw = () => {
        if (left > 0) {
            if (line) {
                line.textContent = fill(view.dataset.teWait);
            }
            if (button) {
                button.setAttribute('aria-disabled', 'true');
                button.title = fill(view.dataset.teBtnWait);
            }
            return;
        }
        if (line) {
            line.textContent = view.dataset.teDone ?? '';
        }
        if (button) {
            button.removeAttribute('aria-disabled');
            button.removeAttribute('aria-describedby');
            button.title = view.dataset.teBtnReady ?? '';
        }
        if (why) {
            why.hidden = true;
        }
        sayOnPage(view.dataset.teLiveDone);
    };

    draw();
    if (left === 0) {
        return;
    }
    const timer = window.setInterval(() => {
        left -= 1;
        draw();
        if (left <= 0 || !view.isConnected) {
            window.clearInterval(timer);
        }
    }, 1000);
}

async function copyCode(button) {
    const code = button.dataset.teCopy ?? '';
    let copied = false;
    try {
        await navigator.clipboard.writeText(code);
        copied = true;
    } catch {
        copied = false;
    }
    const text = copied ? button.dataset.teCopied : button.dataset.teCopyFailed;
    if (copied && typeof window.bsToast === 'function') {
        window.bsToast({ text });
    }
    sayOnPage(text);
}

function initErrorPages() {
    document.querySelectorAll('[data-te-page]').forEach((view) => {
        if (view.dataset.teReady === '1') {
            return;
        }
        view.dataset.teReady = '1';

        if (view.dataset.teRetry !== undefined) {
            startCountdown(view);
        }

        view.addEventListener('click', (event) => {
            const action = event.target.closest('[data-te-action]');
            if (action) {
                event.preventDefault();
                if (action.getAttribute('aria-disabled') === 'true') {
                    return;
                }
                window.location.reload();
                return;
            }
            const copy = event.target.closest('[data-te-copy]');
            if (copy) {
                event.preventDefault();
                copyCode(copy);
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initErrorPages);
} else {
    initErrorPages();
}
document.addEventListener('livewire:navigated', initErrorPages);
