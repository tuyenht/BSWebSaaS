{{-- The actions on one folder of the media library (its ⋮ in the folder column from 900px, the
     folder sheet below it): rename, upload into it (with media.upload), a sub-folder (top-level folders only), move into another folder,
     move up / down among the folders of its parent, delete. An action that cannot be used here stays
     in the menu with its reason under it. $kids = its sub-folders, $tops = the top-level folders. --}}
@php
    $slug = \Illuminate\Support\Js::from((string) $f->slug);
    $top = $f->parent_id === null;
    $stuck = ! $top ? null : ($kids > 0 ? __('media.lib.reparent_why_kids') : ($tops <= 1 ? __('media.lib.reparent_why_none') : null));
@endphp
<x-bs-admin::list.menu-item icon="ri-pencil" wire:click="openRename({{ $slug }})">{{ __('media.lib.rename') }}</x-bs-admin::list.menu-item>
@if ($canUpload)
    <x-bs-admin::list.menu-item icon="ri-upload-2" wire:click="uploadInto({{ $slug }})">{{ __('media.lib.upload_here') }}</x-bs-admin::list.menu-item>
@endif
@if ($top)
    <x-bs-admin::list.menu-item icon="ri-folder-add" wire:click="openNewFolder({{ $slug }})">{{ __('media.lib.subfolder_new') }}</x-bs-admin::list.menu-item>
@endif
@if ($stuck !== null)
    <x-bs-admin::list.menu-item icon="ri-folder-transfer" :why="$stuck">{{ __('media.lib.folder_reparent') }}</x-bs-admin::list.menu-item>
@else
    <x-bs-admin::list.menu-item icon="ri-folder-transfer" wire:click="openReparent({{ $slug }})">{{ __('media.lib.folder_reparent') }}</x-bs-admin::list.menu-item>
@endif
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
<x-bs-admin::list.menu-sep />
<x-bs-admin::list.menu-item icon="ri-folder-reduce" danger wire:click="askDeleteFolder({{ $slug }})">{{ __('media.lib.folder_delete') }}</x-bs-admin::list.menu-item>
