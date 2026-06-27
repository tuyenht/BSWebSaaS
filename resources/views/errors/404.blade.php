<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Không tìm thấy trang — Cơ Khí Chính Xác An Vinh</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/storefront.css'])
</head>
<body>
    {{--
        Minimal branded header — $brand and $menu are not available in error views
        (the StorefrontController did not run). We show the site name as text so
        there is never an English dead-end on a VN-language site.
    --}}
    <header class="site-header" role="banner">
        <a class="site-header__brand" href="/" aria-label="Về trang chủ">
            <span style="color:#f59e0b;font-weight:900;font-size:1.25rem;letter-spacing:0.04em;">
                Cơ Khí Chính Xác An Vinh
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
            &copy; 2024–2026 Cơ Khí Chính Xác An Vinh. Bảo lưu mọi quyền.
        </div>
    </footer>
</body>
</html>
