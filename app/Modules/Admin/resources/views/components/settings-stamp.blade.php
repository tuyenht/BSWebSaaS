@props([
    // «Cập nhật lần cuối … · <người>» (RestoresSettingsSection::stampText()), null when nothing was saved yet
    'stamp' => null,
])
{{-- The board's line above a settings section's fields: when it was last saved and by whom, and
     «Khôi phục mặc định» (on phones the line shows only once something was saved, and the button
     moves into the title row's "⋮": <x-bs-admin::settings-restore-more>). --}}
<div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-3.5 border-b border-border pb-3.5', 'max-md:hidden' => $stamp === null]) }} data-set-stamp>
    <span class="inline-flex min-w-0 items-center gap-[7px] text-caption text-text-muted">
        <x-bs-admin::icon name="ri-time" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
        <span class="min-w-0 [overflow-wrap:anywhere]">{{ $stamp ?? __('admin.set.stamp_none') }}</span>
    </span>
    <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="restoreDefaults" wire:loading.attr="disabled" class="max-md:hidden" data-set-restore>
        <x-bs-admin::icon name="ri-restart" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" /><span>{{ __('admin.set.restore') }}</span>
    </x-bs-admin::button>
</div>
