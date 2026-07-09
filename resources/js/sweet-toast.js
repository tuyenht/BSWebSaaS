// Branded success toast (SweetAlert2 toast mode), lazy-loaded like the confirm dialog so it costs
// nothing until an action fires one. Livewire raises it with `$this->dispatch('bs-toast', text: '…')`
// — LW4's dispatch() emits a bubbling CustomEvent (detail = the named params), so the DOM listener
// below shows it with no per-component wiring. Also exposed as the Alpine magic $bsToast.
//
// Styling mirrors design_handoff_pages_admin §3a: a compact dark pill anchored bottom-center with a
// small green ri-checkbox-circle glyph + 13px text — NOT SweetAlert2's large animated success icon
// or timer bar, which read chunky. Text is escaped (it may carry entry titles).

const CHECK_SVG =
    '<svg viewBox="0 0 24 24" width="16" height="16" fill="#0ab39c" aria-hidden="true" style="flex:none;">' +
    '<path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22ZM17.4571 9.45711L11 15.9142L6.79289 11.7071L8.20711 10.2929L11 13.0858L16.0429 8.04289L17.4571 9.45711Z"/></svg>';

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);
}

async function bsToast(opts = {}) {
    const { default: Swal } = await import('sweetalert2');
    await Swal.fire({
        toast: true,
        position: 'bottom',
        html: `<span style="display:inline-flex;align-items:center;gap:8px;font-size:13px;line-height:1.4;">${CHECK_SVG}<span>${escapeHtml(opts.text)}</span></span>`,
        showConfirmButton: false,
        timer: 2400,
        background: '#212529', // dark slab (design tokens)
        color: '#fff',
        width: 'auto',
        padding: '10px 16px',
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('bsToast', () => bsToast);
});

document.addEventListener('bs-toast', (event) => {
    bsToast({ text: event.detail?.text });
});

window.bsToast = bsToast;
