// Picker "Thiết bị của tôi" uploader (handoff screen 3). Same hardened pipeline as the library page
// (media-upload-pipeline.js) but WITHOUT Uppy's Dashboard — a custom dropzone + progress rows
// matching the design. Mounted by app.js when the device pane appears inside the picker modal;
// on a finished batch it dispatches `media-uploaded` so the Livewire picker refreshes its grid.
//
// Progress rows are built with inline styles on purpose: they are created in JS, so Tailwind's
// content scan wouldn't see their classes and would purge them.

import Uppy from '@uppy/core';
import AwsS3 from '@uppy/aws-s3';
import XHRUpload from '@uppy/xhr-upload';
import Vietnamese from '@uppy/locales/lib/vi_VN';
import '@uppy/core/css/style.min.css';
import { usePipeline } from './media-upload-pipeline.js';

const PRIMARY = 'var(--color-admin-primary)';
const SUCCESS = 'var(--color-admin-success)';
const DANGER = 'var(--color-admin-danger)';

export function createPickerUploader(el) {
    const cfg = el.dataset;
    const maxBytes = Number(cfg.maxBytes) || null;
    const allowed = (cfg.allowed || '').split(',').filter(Boolean);

    const dropEl = el.querySelector('[data-drop]');
    const inputEl = el.querySelector('[data-input]');
    const listEl = el.querySelector('[data-progress]');
    const labelEl = el.querySelector('[data-progress-label]');

    const uppy = new Uppy({
        locale: Vietnamese,
        autoProceed: true,
        restrictions: { maxFileSize: maxBytes, allowedFileTypes: allowed.length ? allowed : null },
    });
    const { directToStorage, registerFile } = usePipeline(uppy, cfg, AwsS3, XHRUpload);

    const rows = new Map(); // uppy fileId → { row, bar, status, objectUrl }

    function addFiles(fileList) {
        for (const file of fileList) {
            try {
                uppy.addFile({ name: file.name, type: file.type, data: file });
            } catch {
                // restriction-failed fires separately; ignore the duplicate/oversize throw here.
            }
        }
    }

    // Files handed over from the source-screen quick-upload dropzone (they can't survive a
    // Livewire round-trip, so app.js stashed the File objects for this island to drain).
    if (Array.isArray(window.__pendingPickerFiles) && window.__pendingPickerFiles.length) {
        const handed = window.__pendingPickerFiles;
        window.__pendingPickerFiles = null;
        addFiles(handed);
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

    // ── Progress rows ────────────────────────────────────────────────────────
    function refreshLabel() {
        if (labelEl) {
            labelEl.textContent = (labelEl.dataset.template || 'ĐANG TẢI LÊN · :n TỆP').replace(':n', String(rows.size));
            labelEl.style.display = rows.size ? '' : 'none';
        }
    }

    function createRow(file) {
        const row = document.createElement('div');
        row.style.cssText =
            'display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--color-border);border-radius:10px;';

        const thumb = document.createElement('span');
        thumb.style.cssText =
            'width:38px;height:38px;border-radius:8px;flex-shrink:0;background:var(--color-surface-hover);background-size:cover;background-position:center;';
        const objectUrl = file.type?.startsWith('image/') ? URL.createObjectURL(file.data) : null;
        if (objectUrl) {
            thumb.style.backgroundImage = `url("${objectUrl}")`;
        }

        const main = document.createElement('div');
        main.style.cssText = 'flex:1;min-width:0;';
        const top = document.createElement('div');
        top.style.cssText = 'display:flex;justify-content:space-between;gap:8px;font-size:12.5px;color:var(--color-body-text);';
        const name = document.createElement('span');
        name.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
        name.textContent = file.name;
        const status = document.createElement('span');
        status.style.cssText = 'flex-shrink:0;color:var(--color-body-muted);';
        status.textContent = '0%';
        top.append(name, status);
        const track = document.createElement('div');
        track.style.cssText = 'height:5px;border-radius:5px;background:var(--color-border);margin-top:6px;overflow:hidden;';
        const bar = document.createElement('div');
        bar.style.cssText = `width:0%;height:100%;border-radius:5px;background:${PRIMARY};transition:width .15s;`;
        track.append(bar);
        main.append(top, track);

        row.append(thumb, main);
        listEl?.append(row);
        rows.set(file.id, { row, bar, status, objectUrl });
        refreshLabel();
    }

    uppy.on('file-added', createRow);

    uppy.on('upload-progress', (file, progress) => {
        const r = rows.get(file.id);
        if (r && progress.bytesTotal) {
            const pct = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100);
            r.bar.style.width = `${pct}%`;
            r.status.textContent = `${pct}%`;
        }
    });

    function markDone(fileId) {
        const r = rows.get(fileId);
        if (r) {
            r.bar.style.width = '100%';
            r.bar.style.background = SUCCESS;
            r.status.textContent = el.dataset.doneLabel || 'Xong';
            r.status.style.color = SUCCESS;
        }
    }

    function markError(fileId, message) {
        const r = rows.get(fileId);
        if (r) {
            r.bar.style.background = DANGER;
            r.status.textContent = message || (el.dataset.errorLabel || 'Lỗi');
            r.status.style.color = DANGER;
        }
    }

    const pending = [];
    uppy.on('upload-success', (file) => {
        pending.push(
            directToStorage
                ? registerFile(file).then(
                      () => markDone(file.id),
                      (e) => markError(file.id, e.message),
                  )
                : Promise.resolve(markDone(file.id)),
        );
    });
    uppy.on('upload-error', (file, error) => markError(file?.id, error?.message));
    uppy.on('restriction-failed', (file, error) => {
        if (file) {
            createRow(file);
            markError(file.id, error?.message);
        }
    });

    uppy.on('complete', async () => {
        await Promise.allSettled(pending.splice(0));
        window.Livewire?.dispatch('media-uploaded');
    });

    return {
        destroy() {
            for (const { objectUrl } of rows.values()) {
                if (objectUrl) URL.revokeObjectURL(objectUrl);
            }
            rows.clear();
            (uppy.destroy ?? uppy.close)?.call(uppy);
        },
    };
}
