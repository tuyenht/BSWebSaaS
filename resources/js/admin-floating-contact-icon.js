// A floating-contact channel's own icon picture on the "Nút liên hệ nổi" screen, loaded only when a
// member picks a file there (admin-floating-contact.js): the browser checks the file first (PNG or
// WebP, at most 50 KB, the same rule the server applies again), uploads it to the site's media library
// through the library's own upload (its content sniffing decides the real type), then hands the
// picture's ulid to the Livewire page (useIcon), which checks it once more and keeps it in the draft.

// The page's own token (read here rather than imported from the media library's upload script, so
// that script's code is not pulled in with this one).
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

export const ICON_TYPES = ['image/png', 'image/webp'];
export const ICON_MAX_BYTES = 51200;

/** Why the browser refuses a file before uploading it: 'type', 'size', or null when it may go. */
export function iconRefusal(file) {
    if (!file) {
        return null;
    }
    if (!ICON_TYPES.includes(file.type)) {
        return 'type';
    }
    if (file.size > ICON_MAX_BYTES) {
        return 'size';
    }

    return null;
}

/**
 * Upload the file to the media library. Resolves { ulid } or { error }: 'denied' (no right to add
 * pictures to the library), 'rejected' (the library refused the file) or 'upload' (anything else).
 */
export async function uploadIcon(file, url, { fetchFn = fetch, token = csrfToken() } = {}) {
    const body = new FormData();
    body.append('file', file, file.name);
    let res;
    try {
        res = await fetchFn(url, { method: 'POST', body, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token }, credentials: 'same-origin' });
    } catch {
        return { error: 'upload' };
    }
    if (res.status === 401 || res.status === 403) {
        return { error: 'denied' };
    }
    if (res.status === 422) {
        return { error: 'rejected' };
    }
    const data = res.ok ? await res.json().catch(() => null) : null;

    return data && typeof data.ulid === 'string' && data.ulid !== '' ? { ulid: data.ulid } : { error: 'upload' };
}

/**
 * The file input's change: check, upload, then tell the page. `state.busy` shows the button's busy
 * word until the page has the answer; the input is emptied so the same file can be picked again.
 */
export async function pickIcon(event, wire, key, url, state = {}, deps = {}) {
    const input = event && event.target;
    const file = input && input.files && input.files[0];
    if (input) {
        input.value = '';
    }
    if (!file || state.busy) {
        return;
    }
    const refused = iconRefusal(file);
    if (refused) {
        await wire.iconRefused(refused);
        return;
    }
    state.busy = true;
    try {
        const result = await uploadIcon(file, url, deps);
        if (result.ulid) {
            await wire.useIcon(key, result.ulid);
        } else {
            await wire.iconRefused(result.error);
        }
    } finally {
        state.busy = false;
    }
}
