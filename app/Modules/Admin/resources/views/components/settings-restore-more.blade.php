@props([
    // the section's name, for the button's name («Thêm thao tác cho <mục>»)
    'name',
])
@php $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0'; @endphp
{{-- A settings section's "⋮" on phones (the shell's `more` slot): «Khôi phục mặc định», which the
     stamp line (<x-bs-admin::settings-stamp>) offers as a button on wider screens. --}}
<x-bs-admin::dropdown align="end">
    <x-slot:trigger>
        <button type="button" class="hit-area inline-flex size-control items-center justify-center rounded-md text-text-muted hover:bg-surface-hover hover:text-text-strong"
                title="{{ __('admin.set.more_aria', ['name' => $name]) }}" aria-label="{{ __('admin.set.more_aria', ['name' => $name]) }}" data-set-more>
            <x-bs-admin::icon name="ri-more-2-fill" :class="$glyph" />
        </button>
    </x-slot:trigger>
    <button type="button" wire:click="restoreDefaults" x-on:click="open = false" class="w-full gap-2 px-3 text-start whitespace-nowrap text-text hover:bg-surface-hover">
        <x-bs-admin::icon name="ri-restart" :class="$glyph.' text-text-muted'" /><span>{{ __('admin.set.restore') }}</span>
    </button>
</x-bs-admin::dropdown>
