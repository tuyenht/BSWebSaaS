@props(['why' => null])
{{-- «Chung» on a card of an editor open in another language than the website's default (board 07 and
     09): what the card holds is one for every language, changed in the default language only. $why says
     where (the tooltip); without it the editors' general words. --}}
<span {{ $attributes->class('inline-flex shrink-0 items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-small font-semibold text-text-muted') }} title="{{ $why ?? __('entries.ed.shared_tip') }}" data-shared-chip>
    <x-bs-admin::icon name="ri-lock" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))]" />{{ __('entries.ed.shared_chip') }}
</span>
