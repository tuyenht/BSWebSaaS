{{-- The ⋮ of the lead being read: move it into or out of spam. --}}
<x-bs-admin::list.actions :name="$name" :title="__('leads.menu_title', ['name' => $name])">
    <x-slot:menu>
        @if ($status === 'spam')
            <x-bs-admin::list.menu-item icon="ri-mail-check" wire:click="setStatus({{ \Illuminate\Support\Js::from($ulid) }}, 'read')">{{ __('leads.not_spam') }}</x-bs-admin::list.menu-item>
        @else
            <x-bs-admin::list.menu-item icon="ri-spam-2" wire:click="setStatus({{ \Illuminate\Support\Js::from($ulid) }}, 'spam')">{{ __('leads.mark_spam') }}</x-bs-admin::list.menu-item>
        @endif
    </x-slot:menu>
</x-bs-admin::list.actions>
