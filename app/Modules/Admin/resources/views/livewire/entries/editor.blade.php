{{-- The post and page editor (design 07, one screen for both; `type` picks what differs). The frame
     every editor shares (components/editor-frame.blade.php: the Shell's header, the bar that stays
     under the admin's top bar, the ⋮, the bottom bar of a narrow editor, the phone's one bar), then
     the status band of live content, then two columns in the board's order. Main: Tiêu đề and Đường dẫn, Mô tả ngắn, the
     content card ("Soạn thảo" = the body as one formatted-text document; "Trình dựng" = a page's
     blocks, or any body laid out in blocks), the shared "Hiện thế nào trên Google" card. Side: Đăng (status, the approval steps where a
     workflow governs the post), Chuyên mục (posts), Ảnh đại diện. The save state at rest is drawn
     here; typing, saving and a lost connection are drawn over it in the browser (bsEditorSave,
     resources/js/admin-editor.js; a new entry or a draft saves itself 2 seconds after the last change). The block list is
     counted by the server (blocksChanged). Columns follow the editor's own width (flex basis
     520 / 300, as the board). --}}
@php
    use App\Modules\Admin\Livewire\Entries\Editor;
    use App\Modules\Admin\Support\SeoFields;
    use Illuminate\Support\Js;

    $isPage = $type === 'page';
    $bandIcons = ['empty' => 'ri-edit', 'short' => 'ri-arrow-up', 'near' => 'ri-arrow-right-up', 'good' => 'ri-check', 'long' => 'ri-error-warning', 'over' => 'ri-close-circle'];
    // The heading's meter is the shared part's (bsSeoFields), read from the `title` field with the design's title limits.
    $headingConfig = SeoFields::clientConfig(['heading' => 'title', 'title' => null, 'description' => null], $address);
    // «Mô tả ảnh» under the featured image: the same meter, read from `coverAlt` with the design's alt limits.
    $altConfig = SeoFields::clientConfig(['alt' => 'coverAlt', 'title' => null, 'description' => null], $address);
    $dirty = __('catalog.ed.sv_dirty');
    // Where this member's copy of unsaved text on this device lives (admin-kept.js): per member,
    // entry and language, never the numeric id.
    $keptKey = $ulid !== null ? 'bs-entry-kept:'.$userUlid.':'.$ulid.':'.$locale : null;
    $keptWhen = ['zone' => $siteZone, 'format' => $siteDateFormat, 'lang' => app()->getLocale()];
    // A new entry or a draft saves itself 2 seconds after the last change (Editor::autosave, board 07); content
    // on the website never does: its unsaved text is kept on this device until «Cập nhật».
    $saveConfig = ['fields' => Editor::TRACKED, 'delay' => 2000, 'autosaveNew' => true, 'needs' => ['title'], 'noAuto' => ['statusTo', 'scheduleAt'], 'extra' => 'blocksChanged', 'keep' => $keptKey !== null ? ['key' => $keptKey] : null, 'words' => [
        'dirty' => $dirty,
        'dirtyDraft' => __('entries.ed.sv_dirty_draft'),
        'dirtyLive' => __('entries.ed.sv_dirty_live'),
        'saving' => __('catalog.ed.sv_saving'),
        'autosaving' => __('entries.ed.sv_autosaving'),
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
                              :note="$note" :save-config="$saveConfig" :actions="$actions" target="save,publish,applyTransition" :save-tip="__($pill['status'] === 'draft' ? 'entries.ed.save_tip_auto' : 'entries.ed.save_tip')"
                              :preview="$frame['preview']" :trash="$frame['trash']" :name="$title !== '' ? $title : $frame['title']">
        @if ($liveUrl !== null)
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 rounded-md bg-[color-mix(in_srgb,var(--color-success-text)_9%,var(--color-surface))] px-3.5 py-2.5 text-body font-semibold text-success-text">
                <x-bs-admin::icon name="ri-checkbox-circle" :class="$glyph" />
                <span>{{ __('entries.ed.pub_band') }}</span>
                <a href="{{ '//'.$liveUrl }}" target="_blank" rel="noopener" class="hit-area underline underline-offset-2" aria-label="{{ __('entries.ed.pub_link_aria', ['url' => $liveUrl]) }}">{{ __('entries.ed.pub_link') }}</a>
                <span class="min-w-0 font-normal [overflow-wrap:anywhere] @max-[35rem]:hidden">{{ $liveUrl }}</span>
            </div>
        @endif
        @if ($scheduleBand !== null)
            {{-- A scheduled entry (board 07's band, the list's «Hủy hẹn giờ»): when it goes live, and the way back to a
                 draft, asked first (Editor::unschedule). The time itself is changed in the "Đăng" card. --}}
            <x-bs-admin::alert role="status" color="warning" icon="ri-time" data-sched-band>
                <div class="flex flex-wrap items-start gap-2.5">
                    <div class="flex min-w-0 flex-[1_1_16.25rem] flex-col gap-0.5 [overflow-wrap:anywhere]">
                        <b class="font-semibold text-text-strong">{{ $scheduleBand['head'] }}</b>
                        <span>{{ $scheduleBand['sub'] }}</span>
                    </div>
                    <div class="flex max-w-full flex-[0_1_auto] flex-wrap gap-2">
                        <x-bs-admin::button variant="outline" color="secondary" size="sm" data-unsched
                                            x-data x-on:click="$bsConfirm({ icon: 'warning', title: {{ Js::from($scheduleBand['ask']['title']) }}, text: {{ Js::from($scheduleBand['ask']['text']) }}, confirm: {{ Js::from($scheduleBand['ask']['confirm']) }}, cancel: {{ Js::from($scheduleBand['ask']['cancel']) }} }).then(ok => { ok && $wire.unschedule() })">
                            <x-bs-admin::icon name="ri-close-circle" :class="$glyph" /> {{ __('entries.unsched') }}
                        </x-bs-admin::button>
                    </div>
                </div>
            </x-bs-admin::alert>
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
                <section class="{{ $card }} px-5 py-4" aria-label="{{ $isPage ? __('entries.ed.title_page') : __('entries.ed.title_post') }}" x-data="bsSeoFields({{ Js::from($headingConfig) }})">
                    <div class="mb-1.5 flex flex-wrap items-center gap-2">
                        <label for="entry-title" class="{{ $label }}">
                            <span>{{ $isPage ? __('entries.ed.title_page') : __('entries.ed.title_post') }} <span class="text-danger-text" aria-hidden="true">*</span></span>
                            @if ($chip)
                                <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                            @endif
                        </label>
                    </div>
                    {{-- While the link follows the title (a version never saved, its link not typed: Editor::updatedTitle),
                         a pause in the typing sends the title and the server makes the link again. --}}
                    <input type="text" id="entry-title" wire:model="title" lang="{{ $locale }}" aria-required="true" maxlength="{{ SeoFields::hardCap('title') }}"
                           x-on:input.debounce.400ms="$wire.slugFresh && ! $wire.slugTouched && ! $wire.pageHome && $wire.$refresh()"
                           aria-describedby="entry-title-c @error('title') entry-title-e @enderror" @error('title') aria-invalid="true" @enderror class="field {{ $fieldLook }}">
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="seo-meter min-w-24 flex-1" :data-b="headingMeter.band" aria-hidden="true">
                            <span class="seo-meter__zone" :style="{ left: headingMeter.lo + '%', width: headingMeter.zw + '%' }"></span>
                            <span class="seo-meter__fill" :style="{ width: headingMeter.pct + '%' }"></span>
                            <span class="seo-meter__tick" :style="{ left: headingMeter.tick + '%' }"></span>
                        </span>
                        <span id="entry-title-c" class="seo-count" :data-b="headingMeter.band" data-b="empty">
                            @foreach ($bandIcons as $band => $icon)
                                <x-bs-admin::icon :name="$icon" x-show="headingMeter.band === '{{ $band }}'" x-cloak />
                            @endforeach
                            <span x-text="headingMeter.text"></span>
                        </span>
                    </div>
                    <p x-show="headingMeter.band === 'empty'" class="mt-1.5 flex items-center gap-1.5 text-caption text-text-muted">
                        <x-bs-admin::icon name="ri-information" class="size-4 shrink-0" />{{ __('entries.ed.title_hint') }}
                    </p>
                    @error('title') <p id="entry-title-e" class="{{ $error }}">{{ $message }}</p> @enderror

                    <div class="mt-3.5">
                        @if ($pageHome)
                            {{-- The Home page is the website's root: its link is shown, locked, never changed (Editor::updatedSlug) --}}
                            <span id="entry-slug-l" class="{{ $label }} mb-1.5">{{ __('entries.ed.slug') }}</span>
                            <p class="m-0 flex min-h-(--spacing-control) items-center gap-1.5 rounded-md border border-dashed border-border-input bg-surface-muted px-3 font-mono text-body text-text-muted">
                                <span dir="ltr">{{ $homePath }}</span><x-bs-admin::icon name="ri-lock" class="size-4 shrink-0" />
                            </p>
                            <p id="entry-slug-h" class="mt-1.5 mb-0 flex items-start gap-1.5 text-caption text-text-muted">
                                <x-bs-admin::icon name="ri-lock" class="mt-0.5 size-3.5 shrink-0" />{{ __('entries.ed.slug_home_hint') }}
                            </p>
                        @else
                            {{-- Left empty, the link is made from the title as the field is left (Editor::updatedSlug) --}}
                            <label for="entry-slug" class="{{ $label }} mb-1.5">{{ __('entries.ed.slug') }}</label>
                            <input type="text" id="entry-slug" wire:model.blur="slug" dir="ltr" autocomplete="off" spellcheck="false"
                                   aria-describedby="entry-slug-h @error('slug') entry-slug-e @enderror" @error('slug') aria-invalid="true" @enderror class="field {{ $fieldLook }}">
                            @error('slug') <p id="entry-slug-e" class="{{ $error }}">{{ $message }}</p> @enderror
                            <p id="entry-slug-h" class="mt-1.5 mb-0 flex items-start gap-1.5 text-caption text-text-muted">
                                <x-bs-admin::icon name="ri-magic" class="mt-0.5 size-3.5 shrink-0" />{{ __('entries.ed.slug_hint') }}
                            </p>
                        @endif
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
                    // «Xóa khối?» asks first and names the language whose layout loses it (design 07)
                    $askDelete = fn (string $text): string => '$bsConfirm({ icon: \'trash-danger\', title: '.\Illuminate\Support\Js::from(__('entries.ed.del_title'))
                        .', text: '.\Illuminate\Support\Js::from($text.' '.__('entries.ed.del_final'))
                        .', confirm: '.\Illuminate\Support\Js::from(__('entries.ed.block_remove'))
                        .', cancel: '.\Illuminate\Support\Js::from(__('admin.cancel')).' })';
                @endphp
                <section class="{{ $card }} overflow-hidden" aria-labelledby="entry-body-h" x-data="{ tab: 'text', openBlock: $wire.entangle('openBlock') }"
                         @unless ($isPage) x-on:bs-rich-editors-remount.document="tab = 'text'" @endunless>
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
                                        <button type="button" class="{{ $rowButton }} hover:border-danger-text hover:text-danger-text"
                                                x-on:click="{{ $askDelete(__('entries.ed.del_prose_text', ['lang' => $localeLabel])) }}.then(ok => ok && $wire.clearProse())"
                                                title="{{ __('entries.ed.block_remove') }}" aria-label="{{ __('entries.ed.block_remove') }}: {{ __('entries.ed.builder_prose_label') }}">
                                            <x-bs-admin::icon name="ri-delete-bin" class="size-4" />
                                        </button>
                                    </div>
                                @else
                                    <p class="m-0 rounded-md border border-border bg-surface-muted p-4 text-center text-small text-text-muted">{{ __('entries.ed.builder_none') }}</p>
                                @endif
                            @else
                                @include('admin::livewire.entries.partials.block-rows')
                            @endif
                            @include('admin::livewire.entries.partials.block-add')
                        </div>
                    @endif
                </section>

                <x-bs-admin::seo-fields excerpt="excerpt" part="card" :kind="$type" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="entry-seo" />
            </div>

            {{-- A page's «Thuộc tính trang» waits behind «Thêm tùy chọn» (design 07), open or closed per browser as in the
                 product editor (localStorage 'bs-editor'); a refused «Trang cha» opens it, and so does a page that has a
                 parent (an existing one, or preset by «Thêm trang con»), so the chosen parent shows. --}}
            <div class="flex min-w-0 flex-[1_1_300px] flex-col gap-4"
                 @if ($isPage) @if ($parentUlid !== null) data-more-open @endif x-data="{ more: false, init() { if ('moreOpen' in this.$el.dataset) { this.more = true; return } try { this.more = JSON.parse(localStorage.getItem('bs-editor') || '{}').more === true } catch (e) {} }, setMore(on) { this.more = on; try { const o = JSON.parse(localStorage.getItem('bs-editor') || '{}') || {}; o.more = on; localStorage.setItem('bs-editor', JSON.stringify(o)) } catch (e) {} } }" @endif>
                <section class="{{ $card }}" aria-labelledby="entry-pub-h">
                    <div class="flex items-center justify-between gap-2.5 border-b border-border px-4 py-3">
                        <h2 id="entry-pub-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_publish') }}</h2>
                        <x-bs-admin::status-pill :status="$pill['status']">{{ $pill['label'] }}</x-bs-admin::status-pill>
                    </div>
                    @if ($statusPanel !== null || $schedule !== null || $workflow !== null || $revisions !== null)
                        <div class="flex flex-col gap-3.5 p-4">
                            @if ($statusPanel !== null)
                                @include('admin::livewire.entries.partials.status-choice', ['panel' => $statusPanel])
                            @endif
                            @if ($schedule !== null)
                                {{-- «Hẹn giờ đăng» (design 07): when a scheduled entry (or one set to be) goes live, in the website's clock;
                                     a new pick starts on tomorrow 08:00. Judged as the field is left and again by the save, which stops at a
                                     missing or past time and puts the cursor here (Editor::updatedScheduleAt, refuseSchedule). --}}
                                <div>
                                    <label for="entry-sched-at" class="{{ $label }} mb-1.5">
                                        {{ __('entries.sched_title') }} <span class="font-normal text-text-muted">· {{ $schedule['zone'] }}</span>
                                    </label>
                                    <input type="datetime-local" id="entry-sched-at" wire:model.blur="scheduleAt" aria-required="true"
                                           aria-describedby="entry-sched-n @error('scheduleAt') entry-sched-e @enderror" @error('scheduleAt') aria-invalid="true" @enderror
                                           class="field {{ $fieldLook }}">
                                    @error('scheduleAt')
                                        <x-bs-admin::field-error id="entry-sched-e" class="mt-1.5">{{ $message }}</x-bs-admin::field-error>
                                    @enderror
                                    <p id="entry-sched-n" class="mt-1.5 mb-0 flex items-start gap-1.5 text-caption text-warning-text">
                                        <x-bs-admin::icon name="ri-time" class="mt-0.5 size-3.5 shrink-0" />{{ $schedule['note'] }}
                                    </p>
                                </div>
                            @endif
                            @if ($workflow !== null)
                                {{-- An active workflow governs this post: its steps and moves replace "Đăng". --}}
                                <div>@include('admin::livewire.entries.partials.workflow-status')</div>
                            @endif
                            @if ($revisions !== null)
                                @if ($statusPanel !== null || $schedule !== null || $workflow !== null)
                                    <div class="h-px bg-border" aria-hidden="true"></div>
                                @endif
                                @include('admin::livewire.entries.partials.revisions')
                            @endif
                        </div>
                    @endif
                </section>

                @if ($categoryCard !== null)
                    {{-- «Chuyên mục» (board 07): a box per category — the post's section first, then the other sections' with
                         their name beside them — in menu order and indented; the search ignores diacritics and case and shows
                         the names above each result. Two or more ticked: «Chuyên mục chính» picks the one the address and the
                         «Chuyên mục» line of the post follow. A row outside the member's limits, or of another section while an
                         approval workflow governs this one, is locked with the reason under it. «Thêm chuyên mục mới» (who may
                         manage categories) makes one at once and ticks it: inline on a wide screen, a sheet from the bottom on a
                         phone. Boxes and choices are requests by key (Editor::toggleCategory, setMainCategory, addCategory);
                         nothing is saved until the post is. --}}
                    <section class="{{ $card }}" aria-labelledby="entry-cat-h"
                             x-data="{ q: '', below: 0, adding: false, name: '', ask: false,
                                       fold(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').trim(); },
                                       hit(t) { const w = this.fold(this.q); return w === '' || this.fold(t).includes(w); },
                                       get searching() { return this.fold(this.q) !== ''; },
                                       measure() { const el = this.$refs.list; if (! el) { return; } const end = el.getBoundingClientRect().bottom; let n = 0;
                                           el.querySelectorAll('.ped-cr').forEach((r) => { const b = r.getBoundingClientRect(); if (b.height >= 1 && b.bottom > end + 1) { n++; } }); this.below = n; },
                                       phone() { return window.matchMedia('(max-width: 599.98px), (max-height: 500px)').matches; },
                                       open() { this.adding = true; this.ask = false; this.$nextTick(() => (this.phone() ? this.$refs.casX : this.$refs.casIn)?.focus()); },
                                       close() { if (this.name.trim() !== '') { this.ask = true; this.$nextTick(() => this.$refs.casStay?.focus()); return; } this.end(); },
                                       end() { this.adding = false; this.name = ''; this.ask = false; this.$nextTick(() => this.$refs.casOpen?.focus()); },
                                       async add() { if (this.name.trim() === '') { this.$refs.casIn?.focus(); return; } if (await $wire.addCategory(this.name)) { this.end(); } else { this.$nextTick(() => this.$refs.casIn?.focus()); } } }"
                             x-init="$nextTick(() => measure()); new MutationObserver(() => measure()).observe($refs.list, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] })"
                             x-effect="q; $nextTick(() => measure())" x-on:resize.window.debounce.150ms="measure()">
                        <div class="{{ $cardHead }}"><h2 id="entry-cat-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_category') }}</h2></div>
                        <div class="flex flex-col gap-2.5 p-4">
                            @if ($categoryCard['scopeNote'] !== null)
                                <p class="m-0 flex items-start gap-1.5 text-caption text-text-muted [overflow-wrap:anywhere]">
                                    <x-bs-admin::icon name="ri-focus-3" class="mt-0.5 size-3.5 shrink-0" /><span>{{ $categoryCard['scopeNote'] }}</span>
                                </p>
                            @endif
                            <x-bs-admin::search :label="__('entries.ed.cat_search')" x-model="q" />
                            <div class="relative">
                                <div id="entry-category" x-ref="list" x-on:scroll.passive="measure()" tabindex="-1"
                                     class="ped-cl entry-cat-list overflow-y-auto overflow-x-hidden rounded border border-border [scrollbar-gutter:stable]"
                                     role="group" aria-label="{{ __('entries.ed.cat_group') }}" @if ($errors->has('categoryId') || $errors->has('categoryIds') || $errors->has('categoryIds.*')) aria-invalid="true" aria-describedby="{{ trim(($errors->has('categoryId') ? 'entry-category-e ' : '').($errors->has('categoryIds') ? 'entry-categories-e ' : '').($errors->has('categoryIds.*') ? 'entry-categories-ie' : '')) }}" @endif>
                                    @foreach ($categoryCard['rows'] as $i => $category)
                                        <div wire:key="ecat-{{ $category['ulid'] }}" class="ped-cr" data-d="{{ $category['depth'] }}" x-bind:data-d="searching ? 0 : {{ $category['depth'] }}"
                                             data-lock="{{ $category['locked'] ? '1' : '0' }}" x-show="hit(@js($category['path'].$category['label']))">
                                            <label for="entry-cat-{{ $i }}">
                                                <input id="entry-cat-{{ $i }}" type="checkbox" @checked($category['on']) @disabled($category['locked'])
                                                       @if ($category['locked']) aria-describedby="entry-cat-why-{{ $i }}" @endif
                                                       wire:click="toggleCategory('{{ $category['ulid'] }}')" wire:loading.attr="disabled" wire:target="toggleCategory,setMainCategory,addCategory">
                                                @if ($category['locked'])
                                                    <x-bs-admin::icon name="ri-lock" class="size-3.5 shrink-0 text-text-muted" />
                                                @endif
                                                <span title="{{ $category['path'].$category['label'] }}"><span class="text-text-muted" x-show="searching" x-cloak>{{ $category['path'] }}</span>{{ $category['label'] }}</span>
                                            </label>
                                            @if ($category['section'] !== null)
                                                <x-bs-admin::badge color="secondary" soft pill class="relative z-[1]">{{ $category['section'] }}</x-bs-admin::badge>
                                            @endif
                                            @if ($category['locked'])
                                                <span class="entry-cat-why" id="entry-cat-why-{{ $i }}">{{ $category['why'] }}</span>
                                            @elseif ($category['moves'])
                                                <span class="entry-cat-why">{{ __('entries.ed.cat_moves', ['section' => $category['section']]) }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if ($categoryCard['rows'] === [])
                                        <p class="m-0 px-3 py-2.5 text-body text-text-muted">{{ __('entries.ed.cat_empty') }}</p>
                                    @else
                                        <p class="m-0 px-3 py-2.5 text-body text-text-muted" x-show="@js(array_map(static fn (array $c): string => $c['path'].$c['label'], $categoryCard['rows'])).every((t) => ! hit(t))" x-cloak
                                           x-text="{{ Js::from(__('entries.ed.cat_nomatch')) }}.replace(':q', q.trim())"></p>
                                    @endif
                                </div>
                                <div class="entry-cat-fade" aria-hidden="true" x-show="below > 0" x-cloak></div>
                            </div>
                            <p class="m-0 flex items-start gap-1.5 text-caption text-text-muted" x-show="below > 0" x-cloak>
                                <x-bs-admin::icon name="ri-arrow-down" class="mt-0.5 size-3.5 shrink-0" />
                                <span class="min-w-0 [overflow-wrap:anywhere]" x-text="{{ Js::from(__('entries.ed.cat_more')) }}.replace(':n', below)"></span>
                            </p>
                            @error('categoryId') <p id="entry-category-e" class="{{ $error }} mt-0">{{ $message }}</p> @enderror
                            @error('categoryIds') <p id="entry-categories-e" class="{{ $error }} mt-0">{{ $message }}</p> @enderror
                            @error('categoryIds.*') <p id="entry-categories-ie" class="{{ $error }} mt-0">{{ $message }}</p> @enderror
                            @if ($categoryCard['primary'] !== [])
                                <div class="flex flex-col gap-1.5">
                                    <label for="entry-cat-main" class="{{ $label }}">{{ __('entries.ed.cat_main') }}</label>
                                    <select id="entry-cat-main" aria-describedby="entry-cat-main-h" wire:change="setMainCategory($event.target.value)" wire:loading.attr="disabled" wire:target="toggleCategory,setMainCategory,addCategory"
                                            class="field block w-full rounded-md border border-border-input bg-surface px-3 text-text transition-colors focus:border-primary-text">
                                        @foreach ($categoryCard['primary'] as $option)
                                            <option value="{{ $option['ulid'] }}" @selected($option['main']) @disabled($option['locked'])>{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <p id="entry-cat-main-h" class="m-0 text-caption text-text-muted [overflow-wrap:anywhere]">{{ __('entries.ed.cat_main_hint') }}</p>
                                </div>
                            @endif
                            @if ($categoryCard['add'] !== null)
                                <div class="flex flex-col gap-1.5" x-show="! adding">
                                    @if ($categoryCard['add']['lock'] !== null)
                                        <button type="button" class="ped-star self-start" x-ref="casOpen" aria-disabled="true" aria-describedby="entry-cat-add-why"
                                                x-on:click="$dispatch('bs-toast', { text: {{ Js::from($categoryCard['add']['lock']) }} })">
                                            <x-bs-admin::icon name="ri-lock" class="size-3.5" />{{ __('entries.ed.cat_add') }}
                                        </button>
                                        <span class="entry-cat-why ps-0" id="entry-cat-add-why">{{ $categoryCard['add']['lock'] }}</span>
                                    @else
                                        <button type="button" class="ped-star self-start" x-ref="casOpen" x-on:click="open()">
                                            <x-bs-admin::icon name="ri-add" class="size-3.5" />{{ __('entries.ed.cat_add') }}
                                        </button>
                                        @if ($categoryCard['add']['in'] !== null)
                                            <span class="entry-cat-why ps-0">{{ __('entries.ed.cat_add_in', ['name' => $categoryCard['add']['in']]) }}</span>
                                        @endif
                                    @endif
                                </div>
                                @if ($categoryCard['add']['lock'] === null)
                                    <div class="entry-cas-w" x-show="adding" x-cloak x-on:click.self="phone() && close()">
                                        <div class="entry-cas" role="group" aria-labelledby="entry-cas-t" x-trap.inert.noscroll="adding && phone()"
                                             x-on:keydown.escape.stop.prevent="ask ? (ask = false) : (phone() ? close() : end())">
                                            <div class="entry-cas-h">
                                                <h2 id="entry-cas-t">{{ __('entries.ed.cat_add') }}</h2>
                                                <button type="button" class="entry-cas-x" x-ref="casX" aria-label="{{ __('admin.close') }}" title="{{ __('admin.close') }}" x-on:click="close()">
                                                    <x-bs-admin::icon name="ri-close" class="size-5" />
                                                </button>
                                            </div>
                                            @if ($categoryCard['add']['in'] !== null)
                                                <span class="entry-cat-why ps-0">{{ __('entries.ed.cat_add_in', ['name' => $categoryCard['add']['in']]) }}</span>
                                            @endif
                                            <div class="entry-cas-row">
                                                <input type="text" x-ref="casIn" x-model="name" x-on:keydown.enter.prevent="add()" maxlength="{{ \App\Modules\Cms\Taxonomy\TaxonomyRules::NAME_MAX }}"
                                                       aria-label="{{ __('entries.ed.cat_new_label') }}" placeholder="{{ __('entries.ed.cat_new_ph') }}" autocomplete="off"
                                                       @error('catNew') aria-invalid="true" aria-describedby="entry-cas-e" @enderror
                                                       class="field min-w-0 flex-[1_1_10rem] rounded-md border border-border-input bg-surface px-3 text-text placeholder:text-placeholder focus:border-primary-text aria-invalid:border-danger-text">
                                                <x-bs-admin::button size="sm" x-on:click="add()" x-bind:aria-disabled="name.trim() === '' ? 'true' : 'false'" wire:loading.attr="disabled" wire:target="addCategory">
                                                    <x-bs-admin::icon name="ri-check" :class="$glyph" /> {{ __('entries.ed.cat_add_do') }}
                                                </x-bs-admin::button>
                                                <x-bs-admin::button variant="outline" color="secondary" size="sm" x-on:click="end()">{{ __('entries.ed.cat_add_cancel') }}</x-bs-admin::button>
                                            </div>
                                            @error('catNew') <p id="entry-cas-e" class="{{ $error }} mt-0">{{ $message }}</p> @enderror
                                            <div class="entry-cas-ask" role="group" aria-labelledby="entry-cas-qt" aria-describedby="entry-cas-qd" x-show="ask" x-cloak>
                                                <p id="entry-cas-qt" class="font-semibold text-text-strong">{{ __('entries.ed.cat_drop_title') }}</p>
                                                <p id="entry-cas-qd" x-text="{{ Js::from(__('entries.ed.cat_drop_text')) }}.replace(':name', name.trim())"></p>
                                                <div class="entry-cas-qb">
                                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-ref="casStay" x-on:click="ask = false; $nextTick(() => $refs.casX?.focus())">{{ __('entries.ed.cat_drop_stay') }}</x-bs-admin::button>
                                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" x-on:click="end()">{{ __('entries.ed.cat_drop_do') }}</x-bs-admin::button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endif
                            <p class="m-0 text-caption text-text-muted [overflow-wrap:anywhere]">{{ $categoryCard['note'] }}</p>
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
                            {{-- «Mô tả ảnh»: this language's words for the picture on the website (Editor::$coverAlt). --}}
                            <div class="entry-alt" x-data="bsSeoFields({{ Js::from($altConfig) }})">
                                <div class="relative mb-1.5 flex items-center gap-1" x-data="{ q: false }" x-on:keydown.escape="q = false" x-on:click.outside="q = false">
                                    <label for="entry-cover-alt" class="{{ $label }}">
                                        <span>{{ __('entries.ed.cover_alt_label') }}</span>
                                        @if ($chip)
                                            <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                                        @endif
                                    </label>
                                    <button type="button" class="entry-alt__q" x-on:click="q = ! q" x-bind:aria-expanded="q ? 'true' : 'false'" aria-expanded="false" aria-controls="entry-cover-alt-q"
                                            title="{{ __('entries.ed.cover_alt_q_title') }}" aria-label="{{ __('entries.ed.cover_alt_q') }}">
                                        <x-bs-admin::icon name="ri-question" class="size-[min(24px,calc(1rem+var(--user-text-boost)))]" />
                                    </button>
                                    <p id="entry-cover-alt-q" role="note" class="entry-alt__pop" x-show="q" x-cloak>{{ __('entries.ed.cover_alt_help', ['unit' => mb_strtolower($unit)]) }}</p>
                                </div>
                                <input type="text" id="entry-cover-alt" wire:model="coverAlt" lang="{{ $locale }}" maxlength="{{ SeoFields::hardCap('alt') }}" placeholder="{{ __('entries.ed.cover_alt_ph') }}"
                                       aria-describedby="entry-cover-alt-c entry-cover-alt-h @error('coverAlt') entry-cover-alt-e @enderror" @error('coverAlt') aria-invalid="true" @enderror class="field {{ $fieldLook }}">
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span class="seo-meter min-w-24 flex-1" :data-b="altMeter.band" aria-hidden="true">
                                        <span class="seo-meter__zone" :style="{ left: altMeter.lo + '%', width: altMeter.zw + '%' }"></span>
                                        <span class="seo-meter__fill" :style="{ width: altMeter.pct + '%' }"></span>
                                        <span class="seo-meter__tick" :style="{ left: altMeter.tick + '%' }"></span>
                                    </span>
                                    <span id="entry-cover-alt-c" class="seo-count" :data-b="altMeter.band" data-b="empty">
                                        @foreach ($bandIcons as $band => $icon)
                                            <x-bs-admin::icon :name="$icon" x-show="altMeter.band === '{{ $band }}'" x-cloak />
                                        @endforeach
                                        <span x-text="altMeter.text"></span>
                                    </span>
                                </div>
                                <p id="entry-cover-alt-h" class="mt-1.5 mb-0 text-caption text-text-muted">{{ __('entries.ed.cover_alt_hint') }}</p>
                                @error('coverAlt') <p id="entry-cover-alt-e" class="{{ $error }}">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </section>
                @endcan

                @if ($isPage && ! $pageHome)
                    <button type="button" x-on:click="setMore(! more)" x-bind:aria-expanded="more ? 'true' : 'false'" aria-expanded="false" aria-controls="entry-props"
                            aria-label="{{ __('catalog.ed.more') }} — {{ __('entries.ed.more_sub_page') }}"
                            x-bind:aria-label="more ? @js(__('catalog.ed.less').' — '.__('catalog.ed.less_sub')) : @js(__('catalog.ed.more').' — '.__('entries.ed.more_sub_page'))"
                            class="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2.5 gap-y-1 rounded-md border border-dashed border-border-input px-4 py-2.5 text-left text-body font-semibold text-primary-text transition-colors min-h-12 hover:bg-[color-mix(in_srgb,var(--color-primary-text)_6%,var(--color-surface))]">
                        <x-bs-admin::icon name="ri-add" :class="$glyph" x-show="! more" />
                        <x-bs-admin::icon name="ri-subtract" :class="$glyph" x-show="more" x-cloak />
                        <span x-text="more ? @js(__('catalog.ed.less')) : @js(__('catalog.ed.more'))">{{ __('catalog.ed.more') }}</span>
                        <span class="col-start-2 text-small font-normal text-text-muted" aria-hidden="true">
                            <span class="ped-ms-l" x-text="more ? @js(__('catalog.ed.less_sub')) : @js(__('entries.ed.more_sub_page'))">{{ __('entries.ed.more_sub_page') }}</span>
                            {{-- the short line of the phone's child screen --}}
                            <span class="ped-ms-s" x-text="more ? @js(__('catalog.ed.less_sub_s')) : @js(__('entries.ed.more_sub_page'))">{{ __('entries.ed.more_sub_page') }}</span>
                        </span>
                    </button>
                    @error('parentUlid') <span hidden x-init="more = true"></span> @enderror
                    {{-- «Thuộc tính trang» (design 07): where the page sits in the website's page tree, shared by every language --}}
                    <section id="entry-props" class="{{ $card }}" aria-labelledby="entry-props-h" data-page-props x-show="more" x-cloak>
                        <div class="{{ $cardHead }}">
                            <h2 id="entry-props-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_page_props') }}</h2>
                            @if ($sharedLock)
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-small font-semibold text-text-muted" title="{{ __('entries.ed.shared_tip') }}">
                                    <x-bs-admin::icon name="ri-lock" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))]" />{{ __('entries.ed.shared_chip') }}
                                </span>
                            @endif
                        </div>
                        <div class="p-4">
                            @include('admin::livewire.entries.partials.parent-picker')
                        </div>
                    </section>
                @endif
            </div>
        </div>

    </x-bs-admin::editor-frame>

    @if ($revisions !== null)
        @include('admin::livewire.entries.partials.revision-dialogs')
    @endif

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

        @include('admin::livewire.entries.partials.block-pickers')
        {{-- The body's «Chèn ảnh» (WithMediaLibraryPicker): library pictures only; a picture taken
             from a link is always saved into the library, so the body never points elsewhere. --}}
        @include('admin::partials.media-picker-modal', ['pickedIds' => [], 'editorLibraryOnly' => true])
    @endcan
    @include('admin::partials.video-embed-modal')
</div>
