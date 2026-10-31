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
    @include('admin::partials.brand-tokens')
    @livewireStyles
</head>
<body class="admin-shell min-h-full bg-bg font-sans text-base text-text antialiased">
{{-- Below 1024px the sidebar is a drawer the menu button opens: focus moves to its first item,
     Tab stays inside and the page behind does not scroll. Esc or the overlay closes it and focus
     goes back to the menu button; the page stays readable to screen readers, so they can also
     close it with the menu button (the open drawer covers it for touch and mouse, as in the
     design). Opening the theme panel closes the drawer. The closed drawer is inert. `open` resets
     when the width crosses 1024px. --}}
<div x-data="{
        open: false,
        mobile: false,
        unlisten: null,
        init() {
            const root = this.$el;
            const lg = window.matchMedia('(min-width: 64rem)');
            const sync = () => { this.mobile = ! lg.matches; this.open = false; };
            sync();
            lg.addEventListener('change', sync);
            // wire:navigate swaps the page: drop the listener with the component.
            this.unlisten = () => lg.removeEventListener('change', sync);
            this.$watch('$store.adminTheme.open', (value) => {
                if (value) {
                    this.open = false;
                }
            });
            this.$watch('open', (value) => {
                if (value) {
                    this.$nextTick(() => root.querySelector('#admin-sidebar nav :is(a[href], button)')?.focus());
                }
            });
        },
        destroy() {
            this.unlisten?.();
        },
        close() {
            if (! this.open) {
                return;
            }
            this.open = false;
            this.$nextTick(() => this.$refs.menuButton?.focus());
        },
     }"
     @keydown.escape.window="close()" class="admin-shell-wrapper min-h-screen">

    {{-- Mobile overlay --}}
    <div x-show="open" @click="close()" x-cloak
         x-transition:enter="transition-opacity duration-150 motion-reduce:transition-none" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-150 motion-reduce:transition-none" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden"></div>

    {{-- Sidebar (Velzon dark vertical). `admin-sidebar` is the [data-layout-position] flip hook. --}}
    <aside id="admin-sidebar"
        x-trap.noscroll.noautofocus="open && mobile"
        :role="open && mobile ? 'dialog' : null"
        :aria-label="open && mobile ? @js(__('admin.menu')) : null"
        x-effect="$el.inert = mobile && ! open"
        class="admin-sidebar fixed inset-y-0 left-0 z-40 flex w-(--sidebar-width) flex-col bg-sidebar-bg text-sidebar-fg transition-all duration-200 motion-reduce:transition-none"
        :class="{
            'translate-x-0': open,
            '-translate-x-full lg:translate-x-0': !open
        }">
        {{-- Faint sidebar texture (data-sidebar-image); z-index:-1 keeps it behind the ink. --}}
        <div class="sidebar-background" aria-hidden="true"></div>

        {{-- Brand (from config — white-label safe) --}}
        <div class="flex h-[var(--spacing-header)] shrink-0 items-center px-4">
            <a href="{{ route('admin.dashboard') }}" wire:navigate class="sidebar-brand-link flex w-full items-center justify-center gap-2.5 text-sidebar-brand">
                <x-bs-admin::brand-logo />
            </a>
        </div>

        {{-- Nav: Velzon grouped visual on the dynamic, RBAC/tenant-scoped $navItems --}}
        @php
            // Accordion: exactly ONE group open at a time = the active page's group (server-
            // resolved), re-synced after each wire:navigate via the morph-updated data-active-group.
            $activeGroup = collect($navItems)->first(fn ($i) => $i->hasChildren() && $i->active)?->key ?? '';
            // The current page's row (and its group's, so the collapsed rail still shows it): white
            // ink, the active fill and an inset bar on the left — told apart by shape, not only by
            // color, now that resting items are white/.85. Links also carry aria-current="page".
            $currentRow = 'text-sidebar-fg-active bg-sidebar-active shadow-[inset_3px_0_0_var(--color-sidebar-fg-active)]';
        @endphp
        <nav class="flex-1 overflow-y-auto px-3 py-3"
             x-data="{ openGroup: @js($activeGroup), t: null }"
             data-active-group="{{ $activeGroup }}"
             x-init="document.addEventListener('livewire:navigated', () => { openGroup = $el.dataset.activeGroup })"
             @scroll="$el.classList.add('is-scrolling'); clearTimeout(t); t = setTimeout(() => $el.classList.remove('is-scrolling'), 1200)">
            {{-- Fully dynamic nested tree (AdminNav: entitlement ∩ RBAC, active resolved there).
                 A node with children renders as an expandable group (open by default when active);
                 leaves are links. Collapsed-rail flyout is a later stage — here groups expand inline. --}}
            <ul class="space-y-1">
                @foreach ($navItems as $item)
                    <li>
                        @if ($item->hasChildren())
                            <div>
                                {{-- Reference state script (_vertical.scss 85-123): an EXPANDED group brightens
                                     (link + arrow follow the active color even when it is not the active page's
                                     group), and the ACTIVE group keeps its arrow rotated down + bright even while
                                     the accordion has another group open (.collapsed.active::after stays at 90°). --}}
                                <button type="button" aria-label="{{ $item->label }}"
                                        @click="openGroup = (openGroup === '{{ $item->key }}' ? '' : '{{ $item->key }}')"
                                        :aria-expanded="openGroup === '{{ $item->key }}'"
                                        :class="openGroup === '{{ $item->key }}' && 'text-sidebar-fg-active'"
                                        @class([
                                            'group flex min-h-control w-full cursor-pointer items-center gap-3 px-3 py-2.5 text-sm font-medium transition-colors hover:text-sidebar-fg-hover pointer-coarse:min-h-hit',
                                            $currentRow => $item->active,
                                        ])>
                                    <x-bs-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                                    <span class="sidebar-label flex-1 text-start">{{ $item->label }}</span>
                                    {{-- Slim reference glyph (~1.05rem), color:inherit — brightens with the states
                                         above; rotates down when open OR when this is the active page's group. --}}
                                    {{-- 12px frame: the reference's font glyph fills only ~half its 1.05rem em-box,
                                         so an SVG at the same frame size reads ~1.5× bigger — measured against the
                                         reference screenshot, 12px lands the same visual arrow size. --}}
                                    <x-bs-admin::icon name="arrow-right-s" class="sidebar-label size-3 shrink-0 transition-transform" ::class="(openGroup === '{{ $item->key }}' || {{ $item->active ? 'true' : 'false' }}) && 'rotate-90'" />
                                </button>
                                <ul x-show="openGroup === '{{ $item->key }}'" x-cloak class="mt-1 space-y-0.5 ps-4">
                                    @foreach ($item->children as $child)
                                        <li>
                                            <a href="{{ $child->url }}" wire:navigate @if ($child->active) aria-current="page" @endif
                                               @class([
                                                   'flex min-h-control items-center gap-2.5 px-3 py-2 text-[13px] transition-colors hover:text-sidebar-fg-hover pointer-coarse:min-h-hit',
                                                   $currentRow => $child->active,
                                               ])>
                                                <span class="h-[1.5px] w-1.5 shrink-0 rounded-full bg-current opacity-60"></span>
                                                <span class="sidebar-label">{{ $child->label }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <a href="{{ $item->url }}" wire:navigate aria-label="{{ $item->label }}" @if ($item->active) aria-current="page" @endif
                               @class([
                                   'group flex min-h-control w-full items-center gap-3 px-3 py-2.5 text-sm font-medium transition-colors hover:text-sidebar-fg-hover pointer-coarse:min-h-hit',
                                   $currentRow => $item->active,
                               ])>
                                <x-bs-admin::icon :name="$item->icon ?? 'doc'" class="size-5 shrink-0" />
                                <span class="sidebar-label">{{ $item->label }}</span>
                                @if ($item->badge !== null)
                                    {{-- Work waiting on this person; hidden with the labels when the rail collapses. --}}
                                    <span class="sidebar-label ml-auto rounded-full bg-admin-danger-solid px-1.5 py-0.5 text-[10.5px] font-bold leading-none text-white">{{ $item->badge }}</span>
                                @endif
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
    </aside>

    {{-- Content column --}}
    <div class="admin-content flex min-h-screen flex-col pl-0 transition-all duration-200 motion-reduce:transition-none lg:pl-(--sidebar-width)">

        {{-- Topbar. `admin-topbar` is the [data-layout-position] flip hook.
             Reference paddings: .75rem start · 1.5rem end (navbar-header), so the user slab
             keeps the same balanced right gap as the content gutter below. --}}
        <header class="admin-topbar sticky top-0 z-20 flex h-[var(--spacing-header)] items-center gap-3 border-b border-topbar-border bg-topbar ps-3 pe-6 shadow-card">
            <button type="button" x-ref="menuButton" @click="open ? close() : (open = true)" aria-label="{{ __('admin.menu') }}"
                    aria-controls="admin-sidebar" aria-expanded="false" :aria-expanded="open ? 'true' : 'false'"
                    class="grid size-control pointer-coarse:size-hit place-items-center rounded text-topbar-fg transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg lg:hidden">
                <x-bs-admin::icon name="menu" class="size-5" />
            </button>
            <button type="button" @click="$store.adminTheme.set('sidebar_size', $store.adminTheme.state.sidebar_size === 'sm' ? 'lg' : 'sm')" aria-label="{{ __('admin.collapse_menu') }}"
                    class="hidden size-control pointer-coarse:size-hit place-items-center rounded text-topbar-fg transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg lg:grid">
                <x-bs-admin::icon name="menu" class="size-5" />
            </button>

            {{-- Search --}}
            <div class="relative hidden sm:block">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-topbar-search-placeholder">
                    <x-bs-admin::icon name="search" class="size-4" />
                </span>
                <input type="search" aria-label="{{ __('admin.search') }}" placeholder="{{ __('admin.search_placeholder') }}"
                       class="w-56 rounded border border-topbar-search-border bg-topbar-search py-2 pl-9 pr-3 text-sm text-topbar-search-fg placeholder:text-topbar-search-placeholder transition-colors focus:border-topbar-search-accent focus:bg-topbar-search-focus lg:w-72">
            </div>

            {{-- Right cluster: language · fullscreen · light/dark · notifications · user --}}
            <div class="ms-auto flex items-center gap-1">
                @include('admin::partials.topbar-tools')
                @include('admin::partials.topbar-user')
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 p-4 lg:p-6">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        {{-- Footer — one left-aligned line (owner preference; deliberately NOT the reference's split layout). --}}
        <footer class="border-t border-border px-4 py-4 text-xs text-footer">
            <div class="flex flex-wrap items-center gap-1">
                <span>&copy; {{ now()->year }}
                    <a href="{{ config('admin.brand.company_url') }}" target="_blank" rel="noopener noreferrer"
                       class="font-medium text-body-muted transition-colors hover:text-accent hover:underline">{{ config('admin.brand.company') }}</a>.
                </span>
                <span>{{ __('auth.rights') }}</span>
            </div>
        </footer>
    </div>
</div>
@include('admin::partials.customizer')
@livewireScripts
</body>
</html>
