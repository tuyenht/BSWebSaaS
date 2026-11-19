{{-- The post and page editor (design 07, one screen for both; `type` picks what differs). The frame
     every editor shares (components/editor-frame.blade.php: the Shell's header, the bar that stays
     under the admin's top bar, the ⋮, the bottom bar of a narrow editor, the phone's one bar), then
     the status band of live content, then two columns in the board's order. Main: Tiêu đề and Đường dẫn, Mô tả ngắn, the
     content card ("Soạn thảo" = the body as one formatted-text document; "Trình dựng" = a page's
     blocks, or any body laid out in blocks), the shared "Hiện thế nào trên Google" card. Side: Đăng (status, the approval steps where a
     workflow governs the post), Chuyên mục (posts), Ảnh đại diện. The save state at rest is drawn
     here; typing, saving and a lost connection are drawn over it in the browser (bsEditorSave,
     resources/js/admin-editor.js; this editor's drafts do not save themselves). The block list is
     counted by the server (blocksChanged). Columns follow the editor's own width (flex basis
     520 / 300, as the board). --}}
@php
    use App\Modules\Admin\Livewire\Entries\Editor;
    use Illuminate\Support\Js;

    $isPage = $type === 'page';
    $dirty = __('catalog.ed.sv_dirty');
    // Where this member's copy of unsaved text on this device lives (admin-kept.js): per member,
    // entry and language, never the numeric id.
    $keptKey = $ulid !== null ? 'bs-entry-kept:'.$userUlid.':'.$ulid.':'.$locale : null;
    $keptWhen = ['zone' => $siteZone, 'format' => $siteDateFormat, 'lang' => app()->getLocale()];
    $saveConfig = ['fields' => Editor::TRACKED, 'autosave' => false, 'extra' => 'blocksChanged', 'keep' => $keptKey !== null ? ['key' => $keptKey] : null, 'words' => [
        'dirty' => $dirty,
        'dirtyDraft' => $dirty,
        'dirtyLive' => $dirty,
        'saving' => __('catalog.ed.sv_saving'),
        'offline' => __('catalog.ed.sv_offline'),
        'offlineToast' => __('catalog.ed.toast_offline'),
        'offlineToastIcon' => \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-wifi-off" class="size-4" />'),
    ]];
    $fieldLook = 'block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text';
    $label = 'inline-flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5 gap-y-1 text-small font-semibold text-text-strong';
    $error = 'mt-1 text-small text-danger-text';
    $card = 'rounded-md border border-border bg-surface shadow-card';
    $cardHead = 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3';
    $cardTitle = 'm-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-tight text-text';
    $chip = $multiLocale ? strtoupper($locale) : null;
    $glyph = 'size-[1.15em] shrink-0';
@endphp
<div class="entry-editor @container" data-entry-editor data-kind="{{ $type }}">
    <x-bs-admin::editor-frame :title="$frame['title']" :crumbs="$frame['crumbs']" :description="$frame['description']" :guide="$frame['guide']"
                              :back-url="$listUrl" :back-label="__('entries.ed.back_title', ['list' => mb_strtolower($listLabel)])"
                              :note="$note" :save-config="$saveConfig" :actions="$actions" target="save,publish,applyTransition" :save-tip="__('entries.ed.save_tip')"
                              :preview="$frame['preview']" :trash="$frame['trash']" :name="$title !== '' ? $title : $frame['title']">
        @if ($liveUrl !== null)
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 rounded-md bg-[color-mix(in_srgb,var(--color-success-text)_9%,var(--color-surface))] px-3.5 py-2.5 text-body font-semibold text-success-text">
                <x-bs-admin::icon name="ri-checkbox-circle" :class="$glyph" />
                <span>{{ __('entries.ed.pub_band') }}</span>
                <a href="{{ '//'.$liveUrl }}" target="_blank" rel="noopener" class="hit-area underline underline-offset-2" aria-label="{{ __('entries.ed.pub_link_aria', ['url' => $liveUrl]) }}">{{ __('entries.ed.pub_link') }}</a>
                <span class="min-w-0 font-normal [overflow-wrap:anywhere] @max-[35rem]:hidden">{{ $liveUrl }}</span>
            </div>
        @endif
        @error('workflow') <p class="{{ $error }} m-0">{{ $message }}</p> @enderror
        @if ($ulid !== null)
            {{-- Text kept on this device by «Lấy bản mới» (the stale question below): «Khôi phục» puts it
                 back in the form, unsaved; «Bỏ» forgets it. Browser state only, never sent until asked. --}}
            <div wire:ignore data-entry-kept x-show="rec" x-cloak x-on:bs-entry-kept.window="if ($event.detail && $event.detail.key === key) { rec = $event.detail.rec; }" x-data="{
                    key: @js($keptKey),
                    cfg: @js($keptWhen),
                    rec: null,
                    init() { const go = () => { this.rec = window.bsKept.read(this.key); }; if (window.bsKept) { go(); } else { window.addEventListener('bs-kept-ready', go, { once: true }); } },
                    forget() { if (window.bsKept) { window.bsKept.remove(this.key); } this.rec = null; },
                    at(r) { return r && window.bsKept ? window.bsKept.when(r.at, this.cfg) : ''; },
                    say(text) { if (typeof window.bsToast === 'function') { window.bsToast({ text: text }); } },
                    drop() { this.forget(); this.say(@js(__('entries.ed.kept_dropped'))); },
                    restore() { const r = this.rec; if (!r) { return; } $wire.restoreKept(r.values).then(() => { this.forget(); this.say(@js(__('entries.ed.kept_restored')).replace(':time', this.at(r))); }); },
                 }">
                <x-bs-admin::alert role="status" color="warning" icon="ri-hard-drive-2">
                <div class="flex flex-wrap items-start gap-2.5">
                <div class="flex min-w-0 flex-[1_1_16.25rem] flex-col gap-0.5 [overflow-wrap:anywhere]">
                    <b class="font-semibold text-text-strong" x-text="rec ? @js(__('entries.ed.kept_head')).replace(':time', at(rec)) : ''"></b>
                    <span>{{ __('entries.ed.kept_sub') }}</span>
                </div>
                <div class="flex max-w-full flex-[0_1_auto] flex-wrap gap-2">
                    <x-bs-admin::button variant="soft" color="primary" size="sm" x-on:click="restore()">
                        <x-bs-admin::icon name="ri-history" :class="$glyph" /> {{ __('entries.ed.kept_restore') }}
                    </x-bs-admin::button>
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-on:click="drop()">
                        <x-bs-admin::icon name="ri-close" :class="$glyph" /> {{ __('entries.ed.kept_drop') }}
                    </x-bs-admin::button>
                </div>
                </div>
                </x-bs-admin::alert>
            </div>
        @endif

        <div class="flex min-w-0 flex-wrap items-start gap-5">
            <div class="flex min-w-0 flex-[999_1_520px] flex-col gap-4">
                <section class="{{ $card }} px-5 py-4" aria-label="{{ $isPage ? __('entries.ed.title_page') : __('entries.ed.title_post') }}">
                    <div class="mb-1.5 flex flex-wrap items-center gap-2">
                        <label for="entry-title" class="{{ $label }}">
                            <span>{{ $isPage ? __('entries.ed.title_page') : __('entries.ed.title_post') }} <span class="text-danger-text" aria-hidden="true">*</span></span>
                            @if ($chip)
                                <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                            @endif
                        </label>
                    </div>
                    <input type="text" id="entry-title" wire:model="title" lang="{{ $locale }}" aria-required="true"
                           @error('title') aria-invalid="true" aria-describedby="entry-title-e" @enderror class="field {{ $fieldLook }}">
                    @error('title') <p id="entry-title-e" class="{{ $error }}">{{ $message }}</p> @enderror

                    <div class="mt-3.5">
                        <label for="entry-slug" class="{{ $label }} mb-1.5">{{ __('entries.ed.slug') }}</label>
                        <input type="text" id="entry-slug" wire:model="slug" dir="ltr" autocomplete="off" spellcheck="false"
                               @error('slug') aria-invalid="true" aria-describedby="entry-slug-e" @enderror class="field {{ $fieldLook }}">
                        @error('slug') <p id="entry-slug-e" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </section>

                <x-bs-admin::seo-fields excerpt="excerpt" part="excerpt" :kind="$type" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="entry-seo" />

                {{-- The content card (ADR-19, one body / two editors). «Soạn thảo»: the body as one
                     formatted-text document (Jodit), or, when the body is a layout of other blocks, a
                     lock pointing to «Trình dựng». «Trình dựng» (pages, and any layout body): the block
                     list and the add palette. Both tabs edit the same body. --}}
                @php
                    $showBuilder = $isPage || ! $prose;
                    $proseBlank = $prose && \App\Modules\Admin\Support\EntryProseBody::isBlank($bodyHtml);
                    $tabBase = 'inline-flex min-h-(--spacing-control) shrink-0 items-center gap-1.5 border-b-2 px-1 text-body transition-colors';
                    $tabOn = 'border-primary-text font-semibold text-primary-text';
                    $tabOff = 'border-transparent font-medium text-text-muted hover:text-text';
                    $rowButton = 'hit-area inline-flex size-control-row shrink-0 items-center justify-center rounded border border-border bg-surface text-text-muted transition-colors hover:bg-surface-hover hover:text-text';
                @endphp
                <section class="{{ $card }} overflow-hidden" aria-labelledby="entry-body-h" x-data="{ tab: 'text', openBlock: $wire.entangle('openBlock') }">
                    <h2 id="entry-body-h" class="sr-only">{{ __('entries.ed.body_label') }}</h2>
                    <div class="flex min-w-0 items-center gap-3 overflow-hidden border-b border-border px-4">
                        @if ($showBuilder)
                            <button type="button" class="{{ $tabBase }}" x-on:click="tab = 'text'" x-bind:aria-pressed="tab === 'text' ? 'true' : 'false'"
                                    x-bind:class="tab === 'text' ? @js($tabOn) : @js($tabOff)" aria-pressed="true">
                                <x-bs-admin::icon name="ri-edit-2" :class="$glyph" />{{ __('entries.ed.tab_text') }}
                            </button>
                            <button type="button" class="{{ $tabBase }}" x-on:click="tab = 'builder'" x-bind:aria-pressed="tab === 'builder' ? 'true' : 'false'"
                                    x-bind:class="tab === 'builder' ? @js($tabOn) : @js($tabOff)" aria-pressed="false">
                                <x-bs-admin::icon name="ri-artboard-2" :class="$glyph" />{{ __('entries.ed.builder_tab') }}
                            </button>
                        @else
                            <span class="{{ $tabBase }} {{ $tabOn }}">
                                <x-bs-admin::icon name="ri-edit-2" :class="$glyph" />{{ __('entries.ed.tab_text') }}
                            </span>
                        @endif
                        <span class="flex-1"></span>
                        @if ($chip)
                            <span class="shrink-0 rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                        @endif
                    </div>

                    <div x-show="tab === 'text'">
                        @if (! $prose)
                            <div class="flex flex-col items-center gap-3 px-5 py-8 text-center">
                                <span class="inline-flex size-12 items-center justify-center rounded-full bg-bg text-primary-text" aria-hidden="true">
                                    <x-bs-admin::icon name="ri-layout-masonry" class="size-6" />
                                </span>
                                <p class="m-0 text-body font-semibold text-text-strong">{{ $isPage ? __('entries.ed.lock_title_page', ['n' => count($blocks)]) : __('entries.ed.lock_title_post', ['n' => count($blocks)]) }}</p>
                                <p class="m-0 max-w-[32rem] text-small text-text-muted">{{ __('entries.ed.lock_text') }}</p>
                                <x-bs-admin::button color="primary" x-on:click="tab = 'builder'">
                                    <x-bs-admin::icon name="ri-artboard-2" :class="$glyph" /> {{ __('entries.ed.lock_open') }}
                                </x-bs-admin::button>
                            </div>
                        @else
                            <div class="px-5 py-4">
                                <x-bs-admin::rich-editor target="bodyHtml" :value="$bodyHtml" rows="14" :placeholder="$bodyPlaceholder"
                                                         aria-label="{{ __('entries.ed.body_label') }}" />
                                @error('bodyHtml') <p class="{{ $error }}">{{ $message }}</p> @enderror
                                @error('blocks') <p class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                            <p class="m-0 flex items-center gap-1.5 border-t border-border px-5 py-2 text-caption text-text-muted">
                                <x-bs-admin::icon name="ri-translate-2" class="size-3.5 shrink-0" />{{ $localeLabel }}
                            </p>
                        @endif
                    </div>

                    @if ($showBuilder)
                        <div x-show="tab === 'builder'" x-cloak class="flex flex-col gap-3 px-5 pt-4 pb-5">
                            <div>
                                <p class="m-0 text-body font-semibold text-text-strong">{{ __($isPage ? 'entries.ed.builder_head' : 'entries.ed.builder_head_post', ['n' => $prose ? ($proseBlank ? 0 : 1) : count($blocks)]) }}</p>
                                <p class="m-0 mt-0.5 text-small text-text-muted">{{ __('entries.ed.builder_sub', ['lang' => $localeLabel]) }}</p>
                            </div>
                            @if ($prose)
                                @if (! $proseBlank)
                                    <div class="flex min-w-0 items-center gap-3 rounded-md border border-border bg-surface px-3 py-2.5">
                                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-bg text-primary-text" aria-hidden="true">
                                            <x-bs-admin::icon name="ri-text-block" class="size-5" />
                                        </span>
                                        <span class="flex min-w-0 flex-1 flex-col">
                                            <span class="text-small font-semibold text-text">{{ __('entries.ed.builder_prose_label') }}</span>
                                            <span class="truncate text-caption text-text-muted">{{ $prosePreview }}</span>
                                        </span>
                                        <x-bs-admin::button variant="outline" color="secondary" size="sm" class="shrink-0" x-on:click="tab = 'text'">
                                            <x-bs-admin::icon name="ri-pencil" :class="$glyph" /> {{ __('entries.ed.builder_prose_edit') }}
                                        </x-bs-admin::button>
                                    </div>
                                @else
                                    <p class="m-0 rounded-md border border-border bg-surface-muted p-4 text-center text-small text-text-muted">{{ __('entries.ed.builder_none') }}</p>
                                @endif
                            @else
                                @error('blocks') <p class="{{ $error }} m-0">{{ $message }}</p> @enderror
                                @if ($blocks === [])
                                    <p class="m-0 rounded-md border border-border bg-surface-muted p-4 text-center text-small text-text-muted">{{ __('entries.ed.builder_none') }}</p>
                                @endif
                                {{-- One row per block (design 07): glyph, name and a glimpse, kind chip, «Sửa nội dung»
                                     (its content form opens under the row, one at a time) and «Xóa khối». Order changes
                                     with the form's «Lên trên» / «Xuống dưới»: there is no drag reordering yet. --}}
                                <ol class="m-0 flex list-none flex-col gap-2 p-0">
                                    @foreach ($blocks as $i => $block)
                                        @php
                                            $i = (int) $i;
                                            $kind = $kinds[$block['type']] ?? \App\Modules\Admin\Support\BlockKinds::of($block['type']);
                                            $peek = \App\Modules\Admin\Support\BlockKinds::preview($block['props']);
                                        @endphp
                                        <li wire:key="block-{{ $i }}" class="min-w-0 rounded-lg border border-border bg-surface">
                                            <div class="flex min-w-0 items-center gap-3 px-3.5 py-2.5">
                                                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md {{ $kind['tint'] }}" aria-hidden="true">
                                                    <x-bs-admin::icon :name="$kind['icon']" class="size-5" />
                                                </span>
                                                <span class="flex min-w-0 flex-1 flex-col">
                                                    <span class="text-small font-semibold text-text-strong">{{ $kind['label'] }}</span>
                                                    @if ($peek !== '')
                                                        <span class="truncate text-caption text-text-muted">{{ $peek }}</span>
                                                    @endif
                                                </span>
                                                <span class="hidden shrink-0 rounded-sm px-2 py-0.5 text-caption font-semibold whitespace-nowrap sm:inline {{ $kind['tint'] }}">{{ $kind['chip'] }}</span>
                                                <button type="button" class="{{ $rowButton }}" title="{{ __('entries.ed.block_edit') }}" aria-label="{{ __('entries.ed.block_edit') }}: {{ $kind['label'] }}"
                                                        aria-controls="block-form-{{ $i }}" aria-expanded="{{ $openBlock === $i ? 'true' : 'false' }}"
                                                        x-on:click="openBlock = openBlock === {{ $i }} ? null : {{ $i }}" x-bind:aria-expanded="openBlock === {{ $i }} ? 'true' : 'false'">
                                                    <x-bs-admin::icon name="ri-pencil" class="size-4" />
                                                </button>
                                                <button type="button" class="{{ $rowButton }} hover:border-danger-text hover:text-danger-text" wire:click="removeBlock({{ $i }})"
                                                        title="{{ __('entries.ed.block_remove') }}" aria-label="{{ __('entries.ed.block_remove') }}: {{ $kind['label'] }}">
                                                    <x-bs-admin::icon name="ri-delete-bin" class="size-4" />
                                                </button>
                                            </div>
                                            <div id="block-form-{{ $i }}" class="flex min-w-0 flex-col gap-3 border-t border-border px-3.5 py-3"
                                                 x-show="openBlock === {{ $i }}" @if ($openBlock !== $i) style="display: none" @endif>
                                                <div class="flex flex-wrap gap-2">
                                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="moveUp({{ $i }})" :disabled="$i === 0">
                                                        <x-bs-admin::icon name="ri-arrow-up" class="size-4" /> {{ __('entries.ed.block_up') }}
                                                    </x-bs-admin::button>
                                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="moveDown({{ $i }})" :disabled="$i === count($blocks) - 1">
                                                        <x-bs-admin::icon name="ri-arrow-down" class="size-4" /> {{ __('entries.ed.block_down') }}
                                                    </x-bs-admin::button>
                                                </div>
                                                <div class="block-props">
                                                    @foreach (($schemas[$block['type']] ?? []) as $prop => $spec)
                                                        @continue(in_array($spec['group'] ?? null, ['style', 'display'], true))
                                                        {{-- A background video is chosen in the page builder's video field (not built in this editor yet); its value is kept as saved. --}}
                                                        @continue(($spec['type'] ?? null) === 'video')
                                                        @if (($spec['type'] ?? 'text') === 'list')
                                                            <fieldset class="prop-list" wire:key="list-{{ $i }}-{{ $prop }}">
                                                                <legend>{{ $prop }}</legend>
                                                                @foreach (($block['props'][$prop] ?? []) as $j => $item)
                                                                    <div class="prop-list__item" wire:key="item-{{ $i }}-{{ $prop }}-{{ $j }}">
                                                                        @if (isset($spec['of']['type']))
                                                                            @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['of']['type'], 'model' => "blocks.$i.props.$prop.$j", 'fieldLabel' => $prop, 'value' => $item, 'imageUrls' => $blockImageUrls, 'options' => $spec['of']['options'] ?? [], 'describedBy' => $prop === 'product_keys' && is_string($item) && in_array(strtoupper($item), $goneProducts, true) ? "gone-$i-$j" : null])
                                                                        @else
                                                                            @foreach (($spec['of'] ?? []) as $field => $fieldSpec)
                                                                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $fieldSpec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop.$j.$field", 'fieldLabel' => $field, 'value' => $item[$field] ?? null, 'imageUrls' => $blockImageUrls, 'options' => $fieldSpec['options'] ?? []])
                                                                            @endforeach
                                                                        @endif
                                                                        @if ($prop === 'product_keys' && is_string($item) && in_array(strtoupper($item), $goneProducts, true))
                                                                            <span class="error" id="gone-{{ $i }}-{{ $j }}">{{ __('entries.product_pick_gone') }}</span>
                                                                        @endif
                                                                        <button type="button" wire:click="moveItemUp({{ $i }}, '{{ $prop }}', {{ $j }})" aria-label="{{ __('entries.move_up') }}">↑</button>
                                                                        <button type="button" wire:click="moveItemDown({{ $i }}, '{{ $prop }}', {{ $j }})" aria-label="{{ __('entries.move_down') }}">↓</button>
                                                                        <button type="button" wire:click="removeItem({{ $i }}, '{{ $prop }}', {{ $j }})">{{ __('entries.remove_item') }}</button>
                                                                    </div>
                                                                @endforeach
                                                                <button type="button" wire:click="addItem({{ $i }}, '{{ $prop }}')">{{ __('entries.add_item') }}</button>
                                                            </fieldset>
                                                        @else
                                                            @include('admin::livewire.entries.partials.prop-input', ['fieldType' => ($spec['type'] ?? 'text') === 'html' ? 'textarea' : ($spec['type'] ?? 'text'), 'model' => "blocks.$i.props.$prop", 'fieldLabel' => $prop, 'value' => $block['props'][$prop] ?? null, 'imageUrls' => $blockImageUrls, 'options' => $spec['options'] ?? [], 'min' => $spec['min'] ?? null, 'max' => $spec['max'] ?? null])
                                                        @endif
                                                    @endforeach
                                                </div>
                                                @include('admin::livewire.entries.partials.block-look', ['look' => $looks[$i]])
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                            {{-- «+ Thêm khối»: the kinds a body may hold, by name. A block added to a written body makes
                                 the document the layout's first block (addBlock). --}}
                            <div x-data="{ adding: false }" class="flex flex-col gap-2">
                                <button type="button" class="hit-area inline-flex min-h-(--spacing-control) items-center justify-center gap-1.5 rounded-lg border-[1.5px] border-dashed border-border-input bg-transparent px-3 text-small font-semibold text-primary-text transition-colors hover:bg-surface-hover"
                                        x-on:click="adding = ! adding" x-bind:aria-expanded="adding ? 'true' : 'false'" aria-expanded="false" aria-controls="block-add-list">
                                    <x-bs-admin::icon name="ri-add" :class="$glyph" />{{ __('entries.ed.add_block') }}
                                </button>
                                <div id="block-add-list" x-show="adding" style="display: none" class="flex flex-col gap-2 rounded-lg border border-border bg-surface p-3">
                                    <p class="m-0 text-small font-semibold text-text-strong">{{ __('entries.ed.add_pick') }}</p>
                                    <ul class="block-palette m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(13rem,1fr))] gap-2 p-0">
                                        @foreach ($availableTypes as $availableType)
                                            <li>
                                                <button type="button" wire:click="addBlock('{{ $availableType }}')" x-on:click="adding = false"
                                                        class="hit-area flex min-h-(--spacing-control) w-full items-center gap-2.5 rounded-md border border-border bg-surface px-2.5 py-1.5 text-start text-small text-text transition-colors hover:bg-surface-hover">
                                                    <span class="inline-flex size-7 shrink-0 items-center justify-center rounded {{ $kinds[$availableType]['tint'] }}" aria-hidden="true">
                                                        <x-bs-admin::icon :name="$kinds[$availableType]['icon']" class="size-4" />
                                                    </span>
                                                    <span class="min-w-0">{{ $kinds[$availableType]['label'] }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    @endif
                </section>

                <x-bs-admin::seo-fields excerpt="excerpt" part="card" :kind="$type" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="entry-seo" />
            </div>

            <div class="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
                <section class="{{ $card }} overflow-hidden" aria-labelledby="entry-pub-h">
                    <div class="flex items-center justify-between gap-2.5 border-b border-border px-4 py-3">
                        <h2 id="entry-pub-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_publish') }}</h2>
                        <x-bs-admin::status-pill :status="$pill['status']">{{ $pill['label'] }}</x-bs-admin::status-pill>
                    </div>
                    @if ($workflow !== null)
                        <div class="p-4">
                            {{-- An active workflow governs this post: its steps and moves replace "Đăng". --}}
                            @include('admin::livewire.entries.partials.workflow-status')
                        </div>
                    @endif
                </section>

                @if ($type === 'post')
                    <section class="{{ $card }}" aria-labelledby="entry-cat-h">
                        <div class="{{ $cardHead }}"><h2 id="entry-cat-h" class="{{ $cardTitle }}"><label for="entry-category">{{ __('entries.ed.card_category') }}</label></h2></div>
                        <div class="p-4">
                            <select id="entry-category" wire:model.live="categoryId" @error('categoryId') aria-invalid="true" aria-describedby="entry-category-e" @enderror
                                    class="field block w-full rounded-md border border-border-input bg-surface px-3 text-text transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                                <option value="">{{ __('entries.category_placeholder') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                                @endforeach
                            </select>
                            @error('categoryId') <p id="entry-category-e" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    </section>
                @endif

                @can('media.view')
                    <section class="{{ $card }} flex flex-col gap-3 px-4 py-4" aria-labelledby="entry-cover-h">
                        <h2 id="entry-cover-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_cover') }}</h2>
                        <div class="relative aspect-video w-full overflow-hidden rounded-md border border-border bg-surface-muted">
                            @if ($coverUrl)
                                <img src="{{ $coverUrl }}" alt="{{ __('entries.cover_alt') }}" class="entry-cover__preview size-full object-cover">
                                <button type="button" wire:click="clearCover" class="hit-area absolute end-2 top-2 inline-flex min-h-(--spacing-control) items-center gap-1 rounded-md bg-surface px-2.5 text-small font-medium text-danger-text shadow-card transition-colors hover:bg-surface-hover"
                                        title="{{ __('entries.ed.cover_remove_title', ['unit' => $unit]) }}" aria-label="{{ __('entries.ed.cover_remove_title', ['unit' => $unit]) }}">
                                    <x-bs-admin::icon name="ri-delete-bin" :class="$glyph" />{{ __('entries.cover_remove') }}
                                </button>
                            @else
                                <button type="button" wire:click="openCoverPicker" class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 text-text-muted transition-colors hover:bg-surface-hover hover:text-primary-text">
                                    <x-bs-admin::icon name="ri-image-add" class="size-7" />
                                    <span class="text-small font-semibold">{{ __('entries.ed.cover_choose') }}</span>
                                </button>
                            @endif
                        </div>
                        @if ($coverUrl)
                            <div>
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="openCoverPicker">
                                    <x-bs-admin::icon name="ri-image-edit" :class="$glyph" /> {{ __('entries.ed.cover_change') }}
                                </x-bs-admin::button>
                            </div>
                        @endif
                    </section>
                @endcan
            </div>
        </div>

    </x-bs-admin::editor-frame>

    <x-bs-admin::unsaved-dialog :count="$changes" :label="$frame['title']" :save="$leave['method']" discard="discardChanges" :body="$leave['body']" :save-label="$leave['save']" :discard-label="$leave['discard']" />

    @if ($stale !== null)
        {{-- Someone else saved since this form was loaded (Editor::askBeforeOverwrite; design 07, the
             stale question). «Xem khác biệt» shows each field that differs; «Lấy bản mới» keeps this
             form's text on this device first (the «Khôi phục» band), then takes the saved version;
             «Giữ bản của tôi» saves over it. Escape or the backdrop close it without saving. Below
             600px it is a sheet from the bottom. --}}
        <div wire:key="entry-stale-{{ md5(json_encode($stale['print'])) }}" data-entry-stale
             x-data="{
                diff: false,
                keepFailed: false,
                opener: null,
                init() { this.opener = document.activeElement; },
                destroy() { const o = this.opener; if (o && o.isConnected) { o.focus(); } },
                // True when the text is on this device now; false (a full or blocked store) keeps the
                // question open, because taking the new version would lose it.
                keep() {
                    const k = window.bsKept;
                    const rec = k ? k.write(@js($keptKey), k.valuesOf($wire)) : null;
                    this.keepFailed = !rec;
                    if (rec) { window.dispatchEvent(new CustomEvent('bs-entry-kept', { detail: { key: @js($keptKey), rec: rec } })); }

                    return !!rec;
                },
             }">
            <x-bs-admin::modal state="true" close="$wire.closeStale()" size="narrow" compact alert :close-label="__('admin.dismiss')">
                <x-slot:heading>
                    <span class="flex items-center gap-2.5">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--color-warning-text)_12%,var(--color-surface))] text-warning-text" aria-hidden="true">
                            <x-bs-admin::icon name="ri-user-shared" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                        </span>
                        <span>{{ $stale['title'] }}</span>
                    </span>
                </x-slot:heading>
                <div class="flex items-start gap-2">
                    <x-bs-admin::icon name="ri-information" class="mt-[3px] size-[1.15em] shrink-0 text-text-muted" />
                    <span>{{ $stale['line'] }}</span>
                </div>
                <x-bs-admin::alert color="danger" icon="ri-error-warning" x-show="keepFailed" x-cloak>{{ __('entries.ed.stale_keep_failed') }}</x-bs-admin::alert>
                @foreach ($stale['rows'] as $row)
                    <div x-show="diff" x-cloak class="flex flex-col gap-[3px] rounded-sm border border-[color-mix(in_srgb,var(--color-warning-text)_40%,var(--color-surface))] bg-[color-mix(in_srgb,var(--color-warning-text)_8%,var(--color-surface))] px-2.5 py-2">
                        <span class="text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text-muted">{{ $row['label'] }}</span>
                        <span class="text-text-strong">{{ __('entries.ed.stale_mine', ['value' => $row['mine']]) }}</span>
                        <span class="text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('entries.ed.stale_theirs', ['value' => $row['theirs']]) }}</span>
                    </div>
                @endforeach
                <x-slot:footer>
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-on:click="diff = !diff" x-bind:aria-expanded="diff ? 'true' : 'false'">
                        <x-bs-admin::icon name="ri-git-commit" :class="$glyph" />
                        <span x-text="diff ? @js(__('entries.ed.stale_diff_hide')) : @js(__('entries.ed.stale_diff_show'))">{{ __('entries.ed.stale_diff_show') }}</span>
                    </x-bs-admin::button>
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-on:click="if (keep()) { $wire.takeNewer(); }" wire:loading.attr="disabled" wire:target="takeNewer,keepMine">
                        <x-bs-admin::icon name="ri-download-2" :class="$glyph" /> {{ __('entries.ed.stale_take') }}
                    </x-bs-admin::button>
                    <x-bs-admin::button color="primary" size="sm" wire:click="keepMine" wire:loading.attr="disabled" wire:target="takeNewer,keepMine">
                        <x-bs-admin::icon name="ri-save-3" :class="$glyph" /> {{ __('entries.ed.stale_keep') }}
                    </x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>
        </div>
    @endif

    @can('media.view')
        @if ($showMediaPicker)
            <div class="media-picker" role="dialog" aria-modal="true" aria-label="{{ __('entries.cover_pick') }}">
                <div class="media-picker__head">
                    <strong>{{ __('entries.cover_pick') }}</strong>
                    <button type="button" wire:click="closeCoverPicker">{{ __('entries.close') }}</button>
                </div>
                @if ($pickerMedia->isEmpty())
                    <p>{{ __('entries.library_empty') }}</p>
                @else
                    <div class="media-picker__grid">
                        @foreach ($pickerMedia as $m)
                            @if (str_starts_with($m->mime, 'image/'))
                                <button type="button" wire:key="pick-{{ $m->id }}"
                                        wire:click="selectCover('{{ $m->storage_key }}')"
                                        class="media-picker__item" title="{{ $m->original_filename }}">
                                    <img src="{{ $urls->url($m->storage_key, 320) }}" alt="{{ $m->original_filename }}" loading="lazy">
                                </button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @if ($showBlockImagePicker)
            <div class="media-picker" role="dialog" aria-modal="true" aria-label="{{ __('entries.image_pick') }}">
                <div class="media-picker__head">
                    <strong>{{ __('entries.image_pick') }}</strong>
                    <button type="button" wire:click="closeBlockImagePicker">{{ __('entries.close') }}</button>
                </div>
                @if ($pickerMedia->isEmpty())
                    <p>{{ __('entries.library_empty') }}</p>
                @else
                    <div class="media-picker__grid">
                        @foreach ($pickerMedia as $m)
                            @if (str_starts_with($m->mime, 'image/'))
                                <button type="button" wire:key="blockpick-{{ $m->id }}"
                                        wire:click="selectBlockImage({{ $m->id }})"
                                        class="media-picker__item" title="{{ $m->original_filename }}">
                                    <img src="{{ $urls->url($m->storage_key, 320) }}" alt="{{ $m->original_filename }}" loading="lazy">
                                </button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- "Tải tệp": a document of the library for a button to hand out. --}}
        <x-bs-admin::modal model="showButtonFilePicker" :title="__('entries.btn_file_dialog_title')" :subtitle="__('entries.btn_file_dialog_sub')" :close-label="__('entries.btn_file_dialog_close')">
            @if ($buttonPanel['documents'] === [])
                <div class="btn-act__docs-empty">
                    <b>{{ __('entries.btn_file_dialog_empty_title') }}</b>
                    <span>{{ __('entries.btn_file_dialog_empty_how') }}</span>
                    <a class="btn-act__lib" href="{{ route('admin.media.index') }}" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
                </div>
            @else
                <div class="btn-act__docs">
                    @foreach (array_map(fn (array $doc): array => $doc + ['current' => $doc['key'] === $buttonPanel['fileCurrent']], $buttonPanel['documents']) as $doc)
                        <button type="button" class="btn-act__doc" aria-pressed="{{ $doc['current'] ? 'true' : 'false' }}" wire:click="pickButtonFile({{ \Illuminate\Support\Js::from($doc['key']) }})" wire:key="btn-doc-{{ $doc['key'] }}" @if ($doc['current']) autofocus @endif>
                            <span class="btn-act__ic"><x-bs-admin::icon :name="$doc['icon']" class="btn-act__ic-glyph" /></span>
                            <span class="btn-act__kt"><span class="btn-act__name">{{ $doc['name'] }}</span><span class="btn-act__meta">{{ $doc['meta'] }}</span>
                                @if ($doc['current'])
                                    <span class="btn-act__doc-cur"><x-bs-admin::icon name="ri-check" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_current') }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
            <x-slot:footer>
                @if ($buttonPanel['documents'] !== [])
                    <a class="btn-act__lib me-auto" href="{{ route('admin.media.index') }}" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
                @endif
                <x-bs-admin::button variant="outline" color="secondary" wire:click="closeButtonFilePicker">{{ __('entries.btn_file_dialog_cancel') }}</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
        {{-- The body's «Chèn ảnh» (WithMediaLibraryPicker): library pictures only; a picture taken
             from a link is always saved into the library, so the body never points elsewhere. --}}
        @include('admin::partials.media-picker-modal', ['pickedIds' => [], 'editorLibraryOnly' => true])
    @endcan
    @include('admin::partials.video-embed-modal')
</div>
