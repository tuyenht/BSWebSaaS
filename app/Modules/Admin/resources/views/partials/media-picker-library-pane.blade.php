{{-- Library tab (handoff screen 8): folder sidebar · toolbar (search/sort/type/format/view) ·
     bulk bar · grid|list · empty state · server-side pager. Receives $mp = mediaPickerState().
     The detail (ⓘ) panel and drag ordering arrive in Phase 4 — no dead affordances until then. --}}
@php($fileGlyph = fn (string $mime): array => str_starts_with($mime, 'video/')
    ? ['ri-film', '#299cdb']
    : (str_starts_with($mime, 'audio/')
        ? ['ri-music-2', '#8b5cf6']
        : ($mime === 'application/pdf' ? ['ri-file-pdf-2', '#f06548'] : ['ri-file-excel-2', '#0ab39c'])))
{{-- Manage mode (the /admin/media page, not the insert picker): clicking a tile opens its detail
     and the checkbox selects for bulk ops; in the picker a tile click IS the pick. Never in trash. --}}
@php($manage = ($pickerManageMode ?? false) && ! $pickerTrash)
<div class="flex min-h-0 flex-1">

    {{-- Folder sidebar — a rail ≥640px, collapsed into the toolbar dropdown below on narrow screens. --}}
    <div class="hidden w-[184px] shrink-0 flex-col gap-0.5 overflow-auto border-r border-border p-[14px_10px] min-[640px]:flex">
        <div class="px-2 pb-1.5 pt-0.5 text-[11px] font-semibold uppercase tracking-[.05em] text-body-faint">{{ __('media.picker_folders') }}</div>
        <button type="button" wire:click="setPickerFolder(null)"
                class="flex w-full items-center gap-2 rounded-[7px] px-[9px] py-[7px] text-left text-[13px] {{ $pickerFolderId === null ? 'bg-admin-primary/8 font-semibold text-admin-primary' : 'font-medium text-body-text hover:bg-surface-hover' }}">
            <x-admin::icon name="ri-stack" class="size-[15px] shrink-0" />
            <span class="flex-1 truncate">{{ __('media.picker_all_images') }}</span>
            <span class="text-[11px] {{ $pickerFolderId === null ? 'text-admin-primary' : 'text-body-faint' }}">{{ $mp['total'] }}</span>
        </button>
        @foreach ($mp['folders'] as $folder)
            <button type="button" wire:key="mp-folder-{{ $folder->id }}" wire:click="setPickerFolder({{ $folder->id }})"
                    class="flex w-full items-center gap-2 rounded-[7px] px-[9px] py-[7px] text-left text-[13px] {{ $pickerFolderId === $folder->id ? 'bg-admin-primary/8 font-semibold text-admin-primary' : 'font-medium text-body-text hover:bg-surface-hover' }}">
                <x-admin::icon name="ri-folder-3" class="size-[15px] shrink-0" />
                <span class="flex-1 truncate">{{ $folder->name }}</span>
                <span class="text-[11px] {{ $pickerFolderId === $folder->id ? 'text-admin-primary' : 'text-body-faint' }}">{{ $folder->media_count }}</span>
            </button>
        @endforeach
        @can('media.upload')
            @if ($pickerNewFolderOpen)
                <div class="mt-1.5 flex flex-col gap-2 rounded-[9px] border border-border-strong bg-surface p-2.5">
                    <input type="text" wire:model.live.debounce.300ms="pickerNewFolderName" wire:keydown.enter="createPickerFolder"
                           placeholder="{{ __('media.picker_folder_name_placeholder') }}" x-init="$el.focus()"
                           class="block w-full rounded-[7px] border border-border-strong bg-surface px-2 py-1.5 text-[12.5px] text-body-text placeholder:text-placeholder focus:border-admin-primary focus:outline-none">
                    @error('pickerNewFolderName') <p class="text-[11px] text-admin-danger">{{ $message }}</p> @enderror

                    {{-- Slug preview /{slug}; the pencil opens it for hand-editing (freezes auto-derive). --}}
                    @if ($pickerNewFolderSlugEdited)
                        <div class="flex items-center gap-1.5 text-[11px]">
                            <x-admin::icon name="ri-link" class="size-3 shrink-0 text-body-faint" />
                            <span class="text-body-faint">/</span>
                            <input type="text" wire:model.live="pickerNewFolderSlug"
                                   class="min-w-0 flex-1 rounded border border-border-strong bg-surface px-1.5 py-0.5 text-[11px] font-semibold text-admin-primary focus:border-admin-primary focus:outline-none">
                        </div>
                    @elseif ($pickerNewFolderSlug !== '')
                        <div class="flex min-w-0 items-center gap-1.5 text-[11px] text-body-faint">
                            <x-admin::icon name="ri-link" class="size-3 shrink-0" />
                            <span class="truncate font-semibold text-admin-primary">/{{ $pickerNewFolderSlug }}</span>
                            <button type="button" wire:click="editPickerFolderSlug" title="{{ __('media.picker_folder_slug_edit') }}"
                                    class="shrink-0 text-body-faint hover:text-admin-primary">
                                <x-admin::icon name="ri-pencil" class="size-3" />
                            </button>
                        </div>
                    @endif
                    @if ($pickerNewFolderSlugFallback)
                        <p class="text-[10.5px] leading-tight text-admin-warning">{{ __('media.picker_folder_slug_fallback') }}</p>
                    @endif

                    <div class="flex gap-1">
                        <button type="button" wire:click="createPickerFolder"
                                class="flex-1 rounded-[7px] bg-admin-primary px-2 py-1 text-[11.5px] font-semibold text-white">{{ __('media.picker_folder_create') }}</button>
                        <button type="button" wire:click="$set('pickerNewFolderOpen', false)"
                                class="rounded-[7px] border border-border-strong px-2 py-1 text-[11.5px] text-body-muted">{{ __('media.picker_cancel') }}</button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="$set('pickerNewFolderOpen', true)"
                        class="mt-1.5 flex w-full items-center gap-2 rounded-[7px] border border-dashed border-placeholder bg-transparent px-[9px] py-2 text-[12.5px] text-body-muted hover:border-admin-primary hover:text-admin-primary">
                    <x-admin::icon name="ri-add" class="size-[15px]" />{{ __('media.picker_new_folder') }}
                </button>
            @endif
        @endcan

        {{-- System "folder": the Trash (onlyTrashed) — a virtual entry, not a media_folders row. --}}
        @can('media.delete')
            <div class="mt-2 border-t border-border pt-2">
                <button type="button" wire:click="openPickerTrash"
                        class="flex w-full items-center gap-2 rounded-[7px] px-[9px] py-[7px] text-left text-[13px] {{ $pickerTrash ? 'bg-admin-danger/8 font-semibold text-admin-danger' : 'font-medium text-body-text hover:bg-surface-hover' }}">
                    <x-admin::icon name="ri-delete-bin" class="size-[15px] shrink-0" />
                    <span class="flex-1 truncate">{{ __('media.picker_trash') }}</span>
                    <span class="text-[11px] {{ $pickerTrash ? 'text-admin-danger' : 'text-body-faint' }}">{{ $mp['trashTotal'] }}</span>
                </button>
            </div>
        @endcan
    </div>

    {{-- Main column --}}
    <div class="flex min-h-0 min-w-0 flex-1 flex-col">

        {{-- Toolbar. In the trash view the folder/sort/type/format selects are hidden (trash is a
             flat onlyTrashed set) — just search + view + an "empty trash" action. --}}
        <div class="flex shrink-0 flex-wrap items-center gap-[9px] border-b border-border px-4 py-3">
            @unless ($pickerTrash)
                {{-- Folder picker on narrow screens (the sidebar rail is hidden <640px). --}}
                <select wire:model.live="pickerFolderId" class="cursor-pointer rounded-lg border border-border-strong bg-surface px-2.5 py-2 text-[12.5px] text-body-text focus:outline-none min-[640px]:hidden" aria-label="{{ __('media.picker_folders') }}">
                    <option value="">{{ __('media.picker_all_images') }} ({{ $mp['total'] }})</option>
                    @foreach ($mp['folders'] as $folder)
                        <option value="{{ $folder->id }}">{{ $folder->name }} ({{ $folder->media_count }})</option>
                    @endforeach
                </select>
            @endunless
            <div class="relative min-w-[150px] flex-1">
                <x-admin::icon name="ri-search" class="absolute left-[11px] top-1/2 size-[15px] -translate-y-1/2 text-body-faint" />
                <input type="search" wire:model.live.debounce.250ms="pickerSearch" placeholder="{{ __('media.picker_search_placeholder') }}"
                       class="block w-full rounded-lg border border-border-strong bg-surface py-2 pl-[34px] pr-3 text-[13px] text-body-text placeholder:text-placeholder focus:border-admin-primary focus:outline-none">
            </div>
            @unless ($pickerTrash)
                <select wire:model.live="pickerSort" class="cursor-pointer rounded-lg border border-border-strong bg-surface px-2.5 py-2 text-[12.5px] text-body-text focus:outline-none" aria-label="{{ __('media.picker_sort_label') }}">
                    <option value="recent">{{ __('media.picker_sort_recent') }}</option>
                    <option value="oldest">{{ __('media.picker_sort_oldest') }}</option>
                    <option value="nameAsc">{{ __('media.picker_sort_name_asc') }}</option>
                    <option value="nameDesc">{{ __('media.picker_sort_name_desc') }}</option>
                    <option value="sizeDesc">{{ __('media.picker_sort_size_desc') }}</option>
                    <option value="sizeAsc">{{ __('media.picker_sort_size_asc') }}</option>
                    <option value="ext">{{ __('media.picker_sort_ext') }}</option>
                </select>
                <select wire:model.live="pickerType" class="cursor-pointer rounded-lg border border-border-strong bg-surface px-2.5 py-2 text-[12.5px] text-body-text focus:outline-none" aria-label="{{ __('media.picker_type_label') }}">
                    <option value="all">{{ __('media.picker_type_all') }}</option>
                    <option value="image">{{ __('media.picker_type_image') }}</option>
                    <option value="video">{{ __('media.picker_type_video') }}</option>
                    <option value="audio">{{ __('media.picker_type_audio') }}</option>
                    <option value="doc">{{ __('media.picker_type_doc') }}</option>
                    <option value="used">{{ __('media.picker_type_used') }}</option>
                    <option value="unused">{{ __('media.picker_type_unused') }}</option>
                </select>
                <select wire:model.live="pickerFormat" class="cursor-pointer rounded-lg border border-border-strong bg-surface px-2.5 py-2 text-[12.5px] text-body-text focus:outline-none" aria-label="{{ __('media.picker_format_label') }}">
                    <option value="all">{{ __('media.picker_format_all') }}</option>
                    @foreach ($mp['formats'] as $ext)
                        <option value="{{ $ext }}">{{ strtoupper($ext) }}</option>
                    @endforeach
                </select>
            @else
                @if (! $mp['page']->isEmpty())
                    <button type="button" wire:click="emptyPickerTrash"
                            wire:confirm="{{ __('media.picker_trash_empty_confirm') }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-admin-danger/20 bg-surface px-3 py-2 text-[12.5px] font-semibold text-admin-danger">
                        <x-admin::icon name="ri-delete-bin" class="size-3.5" />{{ __('media.picker_trash_empty') }}
                    </button>
                @endif
            @endunless
            <div class="flex overflow-hidden rounded-lg border border-border-strong">
                <button type="button" wire:click="setPickerView('grid')" title="{{ __('media.picker_view_grid') }}"
                        class="flex h-8 w-[34px] items-center justify-center {{ $pickerView === 'grid' ? 'bg-admin-primary text-white' : 'bg-surface text-body-muted' }}">
                    <x-admin::icon name="ri-grid" class="size-[15px]" />
                </button>
                <button type="button" wire:click="setPickerView('list')" title="{{ __('media.picker_view_list') }}"
                        class="flex h-8 w-[34px] items-center justify-center {{ $pickerView === 'list' ? 'bg-admin-primary text-white' : 'bg-surface text-body-muted' }}">
                    <x-admin::icon name="ri-list-check-2" class="size-[15px]" />
                </button>
            </div>
        </div>

        {{-- Bulk-selection bar. Trash mode swaps Move/Delete for Restore/Delete-permanently. --}}
        @if ($pickerSelected !== [])
            <div class="flex shrink-0 items-center gap-2.5 border-b border-border {{ $pickerTrash ? 'bg-admin-danger/4' : 'bg-admin-primary/4' }} px-4 py-[9px]">
                <span class="text-[12.5px] font-semibold {{ $pickerTrash ? 'text-admin-danger' : 'text-admin-primary' }}">{{ __('media.picker_selected_count', ['count' => count($pickerSelected)]) }}</span>
                <div class="ml-auto flex gap-1.5">
                    @if ($pickerTrash)
                        @can('media.delete')
                            <button type="button" wire:click="restorePickerSelection"
                                    class="inline-flex items-center gap-1.5 rounded-[7px] border border-admin-success/30 bg-surface px-[11px] py-1.5 text-xs font-medium text-admin-success">
                                <x-admin::icon name="ri-refresh" class="size-[13px]" />{{ __('media.picker_trash_restore') }}
                            </button>
                            <button type="button" wire:click="purgePickerSelection"
                                    wire:confirm="{{ __('media.picker_trash_purge_confirm', ['count' => count($pickerSelected)]) }}"
                                    class="inline-flex items-center gap-1.5 rounded-[7px] border border-admin-danger/20 bg-surface px-[11px] py-1.5 text-xs text-admin-danger">
                                <x-admin::icon name="ri-delete-bin" class="size-[13px]" />{{ __('media.picker_trash_purge') }}
                            </button>
                        @endcan
                    @else
                        @can('media.upload')
                            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                                <button type="button" x-on:click="open = !open"
                                        class="inline-flex items-center gap-1.5 rounded-[7px] border border-border-strong bg-surface px-[11px] py-1.5 text-xs text-body-text">
                                    <x-admin::icon name="ri-folder-transfer" class="size-[13px]" />{{ __('media.picker_move') }}
                                </button>
                                <div class="absolute right-0 top-full z-10 mt-1 max-h-56 w-48 overflow-auto rounded-lg border border-border bg-surface py-1 shadow-card" x-show="open" x-cloak>
                                    <button type="button" wire:click="pickerBulkMove(null)" x-on:click="open = false"
                                            class="block w-full px-3 py-1.5 text-left text-[12.5px] text-body-text hover:bg-surface-hover">{{ __('media.picker_move_root') }}</button>
                                    @foreach ($mp['folders'] as $folder)
                                        <button type="button" wire:key="mp-move-{{ $folder->id }}" wire:click="pickerBulkMove({{ $folder->id }})" x-on:click="open = false"
                                                class="block w-full truncate px-3 py-1.5 text-left text-[12.5px] text-body-text hover:bg-surface-hover">{{ $folder->name }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endcan
                        @can('media.delete')
                            <button type="button" wire:click="pickerBulkDelete"
                                    wire:confirm="{{ __('media.picker_delete_confirm', ['count' => count($pickerSelected)]) }}"
                                    class="inline-flex items-center gap-1.5 rounded-[7px] border border-admin-danger/20 bg-surface px-[11px] py-1.5 text-xs text-admin-danger">
                                <x-admin::icon name="ri-delete-bin" class="size-[13px]" />{{ __('media.picker_delete') }}
                            </button>
                        @endcan
                    @endif
                    <button type="button" wire:click="clearPickerSelection"
                            class="rounded-[7px] border border-border-strong bg-surface px-[11px] py-1.5 text-xs text-body-muted">{{ __('media.picker_clear') }}</button>
                </div>
            </div>
        @endif

        {{-- Items --}}
        <div class="min-h-0 flex-1 overflow-auto p-4">
            @if ($mp['page']->isEmpty())
                <div class="px-5 py-[50px] text-center text-placeholder">
                    <x-admin::icon name="{{ $pickerTrash ? 'ri-delete-bin' : 'ri-image-2' }}" class="mx-auto size-10" />
                    <div class="mt-2.5 text-[13.5px] text-body-faint">{{ $pickerTrash ? __('media.picker_trash_empty_state') : __('media.picker_empty') }}</div>
                </div>
            @elseif ($pickerView === 'grid')
                <div class="grid grid-cols-[repeat(auto-fill,minmax(118px,1fr))] gap-3">
                    @foreach ($mp['page'] as $m)
                        @php($picked = in_array($m->id, $pickedIds ?? [], true))
                        @php($selected = in_array($m->id, $pickerSelected, true))
                        {{-- A div, not a button, so the ⓘ detail button can nest without invalid markup. --}}
                        <div wire:key="mp-item-{{ $m->id }}" role="button" tabindex="0"
                                wire:click="{{ $manage ? 'openPickerDetail' : 'togglePickerItem' }}({{ $m->id }})" title="{{ $m->original_filename }}"
                                @class(['relative aspect-square cursor-pointer overflow-hidden rounded-[11px] border border-border bg-surface-hover', 'pointer-events-none opacity-60' => $picked])>
                            @if (str_starts_with($m->mime, 'image/'))
                                <img src="{{ $mp['urls']->url($m->storage_key, 320) }}" alt="{{ $m->original_filename }}" loading="lazy"
                                     class="absolute inset-0 size-full object-cover"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                                <span class="absolute inset-0 items-center justify-center p-1 text-center text-[10px] leading-tight text-body-faint" style="display:none">{{ $m->original_filename }}</span>
                            @else
                                @php([$glyph, $glyphColor] = $fileGlyph($m->mime))
                                <span class="absolute inset-0 flex items-center justify-center" style="background: {{ $glyphColor }}14;">
                                    <x-admin::icon :name="$glyph" class="size-[34px]" style="color: {{ $glyphColor }}" />
                                </span>
                            @endif
                            <span class="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/55 to-transparent px-2 pb-1.5 pt-3.5 text-left text-[10px] text-white">{{ $m->original_filename }}</span>
                            {{-- ⓘ only in the picker (manage-mode tile click already opens detail; trash hides it). --}}
                            @unless ($pickerTrash || $manage)
                                <button type="button" wire:click.stop="openPickerDetail({{ $m->id }})" title="{{ __('media.picker_detail') }}"
                                        class="absolute left-[7px] top-[7px] flex size-[22px] items-center justify-center rounded-md bg-black/30 text-white transition-colors hover:bg-black/50">
                                    <x-admin::icon name="ri-information" class="size-3.5" />
                                </button>
                            @endunless
                            {{-- Selected = primary tint wash + a filled check badge that reads clearly on any
                                 image; unselected = a subtle ring that darkens on hover (touch-friendly). --}}
                            @if ($selected)
                                <span class="pointer-events-none absolute inset-0 rounded-[11px] bg-admin-primary/25"></span>
                                <span class="pointer-events-none absolute inset-0 rounded-[11px] border-[3px] border-admin-primary"></span>
                            @endif
                            @php($checkClass = 'absolute right-[7px] top-[7px] flex size-[22px] items-center justify-center rounded-full border-2 transition-transform '.($selected || $picked ? 'scale-110 border-white bg-admin-primary text-white shadow-md' : 'border-white bg-black/25 text-white/0'))
                            @if ($manage)
                                {{-- Manage mode: the checkbox is the SELECT affordance (tile click = detail). --}}
                                <button type="button" wire:click.stop="togglePickerItem({{ $m->id }})" class="{{ $checkClass }} {{ $selected ? '' : 'hover:bg-black/45 hover:text-white/70' }}">
                                    <x-admin::icon name="ri-check" class="size-3.5" />
                                </button>
                            @else
                                <span class="{{ $checkClass }}"><x-admin::icon name="ri-check" class="size-3.5" /></span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex flex-col gap-0.5">
                    <div class="flex items-center gap-3 px-2.5 py-[7px] text-[11px] font-semibold uppercase tracking-[.03em] text-body-faint">
                        <span class="w-[22px]"></span><span class="w-[42px]"></span>
                        <span class="flex-1">{{ __('media.picker_col_name') }}</span>
                        <span class="w-[100px]">{{ __('media.picker_col_dims') }}</span>
                        <span class="w-[70px]">{{ __('media.picker_col_size') }}</span>
                        <span class="w-[52px]">{{ __('media.picker_col_type') }}</span>
                    </div>
                    @foreach ($mp['page'] as $m)
                        @php($picked = in_array($m->id, $pickedIds ?? [], true))
                        @php($selected = in_array($m->id, $pickerSelected, true))
                        <div wire:key="mp-row-{{ $m->id }}" role="button" tabindex="0"
                                wire:click="{{ $manage ? 'openPickerDetail' : 'togglePickerItem' }}({{ $m->id }})"
                                @class(['flex cursor-pointer items-center gap-3 rounded-[9px] px-2.5 py-2 text-left', 'bg-admin-primary/10 shadow-[inset_3px_0_0_var(--color-admin-primary)]' => $selected, 'hover:bg-surface-hover' => ! $selected, 'pointer-events-none opacity-60' => $picked])>
                            @php($rowCheckClass = 'flex size-[22px] shrink-0 items-center justify-center rounded-md border-[1.5px] '.($selected || $picked ? 'border-admin-primary bg-admin-primary text-white' : 'border-placeholder bg-surface text-transparent'))
                            @if ($manage)
                                <button type="button" wire:click.stop="togglePickerItem({{ $m->id }})" class="{{ $rowCheckClass }}">
                                    <x-admin::icon name="ri-check" class="size-3" />
                                </button>
                            @else
                                <span class="{{ $rowCheckClass }}"><x-admin::icon name="ri-check" class="size-3" /></span>
                            @endif
                            <span class="size-[42px] shrink-0 overflow-hidden rounded-lg bg-surface-hover">
                                @if (str_starts_with($m->mime, 'image/'))
                                    <img src="{{ $mp['urls']->url($m->storage_key, 320) }}" alt="" loading="lazy" class="size-full object-cover" onerror="this.style.display='none'">
                                @else
                                    @php([$glyph, $glyphColor] = $fileGlyph($m->mime))
                                    <span class="flex size-full items-center justify-center" style="background: {{ $glyphColor }}14;">
                                        <x-admin::icon :name="$glyph" class="size-5" style="color: {{ $glyphColor }}" />
                                    </span>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1 truncate text-[13px] text-body-text">{{ $m->original_filename }}</span>
                            <span class="w-[100px] text-[12.5px] text-body-muted">{{ $m->width !== null ? $m->width.' × '.$m->height : '—' }}</span>
                            <span class="w-[70px] text-[12.5px] text-body-muted">{{ number_format($m->size / 1048576, 1) }} MB</span>
                            <span class="w-[52px]"><span class="rounded-[5px] bg-admin-primary/8 px-[7px] py-0.5 text-[10px] font-semibold uppercase text-admin-primary">{{ $m->extLabel() }}</span></span>
                            @unless ($pickerTrash)
                                <button type="button" wire:click.stop="openPickerDetail({{ $m->id }})" title="{{ __('media.picker_detail') }}"
                                        class="flex size-7 shrink-0 items-center justify-center text-body-faint hover:text-admin-primary">
                                    <x-admin::icon name="ri-information" class="size-4" />
                                </button>
                            @else
                                <span class="w-7 shrink-0"></span>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Server-side pager --}}
        @if ($mp['page']->lastPage() > 1)
            <div class="flex shrink-0 items-center justify-end gap-2 border-t border-border px-4 py-2">
                <span class="text-xs text-body-faint">{{ __('media.picker_page_of', ['page' => $mp['page']->currentPage(), 'pages' => $mp['page']->lastPage()]) }}</span>
                <button type="button" wire:click="pickerPrev" @disabled($mp['page']->onFirstPage()) title="{{ __('media.picker_prev') }}"
                        class="flex size-7 items-center justify-center rounded-md border border-border-strong text-body-muted disabled:opacity-40">
                    <x-admin::icon name="chevron-left" class="size-3.5" />
                </button>
                <button type="button" wire:click="pickerNext" @disabled(! $mp['page']->hasMorePages()) title="{{ __('media.picker_next') }}"
                        class="flex size-7 items-center justify-center rounded-md border border-border-strong text-body-muted disabled:opacity-40">
                    <x-admin::icon name="chevron-right" class="size-3.5" />
                </button>
            </div>
        @endif
    </div>
</div>
