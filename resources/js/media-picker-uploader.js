// Picker "Thiết bị của tôi" uploader (handoff screen 3). Same hardened pipeline as the library page
// (media-upload-pipeline.js) but WITHOUT Uppy's Dashboard — a custom dropzone + progress rows
// matching the design. Mounted by app.js when the device pane appears inside the picker modal;
// on a finished batch it dispatches `media-uploaded` so the Livewire picker refreshes its grid.
//
// The rows are drawn by media-upload-rows.js with the .mp-up classes of admin-upload.css (plain CSS,
// not Tailwind utilities: Tailwind's scan would not see classes made in JS).

import Uppy from '@uppy/core';
import AwsS3 from '@uppy/aws-s3';
import XHRUpload from '@uppy/xhr-upload';
import Vietnamese from '@uppy/locales/lib/vi_VN';
import '@uppy/core/css/style.min.css';
import { usePipeline } from './media-upload-pipeline.js';
import { classify, createUploadList, reasonOf } from './media-upload-rows.js';

const PRIMARY = 'var(--color-admin-primary)';

export function createPickerUploader(el) {
    const cfg = el.dataset;
    const maxBytes = Number(cfg.maxBytes) || null;
    const allowed = (cfg.allowed || '').split(',').filter(Boolean);

    const dropEl = el.querySelector('[data-drop]');
    const inputEl = el.querySelector('[data-input]');
    const listEl = el.querySelector('[data-progress]');
    const headEl = el.querySelector('[data-up-head]');
    const rules = { maxBytes, allowed };

    const uppy = new Uppy({
        locale: Vietnamese,
        autoProceed: true,
        restrictions: { maxFileSize: maxBytes, allowedFileTypes: allowed.length ? allowed : null },
    });
    const { directToStorage, registerFile } = usePipeline(uppy, cfg, AwsS3, XHRUpload);

    function addFiles(fileList) {
        for (const file of fileList) {
            const code = classify(file, rules);
            if (code !== null) {
                const id = 'local-' + local++;
                list.add(id, { name: file.name, size: file.size });
                list.fail(id, code);
                continue;
            }
            try {
                uppy.addFile({ name: file.name, type: file.type, data: file });
            } catch {
                // restriction-failed fires separately; ignore the duplicate throw here.
            }
        }
    }

    // ── Dropzone wiring ──────────────────────────────────────────────────────
    const openDialog = () => inputEl?.click();
    dropEl?.addEventListener('click', openDialog);
    inputEl?.addEventListener('change', () => {
        if (inputEl.files?.length) {
            addFiles(inputEl.files);
            inputEl.value = ''; // allow re-picking the same file
        }
    });
    ['dragenter', 'dragover'].forEach((evt) =>
        dropEl?.addEventListener(evt, (e) => {
            e.preventDefault();
            dropEl.style.borderColor = PRIMARY;
        }),
    );
    ['dragleave', 'drop'].forEach((evt) =>
        dropEl?.addEventListener(evt, (e) => {
            e.preventDefault();
            dropEl.style.borderColor = '';
        }),
    );
    dropEl?.addEventListener('drop', (e) => {
        if (e.dataTransfer?.files?.length) {
            addFiles(e.dataTransfer.files);
        }
    });

    // ── Upload list (design board 13) ────────────────────────────────────────
    const parse = (json, fallback) => {
        try {
            return JSON.parse(json || '') || fallback;
        } catch {
            return fallback;
        }
    };
    const icons = {};
    el.querySelectorAll('template[data-icon]').forEach((t) => {
        icons[t.dataset.icon] = t;
    });
    let local = 0; // ids for files refused before they reach Uppy
    let retrying = null; // the row a retried file takes back
    // each file's bytes, kept by row: a failed or finished file leaves Uppy (Uppy would otherwise send
    // every failed file again with the next one, and refuse the same file picked a second time)
    const kept = new Map();
    const forget = (id) => {
        if (uppy.getFile(id)) {
            uppy.removeFile(id); // stops a running upload
        }
    };
    const list = createUploadList({
        listEl,
        headEl,
        msgs: parse(cfg.msgs, {}),
        labels: parse(cfg.labels, {}),
        locale: document.documentElement.lang || 'vi',
        icon: (name) => icons[name]?.content.firstElementChild?.cloneNode(true) ?? null,
        // where a keyboard user goes when the list has nothing left to focus: the dropzone
        fallbackFocus: () => {
            if (dropEl && !dropEl.hasAttribute('tabindex')) dropEl.tabIndex = -1;

            return dropEl;
        },
        onRetry(id) {
            const file = kept.get(id);
            if (!file) return;
            forget(id);
            retrying = id;
            try {
                uppy.addFile(file);
            } catch {
                // restriction-failed has drawn its own row
            } finally {
                retrying = null;
            }
        },
        onRemove(id) {
            forget(id);
            kept.delete(id);
            list.remove(id);
        },
    });
    headEl?.querySelector('[data-up-clear]')?.addEventListener('click', () => {
        for (const id of list.clearDone()) {
            forget(id);
            kept.delete(id);
        }
    });

    uppy.on('file-added', (file) => {
        kept.set(file.id, { name: file.name, type: file.type, data: file.data });
        if (retrying !== null) {
            list.rekey(retrying, file.id);
        } else {
            list.add(file.id, { name: file.name, size: file.size });
        }
    });

    uppy.on('upload-progress', (file, progress) => {
        if (file && progress.bytesTotal) {
            list.progress(file.id, (progress.bytesUploaded / progress.bytesTotal) * 100);
        }
    });

    const done = (file) => {
        owed = true;
        list.done(file.id, { width: file.meta?.width, height: file.meta?.height });
    };
    const failed = (file, error, fallback) => {
        owed = true;
        list.fail(file.id, reasonOf(error, fallback), error?.message || '');
    };

    // the register calls still open (R2), across batches: a batch may end while another's call runs
    const pending = new Set();
    let owed = false; // a file settled since the page was last told: one batch end reports it
    const track = (promise) => {
        pending.add(promise);
        promise.finally(() => pending.delete(promise));
    };
    uppy.on('upload-success', (file) => {
        if (directToStorage) {
            list.sent(file.id); // in storage, being recorded: × no longer stops it
        }
        track(
            directToStorage
                ? registerFile(file).then(
                      () => done(uppy.getFile(file.id) ?? file),
                      (e) => failed(file, e, 'unexpected'),
                  )
                : Promise.resolve(done(file)),
        );
    });
    // an error with no reason never got an answer from the server: the file did not arrive
    uppy.on('upload-error', (file, error) => {
        if (!file) return;
        failed(file, error, 'upload_missing');
        // Uppy marked the file failed (its own listener ran first); clear the mark so the next upload
        // does not send it again on its own — a second try is the row's «Thử lại», and only there
        if (uppy.getFile(file.id)) {
            uppy.setFileState(file.id, { error: null });
        }
    });
    uppy.on('restriction-failed', (file) => {
        // a file Uppy already holds (picked again while it is still going up) keeps its one row
        const code = file ? classify(file, rules) : null;
        if (code !== null) {
            const id = 'local-' + local++;
            list.add(id, { name: file.name, size: file.size });
            list.fail(id, code);
        }
    });

    uppy.on('complete', async () => {
        await Promise.allSettled([...pending]);
        // the batch is over: finished and failed files leave Uppy, their rows stay
        for (const [id, row] of list.rows) {
            if (row.st !== 'run') {
                forget(id);
            }
            if (row.st === 'done') {
                kept.delete(id); // nothing left to try again
            }
        }
        // a file still going up (another batch) keeps the pane: leaving it would end that upload; the
        // batch that finishes last tells the page. A failure keeps the list in view (its reason and
        // «Thử lại»); a clean finish may go to the library.
        const rows = [...list.rows.values()];
        if (!owed || rows.some((r) => r.st === 'run')) {
            return;
        }
        owed = false;
        window.Livewire?.dispatch('media-uploaded', { failed: rows.filter((r) => r.st === 'err').length });
    });

    // Drain files handed over from the source-screen quick-upload dropzone (they can't survive the
    // Livewire round-trip that switched to this pane, so app.js stashed the File objects). Must run
    // AFTER every uppy.on(...) above — autoProceed fires file-added/complete synchronously, so
    // draining earlier would lose the progress rows and the media-uploaded dispatch.
    if (Array.isArray(window.__pendingPickerFiles) && window.__pendingPickerFiles.length) {
        const handed = window.__pendingPickerFiles;
        window.__pendingPickerFiles = null;
        addFiles(handed);
    }

    return {
        destroy() {
            (uppy.destroy ?? uppy.close)?.call(uppy);
        },
    };
}
