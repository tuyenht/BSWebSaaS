{{-- The actions on one folder of the media library (its ⋮ in the folder column from 900px, the
     folder sheet below it): rename, move up / down among the folders. An action that cannot
     be used here stays in the menu with its reason under it. --}}
@php $slug = \Illuminate\Support\Js::from((string) $f->slug); @endphp
<x-bs-admin::list.menu-item icon="ri-pencil" wire:click="openRename({{ $slug }})">{{ __('media.lib.rename') }}</x-bs-admin::list.menu-item>
<x-bs-admin::list.menu-sep />
@if ($first)
    <x-bs-admin::list.menu-item icon="ri-arrow-up" :why="__('media.lib.folder_top_why')">{{ __('media.lib.folder_up') }}</x-bs-admin::list.menu-item>
@else
    <x-bs-admin::list.menu-item icon="ri-arrow-up" wire:click="moveFolder({{ $slug }}, -1)">{{ __('media.lib.folder_up') }}</x-bs-admin::list.menu-item>
@endif
@if ($last)
    <x-bs-admin::list.menu-item icon="ri-arrow-down" :why="__('media.lib.folder_bottom_why')">{{ __('media.lib.folder_down') }}</x-bs-admin::list.menu-item>
@else
    <x-bs-admin::list.menu-item icon="ri-arrow-down" wire:click="moveFolder({{ $slug }}, 1)">{{ __('media.lib.folder_down') }}</x-bs-admin::list.menu-item>
@endif
