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
@endphp
<div class="relative" x-data="{ userOpen: false }">
    {{-- Velzon reference 1:1 — .header-item sets an EXPLICIT height: $header-height (not a
         percentage chain), so the gray slab spans the full 70px topbar regardless of the
         flex cluster around it, and ENDS at the header's 1.5rem end-padding; 32px avatar,
         13px semibold name over an 11px muted role, no chevron, no hover shift. --}}
    <button type="button" @click="userOpen = ! userOpen" :aria-expanded="userOpen"
            class="flex h-[var(--spacing-header)] items-center gap-2.5 bg-topbar-user-block px-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-topbar-user text-sm font-semibold text-topbar-user-fg">
            {{ \Illuminate\Support\Str::of($name)->substr(0, 1)->upper() }}
        </span>
        <span class="hidden text-start leading-tight sm:block">
            <span class="block text-[13px] font-semibold text-topbar-fg-strong">{{ $shortName }}</span>
            @if (! empty($roleLabel))
                <span class="block text-[11px] text-topbar-fg">{{ $roleLabel }}</span>
            @endif
        </span>
    </button>

    <div x-show="userOpen" @click.outside="userOpen = false" @keydown.escape.window="userOpen = false"
         x-transition x-cloak role="menu"
         class="absolute end-0 z-30 mt-1 w-56 rounded-md border border-border bg-surface py-1 shadow-card">
        <div class="border-b border-border px-4 py-2.5">
            <p class="text-sm font-semibold text-body-text">{{ __('admin.greeting', ['name' => $name]) }}</p>
            @if (! empty($roleLabel))
                <p class="mt-0.5 text-xs text-body-muted">{{ $roleLabel }}</p>
            @endif
        </div>

        {{-- Personal items ONLY — this menu belongs to the signed-in user. Site-wide
             configuration lives under the sidebar's Cài đặt group, not here. --}}
        @unless ($isPlatform)
            <a href="{{ route('admin.settings.security') }}" wire:navigate role="menuitem"
               class="flex min-h-menu-row items-center gap-2.5 px-4 py-2 text-body text-text transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
                <x-bs-admin::icon name="user" class="size-4 text-body-muted" />
                {{ __('admin.settings_account') }}
            </a>
        @endunless

        <div class="my-1 border-t border-border"></div>
        <form method="POST" action="{{ \App\Modules\Admin\Support\AdminPlane::route('logout') }}" role="none">
            @csrf
            <button type="submit" role="menuitem"
                    class="flex min-h-menu-row w-full items-center gap-2.5 px-4 py-2 text-body text-text transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
                <x-bs-admin::icon name="logout" class="size-4 text-body-muted" />
                {{ __('admin.logout') }}
            </button>
        </form>
    </div>
</div>
