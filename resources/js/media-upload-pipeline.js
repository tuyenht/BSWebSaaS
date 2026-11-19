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
 * The error a refused request becomes: the server's plain sentence, and its reason code
 * (MediaRejectedException::REASONS) so the upload list can show the board's sentence and offer
 * «Thử lại» only when a second try can help. A 5xx is "unexpected"; 413 is "too_large".
 */
export function withReason(error, reason, status) {
    if (typeof reason === 'string' && reason !== '') {
        error.reason = reason;
    } else if (status === 413) {
        error.reason = 'too_large';
    } else if (status >= 500) {
        error.reason = 'unexpected';
    }

    return error;
}

async function rejection(res, fallback) {
    const err = await res.json().catch(() => ({}));

    return withReason(new Error(err.message || fallback), err.reason, res.status);
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
                    throw await rejection(res, 'Không tạo được phiên tải lên.');
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
            // a refusal is final; only a lost connection or a server fault is sent again
            shouldRetry: (xhr) => xhr.status === 0 || xhr.status >= 500,
            // the server's answer to a refused file becomes the error Uppy reports (its reason code and
            // sentence); without this Uppy reports only the HTTP status text
            onAfterResponse(xhr) {
                if (xhr.status < 400) {
                    return;
                }
                let body = {};
                try {
                    body = JSON.parse(xhr.responseText) || {};
                } catch {
                    // not JSON (a proxy's page): the plain fallback below
                }

                throw withReason(new Error(body.message || 'Tải lên thất bại.'), body.reason, xhr.status);
            },
        });
    }

    // Finalize one direct-to-R2 file. Returns true on a recorded row, false on a server rejection
    // (e.g. a spoofed file caught by the magic-byte sniff). The caller surfaces the failure.
    async function registerFile(file) {
        const res = await fetch(cfg.registerUrl, {
            method: 'POST',
            signal: AbortSignal.timeout(60_000), // a call that never answers fails instead of holding the batch
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
            throw await rejection(res, 'Không lưu được tệp.');
        }
        return true;
    }

    return { directToStorage, registerFile };
}
