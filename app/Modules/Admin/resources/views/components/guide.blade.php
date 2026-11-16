@props(['guide' => null])
@php
    // A screen's «Hướng dẫn» (App\Modules\Admin\Support\Guide\Guide): a (?) ring and the word, the word
    // left out below 768px (the name stays in aria-label), its hit area 44px — drawn with shared utilities
    // only, as every admin page loads them. Beside it, the member's guide printed for
    // resources/js/admin-guide.js, which downloads on the first press (app.js). The screen passes the
    // guide on its first draw only; wire:ignore keeps what was printed through the redraws that follow,
    // so they read nothing more for it.
@endphp
<button type="button" data-guide-open aria-haspopup="dialog" aria-expanded="false"
        aria-label="{{ __('guide.ui.open_aria') }}" title="{{ __('guide.ui.open_tip') }}"
        {{ $attributes->merge(['class' => 'hit-area relative inline-flex min-h-hit min-w-hit items-center justify-center gap-1.5 whitespace-nowrap rounded-md px-3 align-middle text-body font-semibold text-primary-text transition-colors hover:bg-surface-hover max-md:px-0']) }}>
    <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full border-[1.5px] border-border-input bg-surface" aria-hidden="true"><x-bs-admin::icon name="ri-question-mark" class="size-4" /></span><span class="max-md:hidden">{{ __('guide.ui.open') }}</span>
</button>
<div hidden wire:ignore data-guide-box>
    @if (is_array($guide))
        <script type="application/json" data-guide-ctx>@json($guide, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)</script>
        <template data-guide-icons>
            @foreach (['close' => 'ri-close', 'cursor' => 'ri-cursor', 'down' => 'ri-arrow-down-s', 'check' => 'ri-check', 'left' => 'ri-arrow-left', 'right' => 'ri-arrow-right'] as $key => $name)
                <span data-i="{{ $key }}"><x-bs-admin::icon :name="$name" /></span>
            @endforeach
        </template>
    @endif
</div>
