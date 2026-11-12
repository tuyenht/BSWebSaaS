@php($brandName = \App\Modules\Storefront\Support\ErrorPageBrand::for(request()))
@if ($brandName === null)
@php($noticeLang = \App\Modules\Storefront\Support\ErrorPageBrand::locale(request()))
{{--
    A host no site owns (or one whose domain is not proven yet): the neutral page. No brand, no
    host name, no footer — only what happened and a way to try again, in the language of the
    address. Kept out of search engines; one h1.
--}}
<!doctype html>
<html lang="{{ $noticeLang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('storefront.site_state.unknown_title', [], $noticeLang) }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/storefront.css', 'resources/css/sf-404.css'])
</head>
<body>
    <div class="sf-404__bar" aria-hidden="true"></div>
    <main class="sf-404 sf-404--neutral" id="noi-dung">
        <div class="sf-404__inner">
            <div class="sf-404__icon" aria-hidden="true">@include('theme::partials.sf-notice-icon', ['name' => 'globe', 'size' => 30])</div>
            <h1 class="sf-404__title">{{ __('storefront.site_state.unknown_title', [], $noticeLang) }}</h1>
            <p class="sf-404__text">{{ __('storefront.site_state.unknown_text', [], $noticeLang) }}</p>
            <a class="sf-404__btn" href="{{ \App\Modules\Storefront\Support\SiteStatePage::here(request()) }}">@include('theme::partials.sf-notice-icon', ['name' => 'refresh', 'size' => 20, 'stroke' => 2]){{ __('storefront.site_state.reload', [], $noticeLang) }}</a>
        </div>
    </main>
</body>
</html>
@else
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Không tìm thấy trang — {{ $brandName }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/storefront.css'])
</head>
<body>
    {{--
        Minimal header — $brand and $menu are not available in error views (the
        StorefrontController did not run). The name comes from the request host (this
        site's name, the platform brand on the operator host), so every tenant's visitors
        see their own site — never another tenant's brand — and there is never an English
        dead-end on a VN-language site.
    --}}
    <header class="site-header" role="banner">
        <a class="site-header__brand" href="/" aria-label="Về trang chủ">
            <span style="font-weight:900;font-size:1.25rem;letter-spacing:0.04em;">
                {{ $brandName }}
            </span>
        </a>
    </header>

    <main>
        <div class="error-page">
            <p class="error-page__code" aria-hidden="true">404</p>
            <h1 class="error-page__title">Không tìm thấy trang</h1>
            <p class="error-page__message">
                Trang bạn đang tìm kiếm không tồn tại hoặc đã được chuyển đi.
                Vui lòng kiểm tra lại đường dẫn hoặc quay về trang chủ.
            </p>
            <a class="error-page__cta" href="/">Trang chủ</a>
        </div>
    </main>

    <footer class="site-footer" role="contentinfo">
        <div class="site-footer__bottom">
            &copy; {{ now()->year }} {{ $brandName }}. Bảo lưu mọi quyền.
        </div>
    </footer>
</body>
</html>
@endif
