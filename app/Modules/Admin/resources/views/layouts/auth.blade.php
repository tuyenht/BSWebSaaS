<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The login/2FA pages are the only crawler-reachable admin surface (guests get
         302'd here) — keep every admin plane out of search indexes. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('auth.welcome') }} — {{ config('admin.brand.name') }}</title>
    <meta name="description" content="{{ $metaDescription ?? config('admin.brand.name').' — '.config('admin.brand.tagline') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}?v=6">
    {{-- auth.css golden-standard values are sized against a 14px root (Velzon convention) --}}
    <style>html{font-size:14px}</style>
    @livewireStyles
</head>
<body>
@php
    $brandName = config('admin.brand.name');
    $locales = \App\Modules\Admin\Support\Locales::available();
@endphp
<div class="auth-page">
    <div class="auth-bg">
        <div class="auth-orb-1"></div>
        <div class="auth-orb-2"></div>

        {{-- Language switcher — built dynamically from lang/*.json. Drop a new {code}.json
             translation file and that language appears here automatically. --}}
        <div class="auth-lang">
            <div class="auth-lang-pill">
                @foreach ($locales as $l)
                    {{-- Plain full-page navigation (NOT wire:navigate): this page hosts the Turnstile
                         widget, and a SPA morph would blank its iframe (the once-only auto-render never
                         re-fires). A full reload re-renders it cleanly. The in-app topbar switcher, with
                         no Turnstile, keeps wire:navigate. --}}
                    <a href="{{ \App\Modules\Admin\Support\AdminPlane::route('locale', ['set' => $l['code']]) }}"
                       @class(['auth-lang-btn', 'auth-lang-btn--active' => $l['active']])>
                        {{ strtoupper($l['code']) }}
                        <span class="auth-lang-tooltip">{{ $l['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <main class="auth-main">
            <div class="auth-logo-wrap">
                <a href="{{ \App\Modules\Admin\Support\AdminPlane::route('login') }}" aria-label="{{ $brandName }}">
                    <img src="{{ asset('assets/images/logo-light.png') }}" alt="{{ $brandName }}" class="auth-logo">
                </a>
            </div>

            {{ $slot }}

            <footer class="auth-footer">
                <p>&copy; {{ now()->year }}
                    <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer">{{ config('admin.brand.company') }}</a>.
                    {{ __('auth.rights') }}</p>
            </footer>
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>
