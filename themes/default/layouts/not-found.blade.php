{{--
    A page with nothing of the site's content to show, in the same header, footer and menus as every
    page of the site: the 404 page, and the page of a site that is not open (being set up, suspended,
    closed — Storefront\Support\SiteStatePage). One message, centred: an icon in a soft brand circle,
    the "404" for a missing page, the page's single h1, a line of help, then either the main menu's
    first links ($suggestions, 404 only; left out when there are none) or one button ($notice.action:
    reload, or the site's public email). $notice absent = the 404 page. Nothing here names a tenant.
    The site search box the design draws on the 404 page waits for site search (not built yet).
--}}
@php($notice ??= [
    'icon' => 'compass',
    'code' => '404',
    'heading' => __('storefront.not_found.heading'),
    'text' => __('storefront.not_found.message'),
    'action' => null,
])
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}"{!! ($dir ?? 'ltr') === 'rtl' ? ' dir="rtl"' : '' !!}>
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="sf-404" id="noi-dung" tabindex="-1">
        <div class="sf-404__inner">
            <div class="sf-404__icon" aria-hidden="true">@include('theme::partials.sf-notice-icon', ['name' => $notice['icon'], 'size' => 30])</div>
@if (! empty($notice['code']))
            <p class="sf-404__code">{{ $notice['code'] }}</p>
@endif
            <h1 class="sf-404__title">{{ $notice['heading'] }}</h1>
            <p class="sf-404__text">{{ $notice['text'] }}</p>
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
@if (! empty($notice['action']))
            <a class="sf-404__btn" href="{{ $notice['action']['href'] }}">@include('theme::partials.sf-notice-icon', ['name' => $notice['action']['icon'], 'size' => 20, 'stroke' => 2]){{ $notice['action']['label'] }}</a>
@endif
        </div>
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
