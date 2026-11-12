<div class="flex flex-col gap-4">
    <header class="flex flex-col gap-1">
        <h1 class="m-0 min-w-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('admin.members.title') }}</h1>
    </header>

    <x-bs-admin::card flush role="status" data-state="noperm" class="min-w-0 p-6 text-center">
        <x-bs-admin::icon name="ri-lock-2" class="mx-auto size-8 text-text-muted" />
        <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.members.noperm_title') }}</h2>
        <p class="m-0 mt-1 text-body text-text-muted">{{ $role !== null ? __('admin.members.noperm_text', ['role' => $role]) : __('admin.members.noperm_text_plain') }}</p>
        <x-bs-admin::button variant="outline" color="secondary" class="mt-4" :href="route('admin.dashboard')">
            <x-bs-admin::icon name="ri-home-4" class="size-4" /> {{ __('admin.members.noperm_back') }}
        </x-bs-admin::button>
    </x-bs-admin::card>
</div>
