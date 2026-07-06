// Branded confirm dialog to replace the native window.confirm (what wire:confirm uses). SweetAlert2
// is lazy-loaded on first use, so it costs nothing until a confirm actually fires.
//
// Blade usage (drop wire:confirm, keep wire:click's action on the Alpine handler):
//   <button x-on:click="$bsConfirm({ text: '…', danger: true, confirm: '…', cancel: '…' })
//           .then(ok => ok && $wire.someAction(arg))">…</button>
// Registered as the Alpine magic $bsConfirm and as window.bsConfirm.

async function bsConfirm(opts = {}) {
    const { default: Swal } = await import('sweetalert2');
    const danger = opts.danger !== false; // default: destructive styling

    const result = await Swal.fire({
        title: opts.title ?? '',
        text: opts.text ?? '',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: opts.confirm ?? 'OK',
        cancelButtonText: opts.cancel ?? 'Cancel',
        confirmButtonColor: danger ? '#f06548' : '#405189', // --color-admin-danger / --color-admin-primary
        cancelButtonColor: '#adb5bd', // --color-body-faint
        reverseButtons: true, // Cancel on the left, primary action on the right (platform norm)
        focusCancel: danger, // a destructive dialog opens focused on Cancel, not the delete button
    });

    return result.isConfirmed;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.magic('bsConfirm', () => bsConfirm);
});

window.bsConfirm = bsConfirm;
