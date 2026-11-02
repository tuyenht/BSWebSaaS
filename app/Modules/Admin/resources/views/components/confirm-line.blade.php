@props([
    'icon' => 'ri-information',
])
{{-- One consequence in a <x-bs-admin::confirm-dialog>: a muted icon, then the sentence. --}}
<div class="flex items-start gap-2">
    <x-bs-admin::icon :name="$icon" class="mt-1 size-[calc(1rem+var(--user-text-boost))] shrink-0 text-text-muted" />
    <span class="min-w-0 [overflow-wrap:anywhere]">{{ $slot }}</span>
</div>
