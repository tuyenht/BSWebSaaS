{{-- The ⋮ of the lead being read: move it into or out of spam, move it to the Trash (no question:
     the message offers «Hoàn tác»), or erase it at the visitor's request (never during a support
     session). A lead in the Trash offers only the erase («Khôi phục» is on its band). --}}
<x-bs-admin::list.actions :name="$name" :title="__('leads.menu_title', ['name' => $name])">
    <x-slot:menu>
        @unless ($trashed)
            @if ($status === 'spam')
                <x-bs-admin::list.menu-item icon="ri-mail-check" wire:click="setStatus({{ \Illuminate\Support\Js::from($ulid) }}, 'read')">{{ __('leads.not_spam') }}</x-bs-admin::list.menu-item>
            @else
                <x-bs-admin::list.menu-item icon="ri-spam-2" wire:click="setStatus({{ \Illuminate\Support\Js::from($ulid) }}, 'spam')">{{ __('leads.mark_spam') }}</x-bs-admin::list.menu-item>
            @endif
            <x-bs-admin::list.menu-sep />
            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger wire:click="moveToTrash({{ \Illuminate\Support\Js::from($ulid) }})">{{ __('leads.to_trash') }}</x-bs-admin::list.menu-item>
        @endunless
        @if ($support)
            {{-- support staff never erase a visitor's data on a member's behalf: the item stays, with why --}}
            <x-bs-admin::list.menu-item icon="ri-delete-bin-6" danger :why="__('admin.support.locked')">{{ __('leads.erase') }}</x-bs-admin::list.menu-item>
        @else
            <x-bs-admin::list.menu-item icon="ri-delete-bin-6" danger wire:click="askErase({{ \Illuminate\Support\Js::from($ulid) }})">{{ __('leads.erase') }}</x-bs-admin::list.menu-item>
        @endif
    </x-slot:menu>
</x-bs-admin::list.actions>
