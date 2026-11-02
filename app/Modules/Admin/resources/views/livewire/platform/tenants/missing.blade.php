{{-- A customer page whose address is unknown (404): the design's "not found" card inside the console. --}}
<div class="flex flex-col gap-4">
    <div>
        <a href="{{ route('platform.tenants.index') }}" wire:navigate
           class="inline-flex min-h-hit items-center gap-1 rounded pe-2 text-body font-medium text-primary-text hover:underline">
            <x-bs-admin::icon name="ri-arrow-left-s" class="size-5" />{{ __('admin.customers_back') }}
        </a>
    </div>
    <div class="flex flex-col items-start gap-3 rounded-md border border-border bg-surface px-4 py-6 shadow-card">
        <h1 class="m-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.customers_notfound_title') }}</h1>
        <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('admin.customers_notfound_body') }}</p>
        <x-bs-admin::button color="primary" :href="route('platform.tenants.index')">{{ __('admin.customers_notfound_cta') }}</x-bs-admin::button>
    </div>
</div>
