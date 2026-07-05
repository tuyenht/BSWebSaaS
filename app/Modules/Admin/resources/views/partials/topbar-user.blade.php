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
    $isPlatform = \App\Modules\Admin\Support\AdminPlane::isPlatform();
@endphp
<div class="relative" x-data="{ userOpen: false }">
    <button type="button" @click="userOpen = ! userOpen" :aria-expanded="userOpen"
            class="flex items-center gap-2 rounded px-1.5 py-1 transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg/70">
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-topbar-user text-sm font-semibold text-topbar-user-fg">
            {{ \Illuminate\Support\Str::of($name)->substr(0, 1)->upper() }}
        </span>
        <span class="hidden text-start leading-tight sm:block">
            <span class="block text-sm font-medium text-topbar-fg-strong">{{ $name }}</span>
            @if (! empty($roleLabel))
                <span class="block text-xs text-topbar-fg">{{ $roleLabel }}</span>
            @endif
        </span>
        <x-admin::icon name="chevron-down" class="hidden size-4 text-topbar-fg sm:block" />
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

        @unless ($isPlatform)
            <a href="{{ route('admin.settings.security') }}" wire:navigate role="menuitem"
               class="flex items-center gap-2.5 px-4 py-2 text-sm text-body-text transition-colors hover:bg-surface-hover">
                <x-admin::icon name="user" class="size-4 text-body-faint" />
                {{ __('admin.settings_account') }}
            </a>
            @can('settings.manage')
                <a href="{{ route('admin.settings.email') }}" wire:navigate role="menuitem"
                   class="flex items-center gap-2.5 px-4 py-2 text-sm text-body-text transition-colors hover:bg-surface-hover">
                    <x-admin::icon name="settings" class="size-4 text-body-faint" />
                    {{ __('admin.settings_workspace') }}
                </a>
            @endcan
        @endunless

        <div class="my-1 border-t border-border"></div>
        <form method="POST" action="{{ \App\Modules\Admin\Support\AdminPlane::route('logout') }}" role="none">
            @csrf
            <button type="submit" role="menuitem"
                    class="flex w-full items-center gap-2.5 px-4 py-2 text-sm text-body-text transition-colors hover:bg-surface-hover">
                <x-admin::icon name="logout" class="size-4 text-body-faint" />
                {{ __('admin.logout') }}
            </button>
        </form>
    </div>
</div>
