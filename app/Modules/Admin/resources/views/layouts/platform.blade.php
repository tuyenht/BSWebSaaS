<!doctype html>
{{-- Scheme-only plane: the resolver emits just data-bs-theme here — the platform console
     never follows tenant sidebar/topbar variants. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" @foreach ($chrome as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- CSRF token for fetch()-based endpoints (theme prefs). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('admin.settings_system') }} — {{ config('admin.brand.name') }}</title>
    @include('admin::partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="admin-shell min-h-full bg-body font-sans text-base text-body-text antialiased">
<div class="flex min-h-screen flex-col">
    {{-- Topbar (platform console — no tenant sidebar; the settings shell carries the nav) --}}
    <header class="sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-3 border-b border-topbar-border bg-topbar px-4 shadow-card">
        <a href="{{ route('platform.dashboard') }}" wire:navigate class="flex items-center gap-2.5">
            {{-- Brand mark stays literal brand: an identity mark, fixed across every scheme. --}}
            <x-admin::brand-logo context="topbar" />
            <span class="text-[11px] font-semibold uppercase tracking-wider text-accent">{{ __('admin.platform_console') }}</span>
        </a>

        @if (! empty($platformNav))
            <nav class="ms-4 hidden items-center gap-1 md:flex">
                @foreach ($platformNav as $item)
                    <a href="{{ $item->url }}" wire:navigate
                       class="rounded px-3 py-1.5 text-sm font-medium text-topbar-fg transition-colors hover:bg-topbar-hover hover:text-topbar-fg-hover">{{ $item->label }}</a>
                @endforeach
            </nav>
        @endif

        <div class="ms-auto flex items-center gap-1">
            @include('admin::partials.topbar-tools')
            <span class="mx-1 hidden h-6 w-px bg-topbar-border sm:block" aria-hidden="true"></span>
            @include('admin::partials.topbar-user')
        </div>
    </header>

    <main class="flex-1 p-4 lg:p-6">
        {{ $slot }}
    </main>

    <footer class="border-t border-border px-4 py-4 text-center text-xs text-footer">
        &copy; {{ now()->year }}
        <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer"
           class="font-medium text-body-muted transition-colors hover:text-accent hover:underline">{{ config('admin.brand.company') }}</a>.
        {{ __('auth.rights') }}
    </footer>
</div>
@include('admin::partials.customizer')
@livewireScripts
</body>
</html>
