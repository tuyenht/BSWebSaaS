{{-- The Chuyên mục screen's frame until the design board is ported: its title and empty state. --}}
<div>
    <h1 class="text-lg font-semibold text-text-strong">{{ $label !== '' ? __('categories.title', ['section' => $label]) : __('admin.categories') }}</h1>
    <div class="mx-auto max-w-lg py-16 text-center">
        <span class="mx-auto mb-4 grid size-14 place-items-center rounded-full bg-surface-muted text-text-muted">
            <x-bs-admin::icon name="folder" class="size-7" />
        </span>
        <p class="text-base font-semibold text-text-strong">{{ __('categories.empty_title') }}</p>
        <p class="mx-auto mt-2 max-w-md text-sm text-text-muted">{{ __('categories.empty_text') }}</p>
    </div>
</div>
