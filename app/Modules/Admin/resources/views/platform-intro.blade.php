<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $brandName = config('admin.brand.name');
        $privacyUrl = config('admin.brand.privacy_url');
        $termsUrl = config('admin.brand.terms_url');
    @endphp
    {{-- The console host's public front page: what this app is, for a visitor and for the Google /
         Facebook app reviews (an OAuth app's home page must explain the app without a sign-in).
         Unlike the sign-in pages it may be indexed. --}}
    <title>{{ __('intro.title', ['brand' => $brandName]) }}</title>
    <meta name="description" content="{{ __('intro.meta_description', ['brand' => $brandName]) }}">
    <link rel="canonical" href="{{ route('platform.dashboard') }}">
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css'])
    <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}?v=11">
    <style>
        html{font-size:16px}
        .intro-main{max-width:560px}
        .intro-lead{margin:12px 0 0;font-size:16px;line-height:1.6;color:#334155}
        .intro-list{margin:20px 0 0;padding:0;list-style:none;display:grid;gap:12px}
        .intro-list li{display:flex;gap:10px;font-size:16px;line-height:1.5;color:#1e293b}
        .intro-list svg{flex:none;width:22px;height:22px;margin-top:1px;color:#1d4ed8}
        .intro-note{margin:24px 0 0;padding:16px;border-radius:12px;background:#eff6ff;color:#1e293b}
        .intro-note h2{margin:0 0 6px;font-size:16px;font-weight:700}
        .intro-note p{margin:0;font-size:15px;line-height:1.6}
        .intro-cta{margin-top:24px;min-height:48px;text-decoration:none}
        .intro-links{display:flex;flex-wrap:wrap;justify-content:center;gap:4px 20px;margin-top:16px}
        .intro-links a{display:inline-flex;align-items:center;min-height:44px;font-size:14px;font-weight:500;color:#1d4ed8;text-decoration:underline}
    </style>
</head>
<body>
<div class="auth-page">
    <div class="auth-bg">
        <div class="auth-orb-1"></div>
        <div class="auth-orb-2"></div>

        <main class="auth-main intro-main">
            <div class="auth-logo-wrap">
                <img src="{{ asset('assets/images/logo-light.png') }}" alt="{{ $brandName }}" class="auth-logo">
            </div>

            <div class="auth-glass">
                <h1 class="auth-title">{{ __('intro.title', ['brand' => $brandName]) }}</h1>
                <p class="intro-lead">{{ __('intro.lead', ['brand' => $brandName]) }}</p>

                <ul class="intro-list">
                    @foreach (['intro.feature_content', 'intro.feature_orders', 'intro.feature_signin'] as $feature)
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{{ __($feature) }}</span>
                        </li>
                    @endforeach
                </ul>

                <section class="intro-note">
                    <h2>{{ __('intro.signin_heading') }}</h2>
                    <p>{{ __('intro.signin_body') }}</p>
                </section>

                <a href="{{ route('platform.login') }}" class="auth-submit intro-cta">{{ __('intro.cta') }}</a>

                @if ($privacyUrl || $termsUrl)
                    <nav class="intro-links" aria-label="{{ __('intro.legal') }}">
                        @if ($privacyUrl)
                            <a href="{{ $privacyUrl }}">{{ __('intro.privacy') }}</a>
                        @endif
                        @if ($termsUrl)
                            <a href="{{ $termsUrl }}">{{ __('intro.terms') }}</a>
                        @endif
                    </nav>
                @endif
            </div>

            <footer class="auth-footer">
                <p>&copy; {{ now()->year }}
                    <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer">{{ config('admin.brand.company') }}</a>.
                    {{ __('auth.rights') }}</p>
            </footer>
        </main>
    </div>
</div>
</body>
</html>
