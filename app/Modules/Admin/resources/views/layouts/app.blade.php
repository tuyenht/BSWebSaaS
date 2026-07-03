<!doctype html>
{{-- The chrome data-* attributes (resolved app→site→user) SSR here so the first paint
     is already themed; <html> survives wire:navigate morphs. --}}
<html lang="vi" class="h-full" @foreach ($chrome as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- CSRF token for fetch()-based admin endpoints (DAM presign/register/upload, theme prefs). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('admin.dashboard') }} — {{ config('admin.brand.name') }}</title>
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
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="sidebar-brand-link flex items-center gap-2.5 text-sidebar-brand">
                <x-admin::brand-logo />
            </a>
        </div>

        {{-- Nav: Velzon grouped visual on the dynamic, RBAC/tenant-scoped $navItems --}}
        <nav class="flex-1 overflow-y-auto px-3 py-3">
            <p class="sidebar-label px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-title">{{ __('admin.menu') }}</p>
            {{-- Fully dynamic: dashboard/media/contacts/users/settings + content sections all
                 come from AdminNav (ModuleCatalog ∩ entitlement ∩ RBAC); active is resolved there. --}}
            <ul class="space-y-1">
                @foreach ($navItems as $item)
                    <li>
                        <a href="{{ $item->url }}" wire:navigate title="{{ $item->label }}"
                           @class([
                               'group flex items-center gap-3 rounded px-3 py-2.5 text-sm font-medium transition-colors hover:bg-sidebar-hover hover:text-sidebar-fg-hover',
                               'bg-sidebar-active text-sidebar-fg-active' => $item->active,
                           ])>
                            <x-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                            <span class="sidebar-label">{{ $item->label }}</span>
                        </a>
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
                    class="grid size-9 place-items-center rounded text-topbar-fg transition-colors hover:bg-topbar-hover lg:hidden">
                <x-admin::icon name="menu" class="size-5" />
            </button>
            <button type="button" @click="$store.adminTheme.set('sidebar_size', $store.adminTheme.state.sidebar_size === 'sm' ? 'lg' : 'sm')" aria-label="{{ __('admin.collapse_menu') }}"
                    class="hidden size-9 place-items-center rounded text-topbar-fg transition-colors hover:bg-topbar-hover lg:grid">
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

            {{-- User menu --}}
            <div class="ms-auto" x-data="{ userOpen: false }">
                <div class="relative">
                    <button type="button" @click="userOpen = !userOpen"
                            class="flex items-center gap-2 rounded px-2 py-1.5 transition-colors hover:bg-topbar-hover">
                        <span class="grid size-8 place-items-center rounded-full bg-topbar-user font-semibold text-topbar-user-fg">
                            {{ \Illuminate\Support\Str::of(auth()->user()?->name ?? 'U')->substr(0, 1)->upper() }}
                        </span>
                        <span class="hidden text-sm font-medium text-topbar-fg-strong sm:block">{{ auth()->user()?->name ?? __('admin.account') }}</span>
                    </button>
                    <div x-show="userOpen" @click.outside="userOpen = false" x-transition x-cloak
                         class="absolute end-0 mt-1 w-48 rounded-md border border-border bg-surface py-1 shadow-card">
                        <form method="POST" action="{{ route('admin.logout') }}">
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

        {{-- Page content --}}
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        <footer class="border-t border-border px-4 py-4 text-center text-xs text-footer lg:text-start">
            &copy; {{ now()->year }}
            <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer"
               class="font-medium text-body-muted transition-colors hover:text-accent hover:underline">{{ config('admin.brand.company') }}</a>.
            {{ __('auth.rights') }}
        </footer>
    </div>
</div>
@include('admin::partials.customizer')
@livewireScripts
</body>
</html>
