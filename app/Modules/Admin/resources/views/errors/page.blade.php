{{--
    An admin error page on its own (design board 25, the "Ngoài khung quản trị" frames): before
    sign-in, for a website that is not open, a session that ended, maintenance, or when the admin
    frame cannot be drawn. A slim bar with the platform logo, "Về trang chủ" and "Liên hệ hỗ trợ",
    then the state. Reads only config and $page: it is drawn even with no site and no database.
--}}
@php
    /** @var array<string, mixed> $page */
    $brandName = config('admin.brand.name');
    $logo = config('admin.brand.logo');
    $support = \App\Modules\Admin\Errors\SupportChannels::links();
    // "Liên hệ hỗ trợ" scrolls to the page's support box; a page without one opens the first channel.
    $supportHref = $page['support'] !== null ? '#ho-tro' : ($support[0]['href'] ?? null);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" @foreach ($chrome as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $page['title'] }} — {{ $brandName }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('admin::partials.brand-tokens')
</head>
<body class="te-out min-h-full bg-bg font-sans text-base text-text antialiased">
<div class="te-frame">
    <header class="te-top">
        <div class="te-logo">
            @if (filled($logo['dark'] ?? null))
                <img src="{{ asset($logo['dark']) }}" alt="{{ $brandName }}" class="te-logo-dark">
                <img src="{{ asset($logo['light'] ?? $logo['dark']) }}" alt="{{ $brandName }}" class="te-logo-light">
            @else
                <span class="te-logo-name">{{ $brandName }}</span>
            @endif
        </div>
        <nav class="te-links" aria-label="{{ __('errors.next_nav') }}">
            <a class="te-link" href="{{ config('admin.brand.company_url') }}" aria-label="{{ __('errors.home') }}" title="{{ __('errors.home') }}">
                <x-bs-admin::icon name="ri-home-4" class="size-4" /><span class="te-lt">{{ __('errors.home') }}</span>
            </a>
            @if ($supportHref !== null)
                <a class="te-link" href="{{ $supportHref }}" aria-label="{{ __('errors.support') }}" title="{{ __('errors.support') }}">
                    <x-bs-admin::icon name="ri-customer-service-2" class="size-4" /><span class="te-lt">{{ __('errors.support') }}</span>
                </a>
            @endif
        </nav>
    </header>
    <main class="te-body">
        @include('admin::errors.partials.view', ['page' => $page])
    </main>
</div>
<div class="sr-only" aria-live="polite" data-te-live></div>
</body>
</html>
