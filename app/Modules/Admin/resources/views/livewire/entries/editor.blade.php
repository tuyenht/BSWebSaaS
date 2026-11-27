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
    $saveConfig = ['fields' => Editor::TRACKED, 'delay' => 2000, 'autosaveNew' => true, 'needs' => ['title'], 'noAuto' => ['statusTo', 'scheduleAt', 'expiresAt'], 'extra' => 'blocksChanged', 'keep' => $keptKey !== null ? ['key' => $keptKey] : null, 'words' => [
        'dirty' => $dirty,
        'dirtyDraft' => __('entries.ed.sv_dirty_draft'),
        'dirtyLive' => __('entries.ed.sv_dirty_live'),
        'dirtyShort' => __('catalog.ed.sv_dirty_short'),
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
                              :note="$note" :save-config="$saveConfig" :actions="$actions" target="save,publish,applyTransition,submitChange,askApplyChange" :save-tip="__($pill['status'] === 'draft' ? 'entries.ed.save_tip_auto' : 'entries.ed.save_tip')"
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
        @if ($changeBand !== null)
            {{-- Live content whose edits go into its pending change (board 07): where the change stands, that visitors
                 keep the live version, and the moves this member may make. A move that is not theirs to make is drawn
                 locked with its reason (aria-disabled, said in a toast when pressed). --}}
            <x-bs-admin::alert role="status" :color="$changeBand['tone']" :icon="$changeBand['icon']" data-change-band>
                <div class="flex flex-wrap items-start gap-2.5">
                    <div class="flex min-w-0 flex-[1_1_16.25rem] flex-col gap-0.5 [overflow-wrap:anywhere]">
                        <b class="font-semibold text-text-strong">{{ $changeBand['head'] }}</b>
                        <span>{{ $changeBand['sub'] }}</span>
                    </div>
                    @if ($changeBand['acts'] !== [])
                        <div class="flex max-w-full flex-[0_1_auto] flex-wrap gap-2">
                            @foreach ($changeBand['acts'] as $act)
                                @php
                                    $call = match ($act['do']) { 'diff' => 'openChangeDiff', 'apply' => 'askApplyChange', 'discard' => 'askDiscardChange', 'return' => 'openChangeReturn', default => 'moveChange' };
                                @endphp
                                @if ($act['why'] !== null)
                                    <x-bs-admin::button variant="outline" color="secondary" size="sm" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" aria-disabled="true" title="{{ $act['why'] }}"
                                                        data-change-act="{{ $act['do'] }}" x-data x-on:click="$bsToast({{ Js::from(['text' => $act['why'], 'stay' => true, 'close' => __('admin.close')]) }})">
                                        <x-bs-admin::icon :name="$act['icon']" :class="$glyph" /> {{ $act['label'] }}
                                    </x-bs-admin::button>
                                @else
                                    <x-bs-admin::button :variant="$act['soft'] ? 'soft' : 'outline'" :color="$act['soft'] ? 'primary' : 'secondary'" size="sm" data-change-act="{{ $act['do'] }}"
                                                        wire:click="{{ $call }}{{ $act['key'] !== null ? '('.Js::from($act['key']).')' : '' }}" wire:loading.attr="disabled" wire:target="{{ $call }}">
                                        <x-bs-admin::icon :name="$act['icon']" :class="$glyph" /> {{ $act['label'] }}
                                    </x-bs-admin::button>
                                @endif
                            @endforeach
                        </div>
                    @endif
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
        @if (count($langBar) > 1)
            {{-- The language bar (board 07): the version open and the state of every other; another version opens
                 in this editor with its own link, through the unsaved-changes question, its title field focused. --}}
            <x-bs-admin::lang-bar :langs="$langBar" :manage="$langManage" focus="entry-title" />
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
                            <input type="text" id="entry-slug" wire:model.live.blur="slug" dir="ltr" autocomplete="off" spellcheck="false"
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
                                @if ($isPage && $ulid === null && $proseBlank && ! $startDone && ! $sharedLock)
                                    {{-- «Bắt đầu từ mẫu» (design 07): a new page's empty body, in the website's default language --}}
                                    <div class="mb-3.5 flex flex-col gap-2.5 rounded-md border border-dashed border-border-input px-4 py-3.5" data-page-start>
                                        <div class="flex flex-col gap-0.5">
                                            <span class="text-body font-semibold text-text-strong">{{ __('entries.ed.start_title') }}</span>
                                            <span class="text-caption text-text-muted">{{ __('entries.ed.start_hint') }}</span>
                                        </div>
                                        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(200px,100%),1fr))] gap-2.5" role="group" aria-label="{{ __('entries.ed.start_group') }}">
                                            @foreach (\App\Modules\Admin\Support\Entries\PageStartTemplates::options() as $start)
                                                <button type="button" wire:click="pickStartTemplate(@js($start['key']))" wire:loading.attr="aria-busy" wire:target="pickStartTemplate"
                                                        class="flex min-h-16 min-w-0 items-start gap-2.5 rounded-md border border-border-strong bg-surface p-3 text-left transition-colors hover:border-primary-text hover:bg-[color-mix(in_srgb,var(--color-primary-text)_5%,var(--color-surface))]">
                                                    <x-bs-admin::icon :name="$start['icon']" class="size-5 shrink-0 text-primary-text" />
                                                    <span class="min-w-0">
                                                        <b class="block text-body text-text-strong">{{ $start['label'] }}</b>
                                                        <small class="block text-small leading-[1.45] text-text-muted">{{ $start['desc'] }}</small>
                                                    </span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
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

                <x-bs-admin::seo-fields excerpt="excerpt" part="card" :kind="$type" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="entry-seo"
                                       keyword-model="focusKeyword" :keyword-note="__('entries.ed.keyword_note')" />
            </div>

            {{-- A page's «Thuộc tính trang» and a post's «Thuộc tính bài» wait behind «Thêm tùy chọn» (design 07), open or
                 closed per browser as in the product editor (localStorage 'bs-editor'); a refused value opens it, and so does
                 a value already set (a page's parent, preset by «Thêm trang con» too; a post's own look or shown day, its job
                 details), so it shows. --}}
            <div class="flex min-w-0 flex-[1_1_300px] flex-col gap-4"
                 @if ($isPage || $type === 'post') @if (($isPage && $parentUlid !== null) || (! $isPage && ($templateKey !== '' || $tags !== [] || ($jobCard['set'] ?? false)))) data-more-open @endif x-data="{ more: false, init() { if ('moreOpen' in this.$el.dataset) { this.more = true; return } try { this.more = JSON.parse(localStorage.getItem('bs-editor') || '{}').more === true } catch (e) {} }, setMore(on) { this.more = on; try { const o = JSON.parse(localStorage.getItem('bs-editor') || '{}') || {}; o.more = on; localStorage.setItem('bs-editor', JSON.stringify(o)) } catch (e) {} } }" @endif>
                <section class="{{ $card }}" aria-labelledby="entry-pub-h">
                    <div class="flex items-center justify-between gap-2.5 border-b border-border px-4 py-3">
                        <h2 id="entry-pub-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_publish') }}</h2>
                        <x-bs-admin::status-pill :status="$pill['status']">{{ $pill['label'] }}</x-bs-admin::status-pill>
                    </div>
                    @if ($statusPanel !== null || $schedule !== null || $workflow !== null || $expiry !== null || $pdLine !== null || $revisions !== null)
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
                            @include('admin::livewire.entries.partials.publish-dates')
                            @if ($revisions !== null)
                                @if ($statusPanel !== null || $schedule !== null || $workflow !== null || $expiry !== null || $pdLine !== null)
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

                {{-- «Ảnh đại diện» (design 07 :987-1030): the card is always drawn; a member without media.view sees
                     the picture (or «Chưa có ảnh») and no buttons. The cover is every language's: another
                     language shows the «Chung» chip and its buttons say where to change it (Editor::coverRefused). --}}
                @php($mayPick = auth()->user()?->can('media.view') ?? false)
                <section class="{{ $card }} flex flex-col gap-3 px-4 py-4" aria-labelledby="entry-cover-h" data-picker-home>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="entry-cover-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_cover') }}</h2>
                        @if ($sharedLock)
                            <x-bs-admin::shared-chip />
                        @endif
                    </div>
                    <div class="relative aspect-video w-full overflow-hidden rounded-md border bg-surface-muted {{ $cover ? 'border-border' : 'border-dashed border-border-input' }}">
                        @if ($cover)
                            <img src="{{ $cover['url'] }}" alt="{{ $cover['name'] !== '' ? __('entries.ed.cover_img', ['name' => $cover['name']]) : __('entries.cover_alt') }}" class="entry-cover__preview size-full object-cover">
                            @if ($mayPick)
                                <x-bs-admin::cover-remove wire:click="clearCover" :title="__('entries.ed.cover_remove_title', ['unit' => $unit])">{{ __('entries.cover_remove') }}</x-bs-admin::cover-remove>
                            @endif
                        @elseif ($mayPick)
                            {{-- A member who may add files can also drop pictures here (Editor::dropCover): the browser
                                 keeps the files for the picker's device upload, which opens with them. Not in another
                                 language (the cover is changed in the source), and the kept files are let go when the
                                 server says no, so no later device upload picks them up. --}}
                            @php($mayDrop = ! $sharedLock && (auth()->user()?->can('media.upload') ?? false))
                            <button type="button" data-cover-pick wire:click="openCoverPicker" class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 px-3 text-center text-[calc(0.8125rem+var(--user-text-boost))] leading-normal text-text transition-colors hover:bg-surface-hover"
                                    @if ($mayDrop) x-data x-on:dragover.prevent x-on:drop.prevent="if (window.bsStagePickerFiles($event.dataTransfer?.files, 'image/')) { $wire.dropCover().then((ok) => ok || (window.__pendingPickerFiles = null), () => (window.__pendingPickerFiles = null)); }" @endif>
                                <x-bs-admin::icon name="ri-image-add" class="size-6 text-primary-text" />
                                <span><span class="font-semibold text-primary-text">{{ __('entries.ed.cover_choose') }}</span>@if ($mayDrop)<br>{{ __('entries.ed.cover_drop') }}@endif</span>
                            </button>
                        @else
                            <span class="flex size-full flex-col items-center justify-center gap-1.5 px-3 text-center text-[calc(0.8125rem+var(--user-text-boost))] leading-normal text-text-muted">
                                <x-bs-admin::icon name="ri-image" class="size-6" />{{ __('entries.ed.cover_none') }}
                            </span>
                        @endif
                    </div>
                    @if ($cover)
                        @if ($mayPick)
                            <x-bs-admin::button variant="outline" color="neutral" size="sm" class="w-full" wire:click="openCoverPicker" data-picker-focus>
                                <x-bs-admin::icon name="ri-image-add" :class="$glyph" /> {{ __('entries.ed.cover_change') }}
                            </x-bs-admin::button>
                        @endif
                        {{-- one line on the picture's shape, then where the website shows it (folded by default) --}}
                        <div class="flex flex-col gap-1" x-data="{ where: false }">
                            @if ($cover['ratio'] === 'ok')
                                <p class="m-0 flex items-start gap-1.5 text-[calc(0.8125rem+var(--user-text-boost))] font-semibold leading-normal text-success-text">
                                    <x-bs-admin::icon name="ri-checkbox-circle" :class="'mt-0.5 '.$glyph" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ __('entries.ed.cover_ratio_ok') }}</span>
                                </p>
                            @elseif ($cover['ratio'] === 'warn')
                                <p class="m-0 flex items-start gap-1.5 text-[calc(0.8125rem+var(--user-text-boost))] leading-normal text-warning-text">
                                    <x-bs-admin::icon name="ri-error-warning" :class="'mt-0.5 '.$glyph" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ __('entries.ed.cover_ratio_warn') }}</span>
                                </p>
                            @endif
                            <button type="button" x-on:click="where = ! where" x-bind:aria-expanded="where ? 'true' : 'false'" aria-expanded="false" aria-controls="entry-cover-where"
                                    class="flex min-h-hit w-full items-center gap-1.5 text-start text-[calc(0.875rem+var(--user-text-boost))] font-medium text-primary-text">
                                <x-bs-admin::icon name="ri-image" :class="$glyph" />
                                <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">{{ __('entries.ed.cover_where') }}</span>
                                <x-bs-admin::icon name="ri-arrow-down-s" class="size-[min(24px,calc(1.125rem+var(--user-text-boost)))] shrink-0" x-show="! where" />
                                <x-bs-admin::icon name="ri-arrow-up-s" class="size-[min(24px,calc(1.125rem+var(--user-text-boost)))] shrink-0" x-show="where" x-cloak />
                            </button>
                            <div id="entry-cover-where" class="flex flex-col gap-2" x-show="where" x-cloak>
                                <p class="m-0 text-[calc(0.8125rem+var(--user-text-boost))] leading-normal text-text [overflow-wrap:anywhere]">{{ __('entries.ed.cover_where_auto') }}</p>
                                <div class="flex flex-nowrap items-start gap-2">
                                    {{-- each crop 48px high at its own shape (16:9, square, 1.91:1 share card); a width given
                                         inline, as the three are only drawn here --}}
                                    @foreach (['list' => 'calc(48px * 16 / 9)', 'square' => '48px', 'share' => 'calc(48px * 1.91)'] as $place => $width)
                                        <div class="flex flex-none flex-col items-start gap-1" style="width: {{ $width }}">
                                            <img src="{{ $cover['url'] }}" alt="" class="h-12 w-full rounded object-cover" loading="lazy">
                                            <span class="text-[calc(0.75rem+var(--user-text-boost))] leading-snug text-text [overflow-wrap:anywhere]">{{ __('entries.ed.cover_where_'.$place) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                <p class="m-0 text-[calc(0.75rem+var(--user-text-boost))] leading-normal text-text-muted [overflow-wrap:anywhere]">{{ __('entries.ed.cover_where_social') }}</p>
                            </div>
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

                @if ($isPage)
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
                    @if ($errors->has('parentUlid') || $errors->has('pageOrder')) <span hidden x-init="more = true"></span> @endif
                    {{-- «Thuộc tính trang» (design 07): where the page sits in the website's page tree, shared by every language:
                         «Trang cha» (never on the Home page) and «Thứ tự» among the pages beside it (EditsPageOrder) --}}
                    <section id="entry-props" class="{{ $card }}" aria-labelledby="entry-props-h" data-page-props x-show="more" x-cloak>
                        <div class="{{ $cardHead }}">
                            <h2 id="entry-props-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_page_props') }}</h2>
                            @if ($sharedLock)
                                <x-bs-admin::shared-chip />
                            @endif
                        </div>
                        <div class="flex flex-col gap-3 p-4">
                            @unless ($pageHome)
                                @include('admin::livewire.entries.partials.parent-picker')
                            @endunless
                            <div class="flex flex-col gap-1.5">
                                <label for="entry-ord" class="{{ $label }}">{{ __('entries.ed.ord_label') }}</label>
                                <input type="number" id="entry-ord" min="1" step="1" inputmode="numeric" wire:model.live.blur="pageOrder"
                                       @readonly($sharedLock) @if ($sharedLock) aria-readonly="true" title="{{ $sharedWhy }}" @endif
                                       @error('pageOrder') aria-describedby="entry-ord-e" aria-invalid="true" @enderror
                                       class="field {{ $fieldLook }} max-w-[100px]">
                                @error('pageOrder') <x-bs-admin::field-error id="entry-ord-e">{{ $message }}</x-bs-admin::field-error> @enderror
                            </div>
                        </div>
                    </section>
                @endif

                @if ($type === 'post')
                    <button type="button" x-on:click="setMore(! more)" x-bind:aria-expanded="more ? 'true' : 'false'" aria-expanded="false" aria-controls="entry-post-props"
                            aria-label="{{ __('catalog.ed.more') }} — {{ __('entries.ed.more_sub_post') }}"
                            x-bind:aria-label="more ? @js(__('catalog.ed.less').' — '.__('catalog.ed.less_sub')) : @js(__('catalog.ed.more').' — '.__('entries.ed.more_sub_post'))"
                            class="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2.5 gap-y-1 rounded-md border border-dashed border-border-input px-4 py-2.5 text-left text-body font-semibold text-primary-text transition-colors min-h-12 hover:bg-[color-mix(in_srgb,var(--color-primary-text)_6%,var(--color-surface))]">
                        <x-bs-admin::icon name="ri-add" :class="$glyph" x-show="! more" />
                        <x-bs-admin::icon name="ri-subtract" :class="$glyph" x-show="more" x-cloak />
                        <span x-text="more ? @js(__('catalog.ed.less')) : @js(__('catalog.ed.more'))">{{ __('catalog.ed.more') }}</span>
                        <span class="col-start-2 text-small font-normal text-text-muted" aria-hidden="true">
                            <span class="ped-ms-l" x-text="more ? @js(__('catalog.ed.less_sub')) : @js(__('entries.ed.more_sub_post'))">{{ __('entries.ed.more_sub_post') }}</span>
                            {{-- the short line of the phone's child screen --}}
                            <span class="ped-ms-s" x-text="more ? @js(__('catalog.ed.less_sub_s')) : @js(__('entries.ed.more_sub_post_s'))">{{ __('entries.ed.more_sub_post_s') }}</span>
                        </span>
                    </button>
                    @if ($errors->has('templateKey') || $errors->has('tagDraft') || $errors->has('tags') || $errors->has('tags.*') || $errors->has('job*')) <span hidden x-init="more = true"></span> @endif
                    {{-- «Thẻ» (design 07): the post's tags in this language, each with «Bỏ thẻ»; a name typed and added
                         with Enter (or «Thêm», several split at commas) joins them, and makes a tag on save when none has
                         it. Written to the website at once, so it stays as it is while this member's saves wait for
                         approval (Editor::addTag, EntryTags). --}}
                    @php($tagError = $errors->first('tagDraft') ?: ($errors->first('tags') ?: $errors->first('tags.*')))
                    @php($tagDesc = 'entry-tag-h'.($tagsHeld ? ' entry-tag-ro' : '').($tagError !== '' ? ' entry-tag-e' : ''))
                    <section id="entry-tags" class="{{ $card }}" aria-labelledby="entry-tags-h" data-post-tags x-show="more" x-cloak>
                        <div class="{{ $cardHead }}">
                            <h2 id="entry-tags-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_tags') }}</h2>
                        </div>
                        <div class="flex flex-col gap-2.5 p-4">
                            @if ($tags !== [])
                                <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="{{ __('entries.ed.card_tags') }}">
                                    @foreach ($tags as $i => $tagName)
                                        <li wire:key="tag-{{ $i }}-{{ md5($tagName) }}" class="inline-flex max-w-full items-center gap-1 rounded-full bg-surface-hover py-0.5 ps-2 pe-1 text-caption font-semibold text-text">
                                            <span class="min-w-0 [overflow-wrap:anywhere]">{{ $tagName }}</span>
                                            @unless ($tagsHeld)
                                                <button type="button" wire:click="removeTag({{ $i }})" aria-label="{{ __('entries.ed.tag_remove', ['name' => $tagName]) }}" title="{{ __('entries.ed.tag_remove', ['name' => $tagName]) }}"
                                                        class="inline-flex min-h-6 min-w-6 shrink-0 items-center justify-center rounded-full text-text-muted transition-colors hover:bg-surface hover:text-text any-pointer-coarse:min-h-hit any-pointer-coarse:min-w-hit">
                                                    <x-bs-admin::icon name="ri-close" :class="$glyph" />
                                                </button>
                                            @endunless
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="flex gap-2">
                                <input type="text" id="entry-tag-in" wire:model="tagDraft" wire:keydown.enter.prevent="addTag" x-on:keydown="if ($event.key === ',') { $event.preventDefault(); $wire.addTag() }"
                                       maxlength="{{ \App\Modules\Cms\Support\EntryTags::NAME_MAX * 4 }}" autocomplete="off" placeholder="{{ __('entries.ed.tag_ph') }}"
                                       aria-label="{{ __('entries.ed.tag_label') }}" aria-describedby="{{ $tagDesc }}" @readonly($tagsHeld) @if ($tagsHeld) aria-readonly="true" @endif
                                       @if ($tagError !== '') aria-invalid="true" @endif
                                       class="field min-w-0 flex-[1_1_auto] {{ $fieldLook }}">
                                <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="addTag" wire:loading.attr="disabled" wire:target="addTag" :disabled="$tagsHeld">
                                    <x-bs-admin::icon name="ri-add" :class="$glyph" /> {{ __('entries.ed.tag_add') }}
                                </x-bs-admin::button>
                            </div>
                            @if ($tagError !== '') <x-bs-admin::field-error id="entry-tag-e">{{ $tagError }}</x-bs-admin::field-error> @endif
                            @if ($tagsHeld) <p id="entry-tag-ro" class="m-0 text-caption text-text-muted [overflow-wrap:anywhere]">{{ __('entries.ed.tag_held') }}</p> @endif
                            <p id="entry-tag-h" class="m-0 text-caption text-text-muted [overflow-wrap:anywhere]">{{ __('entries.ed.tag_hint') }}</p>
                        </div>
                    </section>
                    {{-- «Thuộc tính bài» (design 07): the post's own look over its category's layout, and the day it shows as
                         published (empty = the real day). Both are every language's (Editor::$templateKey, $displayDate). --}}
                    <section id="entry-post-props" class="{{ $card }}" aria-labelledby="entry-post-props-h" data-post-props x-show="more" x-cloak>
                        <div class="{{ $cardHead }}">
                            <h2 id="entry-post-props-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_post_props', ['unit' => mb_strtolower($unit)]) }}</h2>
                            @if ($sharedLock)
                                <x-bs-admin::shared-chip />
                            @endif
                        </div>
                        <div class="flex flex-col gap-3 p-4">
                            <div class="flex flex-col gap-1.5">
                                <label for="entry-tpl" class="{{ $label }}">{{ __('entries.rev.f_tpl') }}</label>
                                <select id="entry-tpl" wire:model="templateKey" @disabled($sharedLock) @if ($sharedLock) title="{{ $sharedWhy }}" @endif
                                        @error('templateKey') aria-describedby="entry-tpl-e" aria-invalid="true" @enderror
                                        class="field {{ $fieldLook }}">
                                    <option value="">{{ __('entries.rev.tpl_default') }}</option>
                                    <option value="{{ \App\Modules\Theme\Rendering\TemplateCatalog::VARIANT_HERO }}">{{ __('entries.ed.tpl_hero', ['doc' => __('entries.ed.doc_post')]) }}</option>
                                    <option value="{{ \App\Modules\Theme\Rendering\TemplateCatalog::VARIANT_WIDE }}">{{ __('entries.ed.tpl_wide') }}</option>
                                </select>
                                @error('templateKey') <x-bs-admin::field-error id="entry-tpl-e">{{ $message }}</x-bs-admin::field-error> @enderror
                            </div>
                        </div>
                    </section>
                    @if ($jobCard !== null)
                        @include('admin::livewire.entries.partials.job-card')
                    @endif
                @endif
            </div>
        </div>

    </x-bs-admin::editor-frame>

    @if ($changeBand !== null && $changeBand['ask']['title'] !== '')
        {{-- «Duyệt đăng thay đổi?» / «Cập nhật website?»: what goes live, at once (no time to pick: a change of
             live content goes up when approved), and that the website's version stays in the history. --}}
        <x-bs-admin::confirm-dialog model="changeApplyAsk" :title="$changeBand['ask']['title']" icon="ri-global" :ok="$changeBand['ask']['ok']" ok-icon="ri-check-double"
                                    confirm="applyChange" cancel="cancelChangeAsk">
            @foreach ($changeBand['ask']['lines'] as $line)
                <x-bs-admin::confirm-line>{{ $line }}</x-bs-admin::confirm-line>
            @endforeach
        </x-bs-admin::confirm-dialog>
        {{-- «Bỏ thay đổi?»: whose edits, in which languages, and that it cannot be undone. --}}
        <x-bs-admin::confirm-dialog model="changeDiscardAsk" :title="__('entries.chg.discard_title')" icon="ri-arrow-go-back" danger :ok="__('entries.chg.btn_discard')" ok-icon="ri-arrow-go-back"
                                    confirm="discardChange" cancel="cancelChangeAsk">
            @foreach ($changeBand['discard'] as $line)
                <x-bs-admin::confirm-line>{{ $line }}</x-bs-admin::confirm-line>
            @endforeach
        </x-bs-admin::confirm-dialog>
        {{-- «So sánh với bản đang trên website»: per language, each field that differs — the new value, then what
             visitors see. --}}
        <x-bs-admin::modal model="changeDiffOpen" size="wide" :title="__('entries.chg.diff_title')" :subtitle="$changeBand['diff']['sub']" :close-label="__('admin.close')">
            @if ($changeBand['diff']['rows'] === [])
                <p class="m-0 text-text-muted">{{ __('entries.chg.diff_none') }}</p>
            @else
                <ul class="m-0 flex list-none flex-col gap-3 p-0" data-change-diff>
                    @foreach ($changeBand['diff']['rows'] as $row)
                        <li class="flex min-w-0 flex-col gap-1 rounded-md border border-border px-3 py-2.5">
                            <span class="flex flex-wrap items-center gap-2 text-small font-semibold text-text-strong">
                                @if ($row['lang'] !== null)
                                    <span class="rounded-sm bg-surface-hover px-1.5 text-caption font-semibold text-text-muted">{{ $row['lang'] }}</span>
                                @endif
                                {{ $row['label'] }}
                            </span>
                            <span class="text-text [overflow-wrap:anywhere]">{{ $row['now'] }}</span>
                            <span class="text-small text-text-muted [overflow-wrap:anywhere]">{{ __('entries.chg.diff_seen', ['text' => $row['seen']]) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-slot:footer>
                <x-bs-admin::button color="light" autofocus x-on:click="$wire.changeDiffOpen = false">{{ __('admin.close') }}</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
        {{-- «Trả lại thay đổi»: the note is required (said on the field, kept until it is written). --}}
        <x-bs-admin::modal model="changeReturnOpen" size="narrow" :title="__('entries.chg.return_title')" :close-label="__('admin.close')">
            <div class="flex flex-col gap-1.5">
                <label for="change-note" class="text-small font-semibold text-text">{{ __('entries.chg.return_label', ['by' => $changeBand['return']['by']]) }} <span class="text-danger-text" aria-hidden="true">*</span></label>
                <textarea id="change-note" wire:model="changeNote" rows="4" maxlength="2000" required aria-describedby="change-note-hint change-note-err"
                          aria-invalid="{{ $errors->has('changeNote') ? 'true' : 'false' }}"
                          class="w-full rounded border bg-surface px-3 py-2.5 text-text focus:border-primary-text {{ $errors->has('changeNote') ? 'border-danger-text' : 'border-border-input' }}"></textarea>
                <p id="change-note-hint" class="m-0 text-caption text-text-muted">{{ __('entries.chg.return_hint') }}</p>
                <div id="change-note-err">
                    @error('changeNote') <x-bs-admin::field-error role="alert">{{ $message }}</x-bs-admin::field-error> @enderror
                </div>
            </div>
            <x-slot:footer>
                <x-bs-admin::button color="light" x-on:click="$wire.changeReturnOpen = false">{{ __('admin.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button color="danger" variant="outline" wire:click="returnChange" wire:loading.attr="disabled" wire:target="returnChange" data-change-return-ok>
                    <x-bs-admin::icon name="ri-reply" class="size-4" />{{ __('entries.chg.btn_return') }}
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    @endif

    @if ($revisions !== null)
        @include('admin::livewire.entries.partials.revision-dialogs')
    @endif

    @if ($frame['preview']['url'] ?? null)
        <x-bs-admin::preview-dialog :url="$frame['preview']['url']" :unit="$unit" />
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

    @if ($refusal !== null)
        {{-- «Chưa làm được» (design 07, the refused change): a save, a send, «Cập nhật website» /
             «Duyệt đăng» or «Bỏ thay đổi» of the pending change refused (EditsPendingChange::changeRefused).
             The move as its title, the reason in plain words; «Đóng» takes the focus and closes it, like
             Escape and the backdrop, keeping what was typed. When the change moved on elsewhere,
             «Tải lại» loads it into the form (asking first when edits would be replaced). --}}
        <div wire:key="entry-refusal-{{ md5(json_encode($refusal)) }}" data-entry-refusal
             x-data="{ shut: false, {{ \App\Modules\Admin\Livewire\Entries\Editor::REFUSAL_REFOCUS }} }">
            {{-- `shut` hides it at once on «Đóng», Escape or the backdrop; the server then drops it --}}
            <x-bs-admin::modal state="! shut" close="shut = true; $wire.closeRefusal()" size="narrow" compact alert describedby="entry-refusal-d" :close-label="__('admin.dismiss')">
                <x-slot:heading>
                    <span class="flex items-center gap-2.5">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--color-danger-text)_10%,var(--color-surface))] text-danger-text" aria-hidden="true">
                            <x-bs-admin::icon name="ri-close-circle" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                        </span>
                        <span>{{ $refusal['title'] }}</span>
                    </span>
                </x-slot:heading>
                <x-bs-admin::alert color="danger" icon="ri-close-circle" id="entry-refusal-d">{{ $refusal['text'] }}</x-bs-admin::alert>
                <x-slot:footer>
                    @if ($refusal['reload'])
                        <x-bs-admin::button variant="outline" color="secondary" size="sm" autofocus x-on:click="hide()" data-refusal-close>
                            <x-bs-admin::icon name="ri-close" :class="$glyph" /> {{ __('admin.close') }}
                        </x-bs-admin::button>
                        <x-bs-admin::button color="primary" size="sm" wire:click="reloadChange" wire:loading.attr="disabled" wire:target="reloadChange" data-refusal-reload>
                            <x-bs-admin::icon name="ri-refresh" :class="$glyph" /> {{ __('entries.refuse.reload') }}
                        </x-bs-admin::button>
                    @else
                        <x-bs-admin::button color="primary" size="sm" autofocus x-on:click="hide()" data-refusal-close>
                            <x-bs-admin::icon name="ri-close" :class="$glyph" /> {{ __('admin.close') }}
                        </x-bs-admin::button>
                    @endif
                </x-slot:footer>
            </x-bs-admin::modal>
        </div>
    @endif
    @if ($reloadReplaces !== null)
        {{-- «Tải bản mới nhất?»: «Tải lại» with edits still in the form. Keeping them over the newer change would
             save over another member's edits, so the question names the fields replaced; «Ở lại» (the focus first,
             Escape, the backdrop) keeps the typed text and loads nothing. --}}
        <div wire:key="entry-reload-ask" data-entry-reload-ask x-data="{ {{ \App\Modules\Admin\Livewire\Entries\Editor::REFUSAL_REFOCUS }} }">
            <x-bs-admin::confirm-dialog model="changeReloadAsk" :title="__('entries.refuse.reload_title')" icon="ri-refresh" danger
                                        :ok="__('entries.refuse.reload_ok')" ok-icon="ri-refresh" :cancel-label="__('entries.refuse.reload_stay')"
                                        confirm="confirmReloadChange" cancel="keepTypedText">
                <x-bs-admin::confirm-line>{{ __('entries.refuse.reload_line', ['fields' => $reloadReplaces]) }}</x-bs-admin::confirm-line>
            </x-bs-admin::confirm-dialog>
        </div>
    @endif

    @if ($sharedPicker)
        @include('admin::livewire.entries.partials.shared-block-picker')
    @endif

    @can('media.view')
        @include('admin::livewire.entries.partials.block-pickers')
        {{-- The shared «Chọn ảnh» (WithMediaLibraryPicker): the body's «Chèn ảnh», the cover and a block's
             picture (one each). Library pictures only: a picture taken from a link is always saved into
             the library, so the body never points elsewhere. --}}
        @include('admin::partials.media-picker-modal', ['pickedIds' => [], 'editorLibraryOnly' => true])
    @endcan
    {{-- After a cover pick or «Bỏ ảnh» (design 07 notify): «Đã gỡ ảnh đại diện» carries «Hoàn tác». --}}
     <x-bs-admin::toast :message="$formToast" :seq="$formToastSeq" dismiss="closeFormToast" :undo="$formUndo !== null ? 'undoFormClear' : null" :raised="true" />
    <x-bs-admin::toast :message="$coverToast" :seq="$coverToastSeq" dismiss="closeCoverToast" :undo="$coverUndo !== null ? 'undoCoverRemoval' : null" :raised="true" />
    @include('admin::partials.video-embed-modal')
</div>
