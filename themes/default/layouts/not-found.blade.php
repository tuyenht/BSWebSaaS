{{--
    A page with nothing of the site's content to show, in the same header, footer and menus as every
    page of the site: the 404 page, and the page of a site that is not open (being set up, suspended,
    closed — Storefront\Support\SiteStatePage). One message, centred: an icon in a soft brand circle,
    the "404" for a missing page, the page's single h1, a line of help, then a row of buttons
    ($notice.actions: on the 404 page "home" then the site's contact page when it names one, labelled
    with the site's own name; on a site that is not open one button, reload or the site's public
    email), then the main menu's first links ($suggestions, 404 only; left out when there are none).
    $notice absent = the 404 page. Nothing here names a tenant.
    The site search box the design draws on the 404 page waits for site search (not built yet).
--}}
@php($notice ??= [
    'icon' => 'compass',
    'code' => '404',
    'heading' => __('storefront.not_found.heading'),
    'text' => __('storefront.not_found.message'),
    'actions' => array_values(array_filter([
        ['href' => $homeUrl ?? '/', 'icon' => null, 'kind' => 'home', 'label' => __('storefront.not_found.home')],
        empty($contactUrl) ? null : [
            'href' => $contactUrl,
            'icon' => null,
            'kind' => 'contact',
            'label' => trim(__('storefront.not_found.contact', ['name' => data_get($brand ?? null, 'name') ?: ''])),
        ],
    ])),
])
@php($noticeActions = $notice['actions'] ?? (empty($notice['action']) ? [] : [$notice['action']]))
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
@if ($noticeActions !== [])
            <div class="sf-404__actions">
@foreach ($noticeActions as $action)
                <a class="sf-404__btn{{ empty($action['kind']) ? '' : ' sf-404__btn--'.$action['kind'] }}" href="{{ $action['href'] }}">@if (! empty($action['icon']))@include('theme::partials.sf-notice-icon', ['name' => $action['icon'], 'size' => 20, 'stroke' => 2])@endif{{ $action['label'] }}</a>
@endforeach
            </div>
@endif
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
