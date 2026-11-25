@props([
    // the admin-look axis (AdminChromeResolver::FIELDS key) the cards choose for; also the Livewire property
    'axis',
    // list<string> of the values offered, the first being the platform's default
    'values' => [],
    // value => caption
    'labels' => [],
    // the visible label's id (the radio group's name)
    'labelledby',
    // the caption of the first card, which keeps the platform's default ('' = no such card)
    'inherit' => '',
])
@php
    // One admin-look setting as picture cards (owner's request 24/11: «trực quan hơn, như ảnh 2»): each card draws
    // a small admin page in that look, its caption below and a check badge when chosen. The first card keeps the
    // platform's default and draws it, its value '' (inherit). Native radios under the cards: arrows move between
    // them, Space chooses, the focus ring shows on the card. The page drawings use fixed literal colours on purpose:
    // each card shows one fixed look, so it must not repaint with the live light or dark scheme; the brand colour
    // reads its token. The gradients are the app's own deepened bands (admin-chrome.css), so the card matches the sidebar.
    $brand = 'var(--color-primary)';
    $default = $values[0] ?? '';
    $draw = static function (string $axis, string $v) use ($brand): array {
        $dark = $axis === 'scheme' && $v === 'dark';
        // «Theo máy»: half the page light, half dark, cut on the diagonal (the board's drawing)
        $split = $axis === 'scheme' && $v === 'auto';
        $page = $split ? 'linear-gradient(135deg, #ffffff 50%, #0f1729 50%)' : ($dark ? '#0f1729' : '#ffffff');
        $line = $dark ? 'rgb(255 255 255 / 0.18)' : '#d3d9e4';
        $side = match (true) {
            $axis === 'sidebar' && $v === 'dark', $axis === 'sidebar_image' => "color-mix(in srgb, {$brand} 72%, #0b1d45)",
            $axis === 'sidebar' && $v === 'light' => '#ffffff',
            $axis === 'sidebar' && $v === 'gradient' => "linear-gradient(to right, {$brand}, #056d5e)",
            $axis === 'sidebar' && $v === 'gradient-2' => 'linear-gradient(to right, #036797, #185ad2)',
            $axis === 'sidebar' && $v === 'gradient-3' => 'linear-gradient(to right, #036797, #056d5e)',
            $axis === 'sidebar' && $v === 'gradient-4' => "linear-gradient(to right, #1a1d21, {$brand})",
            $dark => '#111a30',
            default => '#e8ecf3',
        };
        $sideLine = in_array($axis, ['sidebar', 'sidebar_image'], true) && ! ($axis === 'sidebar' && $v === 'light') ? 'rgb(255 255 255 / 0.45)' : $line;
        $top = match (true) {
            $axis === 'topbar' && $v === 'dark' => $brand,
            $axis === 'topbar' && $v === 'auto' => "linear-gradient(to right, {$brand} 50%, #ffffff 50%)",
            default => $dark ? $page : '#f6f8fb',
        };

        return [
            'page' => $page, 'main' => $split ? 'transparent' : $page, 'line' => $line, 'side' => $side, 'sideLine' => $sideLine, 'top' => $top,
            'image' => $axis === 'sidebar_image' && $v !== 'none' ? "url(/images/admin/sidebar/{$v}.jpg)" : null,
            'narrow' => $axis === 'sidebar_size' && $v === 'sm',
            'boxed' => $axis === 'layout_width' && $v === 'boxed',
            'scrolled' => $axis === 'position' && $v === 'scrollable',
        ];
    };
    $cards = $inherit !== '' ? [['value' => '', 'look' => $default, 'label' => $inherit, 'sub' => $labels[$default] ?? $default]] : [];
    foreach ($values as $v) {
        $cards[] = ['value' => $v, 'look' => $v, 'label' => $labels[$v] ?? $v, 'sub' => null];
    }
    $sizes = ['md' => '0.875rem', 'lg' => '1rem', 'xl' => '1.125rem', 'xxl' => '1.25rem'];
@endphp
<div role="radiogroup" aria-labelledby="{{ $labelledby }}" {{ $attributes->merge(['class' => 'grid grid-cols-[repeat(auto-fill,minmax(6rem,1fr))] gap-x-2.5 gap-y-3 md:grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] md:gap-3']) }}>
    @foreach ($cards as $c)
        @php $d = $draw($axis, $c['look']); @endphp
        <label class="group relative flex min-w-0 cursor-pointer flex-col items-center gap-1.5" wire:key="pick-{{ $axis }}-{{ $c['value'] }}">
            <input type="radio" name="pick-{{ $axis }}" value="{{ $c['value'] }}" wire:model.live="{{ $axis }}" class="peer sr-only">
            <span class="relative block aspect-[8/5] w-full overflow-hidden rounded-lg border border-border-strong transition-shadow group-hover:border-primary-text/60 peer-checked:border-primary-text peer-checked:shadow-[0_0_0_1px_var(--color-primary-text)] peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary-text"
                  aria-hidden="true" style="background: {{ $d['page'] }}">
                @if ($axis === 'text_size')
                    <span class="absolute inset-0 flex flex-col items-start justify-center gap-1 px-3" style="color:#1f2a44">
                        <span class="font-semibold leading-none" style="font-size: calc({{ $sizes[$c['look']] ?? '0.875rem' }} * 1.4)">Aa</span>
                        <span class="block h-1 w-4/5 rounded-full" style="background: {{ $d['line'] }}"></span>
                        <span class="block h-1 w-3/5 rounded-full" style="background: {{ $d['line'] }}"></span>
                    </span>
                @else
                    <span class="absolute inset-y-0 start-0 flex flex-col gap-1 border-e px-1 pt-2" style="width: {{ $d['narrow'] ? '11%' : '26%' }}; background: {{ $d['image'] ? 'linear-gradient(rgb(11 29 69 / .5), rgb(11 29 69 / .5)), '.$d['image'].' center / cover no-repeat' : $d['side'] }}; border-color: {{ $d['line'] }}">
                        <span class="block h-1.5 rounded-full" style="background: {{ $d['sideLine'] }}"></span>
                        <span class="block h-1 rounded-full" style="background: {{ $d['sideLine'] }}"></span>
                        <span class="block h-1 rounded-full" style="background: {{ $d['sideLine'] }}"></span>
                        <span class="block h-1 rounded-full" style="background: {{ $d['sideLine'] }}"></span>
                    </span>
                    <span class="absolute inset-y-0 end-0 flex flex-col" style="inset-inline-start: {{ $d['narrow'] ? '11%' : '26%' }};{{ $d['boxed'] ? ' padding: 0 14%; background: #e8ecf3' : '' }}">
                        @unless ($d['scrolled'])
                            <span class="block h-[18%] shrink-0 border-b" style="background: {{ $d['top'] }}; border-color: {{ $d['line'] }}"></span>
                        @endunless
                        <span class="flex flex-1 flex-col gap-1 p-1.5" style="background: {{ $d['main'] }}">
                            <span class="block h-1.5 w-3/4 rounded-full" style="background: {{ $d['line'] }}"></span>
                            <span class="block h-1 rounded-full" style="background: {{ $d['line'] }}"></span>
                            <span class="block h-1 w-5/6 rounded-full" style="background: {{ $d['line'] }}"></span>
                            <span class="block h-1 w-2/3 rounded-full" style="background: {{ $d['line'] }}"></span>
                        </span>
                    </span>
                    @if ($axis === 'position')
                        <span class="absolute end-0.5 block w-1 rounded-full" style="top: {{ $d['scrolled'] ? '46%' : '34%' }}; height: 30%; background: #9aa5b8"></span>
                    @endif
                @endif
                <span class="absolute end-1.5 top-1.5 hidden size-5 items-center justify-center rounded-full bg-primary text-on-primary shadow-sm group-has-[:checked]:flex">
                    <x-bs-admin::icon name="ri-check" class="size-3.5" />
                </span>
            </span>
            <span class="flex min-w-0 flex-col items-center text-center leading-snug">
                <span class="text-small font-medium text-text group-has-[:checked]:font-semibold group-has-[:checked]:text-primary-text">{{ $c['label'] }}</span>
                @if ($c['sub'] !== null)
                    <span class="text-caption text-text-muted">({{ $c['sub'] }})</span>
                @endif
            </span>
        </label>
    @endforeach
</div>
