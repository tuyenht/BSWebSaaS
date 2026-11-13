@props(['icon' => 'ri-checkbox-circle'])
{{--
    A short "saved" note beside the thing that was saved ("Đã lưu lúc 14:05", "Đã lưu — áp dụng ngay"),
    read out politely. The live region stays on the page empty until there is something to say, so a
    screen reader hears the words when they arrive; pass no slot (or an empty one) for nothing yet.
--}}
@php($text = trim((string) $slot))
<span role="status" aria-live="polite"
      {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-small text-success-text']) }}>
    @if ($text !== '')
        <x-bs-admin::icon :name="$icon" class="size-[1.15em] shrink-0" />{{ $slot }}
    @endif
</span>
