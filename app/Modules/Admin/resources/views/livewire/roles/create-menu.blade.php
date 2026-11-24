{{--
    «Tạo nhóm ▾» on the roles list (design board «Nhóm quyền»): the button and its menu, «Nhóm trống»
    then one «Từ: X» per role the member may give ($sources). Kept apart from the screen's view, which
    hosts the explain drawer: the menu's own Alpine names (open, up, close) never sit in a drawer host.
--}}
<div class="relative shrink-0" x-data="{ open: false, up: false, close(back) { this.open = false; if (back) { this.$refs.add.focus() } } }"
    x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="close(true)">
    <x-bs-admin::button color="primary" x-ref="add" data-guide="add" title="{{ __('admin.roles.create_tip') }}"
        aria-haspopup="menu" aria-expanded="false" ::aria-expanded="open.toString()"
        x-on:click="up = window.innerHeight - $el.getBoundingClientRect().bottom < 300; open = ! open; open && $nextTick(() => window.bsMenu.move($refs.menu, 'first'))"
        :label="__('admin.roles.create')" :short="__('admin.list.add_short')">
        <x-bs-admin::icon name="ri-add" class="size-4" />
        <x-slot:end><x-bs-admin::icon name="ri-arrow-down-s" class="size-4" /></x-slot:end>
    </x-bs-admin::button>
    <x-bs-admin::list.menu class="list-menu" :label="__('admin.roles.create')">
        <div class="list-lmh" aria-hidden="true">{{ __('admin.roles.create') }}</div>
        <x-bs-admin::list.menu-item icon="ri-file-add" wire:click="newRole">{{ __('admin.roles.create_blank') }}</x-bs-admin::list.menu-item>
        @if ($sources !== [])
            <x-bs-admin::list.menu-sep />
            <div class="list-lmh" role="presentation">{{ __('admin.roles.create_from_head') }}</div>
            @foreach ($sources as $s)
                <x-bs-admin::list.menu-item :icon="$s['system'] ? 'ri-shield-user' : 'ri-user-settings'" wire:click="copyRole({{ Js::from($s['key']) }})">{{ __('admin.roles.create_from', ['role' => $s['name']]) }}</x-bs-admin::list.menu-item>
            @endforeach
        @endif
    </x-bs-admin::list.menu>
</div>
