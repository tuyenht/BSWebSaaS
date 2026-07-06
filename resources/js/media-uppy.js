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
import { usePipeline } from './media-upload-pipeline.js';

import '@uppy/core/css/style.min.css';
import '@uppy/dashboard/css/style.min.css';

export function createMediaUploader(el) {
    const cfg = el.dataset;
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

    const { directToStorage, registerFile } = usePipeline(uppy, cfg, AwsS3, XHRUpload);
    const pending = [];

    uppy.on('upload-success', (file) => {
        // R2 path: the bytes are at the temp key — finalize the row (surfacing a server rejection as
        // a toast, never a crash). Local path: the upload endpoint already created it.
        pending.push(
            directToStorage
                ? registerFile(file).catch((e) => {
                      uppy.info(e.message || 'Không lưu được tệp.', 'error', 5000);
                      return false;
                  })
                : Promise.resolve(true),
        );
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
