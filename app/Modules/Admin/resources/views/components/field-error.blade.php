{{--
    The error line under a field or a group of fields, as the boards draw it (`.tv-err`): a warning
    icon and the words, in the danger colour, read out as soon as it appears. The words come in the
    slot; the line stays until the cause is fixed (the screen clears it, never a timer).
--}}
<p {{ $attributes->merge(['class' => 'm-0 flex items-start gap-1.5 text-small text-danger-text', 'role' => 'alert']) }}><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span>{{ $slot }}</span></p>
