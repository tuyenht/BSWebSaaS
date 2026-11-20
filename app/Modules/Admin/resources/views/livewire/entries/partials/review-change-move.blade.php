{{--
    One move of a pending change in a flow with more than one waiting stage (board 17): a note move opens
    the return note, the move going live asks «Duyệt đăng thay đổi?» first, a plain step moves at once.
    Its title says where it takes the change. Expects $ulid, $move {key, label, kind, title}, $inDialog.
--}}
@php
    $Js = \Illuminate\Support\Js::class;
    $call = match (true) {
        $move['kind'] === 'plain' => 'moveChange('.$Js::from($ulid).', '.$Js::from($move['key']).')',
        $inDialog => 'switchChange('.$Js::from($move['kind'] === 'note' ? 'return' : 'approve').', '.$Js::from($move['key']).')',
        default => 'openChange('.$Js::from($ulid).', '.$Js::from($move['kind'] === 'note' ? 'return' : 'approve').', '.$Js::from($move['key']).')',
    };
    $icon = ['note' => 'ri-reply', 'plain' => 'ri-send-plane', 'pri' => 'ri-check-double'][$move['kind']];
@endphp
<x-bs-admin::button :variant="$move['kind'] === 'pri' ? 'solid' : 'outline'" :color="$move['kind'] === 'pri' ? 'primary' : 'neutral'" :title="$move['title']"
                    wire:click="{{ $call }}" wire:loading.attr="disabled" wire:key="chg-mv-{{ $inDialog ? 'd' : 'r' }}-{{ $ulid }}-{{ $move['key'] }}" data-chg-move="{{ $move['key'] }}">
    <x-bs-admin::icon :name="$icon" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />{{ $move['label'] }}
</x-bs-admin::button>
