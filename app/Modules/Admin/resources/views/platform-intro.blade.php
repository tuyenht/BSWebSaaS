<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $brandName = config('admin.brand.name');
        $privacyUrl = config('oauth.legal.privacy_url');
        $termsUrl = config('oauth.legal.terms_url');
    @endphp
    {{-- The console host's public front page: what this app is, for a visitor and for the Google /
         Facebook app reviews (an OAuth app's home page must explain the app without a sign-in).
         Unlike the sign-in pages it may be indexed. --}}
    <title>{{ __('intro.title', ['brand' => $brandName]) }}</title>
    <meta name="description" content="{{ __('intro.meta_description', ['brand' => $brandName]) }}">
    <link rel="canonical" href="{{ route('platform.dashboard') }}">
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css'])
    <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}?v=13">
    {{-- auth.css sizes the sign-in pages against a 14px root; this page is read, not filled in, so 16px --}}
    <style>html{font-size:16px}</style>
</head>
<body>
<div class="auth-page">
    <div class="auth-bg intro-bg">
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
