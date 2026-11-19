<div class="flex flex-col gap-3">
    <header class="flex flex-col gap-1">
        <h1 class="m-0 min-w-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('form_builder.title') }}</h1>
    </header>

    {{-- A member whose role cannot change forms: no form is read for them. --}}
    <x-bs-admin::card flush role="status" data-state="noperm" class="min-w-0 p-6 text-center">
        <x-bs-admin::icon name="ri-shield-keyhole" class="mx-auto size-8 text-text-muted" />
        <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('form_builder.noperm_title') }}</h2>
        <p class="m-0 mt-1 text-body text-text-muted">
            {{ $role !== null ? __('form_builder.noperm_text', ['role' => $role]) : __('form_builder.noperm_text_plain') }}
            @if ($manageRoles !== [])
                {{ __('form_builder.noperm_who', ['roles' => implode(', ', $manageRoles)]) }}
            @endif
        </p>
        @if ($canView)
            <p class="m-0 mt-1 text-body text-text-muted">{{ __('form_builder.noperm_view') }}</p>
        @endif
        <div class="mt-4 flex flex-wrap justify-center gap-2">
            @if ($canView)
                <x-bs-admin::button color="primary" :href="route('admin.forms.index')">
                    <x-bs-admin::icon name="ri-mail" class="size-4" /> {{ __('form_builder.open_contacts') }}
                </x-bs-admin::button>
            @endif
            <x-bs-admin::button variant="outline" color="secondary" :href="route('admin.dashboard')">
                <x-bs-admin::icon name="ri-home-4" class="size-4" /> {{ __('form_builder.back_home') }}
            </x-bs-admin::button>
        </div>
    </x-bs-admin::card>
</div>
