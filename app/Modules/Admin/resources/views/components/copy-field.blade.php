@props([
    'label' => null,
    'value',
    'icon' => null,
    'copied',
    'args' => [],
])
@php
    // A value to copy into another site's form (design 21 .tm-tgt): an optional label or icon, the
    // value in the mono face (it wraps, never clipped), and [Sao chép]. `copied` is the host's
    // public method, called with `args` and then true once the browser copied the value, or false
    // when it could not, so the host can show the value to select by hand.
    $call = fn (bool $ok): string => '$wire.'.$copied.'(...'.\Illuminate\Support\Js::from($args).', '.($ok ? 'true' : 'false').')';
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 rounded border border-[color-mix(in_srgb,var(--color-primary-base)_20%,var(--color-surface))] bg-[color-mix(in_srgb,var(--color-primary-base)_6%,var(--color-surface))] px-2.5 py-2']) }}>
    @if ($icon)
        <x-bs-admin::icon :name="$icon" class="size-[calc(1rem+var(--user-text-boost))] shrink-0 text-primary-text" />
    @elseif ($label)
        <span class="text-small text-text-muted">{{ $label }}</span>
    @endif
    <span class="min-w-0 flex-[1_1_10rem] font-mono text-small font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $value }}</span>
    <x-bs-admin::button size="sm" variant="outline" color="primary"
        x-data x-on:click="navigator.clipboard ? navigator.clipboard.writeText(@js($value)).then(() => {{ $call(true) }}, () => {{ $call(false) }}) : {{ $call(false) }}">
        <x-bs-admin::icon name="ri-file-copy" class="size-[calc(1rem+var(--user-text-boost))]" />
        <span>{{ __('admin.copy') }}</span>
    </x-bs-admin::button>
</div>
