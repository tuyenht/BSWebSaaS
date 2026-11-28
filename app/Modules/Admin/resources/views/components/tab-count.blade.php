@props([
    'on' => false,
])
{{--
    The count bubble of a tab (canvas .dli-tab .n, .tv-tab b; admin-base [data-tab-n]): a round pill on
    the light hover tint, drawn with a 1px inner ring in the input border colour, because the tint alone
    is about 1.1:1 against the card and the bubble would read as a bare number. `on` is the chosen tab's
    bubble: the accent ink on its light tint (canvas .dli-tab[aria-selected] .n). It never breaks between
    digits; a tight tab wraps its name instead.
--}}
<span data-tab-n @class([
    'inline-flex min-h-5 min-w-5 shrink-0 items-center justify-center whitespace-nowrap rounded-full px-1.5 text-caption font-semibold leading-normal tabular-nums shadow-[inset_0_0_0_1px_var(--color-border-input)]',
    'bg-surface-hover text-text' => ! $on,
    'bg-[color-mix(in_srgb,var(--color-primary-text)_12%,var(--color-surface))] text-primary-text' => $on,
])>{{ $slot }}</span>
