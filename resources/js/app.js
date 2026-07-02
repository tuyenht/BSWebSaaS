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
