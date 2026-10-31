<!doctype html>
{{-- Scheme-only plane: the resolver emits just data-bs-theme here — the platform console
     never follows tenant sidebar/topbar variants (so no sidebar-size axis either). --}}
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
    @include('admin::partials.brand-tokens')
    @livewireStyles
</head>
<body class="admin-shell min-h-full bg-bg font-sans text-base text-text antialiased">
@php
    $hasNav = ! empty($platformNav);
    // The current page's row: white ink, the active fill and an inset bar on the left — told
    // apart by shape, not only by colour (same marking as the tenant sidebar). Links also carry
    // aria-current="page".
    $currentRow = 'font-semibold text-sidebar-fg-active bg-sidebar-active shadow-[inset_3px_0_0_var(--color-sidebar-fg-active)]';
@endphp
{{-- Shell (admin-chrome.css "Platform console shell"): below 1024px the sidebar is a drawer the
     menu button opens; 1024–1279px it is an icon rail and the menu button lays the full menu over
     the page; from 1280px it stays open. `open` is the only state; it resets when the width
     crosses a breakpoint. Opening moves focus to the first link and keeps Tab inside the open
     menu; below 1024px the closed drawer is inert. Esc or the backdrop closes it and focus goes
     back to the menu button. --}}
<div class="{{ $hasNav ? 'platform-shell ' : '' }}min-h-screen"
     x-data="{
        open: false,
        mobile: false,
        wide: false,
        init() {
            const lg = window.matchMedia('(min-width: 64rem)');
            const xl = window.matchMedia('(min-width: 80rem)');
            const sync = () => { this.mobile = ! lg.matches; this.wide = xl.matches; this.open = false; };
            sync();
            lg.addEventListener('change', sync);
            xl.addEventListener('change', sync);
            this.$watch('open', (value) => {
                if (value) {
                    this.$nextTick(() => this.$refs.nav?.querySelector('a[href]')?.focus());
                }
            });
        },
        close() {
            if (! this.open) {
                return;
            }
            this.open = false;
            this.$nextTick(() => this.$refs.menuButton?.focus());
        },
     }"
     :data-nav-open="open ? '' : null"
     @keydown.escape.window="close()">

    @if ($hasNav)
        {{-- Backdrop under the open drawer / the menu laid over the rail. --}}
        <div x-show="open" x-cloak x-transition.opacity @click="close()" aria-hidden="true"
             class="fixed inset-0 z-30 bg-gray-900/40 xl:hidden"></div>

        <aside id="platform-sidebar"
               class="admin-sidebar fixed inset-y-0 start-0 z-40 flex flex-col bg-sidebar text-sidebar-fg"
               x-trap.noautofocus="open && ! wide"
               x-effect="$el.inert = mobile && ! open">
            {{-- Brand (from config — white-label safe) --}}
            <div class="flex h-[var(--spacing-header)] shrink-0 items-center px-4">
                <a href="{{ route('platform.dashboard') }}" wire:navigate class="sidebar-brand-link flex w-full items-center justify-center gap-2.5 text-sidebar-brand">
                    <x-bs-admin::brand-logo />
                </a>
            </div>

            {{-- Headed groups (PlatformNav: platform permissions, a link only once its screen exists). --}}
            <nav x-ref="nav" aria-label="{{ __('admin.menu') }}" class="flex-1 overflow-y-auto pb-6 pt-2.5">
                @foreach ($platformNav as $section)
                    @if ($section->label !== null)
                        <p id="platform-nav-{{ $section->key }}" class="sidebar-heading px-5 pb-1.5 pt-4 text-caption font-bold uppercase tracking-wider text-sidebar-title">
                            <span class="sidebar-label">{{ $section->label }}</span>
                        </p>
                    @endif
                    <ul @if ($section->label !== null) aria-labelledby="platform-nav-{{ $section->key }}" @endif>
                        @foreach ($section->items as $item)
                            <li>
                                <a href="{{ $item->url }}" wire:navigate @if ($item->active) aria-current="page" @endif
                                   @class([
                                       'sidebar-link flex min-h-control w-full items-center gap-3 px-5 py-2 text-body font-medium transition-colors hover:bg-sidebar-hover hover:text-sidebar-fg-hover pointer-coarse:min-h-hit',
                                       $currentRow => $item->active,
                                   ])>
                                    <x-bs-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                                    <span class="sidebar-label min-w-0 flex-1">{{ $item->label }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </nav>
        </aside>
    @endif

    {{-- Content column --}}
    <div class="admin-content flex min-h-screen flex-col">
        {{-- Topbar. Phone: menu button + logo + tools; the logo moves into the sidebar from 1024px. --}}
        <header class="sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-2 border-b border-topbar-border bg-topbar ps-3 pe-3 shadow-card sm:gap-3 sm:pe-6">
            @if ($hasNav)
                {{-- Menu button: below 1024px a soft 36px tile inside a 44px hit area; 1024–1279px a
                     plain ☰ in a 48px round hit area; gone from 1280px, where the sidebar stays open. --}}
                <button type="button" x-ref="menuButton" @click="open = ! open"
                        aria-label="{{ __('admin.menu') }}" aria-controls="platform-sidebar" aria-expanded="false"
                        :aria-expanded="open ? 'true' : 'false'"
                        title="{{ __('admin.menu') }}"
                        :title="mobile ? @js(__('admin.menu')) : (open ? @js(__('admin.collapse_menu')) : @js(__('admin.expand_menu')))"
                        class="group -ms-1 grid size-hit shrink-0 place-items-center rounded-xl text-topbar-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg lg:ms-0 lg:size-12 lg:rounded-full lg:transition-colors lg:hover:bg-topbar-hover xl:hidden">
                    <span class="grid size-9 place-items-center rounded-[0.625rem] bg-surface-muted transition-colors group-hover:bg-topbar-hover group-aria-expanded:bg-topbar-hover lg:hidden">
                        <svg class="h-3 w-[1.375rem]" viewBox="0 0 24 13" fill="currentColor" aria-hidden="true" focusable="false">
                            <rect x="0" y="0.5" width="24" height="2.2" rx="1.1" />
                            <rect x="0" y="5.4" width="24" height="2.2" rx="1.1" />
                            <rect x="0" y="10.3" width="24" height="2.2" rx="1.1" />
                        </svg>
                    </span>
                    <x-bs-admin::icon name="menu" class="hidden size-6 lg:block" />
                </button>
            @endif
            <a href="{{ route('platform.dashboard') }}" wire:navigate class="flex shrink-0 items-center lg:hidden">
                {{-- Brand mark stays literal brand: an identity mark, fixed across every scheme. --}}
                <x-bs-admin::brand-logo context="topbar" />
            </a>

            <div class="ms-auto flex shrink-0 items-center gap-1">
                @include('admin::partials.topbar-tools')
                <span class="mx-1 hidden h-6 w-px bg-topbar-border sm:block" aria-hidden="true"></span>
                @include('admin::partials.topbar-user')
            </div>
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
</div>
@include('admin::partials.customizer')
@livewireScripts
</body>
</html>
