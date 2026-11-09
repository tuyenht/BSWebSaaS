@props(['key'])
@php
    // "Đã hiểu" on a first-time explanation (one of FirstHints::KEYS): remembers for the signed-in
    // member, on the server, that they read it, then redraws the Livewire page around it — the page
    // then shows the explanation in its section's (i) note instead of in full, and focus moves to
    // that (i). When the request fails the explanation stays where it is and a short line says so.
    $url = \App\Modules\Admin\Support\AdminPlane::route('hint.dismiss');
@endphp
<span {{ $attributes->only('class')->merge(['class' => 'inline-flex flex-col items-end gap-1']) }} x-data="{ busy: false, failed: false }">
<button type="button" data-hint-ok="{{ $key }}"
        x-bind:aria-busy="busy ? 'true' : 'false'" x-bind:disabled="busy"
        x-on:click="if (busy) { return } busy = true; failed = false;
            fetch({{ \Illuminate\Support\Js::from($url) }}, { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
                body: JSON.stringify({ key: {{ \Illuminate\Support\Js::from($key) }} }) })
            .then((r) => { if (! r.ok) { throw new Error(String(r.status)) } return $wire.$refresh() })
            .then(() => { document.querySelector('[data-set-info]')?.focus() })
            .catch(() => { failed = true }).finally(() => { busy = false })"
        {{ $attributes->except('class')->merge(['class' => 'hit-area inline-flex min-h-control-row shrink-0 items-center justify-center rounded border border-admin-secondary-text px-2.5 py-1 text-caption font-medium leading-tight text-admin-secondary-text transition-colors hover:bg-admin-secondary-solid hover:text-white disabled:opacity-60']) }}>{{ __('admin.hint.ok') }}</button>
<span role="alert" x-show="failed" x-cloak class="text-caption text-danger-text">{{ __('admin.hint.fail') }}</span>
</span>
