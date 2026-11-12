<div class="leads flex flex-col gap-3">
    <header class="flex flex-col gap-1">
        <h1 class="m-0 min-w-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('leads.title') }}</h1>
    </header>

    {{-- A member whose role cannot see leads: no lead, name or phone number is read for them. --}}
    <x-bs-admin::card flush role="status" data-state="noperm" class="min-w-0 p-6 text-center">
        <x-bs-admin::icon name="ri-lock-2" class="mx-auto size-8 text-text-muted" />
        <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('leads.noperm_title') }}</h2>
        <p class="m-0 mt-1 text-body text-text-muted">
            {{ $role !== null ? __('leads.noperm_text', ['role' => $role]) : __('leads.noperm_text_plain') }}
            @if ($viewRoles !== [])
                {{ __('leads.noperm_who', ['roles' => implode(', ', $viewRoles)]) }}
            @endif
            {{ __('leads.noperm_ask') }}
        </p>
        <x-bs-admin::button variant="outline" color="secondary" class="mt-4" :href="route('admin.dashboard')">
            <x-bs-admin::icon name="ri-home-4" class="size-4" /> {{ __('leads.noperm_back') }}
        </x-bs-admin::button>
    </x-bs-admin::card>
</div>
