{{--
    Rich storefront header (site setting `storefront.header`, normalized into $header): an optional
    top bar (contact items, language switch, text-size button) that scrolls away, then the main bar
    — logo, the desktop menu from lg, the menu button and its panel below lg — which stays at the
    top. One <header> landmark holds both; it sticks with the top bar's height scrolled off, so no
    script runs on scroll and nothing shifts. Every value is Blade-escaped; links come validated.
--}}
@php
    $sfLanguages = \App\Modules\Theme\Chrome\StorefrontChrome::languages($hreflang ?? [], $lang ?? 'vi');
    $sfTopBar = $header['top_bar'] !== [] || $sfLanguages !== [] || $header['text_size'];
    $sfSizes = ['m' => __('storefront.text_size_m'), 'l' => __('storefront.text_size_l'), 'xl' => __('storefront.text_size_xl')];
@endphp
<header class="sf-header{{ $header['sticky'] ? ' sf-header--sticky' : '' }}{{ $sfTopBar ? ' sf-header--topbar' : '' }}">
    @if ($sfTopBar)
        <div class="sf-topbar">
            <div class="sf-container sf-topbar__inner">
                <ul class="sf-topbar__items">
                    @foreach ($header['top_bar'] as $item)
                        <li class="sf-topbar__item sf-from-{{ $item['from'] }}{{ $item['below'] !== null ? ' sf-below-'.$item['below'] : '' }}">
                            @if ($item['href'] !== null)
                                <a class="sf-topbar__link" href="{{ $item['href'] }}">@include('theme::partials.sf-icon', ['name' => $item['kind'], 'size' => 16])<span>{{ $item['text'] }}</span></a>
                            @else
                                @include('theme::partials.sf-icon', ['name' => $item['kind'], 'size' => 16])<span>{{ $item['text'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="sf-topbar__tools">
                    @if ($sfLanguages !== [])
                        <nav class="sf-lang" aria-label="{{ __('storefront.language') }}">
                            <ul class="sf-lang__list">
                                @foreach ($sfLanguages as $language)
                                    <li>
                                        <a class="sf-lang__link" href="{{ $language['href'] }}" hreflang="{{ $language['locale'] }}" lang="{{ $language['locale'] }}" @if ($language['current']) aria-current="true" @endif>{{ strtoupper($language['locale']) }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif
                    @if ($header['text_size'])
                        <button type="button" class="sf-fs" data-fs-toggle
                            aria-label="{{ __('storefront.text_size_state', ['size' => $sfSizes['m']]) }}"
                            @foreach ($sfSizes as $step => $label) data-label-{{ $step }}="{{ $label }}" data-aria-{{ $step }}="{{ __('storefront.text_size_state', ['size' => $label]) }}" @endforeach
                        >@include('theme::partials.sf-icon', ['name' => 'text-size', 'size' => 16])<span>{{ __('storefront.text_size') }}</span><span class="sf-fs__value">: <span data-fs-value>{{ $sfSizes['m'] }}</span></span></button>
                    @endif
                </div>
            </div>
        </div>
    @endif
    <div class="sf-bar">
        <div class="sf-container sf-bar__inner">
            @if (! empty($brand['logo']))
                <a class="sf-bar__brand" href="{{ $homeUrl ?? '/' }}">
                    <img class="sf-bar__logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] ?? __('storefront.home') }}" width="156" height="40">
                </a>
            @endif
            @if (! empty($menu))
                <nav class="sf-nav sf-nav--desk" aria-label="{{ __('storefront.nav_main') }}">
                    @include('theme::partials.nav-disclosure', ['items' => $menu, 'depth' => 0, 'idPrefix' => 'dsm'])
                </nav>
                <button type="button" class="sf-burger" aria-expanded="false" aria-controls="mobile-menu" data-menu-toggle
                    aria-label="{{ __('storefront.menu_open') }}" data-label-open="{{ __('storefront.menu_open') }}" data-label-close="{{ __('storefront.menu_close') }}">
                    <span class="sf-burger__open">@include('theme::partials.sf-icon', ['name' => 'menu', 'size' => 24])</span><span class="sf-burger__close">@include('theme::partials.sf-icon', ['name' => 'close', 'size' => 24])</span>
                </button>
            @endif
        </div>
        @if (! empty($menu))
            <nav id="mobile-menu" class="sf-nav sf-nav--mob" aria-label="{{ __('storefront.nav_main') }}" hidden>
                @include('theme::partials.nav-disclosure', ['items' => $menu, 'depth' => 0, 'idPrefix' => 'msm'])
            </nav>
        @endif
    </div>
    {{-- Without scripts the menu panel is simply open below the bar, and the text-size button
         (which needs a script) is not shown. --}}
    <noscript><style>@media (max-width:1023.98px){.sf-nav--mob[hidden]{display:block}}.sf-burger,.sf-fs{display:none}</style></noscript>
</header>
