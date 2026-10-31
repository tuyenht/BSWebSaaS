{{--
    Shared topbar tools — language · fullscreen · light/dark · notifications.
    Rendered on BOTH the tenant admin and the platform console (each loads the
    adminTheme store + theme-boot, and mirrors the `locale` route per plane).
    Icon-only, evenly spaced, every control a11y-labelled. Each interactive unit
    carries its own x-data so it works even where the layout root has none (the
    platform shell). Dropdowns are bg-surface popovers (scheme-following) — the
    sanctioned header exception, same as the user menu.
--}}
@php
    $locales = \App\Modules\Admin\Support\Locales::available();
    $current = collect($locales)->firstWhere('active', true)
        ?? ['code' => app()->getLocale(), 'label' => strtoupper(app()->getLocale())];
    // Vendored flag SVGs live at public/images/admin/flags/{code}.svg; fall back to
    // the uppercase code for any locale that ships without a flag.
    $flag = fn (string $code) => is_file(public_path("images/admin/flags/{$code}.svg"))
        ? asset("images/admin/flags/{$code}.svg")
        : null;
@endphp

{{-- Language --}}
<div class="relative" x-data="{ langOpen: false }">
    <button type="button" @click="langOpen = ! langOpen" :aria-expanded="langOpen"
            aria-label="{{ __('admin.language') }}"
            class="grid size-control place-items-center rounded text-topbar-fg pointer-coarse:size-hit transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg">
        @if ($flag($current['code']))
            <img src="{{ $flag($current['code']) }}" alt="{{ $current['label'] }}" class="size-5 rounded-full object-cover">
        @else
            <span class="text-xs font-semibold uppercase">{{ $current['code'] }}</span>
        @endif
    </button>
    <div x-show="langOpen" @click.outside="langOpen = false" x-transition x-cloak role="menu"
         class="absolute end-0 z-30 mt-1 w-44 overflow-hidden rounded-md border border-border bg-surface py-1 shadow-card">
        @foreach ($locales as $l)
            <a wire:navigate href="{{ \App\Modules\Admin\Support\AdminPlane::route('locale', ['set' => $l['code']]) }}" role="menuitem"
               @class([
                   'flex min-h-menu-row items-center gap-2.5 px-4 py-2 text-body transition-colors hover:bg-surface-hover pointer-coarse:min-h-menu-row-touch',
                   'font-semibold text-accent' => $l['active'],
                   'text-body-text' => ! $l['active'],
               ])>
                @if ($flag($l['code']))
                    <img src="{{ $flag($l['code']) }}" alt="" class="size-5 shrink-0 rounded-full object-cover">
                @else
                    <span class="grid size-5 shrink-0 place-items-center rounded-full bg-surface-muted text-[10px] font-semibold uppercase">{{ $l['code'] }}</span>
                @endif
                <span>{{ $l['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>

{{-- Fullscreen --}}
<button type="button" x-data="{ full: false }"
        @fullscreenchange.document="full = !! document.fullscreenElement"
        @click="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()"
        aria-label="{{ __('admin.fullscreen') }}"
        class="hidden size-control place-items-center rounded text-topbar-fg pointer-coarse:size-hit transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg sm:grid">
    <x-bs-admin::icon name="fullscreen" class="size-5" x-show="! full" />
    <x-bs-admin::icon name="minimize" class="size-5" x-show="full" x-cloak />
</button>

{{-- Light / dark — shares the Customizer's scheme axis + adminTheme store. The static name holds until Alpine sets the live one. --}}
<button type="button" x-data
        @click="$store.adminTheme.set('scheme', $store.adminTheme.state.scheme === 'dark' ? 'light' : 'dark')"
        aria-label="{{ __('admin.theme_dark') }}"
        :aria-label="$store.adminTheme.state.scheme === 'dark' ? @js(__('admin.theme_light')) : @js(__('admin.theme_dark'))"
        class="grid size-control place-items-center rounded text-topbar-fg pointer-coarse:size-hit transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg">
    <x-bs-admin::icon name="moon" class="size-5" x-show="$store.adminTheme.state.scheme !== 'dark'" />
    <x-bs-admin::icon name="sun" class="size-5" x-show="$store.adminTheme.state.scheme === 'dark'" x-cloak />
</button>

{{-- Notifications — GĐ1 placeholder (no notification system yet → no fake badge) --}}
<div class="relative" x-data="{ notifOpen: false }">
    <button type="button" @click="notifOpen = ! notifOpen" :aria-expanded="notifOpen"
            aria-label="{{ __('admin.notifications') }}"
            class="grid size-control place-items-center rounded text-topbar-fg pointer-coarse:size-hit transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg">
        <x-bs-admin::icon name="bell" class="size-5" />
    </button>
    <div x-show="notifOpen" @click.outside="notifOpen = false" x-transition x-cloak
         class="absolute end-0 z-30 mt-1 w-72 rounded-md border border-border bg-surface shadow-card">
        <p class="border-b border-border px-4 py-2.5 text-sm font-semibold text-body-text">{{ __('admin.notifications') }}</p>
        <div class="grid place-items-center gap-2 px-4 py-8 text-center">
            <span class="grid size-10 place-items-center rounded-full bg-surface-muted text-body-muted">
                <x-bs-admin::icon name="bell" class="size-5" />
            </span>
            <p class="text-sm text-body-muted">{{ __('admin.notifications_empty') }}</p>
        </div>
    </div>
</div>
