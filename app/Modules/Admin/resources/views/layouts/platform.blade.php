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
    <link rel="icon" href="/favicon.ico" sizes="any">
    @include('admin::partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="admin-shell min-h-full bg-body font-sans text-base text-body-text antialiased">
<div class="flex min-h-screen flex-col">
    {{-- Topbar (platform console — no tenant sidebar; the settings shell carries the nav) --}}
    <header x-data="{ open: false }" class="sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-3 border-b border-topbar-border bg-topbar px-4 shadow-card">
        @if (! empty($platformNav))
            {{-- Mobile hamburger: reveals the console nav as a dropdown below the topbar (<md);
                 without it the nav is hidden md:flex, leaving small screens a dead-end. --}}
            <button type="button" @click="open = ! open" aria-label="{{ __('admin.open_menu') }}"
                    :aria-expanded="open"
                    class="grid size-9 shrink-0 place-items-center rounded text-topbar-fg transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg md:hidden">
                <x-bs-admin::icon name="menu" class="size-5" />
            </button>
        @endif
        <a href="{{ route('platform.dashboard') }}" wire:navigate class="flex items-center gap-2.5">
            {{-- Brand mark stays literal brand: an identity mark, fixed across every scheme. --}}
            <x-bs-admin::brand-logo context="topbar" />
            <span class="text-[11px] font-semibold uppercase tracking-wider text-accent">{{ __('admin.platform_console') }}</span>
        </a>

        @if (! empty($platformNav))
            <nav class="ms-4 hidden items-center gap-1 md:flex">
                @foreach ($platformNav as $item)
                    <a href="{{ $item->url }}" wire:navigate
                       class="rounded px-3 py-1.5 text-sm font-medium text-topbar-fg transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg hover:text-topbar-fg-hover">{{ $item->label }}</a>
                @endforeach
            </nav>
        @endif

        <div class="ms-auto flex items-center gap-1">
            @include('admin::partials.topbar-tools')
            <span class="mx-1 hidden h-6 w-px bg-topbar-border sm:block" aria-hidden="true"></span>
            @include('admin::partials.topbar-user')
        </div>

        @if (! empty($platformNav))
            {{-- Mobile nav panel: drops below the sticky topbar (opaque so it overlays content).
                 Alpine's .outside guard bails while hidden, so the hamburger opens cleanly. --}}
            <nav x-show="open" x-cloak x-transition @click.outside="open = false"
                 class="absolute inset-x-0 top-full flex flex-col gap-1 border-b border-topbar-border bg-topbar p-3 shadow-card md:hidden">
                @foreach ($platformNav as $item)
                    <a href="{{ $item->url }}" wire:navigate @click="open = false"
                       class="rounded px-3 py-2 text-sm font-medium text-topbar-fg transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg hover:text-topbar-fg-hover">{{ $item->label }}</a>
                @endforeach
            </nav>
        @endif
    </header>

    <main class="flex-1 p-4 lg:p-6">
        {{ $slot }}
    </main>

    <footer class="border-t border-border px-4 py-4 text-xs text-footer">
        <div class="flex flex-col items-center gap-1 sm:flex-row sm:justify-between">
            <span>&copy; {{ now()->year }}
                <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer"
                   class="font-medium text-body-muted transition-colors hover:text-accent hover:underline">{{ config('admin.brand.company') }}</a>.
            </span>
            <span class="hidden sm:inline">{{ __('auth.rights') }}</span>
        </div>
    </footer>
</div>
@include('admin::partials.customizer')
@livewireScripts
</body>
</html>
