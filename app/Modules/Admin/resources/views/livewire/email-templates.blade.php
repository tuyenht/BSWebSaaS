{{--
    «Mẫu thư» (design c-email-settings, the templates tab): the website's automatic mails, grouped as
    the board groups them, each with its languages and its switch or button; below, the «Hệ thống» and
    «Bắt buộc» ones in their own block. «Sửa»/«Xem» opens the editor in place of the list (the page's
    tab strip hides while it is open). The editor's text box, toolbar, variables, link box and
    unsaved-changes question are the lazy part bsEmailTpl (admin-email-template.js).
--}}
@php
    $cap = 'text-[calc(0.75rem+var(--user-text-boost))] font-bold tracking-[.04em] text-text-muted uppercase';
    $card = '@container rounded-md border border-border-strong bg-surface shadow-card';
    $sm = 'size-[min(1.25rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $xs = 'size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text';
    $gbtn = 'hit-area inline-flex min-h-control cursor-pointer items-center justify-center gap-1.5 rounded border border-border-input bg-surface px-[15px] text-[calc(0.8125rem+var(--user-text-boost))] font-semibold whitespace-nowrap text-text hover:bg-surface-hover disabled:cursor-not-allowed disabled:border-border-strong disabled:bg-surface-hover disabled:text-text-muted any-pointer-coarse:min-h-hit '.$focus;
    $editBtn = 'hit-area inline-flex min-h-control min-w-[84px] shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-md border border-border-input bg-surface px-3.5 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-primary-text hover:bg-primary-soft any-pointer-coarse:min-h-hit '.$focus;
    $tlink = 'hit-area inline-flex shrink-0 cursor-pointer items-center gap-[5px] border-0 bg-transparent p-0 text-start text-[calc(0.75rem+var(--user-text-boost))] font-medium whitespace-nowrap text-primary-text hover:underline '.$focus;
    // the name is the one real button of the row; its ::after stretches over the name / «when» / note block (an inner control sits above it with z-10)
    $rowBtn = 'cursor-pointer border-0 bg-transparent p-0 text-start after:absolute after:inset-0 after:content-[\'\'] hover:underline '.$focus;
    $badge = [
        'sys' => ['text' => __('admin.mt_b_sys'), 'icon' => 'ri-shield-check', 'cls' => 'bg-surface-muted text-text-muted'],
        'lock' => ['text' => __('admin.mt_b_lock'), 'icon' => 'ri-lock', 'cls' => 'bg-warning-soft text-warning-text'],
    ];
    $chip = fn (bool $done): string => 'inline-flex min-w-[30px] items-center justify-center rounded px-1.5 py-0.5 text-[calc(0.75rem+var(--user-text-boost))] font-bold '
        .($done ? 'border border-success-text/35 bg-success-soft text-success-text' : 'border border-dashed border-text-faint bg-surface text-text-muted');
@endphp
<div class="flex flex-col gap-3.5">
    @if ($editor === null)
        {{-- The list --}}
        <section class="{{ $card }} overflow-hidden" aria-label="{{ __('admin.em_tab_tpl') }}" x-data="{
            q: '',
            fold(s) { return String(s || '').normalize('NFC').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').trim(); },
            hit(el) { const q = this.fold(this.q); return q === '' || this.fold(el.dataset.hay).includes(q); },
            asked() { return this.fold(this.q) !== ''; },
            none() { return this.asked() && ! Array.from($el.querySelectorAll('[data-hay]')).some((r) => this.hit(r)); },
            anyIn(g) { return Array.from(g.querySelectorAll('[data-hay]')).some((r) => this.hit(r)); },
        }">
            <div class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-3.5">
                @if ($showSearch)
                    <label class="flex min-h-control min-w-0 flex-[1_1_260px] items-center gap-2 rounded-md border border-border-strong bg-surface px-3 focus-within:outline-2 focus-within:outline-offset-1 focus-within:outline-primary-text any-pointer-coarse:min-h-hit">
                        <x-bs-admin::icon name="ri-search" class="{{ $sm }} text-text-muted" />
                        <span class="sr-only">{{ __('admin.mt_search_aria') }}</span>
                        <input type="search" x-model="q" {{-- focus-ring-ok: the search box draws the ring (focus-within) --}} placeholder="{{ __('admin.mt_search') }}" autocomplete="off"
                               class="min-w-0 flex-1 self-stretch border-0 bg-transparent p-0 text-[calc(0.84375rem+var(--user-text-boost))] text-text outline-none placeholder:text-placeholder">
                    </label>
                @endif
                <div role="group" aria-label="{{ __('admin.mt_filter_aria') }}" class="me-auto inline-flex min-w-0 max-w-full flex-wrap overflow-hidden rounded-md border border-border-strong">
                    @foreach (\App\Modules\Admin\Livewire\EmailTemplates::FILTERS as $f)
                        <button type="button" wire:key="tf-{{ $f }}" wire:click="setFilter(@js($f))" aria-pressed="{{ $filter === $f ? 'true' : 'false' }}"
                                class="hit-area min-h-control cursor-pointer border-0 border-e border-border-strong bg-surface px-[13px] text-[calc(0.78125rem+var(--user-text-boost))] font-medium whitespace-nowrap text-text-muted last:border-e-0 hover:bg-surface-hover aria-pressed:bg-primary aria-pressed:font-semibold aria-pressed:text-on-primary any-pointer-coarse:min-h-hit focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text">
                            {{ __('admin.mt_f_'.$f) }} {{ $counts[$f] }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($groups !== [])
                <div x-show="! none()" class="grid grid-cols-[20px_minmax(0,1fr)_108px_152px] gap-3 border-t border-border bg-surface-muted px-[18px] pt-2 pb-[7px] @max-[35rem]:hidden" aria-hidden="true">
                    <span></span>
                    <span class="{{ $cap }}">{{ __('admin.mt_col_tpl') }}</span>
                    <span class="{{ $cap }} text-center">{{ __('admin.mt_col_lang') }}</span>
                    <span class="{{ $cap }} text-end">{{ __('admin.mt_col_act') }}</span>
                </div>
            @endif

            @foreach ($groups as $g)
                <div wire:key="tg-{{ $filter }}-{{ $loop->index }}" x-show="anyIn($el)" role="group" aria-labelledby="tg-l-{{ $loop->index }}">
                    <div id="tg-l-{{ $loop->index }}" class="{{ $cap }} px-[18px] pt-3 pb-1.5">{{ $g['label'] }}</div>
                    @foreach ($g['items'] as $t)
                        <div wire:key="tr-{{ $t['key'] }}" data-hay="{{ $t['name'] }} {{ $t['when'] }}" x-show="hit($el)" class="grid grid-cols-[20px_minmax(0,1fr)_108px_152px] items-center gap-3 border-t border-border px-[18px] py-[13px] @max-[35rem]:flex @max-[35rem]:flex-wrap @max-[35rem]:py-3">
                            <x-bs-admin::icon :name="$t['icon']" @class(['size-[min(1.5rem,calc(1.1875rem+var(--user-text-boost)))] shrink-0', 'text-text-muted' => $t['on'], 'text-text-faint' => ! $t['on']]) />
                            <div class="relative flex min-w-0 flex-col items-start gap-0.5 @max-[35rem]:flex-[1_1_calc(100%-31px)]">
                                <span class="flex flex-wrap items-center gap-2">
                                    <button type="button" wire:click="open(@js($t['key']))"
                                            @class([$rowBtn.' text-[calc(0.875rem+var(--user-text-boost))] font-semibold', 'text-text-strong' => $t['on'], 'text-text-muted' => ! $t['on']])>{{ $t['name'] }}</button>
                                    @if ($t['edited'])
                                        <span class="inline-flex items-center gap-1.5 text-[calc(0.75rem+var(--user-text-boost))] font-semibold whitespace-nowrap text-primary-text"><span class="size-[7px] shrink-0 rounded-full bg-primary-text" aria-hidden="true"></span>{{ __('admin.mt_b_edited') }}</span>
                                        <span class="text-[calc(0.75rem+var(--user-text-boost))] text-text-muted" aria-hidden="true">·</span>
                                        <button type="button" class="{{ $tlink }} z-10" wire:click="askReset(@js($t['key']))"
                                                title="{{ __('admin.mt_undo_tip', ['name' => $t['name']]) }}" aria-label="{{ __('admin.mt_undo_tip', ['name' => $t['name']]) }}">
                                            <x-bs-admin::icon name="ri-arrow-go-back" class="{{ $xs }}" />{{ __('admin.mt_undo') }}
                                        </button>
                                    @endif
                                </span>
                                <span class="text-[calc(0.75rem+var(--user-text-boost))] leading-[1.45] text-text-muted">{{ $t['when'] }}</span>
                                @if ($t['note'] !== null)
                                    <span class="inline-flex min-w-0 items-start gap-1.5 text-[calc(0.75rem+var(--user-text-boost))] leading-[1.45] text-text-muted"><x-bs-admin::icon name="ri-error-warning" class="{{ $xs }} mt-px shrink-0" /><span>{{ $t['note'] }}</span></span>
                                @endif
                            </div>
                            <div @class(['flex items-center justify-center gap-[5px] @max-[35rem]:order-3 @max-[35rem]:ms-[34px] @max-[35rem]:justify-start', 'grayscale' => ! $t['on']])>
                                @foreach ($t['langs'] as $l)
                                    <span class="{{ $chip($l['done']) }}" title="{{ __($l['done'] ? 'admin.mt_lang_done' : 'admin.mt_lang_missing', ['code' => strtoupper($l['code'])]) }}">
                                        {{ strtoupper($l['code']) }}<span class="sr-only"> — {{ __($l['done'] ? 'admin.mt_lang_done' : 'admin.mt_lang_missing', ['code' => strtoupper($l['code'])]) }}</span>
                                    </span>
                                @endforeach
                            </div>
                            <div class="flex items-center justify-end gap-2.5 @max-[35rem]:order-3 @max-[35rem]:ms-auto">
                                <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $t['on'] ? 'true' : 'false' }}"
                                        wire:click="toggle(@js($t['key']))"
                                        aria-label="{{ __($t['on'] ? 'admin.mt_sw_off' : 'admin.mt_sw_on', ['name' => $t['name']]) }}"
                                        title="{{ __($t['on'] ? 'admin.mt_sw_off' : 'admin.mt_sw_on', ['name' => $t['name']]) }}"></button>
                                <button type="button" class="{{ $editBtn }}" wire:click="open(@js($t['key']))" aria-label="{{ __('admin.mt_edit') }} — {{ $t['name'] }}">
                                    <x-bs-admin::icon name="ri-pencil" class="{{ $sm }}" />{{ __('admin.mt_edit') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach

            @if ($groups === [])
                <div x-show="! asked()" class="px-9 py-8 text-center text-[calc(0.84375rem+var(--user-text-boost))] leading-relaxed text-text-muted">
                    {{ __('admin.mt_empty_filter') }}<br>
                    <button type="button" class="{{ $tlink }} mt-[9px] text-[calc(0.8125rem+var(--user-text-boost))] font-semibold" wire:click="setFilter('all')">
                        <x-bs-admin::icon name="ri-close-circle" class="{{ $xs }}" />{{ __('admin.mt_clear_filter') }}
                    </button>
                </div>
            @endif

            @if ($filter === 'all')
                <div wire:key="tpl-empty-q" x-show="none()" x-cloak class="px-9 py-9 text-center text-[calc(0.84375rem+var(--user-text-boost))] leading-relaxed text-text-muted" data-empty="q" role="status">
                    <span x-text="@js(__('admin.mt_empty_q', ['q' => ':q'])).replace(':q', () => q.trim())">{{ __('admin.mt_empty_q', ['q' => '']) }}</span>
                </div>
            @else
                <div wire:key="tpl-empty-both" x-show="none()" x-cloak class="px-9 py-8 text-center text-[calc(0.84375rem+var(--user-text-boost))] leading-relaxed text-text-muted" data-empty="both" role="status">
                    <span x-text="@js(__('admin.mt_empty_both', ['q' => ':q'])).replace(':q', () => q.trim())">{{ __('admin.mt_empty_both', ['q' => '']) }}</span><br>
                    <button type="button" class="{{ $tlink }} mt-[9px] text-[calc(0.8125rem+var(--user-text-boost))] font-semibold" wire:click="setFilter('all')">
                        <x-bs-admin::icon name="ri-close-circle" class="{{ $xs }}" />{{ __('admin.mt_clear_filter_keep_q') }}
                    </button>
                </div>
            @endif

            @if ($editedCount > 0)
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-surface-muted px-[18px] py-3">
                    <span class="min-w-0 text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted">{{ __('admin.mt_edited_summary', ['n' => $editedCount]) }}</span>
                    <button type="button" class="{{ $gbtn }}" wire:click="askReset('*')">
                        <x-bs-admin::icon name="ri-arrow-go-back" class="{{ $sm }}" />{{ __('admin.mt_reset_all') }}
                    </button>
                </div>
            @endif
        </section>

        {{-- Mẫu hệ thống & bắt buộc --}}
        <section class="{{ $card }}" x-data="{ open: false }">
            <h2 class="m-0">
                <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-expanded="false" aria-controls="tpl-locked"
                        class="hit-area flex w-full cursor-pointer flex-wrap items-center gap-2.5 rounded-md border-0 bg-transparent px-[18px] py-3.5 text-start focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text">
                    <x-bs-admin::icon name="ri-shield-check" class="{{ $sm }} text-text-muted" />
                    <span class="text-[calc(0.84375rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.mt_locked_title') }}</span>
                    <span class="text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('admin.mt_locked_count', ['n' => count($locked)]) }}</span>
                    <x-bs-admin::icon name="ri-arrow-down-s" class="ms-auto {{ $sm }} text-text-muted transition-transform motion-reduce:transition-none" x-bind:class="open && 'rotate-180'" />
                </button>
            </h2>
            <div id="tpl-locked" x-show="open" x-cloak>
                @foreach ($locked as $t)
                    @php $b = $badge[$t['kind'] === \App\Modules\Admin\Support\Mail\MailTemplateCatalog::SYSTEM ? 'sys' : 'lock']; @endphp
                    <div wire:key="tl-{{ $t['key'] }}" class="flex items-center gap-2.5 border-t border-border px-[18px] py-3 @max-[35rem]:flex-wrap">
                        <x-bs-admin::icon :name="$t['icon']" class="size-[min(1.5rem,calc(1.1875rem+var(--user-text-boost)))] shrink-0 text-text-muted" />
                        <div class="relative flex min-w-0 flex-1 flex-col gap-px">
                            <span class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="open(@js($t['key']))" class="{{ $rowBtn }} text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $t['name'] }}</button>
                            </span>
                            <span class="text-[calc(0.75rem+var(--user-text-boost))] leading-[1.45] text-text-muted">{{ $t['when'] }}</span>
                        </div>
                        <span class="inline-flex items-center gap-[5px] rounded-[20px] px-[9px] py-0.5 text-[calc(0.75rem+var(--user-text-boost))] font-semibold whitespace-nowrap {{ $b['cls'] }}">
                            <x-bs-admin::icon :name="$b['icon']" class="{{ $xs }}" />{{ $b['text'] }}
                        </span>
                        <button type="button" wire:click="open(@js($t['key']))" title="{{ __('admin.mt_view_tip') }}" aria-label="{{ __('admin.mt_view_tpl', ['name' => $t['name']]) }}"
                                class="hit-area inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent text-text-muted hover:bg-primary-soft hover:text-primary-text {{ $focus }}">
                            <x-bs-admin::icon name="ri-eye" class="{{ $sm }}" />
                        </button>
                    </div>
                @endforeach
            </div>
        </section>
    @else
        @php
            $cfg = [
                'subject' => $editor['subject'],
                'body' => $editor['body'],
                'editable' => $editor['editable'],
                'required' => $editor['required'],
                'vars' => array_column($editor['vars'], 'token'),
                'links' => array_values(array_column(array_filter($editor['vars'], static fn (array $v): bool => $v['link']), 'token')),
                'langs' => array_column($editor['languages'], 'code'),
                'preview' => $editor['preview'],
                'texts' => [
                    'guardLang' => __('admin.mt_guard_body_lang', ['name' => $editor['name']]),
                    'guardBack' => __('admin.mt_guard_body_back', ['name' => $editor['name']]),
                    'linkBad' => __('admin.mt_link_bad'),
                    'linkTextReq' => __('admin.mt_link_text_req'),
                ],
            ];
        @endphp
        <div wire:key="tpl-ed-{{ $editor['key'] }}-{{ $lang }}-{{ $version }}" x-ignore wire:ignore.self data-bs-lazy="bsEmailTpl" data-bs-lazy-keep
             x-data="bsEmailTpl({{ \Illuminate\Support\Js::from($cfg) }})" class="flex flex-col gap-3.5">
            <p data-bs-lazy-wait x-show="false" class="m-0 text-caption text-text-muted">{{ __('admin.mt_loading') }}</p>
            <x-bs-admin::alert color="danger" icon="ri-wifi-off" data-bs-lazy-error x-show="false">
                <div class="flex flex-wrap items-center gap-2.5">
                    {{ __('admin.mt_load_failed') }}
                    <x-bs-admin::button size="sm" variant="outline" color="primary" data-bs-lazy-retry><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.em_sig_retry') }}</x-bs-admin::button>
                </div>
            </x-bs-admin::alert>

            {{-- Head: back, the template's name, its switch, «Về mẫu gốc», «Lưu mẫu» --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 flex-wrap items-center gap-3">
                    <button type="button" x-on:click="leave(() => $wire.close())" aria-label="{{ __('admin.mt_back') }}" title="{{ __('admin.mt_back') }}"
                            class="hit-area inline-flex size-[34px] shrink-0 cursor-pointer items-center justify-center rounded-md border border-border-strong bg-surface text-text hover:bg-surface-hover {{ $focus }}">
                        <x-bs-admin::icon name="ri-arrow-left" class="{{ $sm }}" />
                    </button>
                    <h2 class="m-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $editor['name'] }}</h2>
                    @if ($editor['edited'])
                        <span class="rounded bg-surface-muted px-[9px] py-0.5 text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-text-muted">{{ __('admin.mt_you_edited') }}</span>
                    @endif
                    @if ($editor['kind'] === \App\Modules\Admin\Support\Mail\MailTemplateCatalog::REQUIRED)
                        <button type="button" class="switch hit-area" role="switch" aria-checked="true" disabled aria-label="{{ __('admin.mt_lock_ed_aria') }}" title="{{ __('admin.mt_lock_tip') }}"></button>
                    @elseif ($editor['kind'] === \App\Modules\Admin\Support\Mail\MailTemplateCatalog::SWITCH)
                        <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $editor['on'] ? 'true' : 'false' }}" wire:click="toggle(@js($editor['key']))"
                                aria-label="{{ __('admin.mt_toggle_aria') }}" title="{{ __('admin.mt_toggle_tip') }}"></button>
                    @endif
                </div>
                @if ($editor['editable'])
                    <div class="flex flex-wrap items-center gap-2.5">
                        <button type="button" class="{{ $gbtn }}" wire:click="askReset(@js($editor['key']))" @disabled(! $editor['edited'])
                                title="{{ $editor['edited'] ? __('admin.mt_reset_tip') : __('admin.mt_reset_none') }}">
                            <x-bs-admin::icon name="ri-arrow-go-back" class="{{ $sm }}" />{{ __('admin.mt_reset') }}
                        </button>
                        <span x-show="dirty" x-cloak class="inline-flex items-center gap-1.5 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold whitespace-nowrap text-warning-text">
                            <span class="size-2 rounded-full bg-warning-base" aria-hidden="true"></span>{{ __('admin.mt_unsaved') }}
                        </span>
                        <x-bs-admin::button type="button" x-on:click="save()" x-bind:disabled="busy" x-bind:aria-busy="busy ? 'true' : 'false'">
                            <x-bs-admin::icon name="ri-save-3" class="{{ $sm }}" />
                            <span x-text="busy ? @js(__('admin.mt_saving')) : @js(__('admin.mt_save'))">{{ __('admin.mt_save') }}</span>
                        </x-bs-admin::button>
                    </div>
                @endif
            </div>

            @if ($editor['kind'] === \App\Modules\Admin\Support\Mail\MailTemplateCatalog::SWITCH && ! $editor['on'])
                <x-bs-admin::alert color="warning" icon="ri-error-warning" role="status">{!! __('admin.mt_off_warn', ['off' => '<b>'.e(__('admin.mt_off_word')).'</b>']) !!}</x-bs-admin::alert>
            @endif

            {{-- Languages, or why there is one --}}
            @if (! $editor['editable'])
                <p class="m-0 flex min-w-0 items-center gap-2 text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted">
                    <x-bs-admin::icon name="ri-shield-check" class="{{ $sm }}" /><span>{{ __($editor['i18n'] ? 'admin.mt_sys_note_i18n' : 'admin.mt_sys_note') }}</span>
                </p>
            @elseif (! $editor['i18n'])
                <p class="m-0 flex min-w-0 items-center gap-2 text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted">
                    <x-bs-admin::icon name="ri-user-received-2" class="{{ $sm }}" /><span>{{ __('admin.mt_no_i18n') }}</span>
                </p>
            @elseif (count($editor['languages']) > 1)
                <div role="group" aria-label="{{ __('admin.mt_langs_aria') }}" class="flex flex-wrap items-center gap-1.5">
                    @foreach ($editor['languages'] as $lg)
                        <button type="button" wire:key="tl-{{ $lg['code'] }}" x-on:click="pickLang(@js($lg['code']))" aria-pressed="{{ $lg['code'] === $lang ? 'true' : 'false' }}"
                                title="{{ __($lg['done'] ? 'admin.mt_lang_done' : 'admin.mt_lang_missing', ['code' => $lg['name']]) }}"
                                class="hit-area inline-flex min-h-control cursor-pointer items-center gap-1.5 rounded-md border border-border-strong bg-surface-muted px-3.5 text-[calc(0.8125rem+var(--user-text-boost))] font-medium whitespace-nowrap text-text-muted hover:bg-surface-hover aria-pressed:border-primary-text aria-pressed:bg-surface aria-pressed:font-semibold aria-pressed:text-text-strong any-pointer-coarse:min-h-hit {{ $focus }}">
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-success-base' => $lg['done'], 'border border-dashed border-text-faint' => ! $lg['done']]) aria-hidden="true"></span>
                            {{ $lg['name'] }}
                            @if ($lg['source'])
                                <x-bs-admin::icon name="ri-star-fill" class="{{ $xs }} text-warning-text" /><span class="sr-only">({{ __('admin.mt_src_lang') }})</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
            @if ($editor['fromDefault'])
                <x-bs-admin::alert color="info" icon="ri-information" role="status">{{ __('admin.mt_from_default', ['lang' => $editor['langName'], 'default' => $editor['defaultName']]) }}</x-bs-admin::alert>
            @endif

            @if ($editor['editable'])
                <div role="tablist" aria-label="{{ __('admin.mt_modes_aria') }}" class="flex items-end gap-1 border-b border-border-strong">
                    @foreach (['edit' => ['ri-edit-2', __('admin.mt_mode_edit')], 'preview' => ['ri-eye', __('admin.mt_mode_preview')]] as $m => [$icon, $label])
                        <button type="button" role="tab" id="tpl-mode-{{ $m }}" aria-controls="tpl-pane-{{ $m }}" x-on:click="setMode(@js($m))"
                                aria-selected="{{ $m === 'edit' ? 'true' : 'false' }}" x-bind:aria-selected="mode === @js($m) ? 'true' : 'false'"
                                class="hit-area -mb-px inline-flex min-h-control cursor-pointer items-center gap-[7px] border-0 border-b-2 border-transparent bg-transparent px-4 text-[calc(0.84375rem+var(--user-text-boost))] font-semibold text-text-muted aria-selected:border-primary-text aria-selected:text-primary-text any-pointer-coarse:min-h-hit focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text">
                            <x-bs-admin::icon :name="$icon" class="{{ $sm }}" />{{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- Soạn thảo --}}
                <div id="tpl-pane-edit" role="tabpanel" aria-labelledby="tpl-mode-edit" x-show="mode === 'edit'" class="flex flex-col gap-3">
                    <div>
                        <div class="flex items-center gap-2.5 rounded-md border border-border-strong bg-surface px-3.5 py-[11px] focus-within:outline-2 focus-within:outline-offset-1 focus-within:outline-primary-text">
                            <span id="tpl-subj-l" class="shrink-0 text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('admin.mt_subject') }}</span>
                            <div wire:ignore x-ref="subj" contenteditable="true" role="textbox" aria-labelledby="tpl-subj-l" aria-label="{{ __('admin.mt_subject_aria') }}"
                                 data-ph="{{ __('admin.mt_subject_ph') }}" x-on:focus="focusIn('subj')" x-on:input="changed()" x-on:keyup="keep()" x-on:mouseup="keep()" x-on:keydown.enter.prevent x-on:paste="pastePlain($event)"
                                 {{-- focus-ring-ok: the subject box draws the ring (focus-within) --}} class="min-w-0 flex-1 text-[calc(0.90625rem+var(--user-text-boost))] font-medium text-text-strong outline-none [overflow-wrap:anywhere] empty:before:text-text-muted empty:before:content-[attr(data-ph)] [&_.em-var]:mx-px [&_.em-var]:inline-block [&_.em-var]:rounded [&_.em-var]:border [&_.em-var]:border-border [&_.em-var]:bg-surface-muted [&_.em-var]:px-[7px] [&_.em-var]:text-[.92em] [&_.em-var]:font-semibold [&_.em-var]:whitespace-nowrap [&_.em-var]:text-primary-text"></div>
                            <span class="shrink-0 font-mono text-text-muted" aria-hidden="true">{ }</span>
                        </div>
                        @error('tplSubject')<x-bs-admin::field-error id="tpl-subj-err" class="mt-1.5">{{ $message }}</x-bs-admin::field-error>@enderror
                    </div>

                    <div>
                        <div class="overflow-hidden rounded-md border border-border-strong bg-surface">
                            @php
                                $tool = 'hit-area inline-flex min-h-[calc(1.875rem+var(--user-text-boost))] cursor-pointer items-center justify-center gap-[5px] rounded border-0 bg-transparent px-2 text-[calc(0.8125rem+var(--user-text-boost))] whitespace-nowrap text-text-muted hover:bg-surface-hover hover:text-text-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-text';
                                $tools = [
                                    ['ri-bold', 'bold', __('admin.mt_t_bold'), false, false],
                                    ['ri-italic', 'italic', __('admin.mt_t_italic'), false, false],
                                    ['ri-underline', 'underline', __('admin.mt_t_underline'), false, false],
                                    ['ri-align-left', 'justifyLeft', __('admin.mt_t_left'), true, false],
                                    ['ri-align-center', 'justifyCenter', __('admin.mt_t_center'), false, false],
                                    ['ri-align-right', 'justifyRight', __('admin.mt_t_right'), false, false],
                                    ['ri-list-unordered', 'insertUnorderedList', __('admin.mt_t_ul'), true, false],
                                    ['ri-list-ordered-2', 'insertOrderedList', __('admin.mt_t_ol'), false, false],
                                    ['ri-link', 'link', __('admin.mt_t_link'), false, false],
                                    ['ri-cursor', 'button', __('admin.mt_t_button'), false, true],
                                    ['ri-format-clear', 'removeFormat', __('admin.mt_t_clear'), false, false],
                                ];
                            @endphp
                            <div role="toolbar" aria-label="{{ __('admin.mt_tools_aria') }}" aria-controls="tpl-body"
                                 class="flex flex-wrap items-center gap-0.5 border-b border-border bg-surface-muted px-2.5 py-2">
                                @foreach ($tools as [$icon, $cmd, $label, $sep, $withText])
                                    @if ($sep)<span class="mx-1 h-5 w-px shrink-0 bg-border-strong" aria-hidden="true"></span>@endif
                                    <button type="button" class="{{ $tool }}" title="{{ $label }}" @if (! $withText) aria-label="{{ $label }}" @endif
                                            x-on:mousedown.prevent x-on:click="tool(@js($cmd))">
                                        <x-bs-admin::icon :name="$icon" class="{{ $sm }}" />@if ($withText)<span>{{ $label }}</span>@endif
                                    </button>
                                @endforeach
                            </div>
                            <div class="bg-surface-muted px-[18px] py-5 @max-[35rem]:px-2.5">
                                <div wire:ignore id="tpl-body" x-ref="body" contenteditable="true" role="textbox" aria-multiline="true" aria-label="{{ __('admin.mt_body_aria') }}"
                                     data-ph="{{ __('admin.mt_body_ph') }}" x-on:focus="focusIn('body')" x-on:input="changed()" x-on:keyup="keep()" x-on:mouseup="keep()" x-on:paste="paste($event)"
                                     class="mx-auto min-h-60 max-w-[600px] rounded border border-border bg-surface p-6 font-[Arial,Helvetica,sans-serif] text-[calc(1rem+var(--user-text-boost))] leading-normal text-text [overflow-wrap:anywhere] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-text empty:before:text-text-muted empty:before:content-[attr(data-ph)] @max-[35rem]:p-4 [&_a]:text-primary-text [&_a]:underline [&_ol]:list-decimal [&_ol]:ps-6 [&_p]:mb-4 [&_ul]:list-disc [&_ul]:ps-6 [&_.em-var]:mx-px [&_.em-var]:inline-block [&_.em-var]:rounded [&_.em-var]:border [&_.em-var]:border-border [&_.em-var]:bg-surface-muted [&_.em-var]:px-[7px] [&_.em-var]:text-[.92em] [&_.em-var]:font-semibold [&_.em-var]:whitespace-nowrap [&_.em-var]:text-primary-text [&_p.em-cta>a]:inline-block [&_p.em-cta>a]:rounded [&_p.em-cta>a]:bg-[var(--tpl-brand)] [&_p.em-cta>a]:px-5 [&_p.em-cta>a]:py-2.5 [&_p.em-cta>a]:font-semibold [&_p.em-cta>a]:text-[var(--tpl-ink)] [&_p.em-cta>a]:no-underline"
                                     style="--tpl-brand: {{ $brand['color'] }}; --tpl-ink: {{ $brand['text'] }};"></div>
                                <p class="mx-auto mt-2 mb-0 max-w-[600px] text-[calc(0.75rem+var(--user-text-boost))] leading-normal text-text-muted">{{ __('admin.mt_body_hint') }}</p>
                            </div>
                        </div>
                        @error('tplBody')<x-bs-admin::field-error id="tpl-body-err" class="mt-1.5">{{ $message }}</x-bs-admin::field-error>@enderror
                    </div>

                    {{-- Biến --}}
                    <div class="overflow-hidden rounded-md border border-border-strong bg-surface" x-data="{ vg: 'all', vq: '' }">
                        <div class="flex flex-wrap items-center gap-1.5 px-3.5 pt-2.5">
                            <span class="font-mono text-text-muted" aria-hidden="true">{ }</span>
                            <div role="group" aria-label="{{ __('admin.mt_var_groups_aria') }}" class="flex flex-wrap gap-1">
                                @foreach ([['key' => 'all', 'label' => __('admin.mt_vg_all')], ...$editor['varGroups']] as $vg)
                                    <button type="button" x-on:click="vg = @js($vg['key'])" aria-pressed="{{ $vg['key'] === 'all' ? 'true' : 'false' }}" x-bind:aria-pressed="vg === @js($vg['key']) ? 'true' : 'false'"
                                            class="hit-area min-h-[calc(2rem+var(--user-text-boost))] cursor-pointer rounded-md border-0 bg-transparent px-3 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold whitespace-nowrap text-text-muted hover:bg-surface-hover aria-pressed:bg-primary-soft aria-pressed:text-primary-text {{ $focus }}">{{ $vg['label'] }}</button>
                                @endforeach
                            </div>
                            <label class="ms-auto flex min-h-control items-center gap-1.5 rounded-md border border-border-strong px-2.5 focus-within:outline-2 focus-within:outline-offset-1 focus-within:outline-primary-text any-pointer-coarse:min-h-hit">
                                <x-bs-admin::icon name="ri-search" class="{{ $xs }} text-text-muted" />
                                <span class="sr-only">{{ __('admin.mt_var_search_aria') }}</span>
                                <input type="search" x-model="vq" {{-- focus-ring-ok: the search box draws the ring (focus-within) --}} placeholder="{{ __('admin.mt_var_search') }}" class="w-[110px] border-0 bg-transparent p-0 text-[calc(0.78125rem+var(--user-text-boost))] text-text outline-none placeholder:text-placeholder">
                            </label>
                        </div>
                        <div class="flex flex-wrap gap-2 px-3.5 py-3">
                            @foreach ($editor['vars'] as $v)
                                @php $tip = __('admin.mt_var_tip', ['desc' => $v['desc'], 'sample' => $v['sample'] !== '' ? $v['sample'] : $v['token']]); @endphp
                                <span class="inline-flex items-stretch overflow-hidden rounded-2xl border border-border bg-surface-muted hover:border-primary-text"
                                      x-show="(vg === 'all' || vg === @js($v['group'])) && (vq.trim() === '' || @js(mb_strtolower($v['token'].' '.$v['desc'])).includes(vq.trim().toLowerCase()))">
                                    <button type="button" x-on:mousedown.prevent x-on:click="variable(@js($v['token']))" title="{{ $tip }}" aria-label="{{ $v['token'] }} — {{ $tip }}"
                                            class="hit-area cursor-pointer border-0 bg-transparent py-1.5 ps-3 pe-1 font-mono text-[calc(0.75rem+var(--user-text-boost))] font-semibold whitespace-nowrap text-primary-text focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text">{{ $v['token'] }}</button>
                                    @php $copyTip = __($v['link'] ? 'admin.mt_var_copy_link' : 'admin.mt_var_copy', ['token' => $v['token']]); @endphp
                                    <button type="button" x-on:click="copy(@js($v['token']))" title="{{ $copyTip }}" aria-label="{{ $copyTip }}"
                                            class="hit-area inline-flex w-7 cursor-pointer items-center justify-center border-0 border-s border-border bg-transparent text-text-muted hover:bg-primary-soft hover:text-primary-text focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text"
                                            x-bind:class="copied === @js($v['token']) && 'text-success-text'">
                                        <x-bs-admin::icon name="ri-file-copy" class="{{ $xs }}" x-show="copied !== {{ \Illuminate\Support\Js::from($v['token']) }}" />
                                        <x-bs-admin::icon name="ri-check" class="{{ $xs }}" x-show="copied === {{ \Illuminate\Support\Js::from($v['token']) }}" x-cloak />
                                    </button>
                                </span>
                            @endforeach
                        </div>
                        <p class="m-0 px-3.5 pb-3 text-[calc(0.75rem+var(--user-text-boost))] leading-normal text-text-muted">{{ __('admin.mt_var_hint') }}</p>
                    </div>
                </div>
            @endif

            {{-- Xem trước (a «Hệ thống» template shows only this) --}}
            <div id="tpl-pane-preview" @if ($editor['editable']) role="tabpanel" aria-labelledby="tpl-mode-preview" x-show="mode === 'preview'" x-cloak @endif
                 class="rounded-md border border-border-strong bg-bg p-7 @max-[35rem]:p-3">
                <div class="mx-auto max-w-[600px] overflow-hidden rounded-md border border-border-strong bg-surface shadow-card">
                    <div class="border-b border-border bg-surface-muted px-[26px] py-3.5 @max-[35rem]:px-4">
                        <div class="text-[calc(0.8125rem+var(--user-text-boost))] text-text-muted [overflow-wrap:anywhere]">{{ __('admin.mt_from') }} <span class="font-semibold text-text-strong">{{ $fromName }}</span>@if ($fromAddress !== '') &lt;{{ $fromAddress }}&gt;@endif</div>
                        <div class="mt-[3px] text-[calc(0.84375rem+var(--user-text-boost))] font-semibold text-text-strong [overflow-wrap:anywhere]" x-text="pv.subject">{{ $editor['preview']['subject'] ?? '' }}</div>
                    </div>
                    <div class="p-[26px] font-[Arial,Helvetica,sans-serif] text-[calc(1rem+var(--user-text-boost))] leading-normal text-text [overflow-wrap:anywhere] @max-[35rem]:p-4 [&_ol]:list-decimal [&_ol]:ps-6 [&_p]:mb-4 [&_ul]:list-disc [&_ul]:ps-6">
                        @if ($brand['logo'] !== null)
                            <img src="{{ $brand['logo']['url'] }}" alt="{{ $brand['name'] }}" width="{{ $brand['logo']['width'] }}" height="{{ $brand['logo']['height'] }}" class="mb-5 max-w-full">
                        @else
                            <p class="mb-5 font-semibold text-text-strong">{{ $brand['name'] }}</p>
                        @endif
                        <p x-show="pvState === 'wait'" x-cloak class="text-text-muted">{{ __('admin.mt_preview_loading') }}</p>
                        <x-bs-admin::alert color="danger" icon="ri-error-warning" x-show="pvState === 'error'" x-cloak>{{ __('admin.mt_preview_failed') }}</x-bs-admin::alert>
                        <div x-show="pvState === 'ok'" x-html="pv.html">{!! $editor['preview']['html'] ?? '' !!}</div>
                    </div>
                    @if ($brand['signature'] !== '')
                        <div class="border-t border-border bg-surface-muted px-[26px] py-[18px] text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text-muted @max-[35rem]:px-4">{!! $brand['signature'] !!}</div>
                    @endif
                </div>
                <p class="mt-4 mb-0 text-center text-[calc(0.75rem+var(--user-text-boost))] text-text-muted">
                    {{ __('admin.mt_preview_note') }}
                    <button type="button" class="{{ $tlink }} font-semibold" x-on:click="test()" x-bind:disabled="testing">{{ __('admin.mt_test') }}</button>
                </p>
            </div>

            {{-- The link / button box --}}
            <x-bs-admin::modal state="dlg" close="closeDlg()" size="md">
                <x-slot:heading><span x-text="dlg && (dlg.mode === 'button' ? (dlg.edit ? @js(__('admin.mt_btn_edit_title')) : @js(__('admin.mt_btn_insert_title'))) : (dlg.edit ? @js(__('admin.mt_link_edit_title')) : @js(__('admin.mt_link_insert_title'))))">{{ __('admin.mt_link_insert_title') }}</span></x-slot:heading>
                    <div x-show="dlg && dlg.needText" class="flex flex-col gap-1.5">
                        <label for="tpl-link-text" class="text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong"
                               x-text="dlg && dlg.mode === 'button' ? @js(__('admin.mt_btn_text')) : @js(__('admin.mt_link_text'))">{{ __('admin.mt_link_text') }}</label>
                        <input id="tpl-link-text" type="text" x-model="dlgText" placeholder="{{ __('admin.mt_link_text_ph') }}"
                               class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder">
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="tpl-link-url" class="text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong">{{ __('admin.mt_link_url') }}</label>
                        <input id="tpl-link-url" type="text" inputmode="url" x-model="dlgUrl" x-on:keydown.enter.prevent="applyDlg()"
                               x-bind:aria-invalid="dlgError ? 'true' : 'false'" aria-describedby="tpl-link-err"
                               placeholder="{{ __('admin.mt_link_url_ph', ['token' => $cfg['links'][0] ?? '{site_url}']) }}"
                               class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder aria-invalid:border-danger-text">
                        <x-bs-admin::field-error id="tpl-link-err" x-show="dlgError" x-cloak><span x-text="dlgError"></span></x-bs-admin::field-error>
                    </div>
                    @if ($cfg['links'] !== [])
                        <div>
                            <p id="tpl-link-tokens" class="mt-0 mb-2 text-[calc(0.75rem+var(--user-text-boost))] leading-normal text-text-muted">{{ __('admin.mt_link_tokens') }}</p>
                            <div role="group" aria-labelledby="tpl-link-tokens" class="flex flex-wrap gap-2">
                                @foreach ($editor['vars'] as $v)
                                    @if ($v['link'])
                                        <button type="button" x-on:click="dlgUrl = @js($v['token'])" title="{{ $v['desc'] }}" aria-pressed="false" x-bind:aria-pressed="dlgUrl === @js($v['token']) ? 'true' : 'false'"
                                                class="hit-area cursor-pointer rounded-2xl border border-border bg-surface-muted px-3 py-1.5 font-mono text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-primary-text hover:border-primary-text aria-pressed:border-primary-text aria-pressed:bg-primary-soft {{ $focus }}">{{ $v['token'] }}</button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <p x-show="dlg && dlg.mode === 'button'" class="m-0 flex items-start gap-2 text-[calc(0.75rem+var(--user-text-boost))] leading-normal text-text-muted">
                        <x-bs-admin::icon name="ri-palette" class="{{ $xs }} mt-px" />
                        <span>{!! __('admin.mt_btn_color_note', ['tab' => '<b>'.e(__('admin.em_tab_sign')).'</b>']) !!}</span>
                    </p>
                <x-slot:footer>
                    <button type="button" x-show="dlg && dlg.edit" class="{{ $gbtn }} me-auto border-danger-text/45 text-danger-text" x-on:click="removeLink()">
                        <x-bs-admin::icon name="ri-link-unlink" class="{{ $sm }}" /><span x-text="dlg && dlg.mode === 'button' ? @js(__('admin.mt_btn_remove')) : @js(__('admin.mt_link_remove'))">{{ __('admin.mt_link_remove') }}</span>
                    </button>
                    <button type="button" class="{{ $gbtn }}" x-on:click="closeDlg()">{{ __('admin.cancel') }}</button>
                    <x-bs-admin::button type="button" x-on:click="applyDlg()">
                        <x-bs-admin::icon name="ri-link" class="{{ $sm }}" /><span x-text="dlg && dlg.edit ? @js(__('admin.mt_link_update')) : @js(__('admin.mt_link_apply'))">{{ __('admin.mt_link_apply') }}</span>
                    </x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>

            {{-- Unsaved changes before another language or the list --}}
            <div x-show="guard" x-cloak class="sx-udw" x-on:click.self="guard = null" x-on:keydown.escape.stop="guard = null">
                <div class="sx-ud" role="dialog" aria-modal="true" aria-labelledby="tpl-ud-t" aria-describedby="tpl-ud-d" x-trap.noscroll="guard">
                    <h2 id="tpl-ud-t">{{ __('admin.mt_guard_title') }}</h2>
                    <p id="tpl-ud-d" x-text="guard && guard.text"></p>
                    <div class="sx-ud-foot">
                        <button type="button" class="sx-ud-b sx-ud-p" x-on:click="guardSave()" x-bind:aria-busy="busy ? 'true' : 'false'">{{ __('admin.mt_guard_save') }}</button>
                        <button type="button" class="sx-ud-b sx-ud-s" x-on:click="guard = null">{{ __('admin.mt_guard_stay') }}</button>
                        <button type="button" class="sx-ud-b sx-ud-x" x-on:click="guardDiscard()"><x-bs-admin::icon name="ri-error-warning" class="size-[1.15em] shrink-0" />{{ __('admin.mt_guard_discard') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- «Về mẫu gốc» --}}
    <x-bs-admin::confirm-dialog model="resetAsk" :title="$resetAsk === '*' ? __('admin.mt_reset_all_ask') : __('admin.mt_reset_ask', ['name' => $resetAsk !== null && $resetAsk !== '*' ? __('admin.mt_n_'.$resetAsk) : ''])"
                                icon="ri-arrow-go-back" :warning="true" :danger="true" ok-icon="ri-arrow-go-back" :ok="__('admin.mt_reset')" confirm="doReset" cancel="cancelReset">
        <x-bs-admin::confirm-line>{{ $resetAsk === '*' ? __('admin.mt_reset_all_body', ['n' => $editedCount]) : __('admin.mt_reset_body') }}</x-bs-admin::confirm-line>
    </x-bs-admin::confirm-dialog>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</div>
