<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('admin.settings_system') }} — {{ config('admin.brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="admin-shell min-h-full bg-body font-sans text-base text-body-text antialiased">
@php
    $brandName = config('admin.brand.name');
    $brandInitials = collect(explode(' ', (string) $brandName))
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
<div class="flex min-h-screen flex-col">
    {{-- Topbar (platform console — no tenant sidebar; the settings shell carries the nav) --}}
    <header class="sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-3 border-b border-topbar-border bg-topbar px-4 shadow-card">
        <a href="{{ route('platform.dashboard') }}" wire:navigate class="flex items-center gap-2.5">
            {{-- Brand chip stays literal brand: an identity mark, fixed across every scheme. --}}
            <span class="grid size-9 shrink-0 place-items-center rounded bg-admin-primary text-sm font-bold text-white">{{ $brandInitials }}</span>
            <span class="flex flex-col leading-tight">
                <span class="text-sm font-semibold text-topbar-brand">{{ $brandName }}</span>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-accent">{{ __('admin.platform_console') }}</span>
            </span>
        </a>

        @if (! empty($platformNav))
            <nav class="ms-4 hidden items-center gap-1 md:flex">
                @foreach ($platformNav as $item)
                    <a href="{{ $item->url }}" wire:navigate
                       class="rounded px-3 py-1.5 text-sm font-medium text-topbar-fg transition-colors hover:bg-topbar-hover hover:text-topbar-fg-hover">{{ $item->label }}</a>
                @endforeach
            </nav>
        @endif

        <div class="ms-auto" x-data="{ userOpen: false }">
            <div class="relative">
                <button type="button" @click="userOpen = !userOpen"
                        class="flex items-center gap-2 rounded px-2 py-1.5 transition-colors hover:bg-topbar-hover">
                    <span class="grid size-8 place-items-center rounded-full bg-accent/10 font-semibold text-accent">
                        {{ \Illuminate\Support\Str::of(auth()->user()?->name ?? 'U')->substr(0, 1)->upper() }}
                    </span>
                    <span class="hidden text-sm font-medium text-topbar-fg-strong sm:block">{{ auth()->user()?->name ?? __('admin.account') }}</span>
                </button>
                <div x-show="userOpen" @click.outside="userOpen = false" x-transition x-cloak
                     class="absolute end-0 mt-1 w-48 rounded-md border border-border bg-surface py-1 shadow-card">
                    <form method="POST" action="{{ route('platform.logout') }}">
                        @csrf
                        <button type="submit"
                                class="flex w-full items-center gap-2 px-4 py-2 text-sm text-body-text transition-colors hover:bg-surface-hover">
                            <x-admin::icon name="logout" class="size-4" />
                            {{ __('admin.logout') }}
                        </button>
                    </form>
                </div>
            </div>
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
@livewireScripts
</body>
</html>
