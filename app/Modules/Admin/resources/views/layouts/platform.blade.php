<!doctype html>
{{-- The resolver emits only the scheme and the sidebar size attributes here: the platform console never
     follows tenant sidebar/topbar variants, but shares the per-user sidebar size (lg|sm) its menu
     button toggles from 1280px. --}}
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
     the page; from 1280px it stays open, and the menu button switches it between the full sidebar
     and the icon rail through the per-user sidebar size ($store.adminTheme, saved like the tenant
     shell's). `open` resets when the width crosses a breakpoint. The open drawer or laid-over menu
     is a dialog: focus moves to the first link, Tab stays inside and the page behind does not
     scroll. The page stays readable to screen readers (as in the design), so the menu button can
     still close it. Below 1024px the closed drawer is hidden by CSS (so it takes no focus before
     Alpine starts) and inert. Esc or the backdrop closes it and focus goes back to the menu
     button, as it does whenever the menu closes; opening the theme panel closes it. --}}
<div class="{{ $hasNav ? 'platform-shell ' : '' }}min-h-screen"
     x-data="{
        open: false,
        mobile: false,
        wide: false,
        unlisten: null,
        init() {
            const lg = window.matchMedia('(min-width: 64rem)');
            const xl = window.matchMedia('(min-width: 80rem)');
            const sync = () => { this.mobile = ! lg.matches; this.wide = xl.matches; this.open = false; };
            sync();
            lg.addEventListener('change', sync);
            xl.addEventListener('change', sync);
            // wire:navigate swaps the page: drop the listeners with the component.
            this.unlisten = () => {
                lg.removeEventListener('change', sync);
                xl.removeEventListener('change', sync);
            };
            this.$watch('$store.adminTheme.open', (value) => {
                if (value) {
                    this.open = false;
                }
            });
            this.$watch('open', (value) => {
                if (value) {
                    this.$nextTick(() => this.$refs.nav?.querySelector('a[href]')?.focus());
                }
            });
        },
        destroy() {
            this.unlisten?.();
        },
        get expanded() {
            return this.wide ? this.$store.adminTheme.state.sidebar_size !== 'sm' : this.open;
        },
        toggle() {
            if (this.wide) {
                this.$store.adminTheme.set('sidebar_size', this.expanded ? 'sm' : 'lg');
            } else if (this.open) {
                this.close();
            } else {
                this.open = true;
            }
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
        <div x-show="open" x-cloak @click="close()" aria-hidden="true"
             x-transition:enter="platform-fade" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="platform-fade" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-30 bg-gray-900/40 xl:hidden"></div>

        <aside id="platform-sidebar"
               class="admin-sidebar fixed inset-y-0 start-0 z-40 flex flex-col bg-sidebar-bg text-sidebar-fg"
               x-trap.noscroll.noautofocus="open && ! wide"
               :role="open && ! wide ? 'dialog' : null"
               :aria-label="open && ! wide ? @js(__('admin.menu')) : null"
               x-effect="$el.inert = mobile && ! open">
            {{-- Brand (from config — white-label safe) --}}
            <div class="flex h-(--topbar-height) shrink-0 items-center px-4">
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
                                       'sidebar-link flex min-h-control w-full items-center gap-3 px-5 py-2 text-body font-medium transition-colors hover:bg-sidebar-hover hover:text-sidebar-fg-hover any-pointer-coarse:min-h-hit',
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
        {{-- Topbar. Phone: menu button + logo + tools; the logo moves into the sidebar from 1024px.
             With no sidebar (an operator who may see no console screen) it stays here at every width. --}}
        <header class="sticky top-0 z-20 flex h-(--topbar-height) items-center gap-2 border-b border-topbar-border bg-topbar-bg ps-3 pe-3 shadow-card sm:gap-3 sm:pe-6">
            @if ($hasNav)
                {{-- Menu button: below 1024px a soft 36px tile inside a 44px hit area that opens the
                     drawer; from 1024px a plain ☰ in a 48px round hit area — 1024–1279px it lays the
                     full menu over the rail, from 1280px it collapses the sidebar to the rail and back
                     (the per-user sidebar size). The name stays "Menu"; the title and aria-expanded
                     follow the state. --}}
                <button type="button" x-ref="menuButton" @click="toggle()"
                        aria-label="{{ __('admin.menu') }}" aria-controls="platform-sidebar" aria-expanded="false"
                        :aria-expanded="expanded ? 'true' : 'false'"
                        title="{{ __('admin.menu') }}"
                        :title="mobile ? @js(__('admin.menu')) : (expanded ? @js(__('admin.collapse_menu')) : @js(__('admin.expand_menu')))"
                        class="group -ms-1 grid size-hit shrink-0 place-items-center rounded-xl text-topbar-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg lg:ms-0 lg:size-12 lg:rounded-full lg:transition-colors lg:hover:bg-topbar-hover">
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
            {{-- The 28px mark gets a 44px hit area through a transparent extension; the bar keeps its layout. --}}
            <a href="{{ route('platform.dashboard') }}" wire:navigate @class(['hit-area flex shrink-0 items-center', 'lg:hidden' => $hasNav])>
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

        {{-- Caption-size text that follows the reader's text size; the link has a 44px hit area through
             a transparent extension, in one box. --}}
        <footer class="border-t border-border px-4 py-4 text-caption text-footer-fg">
            <div class="flex flex-col items-center gap-1 sm:flex-row sm:justify-between">
                <span>&copy; {{ now()->year }}
                    <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer"
                       class="hit-area inline-block font-medium text-text-muted transition-colors hover:text-primary-text hover:underline">{{ config('admin.brand.company') }}</a>.
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
