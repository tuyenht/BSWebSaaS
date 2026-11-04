@props([
    // The address shown before the browser fills it in (the first paint).
    'address' => '',
    'locale' => 'vi',
    'heading' => null,
])
{{-- The Google result preview: the address, the title and the description, each split where Google
     cuts it ("Google cắt từ đây"). Lives inside an Alpine `bsSeoFields` scope (admin-seo-fields.js),
     which computes every line from the form's values in the browser: shared by the editors'
     "Hiện thế nào trên Google" card and the site's search settings. --}}
<div {{ $attributes }}>
    <h3 class="mb-2 text-caption font-semibold tracking-wide text-text-muted uppercase">{{ $heading ?? __('admin.seo.preview_heading') }}</h3>
    <div role="group" aria-label="{{ __('admin.seo.preview_label') }}" class="rounded-md border border-border bg-surface-muted px-3.5 py-3" lang="{{ $locale }}">
        <div class="mb-1 flex min-w-0 items-center gap-2">
            <span class="inline-flex size-[1.375rem] shrink-0 items-center justify-center rounded-full border border-border bg-bg">
                <x-bs-admin::icon name="ri-global" class="size-(--icon-sm) text-text-muted" />
            </span>
            <span class="min-w-0 text-caption text-text-muted [overflow-wrap:anywhere]" x-text="previewAddress">{{ $address }}</span>
        </div>
        <p class="mb-1 text-lead leading-snug text-primary-text [overflow-wrap:anywhere]"><span x-text="previewTitle[0]"></span><span x-show="previewTitle[1] !== ''" x-cloak><span class="seo-cut">{{ __('admin.seo.cut_mark') }}</span><span class="seo-over" x-text="previewTitle[1]"></span></span></p>
        <p class="text-small text-text [overflow-wrap:anywhere]"><span x-text="previewDescription[0]"></span><span x-show="previewDescription[1] !== ''" x-cloak><span class="seo-cut">{{ __('admin.seo.cut_mark') }}</span><span class="seo-over" x-text="previewDescription[1]"></span></span></p>
    </div>
</div>
