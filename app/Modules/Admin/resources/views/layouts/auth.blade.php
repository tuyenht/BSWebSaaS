<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('auth.welcome') }} — {{ config('admin.brand.name') }}</title>
    <meta name="description" content="{{ $metaDescription ?? config('admin.brand.name').' — '.config('admin.brand.tagline') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}?v=2">
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
                    <a href="{{ route('admin.locale', ['set' => $l['code']]) }}"
                       @class(['auth-lang-btn', 'auth-lang-btn--active' => $l['active']])>
                        {{ strtoupper($l['code']) }}
                        <span class="auth-lang-tooltip">{{ $l['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <main class="auth-main">
            <div class="auth-logo-wrap">
                <a href="{{ route('admin.login') }}" aria-label="{{ $brandName }}">
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
