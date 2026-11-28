@props([
    'title' => '',
    // the h1's id; the heading then takes focus from a script (tabindex -1)
    'titleId' => null,
    // the trail after ⌂: [['label' => string, 'url' => ?string], …], the last entry the screen; [] = no trail
    'crumb' => [],
    // a top-level or list screen: below 600 (and on a short landscape screen) its trail steps aside, the menu is the way back
    'top' => false,
    // the line under the name; `mdesc` is its short phone wording, shown instead below 600 (and on a short landscape screen)
    'desc' => null,
    'mdesc' => null,
    // a link at the end of the line: ['label' => string, 'url' => string, 'icon' => ?string]
    'descLink' => null,
    // the screen's guide (App\Modules\Admin\Support\Guide\Guide::toArray(), printed on the first draw only); false = no «Hướng dẫn»
    'guide' => null,
])
@php
    // One screen head for every admin screen, as the admin shell draws it (design shell-header.js, board
    // «Khung quản trị»): the trail «⌂ › … › screen», then the title row — the name, the (?) «Hướng dẫn», the
    // screen's "⋮" Việc khác (slot `more`), the main button (the slot) and an editing screen's unsaved
    // cluster (slot `save`, x-bs-admin::head-save) — then one line of description, its short wording on a
    // phone, ending with a link when the screen points somewhere (descLink). The title row is
    // x-bs-admin::title-row (its wrapping rules); the trail is x-bs-admin::breadcrumb.
    $hasDesc = $desc !== null && (string) $desc !== '';
    $hasShort = $mdesc !== null && (string) $mdesc !== '';
    $sm = 'max-[599.98px]:hidden [@media(max-height:500px)]:hidden';
@endphp
<header {{ $attributes->merge(['class' => 'flex flex-col gap-1']) }} data-screen-head>
    @if ($crumb !== [])
        <x-bs-admin::breadcrumb data-guide="crumb" @class([$sm => $top]) :items="$crumb" />
    @endif
    <x-bs-admin::title-row :title="$title" :title-id="$titleId">
        @if ($guide !== false)
            <x-slot:help>
                <x-bs-admin::guide :guide="is_array($guide) ? $guide : null" />
            </x-slot:help>
        @endif
        @isset($more)
            <x-slot:more>{{ $more }}</x-slot:more>
        @endisset
        {{ $slot }}
        @isset($save)
            {{ $save }}
        @endisset
    </x-bs-admin::title-row>
    @if ($hasDesc)
        <p class="m-0 max-w-[40rem] text-small leading-[1.55] text-text-muted [overflow-wrap:anywhere] [text-wrap:pretty]" data-screen-desc>
            <span data-desc="full" @class([$sm => $hasShort])>{{ $desc }}</span>@if ($hasShort)<span data-desc="short" class="hidden max-[599.98px]:inline [@media(max-height:500px)]:inline">{{ $mdesc }}</span>@endif
            @if (is_array($descLink) && ($descLink['url'] ?? '') !== '')
                <a href="{{ $descLink['url'] }}" wire:navigate data-guide="desc-link"
                   class="hit-area relative inline-flex items-center gap-1.5 px-1 align-middle font-semibold text-primary-text underline underline-offset-2">
                    <x-bs-admin::icon :name="$descLink['icon'] ?? 'ri-arrow-right'" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" /><span>{{ $descLink['label'] }}</span>
                </a>
            @endif
        </p>
    @endif
</header>
