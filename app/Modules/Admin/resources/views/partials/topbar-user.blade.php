{{--
    Topbar user area — avatar (initials chip) + name + primary-role line; click
    opens an account menu. Menu links are wired to REAL routes per plane so there
    are no dead-ends: the tenant admin gets My Account + (managers) Site settings;
    the platform console carries only sign-out (its top nav owns the rest).
    $roleLabel is provided by the layout view composer (AdminServiceProvider).
--}}
@php
    $user = auth()->user();
    $name = $user?->name ?? __('admin.account');
    // Topbar shows the SHORT name (≤2 words, Velzon-tight block); menus keep the full name.
    $shortName = \App\Modules\Admin\Support\DisplayName::short($name);
    $isPlatform = \App\Modules\Admin\Support\AdminPlane::isPlatform();
    // Below 768px the topbar drops its language and light/dark buttons: this menu carries both.
    $locales = \App\Modules\Admin\Support\Locales::available();
    $currentLocale = collect($locales)->firstWhere('active', true)
        ?? ['code' => app()->getLocale(), 'label' => strtoupper(app()->getLocale())];
@endphp
<div class="relative" x-data="{ userOpen: false, langOpen: false }">
    {{-- Velzon reference 1:1 — .header-item sets an EXPLICIT height: $header-height (not a
         percentage chain), so the gray slab spans the full 70px topbar regardless of the
         flex cluster around it, and ENDS at the header's 1.5rem end-padding; 32px avatar,
         a semibold name at the small text size over a muted role at the caption size (both follow
         the reader's text size) and a ⌄ that says the block opens a menu. Below 1024px only the
         avatar shows, in a 40px tile (44px on touch). --}}
    <button type="button" x-ref="userButton" @click="userOpen = ! userOpen" :aria-expanded="userOpen" aria-haspopup="true"
            aria-label="{{ $name }}{{ ! empty($roleLabel) ? ' · '.$roleLabel : '' }}"
            class="flex h-(--topbar-height) items-center gap-2.5 bg-topbar-user-block px-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg max-lg:size-control max-lg:justify-center max-lg:rounded-lg max-lg:px-1 max-lg:any-pointer-coarse:size-hit">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-topbar-user text-sm font-semibold text-topbar-user-fg">
            {{ \Illuminate\Support\Str::of($name)->substr(0, 1)->upper() }}
        </span>
        <span class="hidden text-start leading-tight lg:block">
            <span class="block text-small leading-tight font-semibold text-topbar-fg-strong">{{ $shortName }}</span>
            @if (! empty($roleLabel))
                <span class="block text-caption leading-tight text-topbar-fg">{{ $roleLabel }}</span>
            @endif
        </span>
        <x-bs-admin::icon name="ri-arrow-down-s" class="hidden size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 text-topbar-fg lg:block" />
    </button>

    <div x-show="userOpen" @click.outside="userOpen = false" @keydown.escape.window="userOpen = false"
         x-transition x-cloak role="menu"
         class="absolute end-0 z-30 mt-1 w-max min-w-[min(17rem,calc(100vw-1rem))] max-w-[min(22.5rem,calc(100vw-1rem))] rounded-md border border-border bg-surface py-1 shadow-card">
        <div class="border-b border-border px-4 py-2.5">
            <p class="text-sm font-semibold text-body-text">{{ __('admin.greeting', ['name' => $name]) }}</p>
            @if (! empty($roleLabel))
                <p class="mt-0.5 text-xs text-body-muted">{{ $roleLabel }}</p>
            @endif
        </div>

        {{-- Personal items ONLY — this menu belongs to the signed-in user. Site-wide
             configuration lives under the sidebar's Cài đặt group, not here. --}}
        @unless ($isPlatform)
            <a href="{{ route('admin.account') }}" wire:navigate role="menuitem"
               class="flex min-h-menu-row items-center gap-2.5 px-4 py-2 text-body text-text transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
                <x-bs-admin::icon name="user" class="size-4 text-body-muted" />
                {{ __('admin.settings_account') }}
            </a>
        @endunless

        {{-- The look of the admin for this user: opens the Customizer panel. Focus goes back to the
             user button first, so closing the panel returns it there (the menu item is hidden by then). --}}
        <button type="button" role="menuitem" aria-haspopup="dialog"
                @click="userOpen = false; $refs.userButton.focus(); $store.adminTheme.open = true"
                class="flex min-h-menu-row w-full items-center gap-2.5 px-4 py-2 text-body text-text transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
            <x-bs-admin::icon name="ri-palette" class="size-4 text-text-muted" />
            {{ __('admin.appearance') }}
            <span class="ms-auto text-caption text-text-muted"
                  x-text="$store.adminTheme.state.scheme === 'dark' ? @js(__('admin.theme_dark')) : @js(__('admin.theme_light'))">{{ __('admin.theme_light') }}</span>
        </button>

        {{-- The admin's own language: opens the list in place (the current one is ticked). --}}
        <button type="button" role="menuitem" @click="langOpen = ! langOpen" :aria-expanded="langOpen" aria-expanded="false"
                class="flex min-h-menu-row w-full items-center gap-2.5 px-4 py-2 text-body text-text transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
            <x-bs-admin::icon name="ri-translate-2" class="size-4 text-text-muted" />
            {{ __('admin.ui_language') }}
            <span class="ms-auto text-caption text-text-muted">{{ $currentLocale['label'] }}</span>
        </button>
        <div x-show="langOpen" x-cloak role="group" aria-label="{{ __('admin.ui_language') }}">
            @foreach ($locales as $l)
                <a wire:navigate href="{{ \App\Modules\Admin\Support\AdminPlane::route('locale', ['set' => $l['code']]) }}" role="menuitem" lang="{{ $l['code'] }}"
                   @if ($l['active']) aria-current="true" @endif
                   @class([
                       'flex min-h-menu-row items-center gap-2.5 py-2 pe-4 ps-[1.875rem] text-body transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch',
                       'font-semibold text-primary-text' => $l['active'],
                       'text-text' => ! $l['active'],
                   ])>
                    <x-bs-admin::icon name="ri-check" @class(['size-4 shrink-0', 'invisible' => ! $l['active']]) />
                    {{ $l['label'] }}
                </a>
            @endforeach
        </div>

        {{-- Light / dark, the same switch as the topbar button (which only shows from 768px). --}}
        <button type="button" role="menuitem"
                @click="$store.adminTheme.set('scheme', $store.adminTheme.state.scheme === 'dark' ? 'light' : 'dark')"
                class="flex min-h-menu-row w-full items-center gap-2.5 px-4 py-2 text-body text-text transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
            <x-bs-admin::icon name="ri-moon" class="size-4 text-text-muted" x-show="$store.adminTheme.state.scheme !== 'dark'" />
            <x-bs-admin::icon name="ri-sun" class="size-4 text-text-muted" x-show="$store.adminTheme.state.scheme === 'dark'" x-cloak />
            <span x-text="$store.adminTheme.state.scheme === 'dark' ? @js(__('admin.switch_to_light')) : @js(__('admin.switch_to_dark'))">{{ __('admin.switch_to_dark') }}</span>
        </button>

        <div class="my-1 border-t border-border"></div>
        <form method="POST" action="{{ \App\Modules\Admin\Support\AdminPlane::route('logout') }}" role="none">
            @csrf
            <button type="submit" role="menuitem"
                    class="flex min-h-menu-row w-full items-center gap-2.5 px-4 py-2 text-body text-danger-text transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
                <x-bs-admin::icon name="ri-logout-box-r" class="size-4" />
                {{ __('admin.logout') }}
            </button>
        </form>
    </div>
</div>
