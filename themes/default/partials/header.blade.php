{{-- Storefront primary navigation (design.md §13). The menu is resolved server-side to a
     primitive tree (label/url/active/children) and rendered here: semantic, zero-JS, a11y
     (landmark + aria-current). Renders nothing when no menu is bound to the region, so an
     unconfigured site is never a dead-end. Values are Blade-escaped (URLs pre-sanitized).
     A site with the `storefront.header` setting gets the rich header (top bar, text size,
     mobile menu panel) instead; $header is that setting, already normalized. --}}
@if (! empty($header))
@include('theme::partials.header-bar')
@elseif (! empty($menu) || ! empty($brand['logo']))
    <header class="site-header">
        @if (! empty($brand['logo']))
            {{-- Brand logo = a server-resolved media URL (Storefront BrandImages: the site
                 profile's logo, or the older brand setting's URL until it is first saved; not
                 author Page-JSON), linked home. width/height reserve space (no CLS). Low priority, as in the
                 rich header: it loads at once but lets the page's main photo go first. --}}
            <a class="site-header__brand" href="{{ $homeUrl ?? '/' }}" aria-label="{{ $brand['name'] ?? __('storefront.home') }}">
                <img class="site-header__logo" src="{{ $brand['logo'] }}" alt="{{ $brand['name'] ?? '' }}" width="156" height="40" fetchpriority="low">
            </a>
        @endif
        @if (! empty($menu))
            <nav class="site-nav" aria-label="{{ __('storefront.nav_main') }}">
                @include('theme::partials.menu-list', ['items' => $menu, 'level' => 0])
            </nav>
        @endif
    </header>
@endif
