@props([
    // the screen's name: «Thêm tin tức», «Sửa sản phẩm»
    'title',
    // the trail after ⌂: [['label' => the list, 'url' => its address], ['label' => «Thêm mới» | «Sửa»]]
    'crumbs' => [],
    // the one line under the name
    'description' => '',
    // the member's guide (App\Modules\Admin\Support\Guide\Guide::toArray), on the first draw only
    'guide' => null,
    'backUrl',
    'backLabel',
    // the save state at rest (the editor's saveNote()) and the browser's part (bsEditorSave)
    'note',
    'saveConfig',
    // ['secondary' => ?string, 'primary' => ?string, 'primaryAction' => string, 'primaryIcon' => string]
    'actions',
    // the Livewire methods the save buttons wait on
    'target' => 'save,publish',
    'saveTip' => null,
    // «Xem trước»: ['url' => ?string, 'why' => ?string] — the address of the saved version, or why there is none yet
    'preview' => ['url' => null, 'why' => null],
    // whether «Xem trước» also sits in the bar from 600px up (posts and pages); otherwise it is only the phone's ⋮ item (products)
    'previewBar' => true,
    // «Chuyển vào thùng rác»: null when the member may not (or nothing is saved yet); ['why' => ?string] — locked with the reason
    'trash' => null,
    // what the ⋮ is named for: the record being edited
    'name' => '',
])
@php
    // The frame every add/edit screen shares (posts, pages, products; design 01 Admin Shell with 07 and 09):
    //  · the Shell's header: the trail «⌂ › list › Thêm mới | Sửa», the title row with «(?) Hướng dẫn» at its
    //    end, one line on what the screen is for;
    //  · the bar that stays under the admin's top bar: ← Quay lại · the save state · Xem trước (posts and pages;
    //    a product's is the phone's ⋮ item only, as its board draws) · Lưu nháp · ⋮ · the main button. The ⋮ holds Lưu nháp, Chuyển vào thùng rác (an existing record, for who may) and, on
    //    a phone, Xem trước and Cỡ chữ;
    //  · below 768px of the editor's own width (container queries), Lưu nháp and the main button move to a
    //    bar along the bottom (44px on touch).
    // On a phone (below 600px, or a screen 500px high or less) the screen is a child screen: the layout drops
    // the admin's top bar (data-child-screen) and this header, and the bar becomes the one 56px bar
    // «← · name + save state · (?) · ⋮» (admin-editor.css). Sizes come from the shared control tokens.
    $glyph = 'size-[1.15em] shrink-0';
    $more = __('admin.list.more_for', ['name' => $name !== '' ? $name : $title]);
    $sizes = ['md' => __('admin.account.fs_md'), 'lg' => __('admin.account.fs_lg'), 'xl' => __('admin.account.fs_xl'), 'xxl' => __('admin.account.fs_xxl')];
    $toast = fn (string $text): string => \Illuminate\Support\Js::from(['text' => $text, 'stay' => true, 'close' => __('admin.close')])->toHtml();
@endphp
<div {{ $attributes->merge(['class' => 'ped-frame mx-auto flex max-w-[80rem] min-w-0 flex-col gap-4']) }}>
    <header class="ped-head flex flex-col gap-1 max-[599.98px]:hidden [@media(max-height:500px)]:hidden">
        <x-bs-admin::breadcrumb data-guide="crumb" :items="$crumbs" />
        <x-bs-admin::title-row :title="$title" title-id="editor-title">
            <x-slot:help>
                <x-bs-admin::guide :guide="$guide" />
            </x-slot:help>
        </x-bs-admin::title-row>
        @if ($description !== '')
            <p class="m-0 max-w-[40rem] text-body text-text-muted [overflow-wrap:anywhere]">{{ $description }}</p>
        @endif
    </header>

    <div class="ped-bar flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-bg py-2 @max-[48rem]:flex-nowrap @max-[48rem]:gap-2"
         x-ignore wire:ignore.self data-bs-lazy="bsEditorSave" x-data="bsEditorSave({{ \Illuminate\Support\Js::from($saveConfig) }})" x-bind:data-sv-over="state() ? 'on' : false">
        <x-bs-admin::button variant="ghost" :href="$backUrl" data-guide="back" class="shrink-0 @max-[37.5rem]:w-(--spacing-control) @max-[37.5rem]:px-0"
                            title="{{ $backLabel }}" aria-label="{{ $backLabel }}">
            <x-bs-admin::icon name="ri-arrow-left" :class="$glyph" /><span class="@max-[37.5rem]:hidden">{{ __('entries.ed.back') }}</span>
        </x-bs-admin::button>

        <div class="ped-tl flex min-w-0 flex-col items-start gap-1 @max-[48rem]:flex-1">
            {{-- the name in the bar shows on the phone's one bar only, where the header above is gone: one h1 shows at a time --}}
            <h1 class="ped-h1 m-0" title="{{ $title }}">{{ $title }}</h1>
            <x-bs-admin::save-state :note="$note" data-guide="state" class="max-w-full" />
        </div>
        <span wire:ignore class="contents">
            <x-bs-admin::button variant="outline" color="secondary" size="sm" class="ped-retry shrink-0" x-show="state() === 'offline'" x-cloak x-on:click="retry()">
                <x-bs-admin::icon name="ri-refresh" :class="$glyph" /> {{ __('catalog.ed.retry') }}
            </x-bs-admin::button>
        </span>

        <span class="ped-sp flex-1"></span>
        <div class="ped-acts flex flex-wrap items-center justify-end gap-2 @max-[48rem]:flex-nowrap">
            @if (! $previewBar)
            @elseif ($preview['url'] ?? null)
                <x-bs-admin::button variant="outline" color="secondary" class="ped-pv shrink-0" data-guide="preview" :href="$preview['url']" :navigate="false" target="_blank" rel="noopener"
                                    title="{{ __('admin.editor.preview_tip') }}">
                    <x-bs-admin::icon name="ri-eye" :class="$glyph" /> {{ __('admin.editor.preview') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span>
                </x-bs-admin::button>
            @elseif ($preview['why'] ?? null)
                <x-bs-admin::button variant="outline" color="secondary" class="ped-pv shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-60" data-guide="preview" aria-disabled="true"
                                    title="{{ $preview['why'] }}" x-on:click="$bsToast({{ $toast($preview['why']) }})">
                    <x-bs-admin::icon name="ri-eye" :class="$glyph" /> {{ __('admin.editor.preview') }}
                </x-bs-admin::button>
            @endif
            @if ($actions['secondary'] !== null)
                <x-bs-admin::button variant="outline" color="secondary" class="shrink-0 @max-[48rem]:hidden" data-guide="save" wire:click="save" wire:loading.attr="disabled" wire:target="{{ $target }}" :title="$saveTip">
                    <x-bs-admin::icon name="ri-save-3" :class="$glyph" /> {{ $actions['secondary'] }}
                </x-bs-admin::button>
            @endif
            <x-bs-admin::guide class="ped-help" :box="false" :icon-only="true" />
            <div class="list-more ped-more" x-data="{ open: false, up: false, close(back) { this.open = false; if (back) { this.$refs.more.focus() } } }"
                 x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="close(true)">
                <x-bs-admin::button variant="outline" color="secondary" icon x-ref="more" data-guide="more"
                                    aria-label="{{ $more }}" title="{{ __('admin.list.more') }}" aria-haspopup="menu" ::aria-expanded="open.toString()"
                                    x-on:click="open = ! open; open && $nextTick(() => $focus.within($refs.menu).first())">
                    <x-bs-admin::icon name="ri-more-2-fill" :class="$glyph" />
                </x-bs-admin::button>
                <x-bs-admin::list.menu class="list-menu" :label="$more">
                    @if ($preview['url'] ?? null)
                        <x-bs-admin::list.menu-item class="ped-child-mi" icon="ri-eye" :href="$preview['url']" target="_blank" rel="noopener">{{ __('admin.editor.preview') }}</x-bs-admin::list.menu-item>
                    @elseif ($preview['why'] ?? null)
                        <x-bs-admin::list.menu-item class="ped-child-mi" icon="ri-eye" off x-on:click="$bsToast({{ $toast($preview['why']) }})">{{ __('admin.editor.preview') }}</x-bs-admin::list.menu-item>
                    @endif
                    @if ($actions['secondary'] !== null)
                        <x-bs-admin::list.menu-item icon="ri-save-3" wire:click="save" wire:loading.attr="disabled" wire:target="{{ $target }}">{{ $actions['secondary'] }}</x-bs-admin::list.menu-item>
                    @endif
                    @if ($trash !== null && ($trash['why'] ?? null) !== null)
                        <x-bs-admin::list.menu-item icon="ri-delete-bin" danger :why="$trash['why']" data-guide="trash">{{ __('admin.editor.trash') }}</x-bs-admin::list.menu-item>
                    @elseif ($trash !== null)
                        <x-bs-admin::list.menu-item icon="ri-delete-bin" danger data-guide="trash" wire:click="trash" wire:loading.attr="disabled" wire:target="{{ $target }},trash">{{ __('admin.editor.trash') }}</x-bs-admin::list.menu-item>
                    @endif
                    {{-- Cỡ chữ: on a phone the admin's top bar (and its way to the member's settings) is gone --}}
                    <div class="ped-fs" role="group" aria-labelledby="ped-fs-h">
                        <p class="ped-fs-h" id="ped-fs-h">{{ __('admin.account.text_size') }}</p>
                        <div class="ped-fs-g">
                            @foreach ($sizes as $value => $label)
                                <button type="button" role="menuitemradio" aria-checked="false" x-bind:aria-checked="($store.adminTheme && $store.adminTheme.state.text_size === '{{ $value }}') ? 'true' : 'false'"
                                        x-on:click="$store.adminTheme && $store.adminTheme.set('text_size', '{{ $value }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </x-bs-admin::list.menu>
            </div>
            @if ($actions['primary'] !== null)
                <x-bs-admin::button color="primary" class="shrink-0 @max-[48rem]:hidden" data-guide="main" wire:click="{{ $actions['primaryAction'] }}" wire:loading.attr="disabled" wire:target="{{ $target }}">
                    <x-bs-admin::icon :name="$actions['primaryIcon']" :class="$glyph" /> {{ $actions['primary'] }}
                </x-bs-admin::button>
            @endif
        </div>
    </div>

    {{ $slot }}

    @if ($actions['secondary'] !== null || $actions['primary'] !== null)
        <div class="ped-bbar flex gap-2 border-t border-border bg-bg pt-2.5 @min-[48rem]:hidden">
            @if ($actions['secondary'] !== null)
                <x-bs-admin::button variant="outline" color="secondary" class="min-w-0 flex-1 any-pointer-coarse:min-h-hit" data-guide="save-b" wire:click="save" wire:loading.attr="disabled" wire:target="{{ $target }}" :title="$saveTip">
                    <x-bs-admin::icon name="ri-save-3" :class="$glyph" /> {{ $actions['secondary'] }}
                </x-bs-admin::button>
            @endif
            @if ($actions['primary'] !== null)
                <x-bs-admin::button color="primary" class="min-w-0 flex-1 any-pointer-coarse:min-h-hit" data-guide="main-b" wire:click="{{ $actions['primaryAction'] }}" wire:loading.attr="disabled" wire:target="{{ $target }}">
                    <x-bs-admin::icon :name="$actions['primaryIcon']" :class="$glyph" /> {{ $actions['primary'] }}
                </x-bs-admin::button>
            @endif
        </div>
    @endif
</div>
