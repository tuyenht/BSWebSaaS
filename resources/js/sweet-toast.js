// Branded success toast (SweetAlert2 toast mode), lazy-loaded like the confirm dialog so it costs
// nothing until an action fires one. Livewire raises it with `$this->dispatch('bs-toast', text: '…')`
// — LW4's dispatch() emits a bubbling CustomEvent (detail = the named params), so the DOM listener
// below shows it with no per-component wiring. Also exposed as the Alpine magic $bsToast.

async function bsToast(opts = {}) {
    const { default: Swal } = await import('sweetalert2');
    await Swal.fire({
        toast: true,
        position: 'bottom',
        title: opts.text ?? '',
        showConfirmButton: false,
        timer: 2400,
        timerProgressBar: true,
        icon: 'success',
        iconColor: '#0ab39c', // --color-admin-success
        background: '#212529', // dark slab (design tokens)
        color: '#fff',
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('bsToast', () => bsToast);
});

document.addEventListener('bs-toast', (event) => {
    bsToast({ text: event.detail?.text });
});

window.bsToast = bsToast;
