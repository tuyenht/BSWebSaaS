<!doctype html>
<html lang="vi" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('admin.dashboard') }} — {{ config('admin.brand.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="admin-shell min-h-full bg-body font-sans text-base text-gray-700 antialiased">
@php
    $brandName = config('admin.brand.name');
    $brandTagline = config('admin.brand.tagline');
    $brandInitials = collect(explode(' ', (string) $brandName))
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
<div x-data="{ open: false, collapsed: false }" class="min-h-screen">

    {{-- Mobile overlay --}}
    <div x-show="open" x-transition.opacity @click="open = false" x-cloak
         class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden"></div>

    {{-- Sidebar (Velzon dark vertical) --}}
    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-[var(--spacing-sidebar)] flex-col bg-admin-primary text-[#abb9e8] transition-all duration-200"
        :class="{
            'lg:w-[var(--spacing-sidebar-collapsed)]': collapsed,
            'lg:w-[var(--spacing-sidebar)]': !collapsed,
            'translate-x-0': open,
            '-translate-x-full lg:translate-x-0': !open
        }">
        {{-- Brand (from config — white-label safe) --}}
        <div class="flex h-[var(--spacing-header)] shrink-0 items-center px-4">
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="flex items-center gap-2.5 text-white">
                <span class="grid size-9 shrink-0 place-items-center rounded bg-white/10 text-sm font-bold">{{ $brandInitials }}</span>
                <span x-show="!collapsed" class="flex flex-col leading-tight">
                    <span class="text-base font-semibold tracking-tight">{{ $brandName }}</span>
                    <span class="text-[11px] text-white/55">{{ $brandTagline }}</span>
                </span>
            </a>
        </div>

        {{-- Nav: Velzon grouped visual on the dynamic, RBAC/tenant-scoped $navItems --}}
        <nav class="flex-1 overflow-y-auto px-3 py-3">
            <p x-show="!collapsed" class="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-white/40">{{ __('admin.menu') }}</p>
            <ul class="space-y-1">
                <li>
                    <a href="{{ route('admin.dashboard') }}" wire:navigate
                       @class([
                           'group flex items-center gap-3 rounded px-3 py-2.5 text-sm font-medium transition-colors hover:bg-white/10 hover:text-white',
                           'bg-white/15 text-white' => request()->routeIs('admin.dashboard'),
                       ])>
                        <x-admin::icon name="dashboard" class="size-5 shrink-0" />
                        <span x-show="!collapsed">{{ __('admin.dashboard') }}</span>
                    </a>
                </li>
                @foreach ($navItems as $item)
                    <li>
                        <a href="{{ $item->url }}" wire:navigate
                           class="group flex items-center gap-3 rounded px-3 py-2.5 text-sm font-medium transition-colors hover:bg-white/10 hover:text-white">
                            <x-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                            <span x-show="!collapsed">{{ $item->label }}</span>
                        </a>
                    </li>
                @endforeach
                @can('users.manage')
                    <li>
                        <a href="{{ route('admin.users.index') }}" wire:navigate
                           @class([
                               'group flex items-center gap-3 rounded px-3 py-2.5 text-sm font-medium transition-colors hover:bg-white/10 hover:text-white',
                               'bg-white/15 text-white' => request()->routeIs('admin.users*'),
                           ])>
                            <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19M21 19v-1.5a3.5 3.5 0 0 0-2.6-3.4M14.5 4.4a3.5 3.5 0 0 1 0 6.2M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/></svg>
                            <span x-show="!collapsed">{{ __('admin.users') }}</span>
                        </a>
                    </li>
                @endcan
                <li>
                    <a href="{{ route('admin.settings') }}" wire:navigate
                       @class([
                           'group flex items-center gap-3 rounded px-3 py-2.5 text-sm font-medium transition-colors hover:bg-white/10 hover:text-white',
                           'bg-white/15 text-white' => request()->routeIs('admin.settings*'),
                       ])>
                        <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.4 3.5h3.2l.5 2.4 1.9.8 2.1-1.3 2.2 2.2-1.3 2.1.8 1.9 2.4.5v3.2l-2.4.5-.8 1.9 1.3 2.1-2.2 2.2-2.1-1.3-1.9.8-.5 2.4h-3.2l-.5-2.4-1.9-.8-2.1 1.3-2.2-2.2 1.3-2.1-.8-1.9L2 13.6v-3.2l2.4-.5.8-1.9-1.3-2.1 2.2-2.2 2.1 1.3 1.9-.8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span x-show="!collapsed">{{ __('admin.settings') }}</span>
                    </a>
                </li>
            </ul>
        </nav>
    </aside>

    {{-- Content column --}}
    <div class="flex min-h-screen flex-col transition-all duration-200"
         :class="collapsed ? 'lg:pl-[var(--spacing-sidebar-collapsed)]' : 'lg:pl-[var(--spacing-sidebar)]'">

        {{-- Topbar --}}
        <header class="sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-3 border-b border-gray-300 bg-white px-4 shadow-card">
            <button type="button" @click="open = true" aria-label="{{ __('admin.open_menu') }}"
                    class="grid size-9 place-items-center rounded text-gray-600 transition-colors hover:bg-gray-100 lg:hidden">
                <x-admin::icon name="menu" class="size-5" />
            </button>
            <button type="button" @click="collapsed = !collapsed" aria-label="{{ __('admin.collapse_menu') }}"
                    class="hidden size-9 place-items-center rounded text-gray-600 transition-colors hover:bg-gray-100 lg:grid">
                <x-admin::icon name="menu" class="size-5" />
            </button>

            {{-- Search --}}
            <div class="relative hidden sm:block">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <x-admin::icon name="search" class="size-4" />
                </span>
                <input type="search" aria-label="{{ __('admin.search') }}" placeholder="{{ __('admin.search_placeholder') }}"
                       class="w-56 rounded border border-gray-200 bg-gray-50 py-2 pl-9 pr-3 text-sm text-gray-700 placeholder:text-gray-400 transition-colors focus:border-admin-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-admin-primary/20 lg:w-72">
            </div>

            {{-- User menu --}}
            <div class="ms-auto" x-data="{ userOpen: false }">
                <div class="relative">
                    <button type="button" @click="userOpen = !userOpen"
                            class="flex items-center gap-2 rounded px-2 py-1.5 transition-colors hover:bg-gray-100">
                        <span class="grid size-8 place-items-center rounded-full bg-admin-primary/10 font-semibold text-admin-primary">
                            {{ \Illuminate\Support\Str::of(auth()->user()?->name ?? 'U')->substr(0, 1)->upper() }}
                        </span>
                        <span class="hidden text-sm font-medium text-gray-700 sm:block">{{ auth()->user()?->name ?? __('admin.account') }}</span>
                    </button>
                    <div x-show="userOpen" @click.outside="userOpen = false" x-transition x-cloak
                         class="absolute end-0 mt-1 w-48 rounded-md border border-gray-200 bg-white py-1 shadow-card">
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100">
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
        <footer class="border-t border-gray-200 px-4 py-4 text-center text-xs text-gray-500 lg:text-start">
            &copy; {{ now()->year }}
            <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer"
               class="font-medium text-gray-600 transition-colors hover:text-admin-primary hover:underline">{{ config('admin.brand.company') }}</a>.
            {{ __('auth.rights') }}
        </footer>
    </div>
</div>
@livewireScripts
</body>
</html>
