<!doctype html>
{{-- The chrome data-* attributes (resolved app→site→user) SSR here so the first paint
     is already themed; <html> survives wire:navigate morphs. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" @foreach ($chrome as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- CSRF token for fetch()-based admin endpoints (DAM presign/register/upload, theme prefs). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('admin.dashboard') }} — {{ config('admin.brand.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @include('admin::partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="admin-shell min-h-full bg-body font-sans text-base text-body-text antialiased">
<div x-data="{ open: false }" class="admin-shell-wrapper min-h-screen">

    {{-- Mobile overlay --}}
    <div x-show="open" x-transition.opacity @click="open = false" x-cloak
         class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden"></div>

    {{-- Sidebar (Velzon dark vertical). `admin-sidebar` is the [data-layout-position] flip hook. --}}
    <aside
        class="admin-sidebar fixed inset-y-0 left-0 z-40 flex w-[var(--spacing-sidebar)] flex-col bg-sidebar text-sidebar-fg transition-all duration-200"
        :class="{
            'translate-x-0': open,
            '-translate-x-full lg:translate-x-0': !open
        }">
        {{-- Faint sidebar texture (data-sidebar-image); z-index:-1 keeps it behind the ink. --}}
        <div class="sidebar-background" aria-hidden="true"></div>

        {{-- Brand (from config — white-label safe) --}}
        <div class="flex h-[var(--spacing-header)] shrink-0 items-center px-4">
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="sidebar-brand-link flex w-full items-center justify-center gap-2.5 text-sidebar-brand">
                <x-admin::brand-logo />
            </a>
        </div>

        {{-- Nav: Velzon grouped visual on the dynamic, RBAC/tenant-scoped $navItems --}}
        @php
            // Accordion: exactly ONE group open at a time = the active page's group (server-
            // resolved), re-synced after each wire:navigate via the morph-updated data-active-group.
            $activeGroup = collect($navItems)->first(fn ($i) => $i->hasChildren() && $i->active)?->key ?? '';
        @endphp
        <nav class="flex-1 overflow-y-auto px-3 py-3"
             x-data="{ openGroup: @js($activeGroup), t: null }"
             data-active-group="{{ $activeGroup }}"
             x-init="document.addEventListener('livewire:navigated', () => { openGroup = $el.dataset.activeGroup })"
             @scroll="$el.classList.add('is-scrolling'); clearTimeout(t); t = setTimeout(() => $el.classList.remove('is-scrolling'), 1200)">
            <p class="sidebar-label px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-title">{{ __('admin.menu') }}</p>
            {{-- Fully dynamic nested tree (AdminNav: entitlement ∩ RBAC, active resolved there).
                 A node with children renders as an expandable group (open by default when active);
                 leaves are links. Collapsed-rail flyout is a later stage — here groups expand inline. --}}
            <ul class="space-y-1">
                @foreach ($navItems as $item)
                    <li>
                        @if ($item->hasChildren())
                            <div>
                                <button type="button" aria-label="{{ $item->label }}"
                                        @click="openGroup = (openGroup === '{{ $item->key }}' ? '' : '{{ $item->key }}')"
                                        :aria-expanded="openGroup === '{{ $item->key }}'"
                                        @class([
                                            'group flex w-full cursor-pointer items-center gap-3 px-3 py-2.5 text-sm font-medium transition-colors hover:text-sidebar-fg-hover',
                                            'text-sidebar-fg-active' => $item->active,
                                        ])>
                                    <x-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                                    <span class="sidebar-label flex-1 text-start">{{ $item->label }}</span>
                                    <x-admin::icon name="chevron-down" class="sidebar-label size-4 shrink-0 transition-transform" ::class="openGroup === '{{ $item->key }}' && 'rotate-180'" />
                                </button>
                                <ul x-show="openGroup === '{{ $item->key }}'" x-cloak class="mt-1 space-y-0.5 ps-4">
                                    @foreach ($item->children as $child)
                                        <li>
                                            <a href="{{ $child->url }}" wire:navigate
                                               @class([
                                                   'flex items-center gap-2.5 px-3 py-2 text-[13px] transition-colors hover:text-sidebar-fg-hover',
                                                   'text-sidebar-fg-active' => $child->active,
                                               ])>
                                                <span class="h-[1.5px] w-1.5 shrink-0 rounded-full bg-current opacity-60"></span>
                                                <span class="sidebar-label">{{ $child->label }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <a href="{{ $item->url }}" wire:navigate aria-label="{{ $item->label }}"
                               @class([
                                   'group flex w-full items-center gap-3 px-3 py-2.5 text-sm font-medium transition-colors hover:text-sidebar-fg-hover',
                                   'text-sidebar-fg-active' => $item->active,
                               ])>
                                <x-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                                <span class="sidebar-label">{{ $item->label }}</span>
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    </aside>

    {{-- Content column --}}
    <div class="admin-content flex min-h-screen flex-col pl-0 transition-all duration-200 lg:pl-[var(--spacing-sidebar)]">

        {{-- Topbar. `admin-topbar` is the [data-layout-position] flip hook. --}}
        <header class="admin-topbar sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-3 border-b border-topbar-border bg-topbar px-4 shadow-card">
            <button type="button" @click="open = true" aria-label="{{ __('admin.open_menu') }}"
                    class="grid size-9 place-items-center rounded text-topbar-fg transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg lg:hidden">
                <x-admin::icon name="menu" class="size-5" />
            </button>
            <button type="button" @click="$store.adminTheme.set('sidebar_size', $store.adminTheme.state.sidebar_size === 'sm' ? 'lg' : 'sm')" aria-label="{{ __('admin.collapse_menu') }}"
                    class="hidden size-9 place-items-center rounded text-topbar-fg transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg lg:grid">
                <x-admin::icon name="menu" class="size-5" />
            </button>

            {{-- Search --}}
            <div class="relative hidden sm:block">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-topbar-search-placeholder">
                    <x-admin::icon name="search" class="size-4" />
                </span>
                <input type="search" aria-label="{{ __('admin.search') }}" placeholder="{{ __('admin.search_placeholder') }}"
                       class="w-56 rounded border border-topbar-search-border bg-topbar-search py-2 pl-9 pr-3 text-sm text-topbar-search-fg placeholder:text-topbar-search-placeholder transition-colors focus:border-topbar-search-accent focus:bg-topbar-search-focus focus:outline-none focus:ring-2 focus:ring-topbar-search-accent/20 lg:w-72">
            </div>

            {{-- Right cluster: language · fullscreen · light/dark · notifications · user --}}
            <div class="ms-auto flex items-center gap-1">
                @include('admin::partials.topbar-tools')
                <span class="mx-1 hidden h-6 w-px bg-topbar-border sm:block" aria-hidden="true"></span>
                @include('admin::partials.topbar-user')
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>

        {{-- Footer --}}
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
