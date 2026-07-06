// Shared DAM upload pipeline (presign → direct-to-R2 PUT → register), independent of any UI.
// Both the media library page (Uppy Dashboard, media-uppy.js) and the picker's "Thiết bị của tôi"
// tab (custom dropzone, media-picker-uploader.js) drive Uppy through THIS module, so the hardened
// server flow lives in one place. See media-uppy.js header for the security rationale.

export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * An image's pixel dimensions (best-effort) so register can persist width/height (anti-CLS).
 * Resolves {width, height} or null (non-image / unreadable).
 */
export async function imageDimensions(file) {
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

/**
 * Wire the presign/register (R2) or XHR (local dev) upload plugin onto an Uppy instance from the
 * island's data-* config. Returns { directToStorage, registerFile } — the caller calls registerFile
 * on each `upload-success` (R2 path) to finalize the row (the real magic-byte/size/hash gate).
 *
 * @param {import('@uppy/core').Uppy} uppy
 * @param {DOMStringMap} cfg  the island element's dataset (disk, presignUrl, registerUrl, uploadUrl)
 * @param {typeof import('@uppy/aws-s3').default} AwsS3
 * @param {typeof import('@uppy/xhr-upload').default} XHRUpload
 */
export function usePipeline(uppy, cfg, AwsS3, XHRUpload) {
    const directToStorage = cfg.disk !== 'local';

    if (directToStorage) {
        uppy.use(AwsS3, {
            shouldUseMultipart: false, // one signed PUT per file (≤ 10MB cap)
            async getUploadParameters(file) {
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
    // (e.g. a spoofed file caught by the magic-byte sniff). The caller surfaces the failure.
    async function registerFile(file) {
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
            throw new Error(err.message || 'Không lưu được tệp.');
        }
        return true;
    }

    return { directToStorage, registerFile };
}
