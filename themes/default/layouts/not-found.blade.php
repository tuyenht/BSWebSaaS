{{--
    The site's 404 page, in the same header, footer and menus as every page of the site. The big
    "404" is decoration (hidden from screen readers, its middle digit in the brand's text color);
    the page's single h1 says what happened. Two ways on: home, and the site's contact page when the
    site names one (the button is left out otherwise). Below them, the first links of the main menu
    ($suggestions, from the menu the header already loaded); the part is left out when there are none.
    Nothing here names a tenant: the contact label carries the site's own name.
--}}
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="sf-404" id="noi-dung" tabindex="-1">
        <div class="sf-404__inner">
            <p class="sf-404__code" aria-hidden="true">4<span class="sf-404__accent">0</span>4</p>
            <h1 class="sf-404__title">{{ __('storefront.not_found.heading') }}</h1>
            <p class="sf-404__text">{{ __('storefront.not_found.message') }}</p>
            <div class="sf-404__actions">
                <a class="sf-404__btn sf-404__btn--home" href="{{ $homeUrl ?? '/' }}">{{ __('storefront.not_found.home') }}</a>
@if (! empty($contactUrl))
                <a class="sf-404__btn sf-404__btn--contact" href="{{ $contactUrl }}">{{ trim(__('storefront.not_found.contact', ['name' => data_get($brand ?? null, 'name') ?: ''])) }}</a>
@endif
            </div>
@if (! empty($suggestions))
            <nav class="sf-404__more" aria-labelledby="sf-404-more">
                <p class="sf-404__more-label" id="sf-404-more">{{ __('storefront.not_found.suggest') }}</p>
                <ul class="sf-404__links">
@foreach ($suggestions as $link)
                    <li><a class="sf-404__link" href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
@endforeach
                </ul>
            </nav>
@endif
        </div>
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
