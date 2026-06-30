// DAM uploader (lazy chunk, loaded only on the media library page — see app.js).
//
// Flow (R2/S3 disk): browser asks the server for a short-lived presigned PUT (admin.media.presign),
// PUTs the bytes STRAIGHT to R2 (no PHP upload limit / Livewire-temp / reverse-proxy), then asks the
// server to finalize the row (admin.media.register — the REAL gate: magic-byte sniff + size + hash +
// dedup). On a `local` disk (dev) it falls back to a normal multipart POST through Laravel
// (admin.media.upload). On a finished batch it dispatches `media-uploaded` so the Livewire grid
// refreshes. presign/register/upload are same-origin and CSRF-protected (X-CSRF-TOKEN from the
// <meta> tag); the cross-origin PUT to R2 carries only the signed headers, never cookies/CSRF.

import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import AwsS3 from '@uppy/aws-s3';
import XHRUpload from '@uppy/xhr-upload';
import Vietnamese from '@uppy/locales/lib/vi_VN';

import '@uppy/core/css/style.min.css';
import '@uppy/dashboard/css/style.min.css';

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Read an image's pixel dimensions client-side (best-effort) so register can persist width/height
 * and the grid can reserve space (CLS). Resolves {width, height} or null (non-image / unreadable).
 */
async function imageDimensions(file) {
    if (!file.type?.startsWith('image/') || typeof createImageBitmap !== 'function') {
        return null;
    }
    try {
        const bitmap = await createImageBitmap(file.data);
        const dims = { width: bitmap.width, height: bitmap.height };
        bitmap.close?.();
        return dims;
    } catch {
        return null;
    }
}

export function createMediaUploader(el) {
    const cfg = el.dataset;
    const directToStorage = cfg.disk !== 'local';
    const maxBytes = Number(cfg.maxBytes) || null;
    const allowed = (cfg.allowed || '').split(',').filter(Boolean);

    const uppy = new Uppy({
        locale: Vietnamese,
        autoProceed: true,
        restrictions: {
            maxFileSize: maxBytes,
            allowedFileTypes: allowed.length ? allowed : null,
        },
    });

    uppy.use(Dashboard, {
        inline: true,
        target: el,
        height: 300,
        width: '100%',
        proudlyDisplayPoweredByUppy: false,
        showProgressDetails: true,
    });

    if (directToStorage) {
        uppy.use(AwsS3, {
            shouldUseMultipart: false, // one signed PUT per file (≤ 10MB cap)
            async getUploadParameters(file) {
                // Compute dimensions BEFORE the PUT so they are on the file's meta by register time.
                const dims = await imageDimensions(file);
                if (dims) {
                    uppy.setFileMeta(file.id, dims);
                }

                const res = await fetch(cfg.presignUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    body: JSON.stringify({ filename: file.name, mime: file.type, size: file.size }),
                });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    throw new Error(err.message || 'Không tạo được phiên tải lên.');
                }

                const data = await res.json();
                uppy.setFileMeta(file.id, { tempKey: data.tempKey });

                // Forward ONLY the signed headers; drop Host (a forbidden header the browser rejects).
                const headers = {};
                for (const [name, value] of Object.entries(data.headers || {})) {
                    if (name.toLowerCase() === 'host') continue;
                    headers[name] = Array.isArray(value) ? value[0] : value;
                }

                return { method: 'PUT', url: data.url, headers, fields: {} };
            },
        });
    } else {
        uppy.use(XHRUpload, {
            endpoint: cfg.uploadUrl,
            method: 'POST',
            fieldName: 'file',
            formData: true,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            getResponseError(responseText) {
                try {
                    return new Error(JSON.parse(responseText).message || 'Tải lên thất bại.');
                } catch {
                    return new Error('Tải lên thất bại.');
                }
            },
        });
    }

    // Finalize one direct-to-R2 file. Returns true on a recorded row, false on a server rejection
    // (e.g. a spoofed file caught by the magic-byte sniff) — surfaced as a toast, never a thrown crash.
    async function registerFile(file) {
        try {
            const res = await fetch(cfg.registerUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    tempKey: file.meta.tempKey,
                    filename: file.name,
                    width: file.meta.width ?? null,
                    height: file.meta.height ?? null,
                }),
            });
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                uppy.info(err.message || 'Không lưu được tệp.', 'error', 5000);
                return false;
            }
            return true;
        } catch {
            uppy.info('Không lưu được tệp. Vui lòng thử lại.', 'error', 5000);
            return false;
        }
    }

    const pending = [];

    uppy.on('upload-success', (file) => {
        // R2 path: the bytes are at the temp key — finalize the row. Local path: the upload endpoint
        // already created it. Collect the work so `complete` can wait for every register to settle.
        pending.push(directToStorage ? registerFile(file) : Promise.resolve(true));
    });

    uppy.on('complete', async () => {
        const results = await Promise.allSettled(pending.splice(0));
        const recorded = results.some((r) => r.status === 'fulfilled' && r.value === true);
        if (recorded) {
            window.Livewire?.dispatch('media-uploaded');
        }
    });

    return uppy;
}
