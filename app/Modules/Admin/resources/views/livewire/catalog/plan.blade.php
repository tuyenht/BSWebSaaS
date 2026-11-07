<div class="catalog-index flex flex-col gap-3">
    <header class="flex flex-col gap-1">
        <h1 class="m-0 min-w-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('catalog.title') }}</h1>
    </header>

    {{-- The site's plan has no catalog: said before any permission question, and no rows are read. --}}
    <x-bs-admin::card flush role="status" data-state="plan" class="min-w-0 p-6 text-center">
        <x-bs-admin::icon name="ri-lock-2" class="mx-auto size-8 text-text-muted" />
        <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('catalog.plan_title') }}</h2>
        <p class="m-0 mt-1 text-body text-text-muted">{{ __('catalog.plan_text') }}</p>
        @if ($supportMail !== '')
            <x-bs-admin::button variant="outline" color="secondary" class="mt-4" :href="'mailto:'.$supportMail">
                <x-bs-admin::icon name="ri-customer-service-2" class="size-4" /> {{ __('catalog.plan_support') }}
            </x-bs-admin::button>
        @endif
    </x-bs-admin::card>
</div>
