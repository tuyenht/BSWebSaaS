// What the top bar's bell (admin-bell.js) and the "Thông báo" page (admin-notifications.js) share, on
// an Alpine part `ctx` with a Livewire $wire, a `failed` word ('' · 'load' · 'save') and a `retry`:
//  - followNotice: a notice's link. A plain click marks it read on the server, which then opens it;
//    Ctrl/Cmd/Shift/Alt-click or another button keeps the browser's own new tab or window (the notice
//    stays unread);
//  - catchLost: a lost request (no connection, a server error) of one of the part's actions sets
//    `failed` (the shared error alert shows) and keeps the same request in `retry`; a refusal or an
//    expired page (any 4xx) is left to Livewire; an answer clears both. Returns the stop function;
//  - tryAgain: "Thử lại" sends the kept request again (or `fallback` when there is none).

export function followNotice(ctx, e, ulid) {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) {
        return;
    }
    e.preventDefault();
    ctx.$wire.open(ulid);
}

// kindOf(actionName) → 'load' | 'save' | '' (an action the part leaves alone); done(action) runs
// after a successful answer.
export function catchLost(ctx, kindOf, done) {
    return ctx.$wire.$intercept(({ action, onError, onFailure, onSuccess }) => {
        const kind = kindOf(action.name);
        if (!kind) {
            return;
        }
        const fail = () => {
            ctx.failed = kind;
            ctx.retry = () => ctx.$wire[action.name](...(action.params || []));
        };
        onFailure(fail);
        onError(({ response, preventDefault }) => {
            // A refusal (403, 404, 419 …) is not a lost connection: Livewire shows its page.
            if (response && response.status < 500) {
                return;
            }
            preventDefault();
            fail();
        });
        onSuccess(() => {
            ctx.failed = '';
            ctx.retry = null;
            done?.(action);
        });
    });
}

export function tryAgain(ctx, fallback) {
    const run = ctx.retry || fallback;
    ctx.failed = '';
    ctx.retry = null;
    run?.();
}
