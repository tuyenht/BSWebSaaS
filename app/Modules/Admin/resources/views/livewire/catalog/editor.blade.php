{{-- The product editor (design 09): the frame every editor shares (components/editor-frame.blade.php:
     the Shell's header, the bar that stays under the admin's top bar, the ⋮, the bottom bar of a
     narrow editor, the phone's one bar), then the cards in the board's order. Main column: Thông tin sản
     phẩm (name; slug and summary in the "Thêm tùy chọn" group), Ảnh sản phẩm, Giá & tồn kho (the
     price; in the group the stock and the sale with its start and end; folded behind a note while the
     website only presents its products), Mô tả, the shared "Hiện thế nào trên Google" card, the "Thêm
     tùy chọn" button. Side column: Đăng (status, first publication, last save, what visitors see),
     then in the group Chuyên mục and Mã & nhãn (SKU, «Nổi bật», «Bán chạy»). The group stays open or
     closed per browser (localStorage 'bs-editor'), and opens (with the price card) when a field in it
     has an error. Amounts, stock, dates and the SKU are checked when the field is left. The save state at rest is drawn here; typing, saving and a lost connection are
     drawn over it in the browser (resources/js/admin-editor.js). The description is a Jodit island
     (lazy chunk) inside wire:ignore; its HTML syncs back deferred. Sizes come from the control
     tokens; the layout follows the editor's own width (container queries). --}}
@php
    use App\Modules\Admin\Livewire\Catalog\Editor;
    use App\Modules\Admin\Support\SeoFields;
    use Illuminate\Support\Js;

    $bandIcons = ['empty' => 'ri-edit', 'short' => 'ri-arrow-up', 'near' => 'ri-arrow-right-up', 'good' => 'ri-check', 'long' => 'ri-error-warning', 'over' => 'ri-close-circle'];
    // The name and summary meters are the shared part's (bsSeoFields): the name measured like a title, the summary like a short description.
    $infoConfig = SeoFields::clientConfig(['excerpt' => 'summary', 'title' => 'name', 'description' => null, 'pageTitle' => null, 'slug' => 'slug'], $address);
    // read only: nothing to save, so no autosave
    $saveConfig = ['fields' => Editor::TRACKED, 'autosave' => $canEdit, 'words' => [
        'dirty' => __('catalog.ed.sv_dirty'),
        'dirtyDraft' => __('catalog.ed.sv_dirty_draft'),
        'dirtyLive' => __('catalog.ed.sv_dirty_live'),
        'saving' => __('catalog.ed.sv_saving'),
        'offline' => __('catalog.ed.sv_offline'),
        'offlineToast' => __('catalog.ed.toast_offline'),
        'offlineToastIcon' => \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-wifi-off" class="size-4" />'),
    ]];
    $field = 'field block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text';
    $label = 'inline-flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5 gap-y-1 text-small font-semibold text-text-strong';
    $labelRow = 'mb-1.5 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1';
    $error = 'mt-1 text-small text-danger-text';
    $hint = 'mt-1.5 text-caption text-text-muted';
    $cardHead = 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3';
    $cardTitle = 'm-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-tight text-text';
    $chip = $multiLocale ? strtoupper($locale) : null;
    $glyph = 'size-[1.15em] shrink-0';
    $moneyBox = 'flex min-w-0 items-center rounded-md border border-border-input bg-surface-muted focus-within:border-primary-text has-aria-invalid:border-danger-text';
    $fold = 'flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-border bg-surface-muted px-3 py-2.5 text-small text-text';
    $subLabel = 'mb-1.5 block text-small font-medium text-text-strong';
    $stockWords = ['none' => __('catalog.ed.stock_hint_none'), 'out' => __('catalog.ed.stock_hint_out'), 'in' => __('catalog.ed.stock_hint_in')];
    $stockKind = static fn (?string $typed): string => ($digits = preg_replace('/\D/', '', (string) $typed)) === '' ? 'none' : ((int) $digits === 0 ? 'out' : 'in');
@endphp
<div class="catalog-editor @container" data-catalog-editor>
    <x-bs-admin::editor-frame :title="$frame['title']" :crumbs="$frame['crumbs']" :description="$frame['description']" :guide="$frame['guide']"
                              :back-url="route('admin.catalog.index')" :back-label="__('catalog.ed.back_title')"
                              :note="$note" :save-config="$saveConfig" :actions="$actions" target="save,publish,unpublish,toDraft"
                              :preview="$frame['preview']" :preview-bar="false" :trash="$frame['trash']" :trash-hint="$frame['trash'] !== null ? __('catalog.menu_trash_hint') : null"
                              :name="$name !== '' ? $name : $frame['title']">
        {{-- the ⋮'s status moves (the board's): a published product can be taken off the website or put back
             to draft, an archived one put back to draft — each a full save, a field to fix stops it --}}
        @if ($canEdit && $ulid !== null && in_array($status, ['published', 'archived'], true))
            <x-slot:menu>
                @if ($status === 'published')
                    <x-bs-admin::list.menu-item icon="ri-archive" wire:click="unpublish" wire:loading.attr="disabled" wire:target="save,publish,unpublish,toDraft">{{ __('catalog.ed.menu_unpublish') }}</x-bs-admin::list.menu-item>
                    <span class="list-mwhy">{{ __('catalog.ed.menu_unpublish_hint') }}</span>
                @endif
                <x-bs-admin::list.menu-item icon="ri-draft" wire:click="toDraft" wire:loading.attr="disabled" wire:target="save,publish,unpublish,toDraft">{{ __('catalog.ed.menu_to_draft') }}</x-bs-admin::list.menu-item>
                <span class="list-mwhy">{{ __($status === 'published' ? 'catalog.ed.menu_to_draft_hint_live' : 'catalog.ed.menu_to_draft_hint_archived') }}</span>
            </x-slot:menu>
        @endif
        @unless ($canEdit)
            <div class="flex items-start gap-2.5 rounded-md border border-[color-mix(in_srgb,var(--color-primary-text)_26%,var(--color-surface))] bg-[color-mix(in_srgb,var(--color-primary-text)_7%,var(--color-surface))] px-3.5 py-3 text-body text-text-strong" role="note" data-state="readonly">
                <x-bs-admin::icon name="ri-eye" class="mt-0.5 size-[min(24px,calc(1.125rem+var(--user-text-boost)))] shrink-0 text-primary-text" />
                <p class="m-0 min-w-0 flex-1"><b>{{ __('catalog.ed.ro_lead') }}</b> {{ $roRole !== null ? __('catalog.ed.ro_text', ['role' => $roRole]) : __('catalog.ed.ro_text_plain') }}</p>
            </div>
        @endunless
        <div class="grid min-w-0 items-start gap-4 @min-[64rem]:grid-cols-3"
             x-data="{ more: false, init() { try { this.more = JSON.parse(localStorage.getItem('bs-editor') || '{}').more === true } catch (e) {} }, setMore(on) { this.more = on; try { const o = JSON.parse(localStorage.getItem('bs-editor') || '{}') || {}; o.more = on; localStorage.setItem('bs-editor', JSON.stringify(o)) } catch (e) {} } }">
            <div class="flex min-w-0 flex-col gap-4 @min-[64rem]:col-span-2">
                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-info" x-data="bsSeoFields({{ Js::from($infoConfig) }})">
                    <div class="{{ $cardHead }}"><h2 id="ped-h-info" class="{{ $cardTitle }}">{{ __('catalog.card_product') }}</h2></div>
                    <div class="flex flex-col gap-4 p-4">
                        <div>
                            <div class="{{ $labelRow }}">
                                <label for="catalog-name" class="{{ $label }}">
                                    <span>{{ __('catalog.name_label') }} <span class="text-danger-text" aria-hidden="true">*</span></span>
                                    @if ($chip)
                                        <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                                    @endif
                                </label>
                            </div>
                            <textarea id="catalog-name" wire:model="name" @readonly(! $canEdit) rows="1" placeholder="{{ __('catalog.ed.name_ph') }}" maxlength="{{ \App\Modules\Catalog\Actions\RenameProduct::MAX }}" lang="{{ $locale }}" aria-required="true"
                                      aria-describedby="catalog-name-c @error('name') catalog-name-e @enderror" @error('name') aria-invalid="true" @enderror
                                      x-on:keydown.enter="if (! $event.isComposing && $event.keyCode !== 229) $event.preventDefault()"
                                      class="{{ $field }} resize-none py-2 [field-sizing:content]"></textarea>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="seo-meter min-w-24 flex-1" :data-b="titleMeter.band" aria-hidden="true">
                                    <span class="seo-meter__zone" :style="{ left: titleMeter.lo + '%', width: titleMeter.zw + '%' }"></span>
                                    <span class="seo-meter__fill" :style="{ width: titleMeter.pct + '%' }"></span>
                                    <span class="seo-meter__tick" :style="{ left: titleMeter.tick + '%' }"></span>
                                </span>
                                <span id="catalog-name-c" class="seo-count" :data-b="titleMeter.band" data-b="empty">
                                    @foreach ($bandIcons as $band => $icon)
                                        <x-bs-admin::icon :name="$icon" x-show="titleMeter.band === '{{ $band }}'" x-cloak />
                                    @endforeach
                                    <span x-text="titleMeter.text"></span>
                                </span>
                            </div>
                            @error('name') <p id="catalog-name-e" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>

                        <div x-show="more" x-cloak>
                            <label for="catalog-slug" class="{{ $label }} mb-1.5">
                                <span>{{ __('catalog.card_opt_slug') }}</span>
                                @if ($chip)
                                    <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                                @endif
                            </label>
                            {{-- the address before the slug sits inside the field, as the board draws it; a long one wraps --}}
                            <div class="flex min-w-0 flex-wrap items-center rounded-md border border-border-input bg-surface-muted px-3 focus-within:border-primary-text has-aria-invalid:border-danger-text">
                                <span class="min-w-0 max-w-full pe-0.5 font-mono text-small leading-snug text-text-muted [overflow-wrap:anywhere]" dir="ltr">{{ $address['base'] }}</span>
                                <input type="text" id="catalog-slug" wire:model.live.blur="slug" @readonly(! $canEdit) dir="ltr" spellcheck="false" autocomplete="off"
                                       aria-describedby="catalog-slug-h @error('slug') catalog-slug-e @enderror" @error('slug') aria-invalid="true" @enderror
                                       class="field min-w-0 flex-1 border-0 bg-transparent px-1 font-mono text-text">
                            </div>
                            @error('slug') <p id="catalog-slug-e" class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                            @if ($liveUrl !== null && ($saved['slug'] ?? '') !== '')
                                <p class="mt-1.5 flex items-start gap-1.5 text-small text-warning-text" x-show="$wire.slug !== @js($saved['slug'])" x-cloak>
                                    <x-bs-admin::icon name="ri-route" class="mt-0.5 {{ $glyph }}" />
                                    <span>{{ __('catalog.ed.slug_moves', ['old' => $liveUrl]) }}</span>
                                </p>
                            @endif
                            <div id="catalog-slug-h" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="min-w-0 text-caption text-text-muted [overflow-wrap:anywhere]" dir="ltr" x-text="previewAddress">{{ $address['base'].$slug }}</span>
                                @if ($liveUrl !== null)
                                    <x-bs-admin::button variant="ghost" size="sm" :href="'//'.$liveUrl" :navigate="false" target="_blank" rel="noopener">
                                        <x-bs-admin::icon name="ri-external-link" :class="$glyph" /> {{ __('catalog.view_on_site') }}
                                    </x-bs-admin::button>
                                @endif
                            </div>
                        </div>

                        <div x-show="more" x-cloak>
                            <div class="{{ $labelRow }}">
                                <label for="catalog-summary" class="{{ $label }}">
                                    <span>{{ __('catalog.ed.summary_label') }}</span>
                                    @if ($chip)
                                        <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                                    @endif
                                </label>
                                <span id="catalog-summary-c" class="seo-count" :data-b="excerptMeter.band" data-b="empty">
                                    @foreach ($bandIcons as $band => $icon)
                                        <x-bs-admin::icon :name="$icon" x-show="excerptMeter.band === '{{ $band }}'" x-cloak />
                                    @endforeach
                                    <span x-text="excerptMeter.text"></span>
                                </span>
                            </div>
                            <textarea id="catalog-summary" wire:model="summary" @readonly(! $canEdit) rows="3" placeholder="{{ __('catalog.ed.summary_ph') }}" maxlength="{{ Editor::SUMMARY_MAX }}" lang="{{ $locale }}"
                                      aria-describedby="catalog-summary-c catalog-summary-h @if ($summaryWarning) catalog-summary-w @endif @error('summary') catalog-summary-e @enderror" @error('summary') aria-invalid="true" @enderror
                                      class="{{ $field }} resize-y py-2.5"></textarea>
                            <span class="seo-meter" :data-b="excerptMeter.band" aria-hidden="true">
                                <span class="seo-meter__zone" :style="{ left: excerptMeter.lo + '%', width: excerptMeter.zw + '%' }"></span>
                                <span class="seo-meter__fill" :style="{ width: excerptMeter.pct + '%' }"></span>
                                <span class="seo-meter__tick" :style="{ left: excerptMeter.tick + '%' }"></span>
                            </span>
                            @error('summary') <p id="catalog-summary-e" class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                            @if ($summaryWarning)
                                <p id="catalog-summary-w" class="mt-1.5 flex items-start gap-1.5 text-small text-warning-text" x-init="setMore(true)">
                                    <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $glyph }}" /><span>{{ $summaryWarning }}</span>
                                </p>
                            @endif
                            <p id="catalog-summary-h" class="{{ $hint }}">{{ __('catalog.ed.summary_hint') }}</p>
                        </div>
                    </div>
                </section>

                {{-- Ảnh sản phẩm (board 09): one strip that scrolls sideways (‹ › when it overflows), the first picture the
                     main one. A tap selects a picture and opens the bar under the strip: Chuyển lên / Chuyển xuống,
                     Đặt làm ảnh đại diện, Bỏ khỏi sản phẩm (with «Hoàn tác»). A picture can also be dragged to another
                     place; ← → go from picture to picture. The order is saved with the product. --}}
                @php
                    $imgN = count($galleryImages);
                    $mayAddImages = $canEdit && auth()->user()?->can('media.view');
                    $imgWords = [
                        'pos' => __('catalog.ed.img_pos'), 'posCover' => __('catalog.ed.img_pos_cover'),
                        'upFirst' => __('catalog.ed.img_up_first'), 'up' => __('catalog.ed.img_up_why'),
                        'downLast' => __('catalog.ed.img_down_last'), 'down' => __('catalog.ed.img_down_why'),
                        'coverIs' => __('catalog.ed.img_cover_is'), 'cover' => __('catalog.ed.img_cover_why'),
                    ];
                @endphp
                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-img" data-picker-home
                         x-data="{
                             sel: null, from: null, over: null, canL: false, canR: false,
                             get n() { return this.$refs.strip ? this.$refs.strip.querySelectorAll('[data-img-i]').length : 0; },
                             w: {{ Js::from($imgWords) }},
                             edge() { const s = this.$refs.strip; if (! s) { this.canL = this.canR = false; return; } const x = Math.abs(s.scrollLeft); this.canL = x > 2; this.canR = x + s.clientWidth < s.scrollWidth - 2; },
                             page(d) { const s = this.$refs.strip; s && s.scrollBy({ left: d * Math.max(120, s.clientWidth * 0.8), behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); },
                             pick(i) { this.sel = this.sel === i ? null : i; },
                             act(method, to) { const i = this.sel; if (i === null) return; $wire[method](...(to === undefined ? [i] : [i, to])).then(() => { this.sel = method === 'removeImage' ? null : (method === 'setCover' ? 0 : to); this.$nextTick(() => this.focusTile(this.sel)); }); },
                             drop(i) { const f = this.from; this.from = this.over = null; if (f === null || f === i) return; $wire.moveImage(f, i).then(() => { this.sel = i; }); },
                             key(e, i) { const d = e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowLeft' ? -1 : 0); if (! d) return; e.preventDefault(); this.focusTile(Math.max(0, Math.min(this.n - 1, i + d))); },
                             focusTile(i) { if (i === null) return; const b = this.$root.querySelector('[data-img-i=&quot;' + i + '&quot;]'); b && b.focus(); b && b.scrollIntoView({ block: 'nearest', inline: 'nearest' }); },
                         }"
                         x-on:resize.window.debounce.100ms="edge()">
                    <div class="{{ $cardHead }}">
                        <h2 id="ped-h-img" class="{{ $cardTitle }}">{{ __('catalog.card_gallery') }}</h2>
                        <span class="flex-1"></span>
                        @if ($mayAddImages)
                            <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="openImagePicker">
                                <x-bs-admin::icon name="ri-image-add" :class="$glyph" /> {{ __('catalog.ed.img_add') }}
                            </x-bs-admin::button>
                        @endif
                    </div>
                    <div class="flex flex-col gap-3 p-4">
                        @if ($galleryImages === [])
                            <div class="ped-imempty">
                                <x-bs-admin::icon name="ri-image-add" class="size-7" />
                                <span>{{ __('catalog.ed.img_empty') }}</span>
                                @if ($mayAddImages)
                                    <x-bs-admin::button variant="outline" color="secondary" wire:click="openImagePicker">
                                        <x-bs-admin::icon name="ri-image-add" :class="$glyph" /> {{ __('catalog.ed.img_choose') }}
                                    </x-bs-admin::button>
                                @endif
                            </div>
                        @else
                            <div class="ped-strip">
                                <button type="button" class="ped-sarr" x-show="canL" x-cloak x-on:click="page(-1)" title="{{ __('catalog.ed.img_strip_l_title') }}" aria-label="{{ __('catalog.ed.img_strip_l') }}">
                                    <x-bs-admin::icon name="ri-arrow-left-s" class="size-5" />
                                </button>
                                <div class="ped-imgs" x-ref="strip" role="list" aria-label="{{ __('catalog.ed.img_strip') }}" x-on:scroll.debounce.50ms="edge()"
                                     x-init="$nextTick(() => edge()); new MutationObserver(() => edge()).observe($el, { childList: true })">
                                    @foreach ($galleryImages as $i => $image)
                                        @php
                                            $file = $image['name'] ?? __('catalog.gallery_missing');
                                            $aria = __($i === 0 ? 'catalog.ed.img_aria_cover' : 'catalog.ed.img_aria', ['i' => $i + 1, 'n' => $imgN]).($canEdit ? __('catalog.ed.img_aria_pick') : '');
                                        @endphp
                                        <div wire:key="gallery-{{ $image['id'] }}" class="ped-im" role="listitem"
                                             x-bind:data-sel="sel === {{ $i }} ? '1' : '0'" x-bind:data-over="over === {{ $i }} ? '1' : '0'"
                                             @if ($canEdit) draggable="true" x-on:dragstart="from = {{ $i }}; $event.dataTransfer.effectAllowed = 'move'" x-on:dragover.prevent="over = {{ $i }}"
                                             x-on:dragleave="over === {{ $i }} && (over = null)" x-on:drop.prevent="drop({{ $i }})" x-on:dragend="from = over = null" @endif>
                                            <button type="button" class="ped-imb" data-img-i="{{ $i }}" title="{{ $file }}" aria-label="{{ $aria }}"
                                                    @if ($canEdit) aria-pressed="false" x-bind:aria-pressed="(sel === {{ $i }}).toString()" x-on:click="pick({{ $i }})" @endif
                                                    x-on:keydown="key($event, {{ $i }})">
                                                <span class="ped-imf">
                                                    @if ($image['missing'])
                                                        <span class="px-2 text-center text-caption text-text-muted">{{ __('catalog.gallery_missing') }}</span>
                                                    @else
                                                        <img src="{{ $image['url'] }}" alt="" loading="lazy" class="size-full object-cover">
                                                    @endif
                                                </span>
                                            </button>
                                            @if ($i === 0)
                                                <x-bs-admin::badge color="warning" soft class="ped-main self-start"><x-bs-admin::icon name="ri-star-fill" class="size-3.5" />{{ __('catalog.gallery_primary') }}</x-bs-admin::badge>
                                            @endif
                                            <span class="ped-imn" title="{{ $file }}">{{ $file }}</span>
                                        </div>
                                    @endforeach
                                    @if ($mayAddImages)
                                        <div class="ped-imaddw" role="listitem">
                                            <button type="button" class="ped-imadd" wire:click="openImagePicker" aria-label="{{ __('catalog.ed.img_add') }}" title="{{ __('catalog.ed.img_add') }}">
                                                <x-bs-admin::icon name="ri-add" class="size-6" /><span>{{ __('catalog.ed.img_add_short') }}</span>
                                            </button>
                                        </div>
                                    @endif
                                </div>
                                <button type="button" class="ped-sarr" x-show="canR" x-cloak x-on:click="page(1)" title="{{ __('catalog.ed.img_strip_r_title') }}" aria-label="{{ __('catalog.ed.img_strip_r') }}">
                                    <x-bs-admin::icon name="ri-arrow-right-s" class="size-5" />
                                </button>
                            </div>
                            @if ($canEdit)
                                <div class="ped-tb" role="toolbar" aria-label="{{ __('catalog.ed.img_toolbar') }}" x-show="sel !== null" x-cloak>
                                    <span>{{ __('catalog.ed.img_selected') }} <strong x-text="w.pos.replace(':i', sel + 1).replace(':n', n)"></strong></span>
                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-bind:aria-disabled="(sel === 0).toString()" x-bind:title="sel === 0 ? w.upFirst : w.up"
                                                        x-on:click="sel > 0 && act('moveImage', sel - 1)" class="aria-disabled:opacity-60">
                                        <x-bs-admin::icon name="ri-arrow-left" :class="$glyph" /> {{ __('catalog.gallery_move_up') }}
                                    </x-bs-admin::button>
                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-bind:aria-disabled="(sel === n - 1).toString()" x-bind:title="sel === n - 1 ? w.downLast : w.down"
                                                        x-on:click="sel < n - 1 && act('moveImage', sel + 1)" class="aria-disabled:opacity-60">
                                        <x-bs-admin::icon name="ri-arrow-right" :class="$glyph" /> {{ __('catalog.gallery_move_down') }}
                                    </x-bs-admin::button>
                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-bind:aria-disabled="(sel === 0).toString()" x-bind:title="sel === 0 ? w.coverIs : w.cover"
                                                        x-on:click="sel > 0 && act('setCover')" class="aria-disabled:opacity-60">
                                        <x-bs-admin::icon name="ri-star" :class="$glyph" /> {{ __('catalog.ed.img_set_cover') }}
                                    </x-bs-admin::button>
                                    <x-bs-admin::button variant="outline" color="danger" size="sm" title="{{ __('catalog.ed.img_remove_why') }}" x-on:click="act('removeImage')">
                                        <x-bs-admin::icon name="ri-close-circle" :class="$glyph" /> {{ __('catalog.ed.img_remove') }}
                                    </x-bs-admin::button>
                                </div>
                            @endif
                            <p class="{{ $hint }} mt-0">{{ $canEdit ? __('catalog.ed.img_hint') : __('catalog.ed.img_hint_ro') }}</p>
                        @endif
                        @error('imageMediaIds.*') <p class="mt-1 text-sm text-danger-text">{{ $message }}</p> @enderror
                    </div>
                </section>

                {{-- Giá & tồn kho (board 09): the price; in the group the stock and the sale. A website that only
                     presents its products folds the card behind a note (the amounts are kept); a field to fix
                     inside it opens it. Two columns while the card is wide enough. --}}
                <section class="@container rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-price" x-data="{ priceOpen: false }">
                    <div class="{{ $cardHead }}"><h2 id="ped-h-price" class="{{ $cardTitle }}">{{ $selling ? __('catalog.ed.card_price_stock') : __('catalog.card_price') }}</h2></div>
                    <div class="flex flex-col gap-4 p-4">
                        @unless ($selling)
                            <div class="{{ $fold }}" x-show="! priceOpen">
                                <x-bs-admin::icon name="ri-presentation" class="size-[min(24px,calc(1.125rem+var(--user-text-boost)))] shrink-0 text-primary-text" />
                                <span class="min-w-0 flex-[1_1_16rem]">{{ __('catalog.ed.fold_note') }} {{ $priceCard['keep'] }}</span>
                                @if ($mayOpenSettings)
                                    <x-bs-admin::button variant="ghost" size="sm" :href="route('admin.settings.catalog')">
                                        <x-bs-admin::icon name="ri-settings-3" :class="$glyph" />{{ __('catalog.ed.fold_settings') }}
                                    </x-bs-admin::button>
                                @endif
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" aria-expanded="false" aria-controls="catalog-price-fields" x-on:click="priceOpen = true; $nextTick(() => $refs.priceFirst.focus())">
                                    <x-bs-admin::icon name="ri-arrow-down-s" :class="$glyph" />{{ __('catalog.ed.fold_open') }}
                                </x-bs-admin::button>
                            </div>
                        @endunless
                        <div id="catalog-price-fields" class="flex flex-col gap-4" @unless ($selling) x-show="priceOpen" x-cloak @endunless>
                            @unless ($selling)
                                <div class="{{ $fold }}">
                                    <x-bs-admin::icon name="ri-presentation" class="size-[min(24px,calc(1.125rem+var(--user-text-boost)))] shrink-0 text-primary-text" />
                                    <span class="min-w-0 flex-[1_1_16rem]">{{ __('catalog.ed.fold_intro') }}</span>
                                    <x-bs-admin::button variant="ghost" size="sm" aria-expanded="true" aria-controls="catalog-price-fields" x-on:click="priceOpen = false">
                                        <x-bs-admin::icon name="ri-arrow-up-s" :class="$glyph" />{{ __('catalog.ed.fold_close') }}
                                    </x-bs-admin::button>
                                </div>
                            @endunless
                            <div class="grid gap-x-4 gap-y-3.5 @min-[32.5rem]:grid-cols-2">
                                <div>
                                    <label for="catalog-price" class="{{ $label }} mb-1.5">{{ __('catalog.ed.price_label') }}</label>
                                    <div class="{{ $moneyBox }}">
                                        <input type="text" id="catalog-price" x-ref="priceFirst" wire:model.blur="price" @readonly(! $canEdit) inputmode="numeric" autocomplete="off" placeholder="1.250.000"
                                               aria-describedby="catalog-price-h @error('price') catalog-price-e @enderror" @error('price') aria-invalid="true" @enderror class="field min-w-0 flex-1 border-0 bg-transparent px-3 text-right tabular-nums text-text placeholder:text-placeholder">
                                        <span class="pe-3 font-semibold text-text-muted" aria-hidden="true">₫</span>
                                    </div>
                                    @error('price') <x-bs-admin::field-error id="catalog-price-e" class="mt-1" x-init="priceOpen = true">{{ $message }}</x-bs-admin::field-error> @enderror
                                    <p id="catalog-price-h" class="{{ $hint }}">{{ __('catalog.ed.price_hint') }}@if ($priceCard['shown'] !== null) {{ __('catalog.ed.price_seen') }} <b class="font-semibold text-text-strong">{{ $priceCard['shown'] }}</b>@endif</p>
                                </div>
                                <div x-show="more" x-cloak>
                                    <label for="catalog-stock" class="{{ $label }} mb-1.5">{{ __('catalog.ed.stock_label') }}</label>
                                    <input type="text" id="catalog-stock" wire:model.blur="stock" @readonly(! $canEdit) inputmode="numeric" autocomplete="off" placeholder="24"
                                           aria-describedby="catalog-stock-h @error('stock') catalog-stock-e @enderror" @error('stock') aria-invalid="true" @enderror class="field block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                                    @error('stock') <x-bs-admin::field-error id="catalog-stock-e" class="mt-1" x-init="priceOpen = true; setMore(true)">{{ $message }}</x-bs-admin::field-error> @enderror
                                    <p id="catalog-stock-h" class="{{ $hint }}" x-data="{ words: {{ Js::from($stockWords) }} }"
                                       x-text="words[(() => { const d = String($wire.stock ?? '').replace(/\D/g, ''); return d === '' ? 'none' : (Number(d) === 0 ? 'out' : 'in') })()]">{{ $stockWords[$stockKind($stock)] }}</p>
                                </div>
                            </div>
                            <div x-show="more" x-cloak>
                                <p class="{{ $label }} mb-2">{{ __('catalog.ed.sale_head') }}</p>
                                <div class="grid gap-x-4 gap-y-3.5 @min-[32.5rem]:grid-cols-2">
                                    <div>
                                        <label for="catalog-sale" class="{{ $subLabel }}">{{ __('catalog.ed.sale_label') }}</label>
                                        <div class="{{ $moneyBox }}">
                                            <input type="text" id="catalog-sale" wire:model.blur="salePrice" @readonly(! $canEdit) inputmode="numeric" autocomplete="off" placeholder="1.090.000"
                                                   @error('salePrice') aria-invalid="true" aria-describedby="catalog-sale-e" @enderror class="field min-w-0 flex-1 border-0 bg-transparent px-3 text-right tabular-nums text-text placeholder:text-placeholder">
                                            <span class="pe-3 font-semibold text-text-muted" aria-hidden="true">₫</span>
                                        </div>
                                        @error('salePrice') <x-bs-admin::field-error id="catalog-sale-e" class="mt-1" x-init="priceOpen = true; setMore(true)">{{ $message }}</x-bs-admin::field-error> @enderror
                                    </div>
                                    <div aria-hidden="true"></div>
                                    <div>
                                        <label for="catalog-sale-from" class="{{ $subLabel }}">{{ __('catalog.ed.sale_from') }}</label>
                                        <input type="datetime-local" id="catalog-sale-from" wire:model.blur="saleStartsAt" @readonly(! $canEdit) aria-describedby="catalog-sale-h @error('saleStartsAt') catalog-sale-from-e @enderror"
                                               @error('saleStartsAt') aria-invalid="true" @enderror class="field block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                                        @error('saleStartsAt') <x-bs-admin::field-error id="catalog-sale-from-e" class="mt-1" x-init="priceOpen = true; setMore(true)">{{ $message }}</x-bs-admin::field-error> @enderror
                                    </div>
                                    <div>
                                        <label for="catalog-sale-to" class="{{ $subLabel }}">{{ __('catalog.ed.sale_to') }}</label>
                                        <input type="datetime-local" id="catalog-sale-to" wire:model.blur="saleEndsAt" @readonly(! $canEdit) aria-describedby="catalog-sale-h @error('saleEndsAt') catalog-sale-to-e @enderror"
                                               @error('saleEndsAt') aria-invalid="true" @enderror class="field block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                                        @error('saleEndsAt') <x-bs-admin::field-error id="catalog-sale-to-e" class="mt-1" x-init="priceOpen = true; setMore(true)">{{ $message }}</x-bs-admin::field-error> @enderror
                                    </div>
                                </div>
                                <p id="catalog-sale-h" class="{{ $hint }}">{{ $priceCard['sale'] }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-desc">
                    <div class="{{ $cardHead }}">
                        <h2 id="ped-h-desc" class="{{ $cardTitle }}"><label for="rich-editor-description">{{ __('catalog.ed.card_desc') }}</label></h2>
                        @if ($chip)
                            <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                        @endif
                        @php
                            // The product's spec table, added at the end of the description for the shop to fill in.
                            $specHtml = '<h3>'.e(__('admin.rte.spec_head')).'</h3><table><tbody><tr><th>'.e(__('admin.rte.spec_key')).'</th><th>'.e(__('admin.rte.spec_value')).'</th></tr>'
                                .implode('', array_map(static fn (string $row): string => '<tr><td>'.e($row).'</td><td>&nbsp;</td></tr>', explode('|', __('admin.rte.spec_rows'))))
                                .'</tbody></table><p></p>';
                        @endphp
                        @if ($canEdit)
                            <x-bs-admin::button variant="outline" color="secondary" size="sm" class="ms-auto shrink-0" data-rich-append="description" :data-rich-html="$specHtml" :data-rich-toast="__('admin.rte.spec_done')">
                                <x-bs-admin::icon name="ri-table" class="size-4" />{{ __('admin.rte.spec') }}
                            </x-bs-admin::button>
                        @endif
                    </div>
                    <div class="p-4">
                        @if ($canEdit)
                            <x-bs-admin::rich-editor target="description" :value="$description" rows="10" :placeholder="__('catalog.ed.desc_placeholder')" />
                        @else
                            {{-- read only: the saved description as visitors read it (cleaned again on the way out) --}}
                            <div id="rich-editor-description" class="rte-ro min-h-24 rounded-md border border-border bg-surface-muted px-4 py-3 text-body text-text [overflow-wrap:anywhere]" tabindex="0" role="document" aria-labelledby="ped-h-desc">
                                @if (trim(strip_tags($description)) !== '')
                                    {!! app(\App\Modules\Shared\Support\RichTextSanitizer::class)->full($description) !!}
                                @else
                                    <p class="m-0 text-text-muted">{{ __('catalog.ed.desc_empty') }}</p>
                                @endif
                            </div>
                        @endif
                        @error('description') <p class="mt-1 text-sm text-danger-text">{{ $message }}</p> @enderror
                    </div>
                </section>

                <x-bs-admin::seo-fields page-title-model="name" kind="product" :readonly="! $canEdit" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="catalog-seo" />

                <button type="button" x-on:click="setMore(! more)" x-bind:aria-expanded="more ? 'true' : 'false'" aria-expanded="false"
                        aria-label="{{ __('catalog.ed.more') }} — {{ __('catalog.ed.more_sub') }}"
                        x-bind:aria-label="more ? @js(__('catalog.ed.less').' — '.__('catalog.ed.less_sub')) : @js(__('catalog.ed.more').' — '.__('catalog.ed.more_sub'))"
                        class="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2.5 gap-y-1 rounded-md border border-dashed border-border-input px-4 py-2.5 text-left text-body font-semibold text-primary-text transition-colors min-h-12 hover:bg-[color-mix(in_srgb,var(--color-primary-text)_6%,var(--color-surface))]">
                    <x-bs-admin::icon name="ri-add" :class="$glyph" x-show="! more" />
                    <x-bs-admin::icon name="ri-subtract" :class="$glyph" x-show="more" x-cloak />
                    <span x-text="more ? @js(__('catalog.ed.less')) : @js(__('catalog.ed.more'))">{{ __('catalog.ed.more') }}</span>
                    <span class="col-start-2 text-small font-normal text-text-muted" aria-hidden="true">
                        <span class="ped-ms-l" x-text="more ? @js(__('catalog.ed.less_sub')) : @js(__('catalog.ed.more_sub'))">{{ __('catalog.ed.more_sub') }}</span>
                        {{-- the short line of the phone's child screen --}}
                        <span class="ped-ms-s" x-text="more ? @js(__('catalog.ed.less_sub_s')) : @js(__('catalog.ed.more_sub_s'))">{{ __('catalog.ed.more_sub_s') }}</span>
                    </span>
                </button>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-pub">
                    <div class="{{ $cardHead }}">
                        <h2 id="ped-h-pub" class="{{ $cardTitle }}">{{ __('catalog.card_publish') }}</h2>
                        <span class="flex-1"></span>
                        <x-bs-admin::status-pill :status="$publishCard['status']">{{ $publishCard['chip'] }}</x-bs-admin::status-pill>
                    </div>
                    <div class="flex flex-col gap-3 p-4">
                        <dl class="m-0 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 text-small">
                            <dt class="text-text-muted">{{ __('catalog.ed.pub_label') }}</dt><dd class="m-0 text-text [overflow-wrap:anywhere]">{{ $publishCard['published'] }}</dd>
                            <dt class="text-text-muted">{{ __('catalog.ed.upd_label') }}</dt><dd class="m-0 text-text [overflow-wrap:anywhere]">{{ $publishCard['updated'] }}</dd>
                            <dt class="text-text-muted">{{ __('catalog.ed.seen_label') }}</dt><dd class="m-0 text-text [overflow-wrap:anywhere]">{{ $publishCard['seen'] }}</dd>
                        </dl>
                        @if ($liveUrl !== null)
                            <div>
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" :href="'//'.$liveUrl" :navigate="false" target="_blank" rel="noopener">
                                    <x-bs-admin::icon name="ri-external-link" :class="$glyph" /> {{ __('catalog.view_on_site') }}
                                </x-bs-admin::button>
                            </div>
                        @endif
                    </div>
                </section>

                {{-- Chuyên mục (board 09): a box per category, in menu order and indented; «Mục chính» on the main
                     one and «Đặt làm mục chính» on the others ticked. The search ignores diacritics and case and
                     shows the names above each result. A box or a star is a request (keys only); nothing is saved
                     until the product is. --}}
                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-cat" x-show="more" x-cloak
                         x-data="{ q: '', fold(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').trim(); }, hit(t) { const w = this.fold(this.q); return w === '' || this.fold(t).includes(w); }, get searching() { return this.fold(this.q) !== ''; } }">
                    <div class="{{ $cardHead }}"><h2 id="ped-h-cat" class="{{ $cardTitle }}">{{ __('catalog.card_opt_cat') }}</h2></div>
                    <div class="flex flex-col gap-3 p-4">
                        @if ($categories !== [])
                            <x-bs-admin::search :label="__('catalog.ed.cat_search')" :placeholder="__('catalog.ed.cat_search_ph')" x-model="q" :disabled="! $canEdit" />
                        @endif
                        <div id="catalog-category" class="ped-cl max-h-[300px] overflow-y-auto overflow-x-hidden rounded border border-border [scrollbar-gutter:stable]" role="group" aria-label="{{ __('catalog.ed.cat_group') }}" tabindex="-1"
                             @error('categoryId') aria-invalid="true" aria-describedby="catalog-category-e" @enderror>
                            @foreach ($categories as $i => $category)
                                <div wire:key="cat-{{ $category['ulid'] }}" class="ped-cr" data-d="{{ $category['depth'] }}" x-bind:data-d="searching ? 0 : {{ $category['depth'] }}" x-show="hit(@js($category['path'].$category['label']))">
                                    <label for="catalog-cat-{{ $i }}">
                                        <input id="catalog-cat-{{ $i }}" type="checkbox" @checked($category['on']) @disabled(! $canEdit)
                                               wire:click="toggleCategory('{{ $category['ulid'] }}')" wire:loading.attr="disabled" wire:target="toggleCategory,setMainCategory">
                                        <span title="{{ $category['path'].$category['label'] }}"><span class="text-text-muted" x-show="searching" x-cloak>{{ $category['path'] }}</span>{{ $category['label'] }}</span>
                                    </label>
                                    @if ($category['main'])
                                        <x-bs-admin::badge color="warning" soft class="ped-main"><x-bs-admin::icon name="ri-star-fill" class="size-3.5" />{{ __('catalog.ed.cat_main') }}</x-bs-admin::badge>
                                    @elseif ($category['on'] && $canEdit)
                                        <button type="button" class="ped-star" wire:click="setMainCategory('{{ $category['ulid'] }}')" wire:loading.attr="disabled" wire:target="toggleCategory,setMainCategory"
                                                aria-label="{{ __('catalog.ed.cat_star_aria', ['name' => $category['label']]) }}">
                                            <x-bs-admin::icon name="ri-star" class="size-3.5" />{{ __('catalog.ed.cat_star') }}
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                            @if ($categories === [])
                                <p class="m-0 px-3 py-2.5 text-body text-text-muted">{{ __('catalog.ed.cat_empty') }}</p>
                            @else
                                <p class="m-0 px-3 py-2.5 text-body text-text-muted" x-show="@js(array_map(static fn (array $c): string => $c['path'].$c['label'], $categories)).every((t) => ! hit(t))" x-cloak
                                   x-text="{{ Js::from(__('catalog.ed.cat_nomatch')) }}.replace(':q', q.trim())"></p>
                            @endif
                        </div>
                        @error('categoryId') <p id="catalog-category-e" class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                        @error('categoryIds.*') <p class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                        <p class="{{ $hint }} mt-0">{{ __('catalog.ed.cat_hint') }}</p>
                        @if ($mayManageCategories)
                            <div>
                                <x-bs-admin::button variant="ghost" size="sm" :href="route('admin.taxonomies.index', ['section' => 'catalog'])">
                                    <x-bs-admin::icon name="ri-settings-3" :class="$glyph" /> {{ __('catalog.ed.cat_manage') }}
                                </x-bs-admin::button>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-sku" x-show="more" x-cloak>
                    <div class="{{ $cardHead }}"><h2 id="ped-h-sku" class="{{ $cardTitle }}">{{ __('catalog.ed.card_code_tags') }}</h2></div>
                    <div class="flex flex-col gap-3 p-4">
                        <div>
                            <label for="catalog-sku" class="{{ $label }} mb-1.5">{{ __('catalog.card_opt_sku') }}</label>
                            <input type="text" id="catalog-sku" wire:model.blur="sku" @readonly(! $canEdit) dir="ltr" autocomplete="off" spellcheck="false" placeholder="AV-DP-D10" maxlength="64"
                                   x-on:input="if ($event.isComposing) return; const a = $el.selectionStart, v = $el.value.toUpperCase().replace(/\s+/g, '-').slice(0, 64); if (v !== $el.value) { $el.value = v; $el.setSelectionRange(Math.min(a, v.length), Math.min(a, v.length)); }"
                                   x-on:compositionend="const a = $el.selectionStart, v = $el.value.toUpperCase().replace(/\s+/g, '-').slice(0, 64); if (v !== $el.value) { $el.value = v; $el.setSelectionRange(Math.min(a, v.length), Math.min(a, v.length)); }"
                                   aria-describedby="catalog-sku-h @error('sku') catalog-sku-e @enderror" @error('sku') aria-invalid="true" @enderror
                                   class="field block w-full rounded-md border border-border-input bg-surface-muted px-3 font-mono text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                            @error('sku') <x-bs-admin::field-error id="catalog-sku-e" class="mt-1" x-init="setMore(true)">{{ $message }}</x-bs-admin::field-error> @enderror
                            <p id="catalog-sku-h" class="{{ $hint }}">{{ __('catalog.ed.sku_hint') }}</p>
                        </div>
                        {{-- the labels are form fields, saved with the rest (FlagProduct, the list's write) --}}
                        @foreach (['featured' => ['catalog-flag-ft', 'catalog.ed.flag_featured', 'catalog.ed.flag_featured_on', $featured], 'bestseller' => ['catalog-flag-bs', 'catalog.ed.flag_best', 'catalog.ed.flag_best_on', $bestseller]] as $flag => [$flagId, $flagLabel, $flagOn, $flagIsOn])
                            <div class="flex min-h-11 items-center gap-3">
                                <span class="min-w-0 flex-1">
                                    <span id="{{ $flagId }}" class="block font-semibold text-text-strong">{{ __($flagLabel) }}</span>
                                    <span class="block text-small text-text-muted" x-text="$wire.{{ $flag }} ? @js(__($flagOn)) : @js(__('catalog.ed.flag_off'))">{{ $flagIsOn ? __($flagOn) : __('catalog.ed.flag_off') }}</span>
                                </span>
                                <button type="button" class="switch hit-area" role="switch" aria-labelledby="{{ $flagId }}" aria-checked="{{ $flagIsOn ? 'true' : 'false' }}"
                                        x-bind:aria-checked="$wire.{{ $flag }} ? 'true' : 'false'" @if ($canEdit) x-on:click="$wire.{{ $flag }} = ! $wire.{{ $flag }}" @else aria-disabled="true" @endif></button>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        </div>

    </x-bs-admin::editor-frame>

    {{-- «Hoàn tác» after a picture is taken off the product (the save bar along the bottom of a narrow editor: raised above it) --}}
    <x-bs-admin::toast :message="$toastMsg" :seq="$toastSeq" dismiss="closeToast" :undo="$undo !== null ? 'undoLast' : null" :raised="$canEdit" />

    <x-bs-admin::unsaved-dialog :count="$changes" :label="$frame['title']" save="save" discard="resetChanges" :body="$leave['body']" :save-label="$leave['save']" />

    {{-- Shared library picker (WithMediaLibraryPicker): gallery mode marks already-attached
         images and stays open for multi-pick; editor mode inserts at the caret and closes. --}}
    @include('admin::partials.media-picker-modal', ['pickedIds' => $imagePickerMode === 'gallery' ? $imageMediaIds : []])
    @include('admin::partials.video-embed-modal')
</div>
