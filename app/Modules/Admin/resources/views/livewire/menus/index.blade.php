{{--
    Trình đơn (design 11). Everything on the page is drawn by the screen's Alpine part 'bsMenus'
    (resources/js/admin-menus.js, downloaded by the shared lazy loader) from what MenuScreen loads; every change goes through the
    component's methods ($wire.saveDraft, assign, …). Built on the shared admin parts: the item
    tree is <x-bs-admin::tree local> (the draft stays in the browser until "Lưu"), the buttons,
    cards, ⋮ menus, dialogs and the message line are the shared components. Labels, descriptions
    and self-check messages are editor text and are only ever shown as text (x-text), never as HTML.
--}}
@php
    $u = fn (string $key, array $r = []): string => __('menus.ui_'.$key, $r);
    // A text as a JavaScript literal, for Alpine expressions inside component tags.
    $j = fn (string $text): string => \Illuminate\Support\Js::from($text)->toHtml();
    $texts = collect([
        'noName', 'fAll', 'fAllTip', 'fIssue', 'fIssueTip', 'fHidden', 'fHiddenTip', 'fUntr', 'fUntrTip',
        'dEdited', 'dAdded', 'dRemoved', 'dOrder', 'saveN', 'save', 'resetTitle', 'resetBody', 'resetOk',
        'removed', 'removedKids', 'hiddenNow', 'shownNow', 'copyNeedsSave', 'copied', 'noMoveFiltered',
        'mvTop', 'mvTopSub', 'mvHere', 'mvSub', 'mvPick',
        'mvGoTop', 'mvGo', 'movedTo', 'specialMissing', 'added', 'urlBad', 'urlMail', 'urlTel', 'urlHttps',
        'needName', 'staleTitle', 'staleBody', 'staleOk', 'saved', 'netErr', 'leaveTitle', 'leaveBody',
        'leaveSave', 'leaveDrop', 'renamed', 'newMenuName', 'menuCopied', 'delTitle', 'delBodyUsed', 'delBody',
        'delOk', 'menuDeleted', 'removeKidsTitle', 'removeKidsBody', 'removeKidsOk', 'langsToast', 'areaNone', 'areaMain', 'areaSame', 'areasSet', 'areaSaved', 'ptabOn', 'ptabOff', 'codeCopied',
        'checkOk', 'checkTips', 'checkBad', 'checkAndTips', 'trashKids', 'trashDays', 'restoreNeedsSave', 'restored',
        'iconHits', 'descTaken', 'goneTitle', 'goneBody', 'goneKids', 'goneOk', 'goneRemoved',
    ])->mapWithKeys(fn (string $k): array => [$k => $u($k)])->all();
    $texts += [
        'undone' => __('admin.tree.undone'),
        'redone' => __('admin.tree.redone'),
        'tooDeep' => __('admin.tree.too_deep'),
        'kinds' => [
            'page' => __('menus.kind_page'), 'post' => __('menus.kind_post'), 'category' => __('menus.kind_category'),
            'product' => __('menus.kind_product'), 'url' => $u('kind_url'), 'special' => $u('kind_special'),
            'entry' => __('menus.kind_page'), 'taxonomy' => __('menus.kind_category'),
        ],
        'state' => ['unpublished' => $u('state_unpublished'), 'trashed' => $u('state_trashed'), 'gone' => $u('state_gone')],
        'badges' => ['new' => $u('badge_new'), 'hot' => $u('badge_hot'), 'sale' => $u('badge_sale'), 'soon' => $u('badge_soon')],
        'no' => ['gone' => __('admin.tree.gone')],
    ];
    $config = ['initial' => $initial, 'main' => $main, 'icons' => $icons, 'texts' => $texts, 'edit' => $edit];

    // The few looks this screen shares between its own parts (sizes and colours are the shared tokens).
    $head = 'hit-area -mx-4 -my-3 flex min-w-0 flex-1 items-center gap-2 px-4 py-3 text-left';
    $title = 'min-w-0 text-body font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]';
    $chipHide = '@max-[32.5rem]:hidden';
@endphp

<div class="@container" x-ignore wire:ignore.self data-bs-lazy="bsMenus" x-data="bsMenus(@js($config))" x-on:keydown.window="keys($event)">
    {{-- The website icons, drawn once and reused by name (<use>). --}}
    <svg class="hidden" aria-hidden="true">
        @foreach (array_merge(...array_column($icons, 'icons')) as $icon)
            <symbol id="mn-ic-{{ $icon['name'] }}" viewBox="0 0 24 24">
                @if (\App\Modules\Theme\Chrome\StorefrontIcons::GLYPHS[$icon['name']][0] === 'f')
                    <g fill="currentColor">{!! \App\Modules\Theme\Chrome\StorefrontIcons::GLYPHS[$icon['name']][1] !!}</g>
                @else
                    <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! \App\Modules\Theme\Chrome\StorefrontIcons::GLYPHS[$icon['name']][1] !!}</g>
                @endif
            </symbol>
        @endforeach
    </svg>

    <div class="mx-auto flex w-full max-w-[1400px] flex-col gap-4">
        <x-bs-admin::page-header :title="__('menus.title')" :subtitle="$u('intro')" class="!mb-0">
            <x-slot:breadcrumbs>{{ $u('crumb') }}</x-slot:breadcrumbs>
            <x-slot:actions>
                <x-bs-admin::button variant="outline" color="secondary" :href="$siteUrl" :navigate="false" target="_blank" rel="noopener">
                    <x-bs-admin::icon name="ri-external-link" class="size-4" />{{ $u('open_site') }}
                </x-bs-admin::button>
                <x-bs-admin::button disabled x-on:click="save()" x-bind:disabled="!dirty().n || saveBusy || screen !== 'data'"
                    x-bind:title="dirty().n ? dirty().text : {{ $j($u('nothing_to_save')) }}">
                    <x-bs-admin::icon name="ri-save-3" class="size-4" /><span x-text="saveBusy ? {{ $j($u('saving')) }} : saveLabel">{{ $u('save') }}</span>
                </x-bs-admin::button>
            </x-slot:actions>
        </x-bs-admin::page-header>

        {{-- Loading: also what shows while the screen's part downloads (admin-lazy.js) --}}
        <x-bs-admin::card flush data-bs-lazy-wait aria-busy="true" aria-label="{{ $u('loading') }}" x-show="screen === 'loading'">
            <div class="flex flex-wrap items-center gap-2 border-b border-border px-3.5 py-2.5">
                <span class="h-[38px] max-w-[360px] flex-[1_1_190px] animate-pulse rounded bg-surface-hover"></span>
                @foreach (range(1, 3) as $i)
                    <span class="h-8 w-[84px] animate-pulse rounded bg-surface-hover"></span>
                @endforeach
            </div>
            @foreach ([0, 0, 1, 1, 0] as $i => $deep)
                <div class="flex min-h-11 items-center gap-2.5 py-1 pr-3.5 {{ $deep ? 'pl-9' : 'pl-3.5' }} {{ $i ? 'border-t border-border-soft' : '' }}">
                    <span class="size-[18px] animate-pulse rounded bg-surface-hover"></span>
                    <span class="h-3.5 max-w-[220px] flex-auto animate-pulse rounded bg-surface-hover"></span>
                    <span class="ml-auto h-3.5 w-14 animate-pulse rounded bg-surface-hover"></span>
                </div>
            @endforeach
            <p role="status" class="border-t border-border-soft px-3.5 py-2.5 text-caption text-text-muted">{{ $u('loading_text') }}</p>
        </x-bs-admin::card>

        {{-- The screen's part could not be downloaded: the same message, shown by the shared CSS before any Alpine runs;
             "Thử lại" downloads it again (the shared loader). Hidden for good once the part has started. --}}
        <div x-show="false">
            <x-bs-admin::card data-bs-lazy-error>
                <div class="flex flex-col items-center gap-2.5 px-1 py-4 text-center">
                    <x-bs-admin::icon name="ri-wifi-off" class="size-6 text-danger-text" />
                    <h2 class="text-body font-semibold text-text-strong">{{ $u('error_title') }}</h2>
                    <p role="alert" class="max-w-[420px] text-small leading-relaxed text-text">{{ $u('error_text') }}</p>
                    <x-bs-admin::button data-bs-lazy-retry><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ $u('retry') }}</x-bs-admin::button>
                </div>
            </x-bs-admin::card>
        </div>

        {{-- Could not load --}}
        <x-bs-admin::card x-cloak x-show="screen === 'error'">
            <div class="flex flex-col items-center gap-2.5 px-1 py-4 text-center">
                <x-bs-admin::icon name="ri-wifi-off" class="size-6 text-danger-text" />
                <h2 class="text-body font-semibold text-text-strong">{{ $u('error_title') }}</h2>
                <p role="alert" class="max-w-[420px] text-small leading-relaxed text-text">{{ $u('error_text') }}</p>
                <x-bs-admin::button x-on:click="reload()"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ $u('retry') }}</x-bs-admin::button>
            </div>
        </x-bs-admin::card>

        <template x-if="screen === 'data'">
        <div class="flex flex-col gap-4">
            {{-- Saving failed --}}
            <x-bs-admin::alert color="danger" icon="ri-error-warning" x-show="saveErr">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="min-w-0 flex-[1_1_240px]">{{ $u('save_error') }}</span>
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="save()"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ $u('retry') }}</x-bs-admin::button>
                </div>
            </x-bs-admin::alert>

            {{-- Everything that edits; inert with a busy look while "Lưu" runs, so nothing typed then is lost --}}
            <div class="flex flex-col gap-4 transition-opacity" x-bind:inert="saveBusy" x-bind:aria-busy="saveBusy ? 'true' : 'false'" x-bind:class="saveBusy && 'opacity-60'">

            {{-- Language being edited --}}
            <x-bs-admin::card flush x-show="multi">
                <div class="flex flex-wrap items-center gap-2.5 px-3 py-2">
                    <span title="{{ $u('lang_tip') }}" class="shrink-0 text-text-muted"><x-bs-admin::icon name="ri-translate-2" class="size-5" /></span>
                    <label class="flex min-w-0 flex-auto items-center gap-1.5 @min-[35.0625rem]:hidden">
                        <span class="sr-only">{{ $u('lang_pick') }}</span>
                        <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted w-auto min-w-0 flex-auto" x-model="loc" x-on:change="pickLoc(loc)">
                            <template x-for="l in d.locales" :key="l.code"><option :value="l.code" x-text="l.name + (l.main ? ' ★' : '')"></option></template>
                        </select>
                    </label>
                    <x-bs-admin::button variant="outline" color="secondary" class="@min-[35.0625rem]:hidden" x-on:click="notify({{ $j($u('langsToast')) }}.replace(':n', d.locales.length))" title="{{ $u('langs_tip') }}" aria-label="{{ $u('langs_tip') }}">
                        <x-bs-admin::icon name="ri-list-check-2" class="size-4" /><span class="@max-[23.75rem]:hidden">{{ $u('langs') }}</span>
                    </x-bs-admin::button>
                    <div class="hidden min-w-0 flex-wrap items-center gap-1.5 @min-[35.0625rem]:flex" role="group" aria-label="{{ $u('lang_pick') }}">
                        <template x-for="l in d.locales" :key="l.code">
                            <x-bs-admin::button variant="outline" color="secondary" x-on:click="pickLoc(l.code)" x-bind:aria-pressed="loc === l.code"
                                x-bind:title="langStat(l.code).done + '/' + langStat(l.code).total + ' ' + {{ $j($u('labels_done')) }}">
                                <span class="size-2 rounded-full" x-bind:class="langStat(l.code).done === langStat(l.code).total ? 'bg-success' : 'bg-warning'" aria-hidden="true"></span>
                                <span x-text="l.name"></span>
                                <template x-if="l.main"><span class="text-warning-text" aria-label="{{ $u('lang_main') }}"><x-bs-admin::icon name="ri-star-fill" class="size-3.5" /></span></template>
                            </x-bs-admin::button>
                        </template>
                    </div>
                    <span class="ml-auto hidden items-center gap-1.5 whitespace-nowrap text-caption text-text-muted @min-[52.5625rem]:inline-flex">
                        <span :class="langStat(loc).done === langStat(loc).total ? 'text-success-text' : 'text-warning-text'">
                            <x-bs-admin::icon name="ri-check" class="size-4" x-show="langStat(loc).done === langStat(loc).total" /><x-bs-admin::icon name="ri-time" class="size-4" x-show="langStat(loc).done !== langStat(loc).total" />
                        </span>
                        <span x-text="langStat(loc).done + '/' + langStat(loc).total + ' ' + @js($u('labels_done'))"></span>
                    </span>
                </div>
                <div class="border-t border-border-soft">
                    @foreach (['info' => 'onMain', 'success' => '!onMain && langStat(loc).done === langStat(loc).total', 'warning' => '!onMain && langStat(loc).done !== langStat(loc).total'] as $tone => $when)
                        <x-bs-admin::alert :color="$tone" icon="ri-information" class="!rounded-none !py-2 text-caption leading-relaxed" x-show="{{ $when }}">
                            <span x-text="(onMain ? @js($u('note_main')) : (langStat(loc).done === langStat(loc).total ? @js($u('note_full')) : @js($u('note_part')).replace(':n', langStat(loc).total - langStat(loc).done))).replace(':lang', locName(loc))"></span>
                        </x-bs-admin::alert>
                    @endforeach
                </div>
            </x-bs-admin::card>

            {{-- Self-check --}}
            <x-bs-admin::card flush>
                <x-slot:header>
                    <button type="button" class="{{ $head }}" x-on:click="fold2.check = !fold2.check" :aria-expanded="!fold2.check">
                        <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.check && '-rotate-90'" />
                        <span class="shrink-0" :class="issues.some(q => q.level !== 'tip') ? 'text-warning-text' : (issues.length ? 'text-info-text' : 'text-success-text')">
                            <x-bs-admin::icon name="ri-shield-check" class="size-4" />
                        </span>
                        <span class="{{ $title }}" x-text="checkTitle"></span>
                        <span class="ml-auto hidden text-right text-caption text-text-muted @min-[38.8125rem]:inline">{{ $u('check_sub') }}</span>
                        <span class="ml-auto shrink-0 px-1 text-small font-semibold text-primary-text underline @min-[38.8125rem]:ml-3" x-text="fold2.check ? @js($u('see')) : @js($u('less'))">{{ $u('see') }}</span>
                    </button>
                </x-slot:header>
                <div x-show="!fold2.check">
                    <p x-show="!issues.length" class="px-4 py-3 text-small leading-relaxed text-text-muted">{{ $u('check_none') }}</p>
                    <template x-for="(q, i) in shownIssues()" :key="i">
                        <div class="flex flex-wrap items-center gap-2.5 border-t border-border-soft px-4 py-2.5 first:border-t-0">
                            <span class="shrink-0" :class="q.level === 'tip' ? 'text-info-text' : 'text-warning-text'"><x-bs-admin::icon name="ri-error-warning" class="size-4" /></span>
                            <span class="min-w-0 flex-[1_1_260px] text-small leading-relaxed text-text" x-text="q.message"></span>
                            <template x-if="q.item && item(q.item)">
                                <span class="flex flex-wrap gap-2">
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="goIssue(q)"><x-bs-admin::icon name="ri-focus-3" class="size-4" />{{ $u('see_item') }}</x-bs-admin::button>
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="remove(q.item)"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ $u('remove') }}</x-bs-admin::button>
                                </span>
                            </template>
                        </div>
                    </template>
                    <div x-show="issues.length > 3" class="border-t border-border-soft px-4 py-2">
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="issuesAll = !issuesAll">
                            <span x-text="issuesAll ? {{ $j($u('less')) }} : {{ $j($u('more')) }}.replace(':n', issues.length - 3)"></span>
                        </x-bs-admin::button>
                    </div>
                </div>
            </x-bs-admin::card>

            <div class="grid grid-cols-[262px_minmax(0,1fr)_288px] items-start gap-4 @max-[75.625rem]:grid-cols-[262px_minmax(0,1fr)] @max-[53.75rem]:flex @max-[53.75rem]:flex-col @max-[53.75rem]:items-stretch">
                {{-- Left column: menus, areas, what the system does --}}
                <div class="flex flex-col gap-4 @max-[53.75rem]:contents">
                    <x-bs-admin::card flush class="@max-[53.75rem]:order-1">
                        <x-slot:header>
                            <button type="button" class="{{ $head }}" x-on:click="fold2.menus = !fold2.menus" :aria-expanded="!fold2.menus">
                                <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.menus && '-rotate-90'" />
                                <span class="{{ $title }}">{{ __('menus.title') }}</span>
                                <span class="ml-auto text-caption text-text-muted" x-text="@js($u('menu_count')).replace(':n', d.menus.length)"></span>
                            </button>
                        </x-slot:header>
                        <div x-show="!fold2.menus">
                            <template x-for="m in menuRows()" :key="m.key">
                                <div class="relative flex cursor-pointer flex-col gap-1 border-t border-border-soft px-3 py-2.5 first:border-t-0 hover:bg-surface-muted"
                                     :class="m.on && 'bg-primary-soft shadow-[inset_3px_0_0_var(--color-primary-text)]'" x-on:click="selectMenu(m.key)">
                                    <div class="flex items-start gap-2">
                                        <button type="button" class="hit-area min-h-6 min-w-0 flex-auto text-left text-small font-semibold leading-snug [overflow-wrap:anywhere]"
                                                :class="m.on ? 'text-primary-text' : 'text-text-strong'" :aria-current="m.on && 'true'" x-text="m.name"></button>
                                        <span x-on:click.stop class="@min-[53.8125rem]:hidden">
                                            <x-bs-admin::list.actions name="" name-bind="m.name" :title="$u('more_actions')">
                                                <x-slot:menu>
                                                    <x-bs-admin::list.menu-item icon="ri-edit" x-on:click="startRename(m)">{{ $u('rename') }}</x-bs-admin::list.menu-item>
                                                    <x-bs-admin::list.menu-item icon="ri-file-copy" x-on:click="dupMenu(m)">{{ $u('dup_menu') }}</x-bs-admin::list.menu-item>
                                                    <x-bs-admin::list.menu-sep />
                                                    <x-bs-admin::list.menu-item icon="ri-delete-bin" danger x-on:click="m.use.length || delMenu(m)" x-bind:aria-disabled="m.use.length ? 'true' : null">{{ $u('del_menu') }}</x-bs-admin::list.menu-item>
                                                    <span class="list-mwhy" x-show="m.use.length" x-text="{{ $j($u('del_used')) }}.replace(':name', m.name).replace(':where', m.use.join(', '))"></span>
                                                </x-slot:menu>
                                            </x-bs-admin::list.actions>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="min-w-0 flex-auto text-caption text-text-muted" x-text="m.on ? @js($u('items_n')).replace(':n', items.length) : ''"></span>
                                        <span class="hidden items-center gap-2 @min-[53.8125rem]:flex" x-on:click.stop>
                                            <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-on:click="startRename(m)" title="{{ $u('rename_tip') }}" aria-label="{{ $u('rename_tip') }}"><x-bs-admin::icon name="ri-edit" class="size-4" /></x-bs-admin::button>
                                            <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-on:click="dupMenu(m)" x-bind:title="{{ $j($u('dup_tip')) }}.replace(':name', m.name)" x-bind:aria-label="{{ $j($u('dup_tip')) }}.replace(':name', m.name)"><x-bs-admin::icon name="ri-file-copy" class="size-4" /></x-bs-admin::button>
                                            <x-bs-admin::button size="sm" variant="ghost" color="danger" icon x-on:click="delMenu(m)" x-bind:disabled="m.use.length > 0"
                                                x-bind:title="m.use.length ? {{ $j($u('del_used')) }}.replace(':name', m.name).replace(':where', m.use.join(', ')) : {{ $j($u('del_tip')) }}.replace(':name', m.name)" x-bind:aria-label="{{ $j($u('del_tip')) }}.replace(':name', m.name)"><x-bs-admin::icon name="ri-delete-bin" class="size-4" /></x-bs-admin::button>
                                        </span>
                                    </div>
                                    <template x-if="renaming === m.key">
                                        <div x-on:click.stop>
                                            <x-bs-admin::input id="mn-rename" x-model="renameVal" x-on:keydown.enter.prevent="doneRename()" x-on:blur="doneRename()" placeholder="{{ $u('menu_name') }}" aria-label="{{ $u('menu_name') }}" maxlength="120" />
                                        </div>
                                    </template>
                                    <span class="flex items-start gap-1.5 text-caption leading-normal" :class="m.use.length ? 'text-success-text' : 'text-warning-text'">
                                        <span class="mt-0.5 shrink-0"><x-bs-admin::icon name="ri-checkbox-circle" class="size-3.5" /></span>
                                        <span x-text="m.use.length ? @js($u('used_in')).replace(':where', m.use.join(' · ')) : @js($u('unused'))"></span>
                                    </span>
                                </div>
                            </template>
                            <div class="border-t border-border-soft px-3 py-2.5">
                                <x-bs-admin::button variant="outline" color="secondary" class="w-full" x-on:click="newMenu()"><x-bs-admin::icon name="ri-add" class="size-4" />{{ $u('new_menu') }}</x-bs-admin::button>
                            </div>
                        </div>
                    </x-bs-admin::card>

                    <x-bs-admin::card flush class="@max-[53.75rem]:order-3">
                        <x-slot:header>
                            <button type="button" class="{{ $head }}" x-on:click="fold2.areas = !fold2.areas" :aria-expanded="!fold2.areas">
                                <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.areas && '-rotate-90'" />
                                <span class="{{ $title }}">{{ $u('areas') }}</span>
                                <span class="ml-auto text-right text-caption text-text-muted" x-text="areasSet"></span>
                            </button>
                        </x-slot:header>
                        <div x-show="!fold2.areas">
                            <p class="border-b border-border-soft px-3.5 py-2.5 text-caption leading-relaxed text-text-muted" x-text="multi ? @js($u('areas_intro_multi')) : @js($u('areas_intro'))"></p>
                            <template x-for="a in areaRows()" :key="a.key">
                                <div>
                                    <div x-show="a.head" class="border-t border-border-soft px-3.5 pb-1 pt-2.5 text-caption font-bold uppercase tracking-wider text-text-muted" x-text="a.group"></div>
                                    <div class="flex flex-col gap-1.5 px-3.5 pb-2.5 pt-1">
                                        <span class="flex items-center gap-1.5 text-caption font-semibold text-text-strong" :title="a.help">
                                            <span class="text-text-muted"><x-bs-admin::icon name="ri-layout-top" class="size-4" /></span>
                                            <span class="min-w-0 [overflow-wrap:anywhere]" x-text="a.label"></span>
                                        </span>
                                        <template x-if="devOn">
                                            <x-bs-admin::button size="sm" variant="outline" color="secondary" class="self-start font-mono" x-on:click="copyCode(a.key)" title="{{ $u('copy_code') }}">
                                                <span x-text="a.key"></span><x-bs-admin::icon name="ri-file-copy" class="size-3.5" />
                                            </x-bs-admin::button>
                                        </template>
                                        <label>
                                            <span class="sr-only" x-text="a.label"></span>
                                            <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" :title="a.help" x-on:change="setArea(a, @js($main), $event.target.value)">
                                                <template x-for="o in areaOptions(a, @js($main))" :key="o.v"><option :value="o.v" x-text="o.l" :selected="o.v === areaValue(a, @js($main))"></option></template>
                                            </select>
                                        </label>
                                        <template x-if="a.key === 'handheld' && d.productsTab.available">
                                            <div class="mn-ptab mt-1 flex min-w-0 items-start gap-3 rounded border border-border px-3 py-2.5">
                                                <span class="flex min-w-0 flex-1 flex-col">
                                                    <span id="mn-ptab-l" class="text-caption font-semibold leading-normal text-text-strong">{{ $u('ptab_label') }}</span>
                                                    <span id="mn-ptab-h" class="mt-0.5 text-caption leading-relaxed text-text-muted [overflow-wrap:anywhere] [text-wrap:pretty]">{{ $u('ptab_help') }}</span>
                                                </span>
                                                <button type="button" class="switch hit-area" role="switch" :aria-checked="d.productsTab.on ? 'true' : 'false'" :aria-disabled="ptabBusy ? 'true' : null" aria-labelledby="mn-ptab-l" aria-describedby="mn-ptab-h" x-on:click="setProductsTab()"></button>
                                            </div>
                                        </template>
                                        <template x-if="multi">
                                            <div class="mt-0.5 flex flex-col divide-y divide-border rounded border border-border bg-surface-muted px-2.5">
                                                <template x-for="l in d.locales.filter(x => !x.main)" :key="l.code">
                                                    <div class="flex min-w-0 flex-col gap-1.5 py-2">
                                                        <span class="flex flex-wrap items-center gap-1.5">
                                                            <span class="text-caption font-semibold text-text-strong" x-text="l.name"></span>
                                                            <span x-show="!areaValue(a, l.code)" class="text-caption text-text-muted">{{ $u('area_follows_main') }}</span>
                                                        </span>
                                                        <label>
                                                            <span class="sr-only" x-text="a.label + ' — ' + l.name"></span>
                                                            <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" x-on:change="setArea(a, l.code, $event.target.value)">
                                                                <template x-for="o in areaOptions(a, l.code)" :key="o.v"><option :value="o.v" x-text="o.l" :selected="o.v === areaValue(a, l.code)"></option></template>
                                                            </select>
                                                        </label>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <div class="flex flex-col gap-2 border-t border-border-soft px-3 pb-3 pt-2">
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" class="w-full" x-show="d.areas.some(a => a.advanced)" x-on:click="advOn = !advOn"
                                    ><span x-text="advOn ? {{ $j($u('adv_hide')) }} : {{ $j($u('adv_show')) }}">{{ $u('adv_show') }}</span></x-bs-admin::button>
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" class="w-full" x-on:click="devOn = !devOn" title="{{ $u('dev_tip') }}">
                                    <x-bs-admin::icon name="ri-code-s-slash" class="size-4" /><span x-text="devOn ? {{ $j($u('dev_hide')) }} : {{ $j($u('dev_show')) }}"></span>
                                </x-bs-admin::button>
                                <p x-show="devOn" class="text-caption leading-relaxed text-text-muted">{{ $u('dev_foot') }}</p>
                            </div>
                        </div>
                    </x-bs-admin::card>

                    <x-bs-admin::card flush class="@max-[53.75rem]:order-3" x-show="menu">
                        <x-slot:header>
                            <button type="button" class="{{ $head }}" x-on:click="fold2.auto = !fold2.auto" :aria-expanded="!fold2.auto">
                                <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.auto && '-rotate-90'" />
                                <span class="{{ $title }}">{{ $u('auto') }}</span>
                                <span class="ml-auto shrink-0 text-caption text-text-muted" x-text="@js($u('auto_n')).replace(':n', menu && menu.autoAdd ? 4 : 3)"></span>
                            </button>
                        </x-slot:header>
                        <div x-show="!fold2.auto" class="flex flex-col gap-1 px-3 pb-3 pt-2">
                            <button type="button" role="switch" class="switch-row" :aria-checked="!!(menu && menu.autoAdd)" x-on:click="setAutoAdd()">
                                <span class="text-small text-text-strong">{{ $u('auto_add') }}</span>
                            </button>
                            <span class="-mt-0.5 mb-1.5 pl-12 text-caption leading-relaxed text-text-muted" x-text="menu && menu.autoAdd ? @js($u('auto_on')) : @js($u('auto_off'))"></span>
                            <span class="flex flex-col gap-2 rounded bg-surface-muted px-3 py-2.5">
                                @foreach (['auto_fix' => null, 'auto_hide' => 'issues', 'auto_undo' => 'trash'] as $line => $count)
                                    <span class="flex items-start gap-2">
                                        <span class="mt-0.5 shrink-0 text-success-text"><x-bs-admin::icon name="ri-checkbox-circle" class="size-3.5" /></span>
                                        <span class="flex min-w-0 flex-col text-caption leading-normal">
                                            <span class="text-text">{{ $u($line) }}</span>
                                            @if ($count === 'issues')
                                                <span class="text-text-muted" x-text="issues.filter(q => q.item).length ? @js($u('auto_hide_n')).replace(':n', issues.filter(q => q.item).length) : @js($u('auto_hide_0'))"></span>
                                            @elseif ($count === 'trash')
                                                <span class="text-text-muted" x-text="menu && menu.trash.length ? @js($u('auto_trash_n')).replace(':n', menu.trash.length) : @js($u('auto_trash_0'))"></span>
                                            @else
                                                <span class="text-text-muted" x-text="fixedN ? @js($u('auto_fix_n')).replace(':n', fixedN) : @js($u('auto_fix_0'))"></span>
                                            @endif
                                        </span>
                                    </span>
                                @endforeach
                            </span>
                        </div>
                    </x-bs-admin::card>
                </div>

                {{-- Middle column: the tree and the preview --}}
                <div class="flex min-w-0 flex-col gap-4 @max-[53.75rem]:order-2">
                    <template x-if="!menu">
                        <x-bs-admin::card>
                            <div class="flex flex-col items-center gap-2 px-1 py-5 text-center">
                                <span class="text-text-faint"><x-bs-admin::icon name="ri-node-tree" class="size-7" /></span>
                                <span class="text-small font-semibold text-text">{{ $u('no_menu_title') }}</span>
                                <span class="max-w-[340px] text-caption leading-relaxed text-text-muted">{{ $u('no_menu_text') }}</span>
                                <x-bs-admin::button variant="outline" color="secondary" x-on:click="newMenu()"><x-bs-admin::icon name="ri-add" class="size-4" />{{ $u('new_menu') }}</x-bs-admin::button>
                            </div>
                        </x-bs-admin::card>
                    </template>
                    <template x-if="menu">
                    <x-bs-admin::card flush>
                        <x-slot:header>
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                <span class="{{ $title }}" x-text="menu.name"></span>
                                <x-bs-admin::badge soft pill color="secondary"><span x-text="@js($u('items_n')).replace(':n', items.length)"></span></x-bs-admin::badge>
                                <x-bs-admin::badge soft pill color="secondary" x-bind:title="{{ $j($u('depth_tip')) }}.replace(':n', maxDepth)"><x-bs-admin::icon name="ri-node-tree" class="size-3.5" /><span x-text="@js($u('depth_n')).replace(':n', maxDepth)"></span></x-bs-admin::badge>
                                <span class="ml-auto flex shrink-0 items-center gap-1.5">
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-show="menu.trash.length" x-on:click="trashOpen = !trashOpen" title="{{ $u('trash_tip') }}"
                                        x-bind:aria-label="{{ $j($u('trash_aria')) }}.replace(':n', menu.trash.length)" x-bind:aria-expanded="trashOpen">
                                        <x-bs-admin::icon name="ri-delete-bin" class="size-4" /><span class="@max-[23.75rem]:hidden">{{ $u('trash') }}</span><span x-text="menu.trash.length"></span>
                                    </x-bs-admin::button>
                                    <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-on:click="undo()" x-bind:disabled="!histN"
                                        x-bind:title="histN ? {{ $j($u('undo_tip')) }} : {{ $j($u('undo_none')) }}" x-bind:aria-label="histN ? {{ $j($u('undo_tip')) }} : {{ $j($u('undo_none')) }}"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /></x-bs-admin::button>
                                    <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-on:click="redo()" x-bind:disabled="!futN"
                                        x-bind:title="futN ? {{ $j($u('redo_tip')) }} : {{ $j($u('redo_none')) }}" x-bind:aria-label="futN ? {{ $j($u('redo_tip')) }} : {{ $j($u('redo_none')) }}"><x-bs-admin::icon name="ri-arrow-go-forward" class="size-4" /></x-bs-admin::button>
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" class="@max-[37.4375rem]:hidden" x-on:click="foldRows()" x-bind:title="(anyShut ? {{ $j($u('open_all')) }} : {{ $j($u('close_all')) }}) + ' — ' + {{ $j($u('fold_tip')) }}">
                                        <x-bs-admin::icon name="ri-node-tree" class="size-4" /><span x-text="anyShut ? {{ $j($u('open_all')) }} : {{ $j($u('close_all')) }}">{{ $u('close_all') }}</span>
                                    </x-bs-admin::button>
                                </span>
                            </div>
                        </x-slot:header>

                        {{-- Trash --}}
                        <div x-show="trashOpen" class="border-b border-border bg-surface-muted">
                            <div class="flex items-center gap-2 px-3.5 pb-1.5 pt-2.5">
                                <span class="text-text-muted"><x-bs-admin::icon name="ri-delete-bin" class="size-4" /></span>
                                <span class="flex-auto text-caption leading-normal text-text-muted">{{ $u('trash_note') }}</span>
                                <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-on:click="trashOpen = false" title="{{ $u('trash_close') }}" aria-label="{{ $u('trash_close') }}"><x-bs-admin::icon name="ri-close" class="size-4" /></x-bs-admin::button>
                            </div>
                            <template x-for="r in menu.trash" :key="r.key">
                                <div class="flex items-center gap-2 border-t border-border-soft px-3.5 py-2">
                                    <span class="flex min-w-0 flex-auto flex-col gap-0.5">
                                        <span class="text-small leading-snug text-text-strong [overflow-wrap:anywhere]" x-text="r.label || @js($u('noName'))"></span>
                                        <span class="text-caption text-text-muted" x-text="trashMeta(r)"></span>
                                    </span>
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="restore(r)"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />{{ $u('restore') }}</x-bs-admin::button>
                                </div>
                            </template>
                        </div>

                        {{-- Search and filters --}}
                        <div class="flex flex-wrap items-center gap-2 border-b border-border px-3.5 py-2.5">
                            <div class="min-w-0 flex-[1_1_190px]">
                                <x-bs-admin::search :label="$u('tree_search')" :placeholder="$u('tree_search_ph')" x-model="q" />
                            </div>
                            <span class="hidden flex-wrap gap-2 @min-[37.5rem]:flex" role="group" aria-label="{{ $u('filter') }}">
                                <template x-for="f in filters()" :key="f.k">
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-bind:aria-pressed="filter === f.k" x-bind:title="f.title" x-on:click="filter = f.k; sel = null">
                                        @foreach (['all' => 'ri-list-check-2', 'issue' => 'ri-information', 'hidden' => 'ri-eye-off', 'untr' => 'ri-translate-2'] as $fk => $fic)
                                            <span x-show="f.k === '{{ $fk }}'"><x-bs-admin::icon :name="$fic" class="size-4" /></span>
                                        @endforeach
                                        <span x-text="f.l + ' ' + f.n"></span>
                                    </x-bs-admin::button>
                                </template>
                            </span>
                            <label class="@min-[37.5rem]:hidden">
                                <span class="sr-only">{{ $u('filter') }}</span>
                                <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted w-auto" x-model="filter">
                                    <template x-for="f in filters()" :key="f.k"><option :value="f.k" x-text="f.l + ' ' + f.n" :selected="filter === f.k"></option></template>
                                </select>
                            </label>
                            <x-bs-admin::button variant="outline" color="secondary" class="@min-[37.5rem]:hidden" x-on:click="foldRows()" title="{{ $u('fold_tip') }}">
                                <x-bs-admin::icon name="ri-node-tree" class="size-4" /><span x-text="anyShut ? {{ $j($u('open_all')) }} : {{ $j($u('close_all')) }}">{{ $u('close_all') }}</span>
                            </x-bs-admin::button>
                        </div>
                        <div x-show="filtering" class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-3.5 py-2">
                            <span class="text-text-muted"><x-bs-admin::icon name="ri-filter-3" class="size-4" /></span>
                            <span class="min-w-0 flex-[1_1_220px] text-caption leading-normal text-text-muted" x-text="@js($u('filter_note')).replace(':n', rows().length)"></span>
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" x-show="filter === 'issue' && goneKeys.length >= 2" x-on:click="removeGone()" title="{{ $u('gone_bulk_tip') }}">
                                <x-bs-admin::icon name="ri-delete-bin" class="size-4" /><span x-text="@js($u('gone_bulk')).replace(':n', goneKeys.length)"></span>
                            </x-bs-admin::button>
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="q = ''; filter = 'all'">{{ $u('clear_filter') }}</x-bs-admin::button>
                        </div>
                        <div x-show="!rows().length" class="flex flex-col items-center gap-2 px-5 py-9 text-center">
                            <span class="text-text-faint"><x-bs-admin::icon name="ri-node-tree" class="size-7" /></span>
                            <span class="text-small font-semibold text-text" x-text="filtering ? @js($u('empty_filter_title')) : @js($u('empty_title'))"></span>
                            <span class="max-w-[340px] text-caption leading-relaxed text-text-muted" x-text="filtering ? @js($u('empty_filter_text')) : @js($u('empty_text'))"></span>
                        </div>

                        {{-- The rows: the shared tree, drawn from the draft --}}
                        <x-bs-admin::tree id="mn-tree" local move="treeMove" :label="$u('tree_label')" x-bind:data-max-depth="maxDepth" x-init="treeOn++">
                            <template x-for="r in rows()" :key="r.k">
                                <x-bs-admin::tree.row row="r" drag-off="filtering || sel === r.k" x-bind:class="sel === r.k && '!bg-primary-soft'">
                                    <span class="flex min-w-0 items-center gap-2">
                                    <span class="shrink-0 self-center text-text-muted">
                                        <template x-if="r.it.options.icon"><svg class="size-4" aria-hidden="true"><use :href="'#mn-ic-' + r.it.options.icon"></use></svg></template>
                                        <template x-if="!r.it.options.icon"><span>
                                            <span x-show="kind(r.it) === 'url'"><x-bs-admin::icon name="ri-links" class="size-4" /></span>
                                            <span x-show="kind(r.it) === 'special'"><x-bs-admin::icon name="ri-star" class="size-4" /></span>
                                            <span x-show="kind(r.it) === 'category'"><x-bs-admin::icon name="ri-price-tag-3" class="size-4" /></span>
                                            <span x-show="kind(r.it) === 'product'"><x-bs-admin::icon name="ri-box-3" class="size-4" /></span>
                                            <span x-show="kind(r.it) === 'page' || kind(r.it) === 'post'"><x-bs-admin::icon name="ri-file-text" class="size-4" /></span>
                                        </span></template>
                                    </span>
                                    <x-bs-admin::tree.name x-on:click="pick(r.k)" x-bind:aria-expanded="sel === r.k" title="{{ $u('edit_tip') }}"
                                        x-bind:class="r.it.options.hidden ? '!font-normal !text-text-muted line-through' : (r.lv === 1 ? '' : '!font-normal !text-text')" x-text="r.name"></x-bs-admin::tree.name>
                                    </span>
                                    <span class="flex min-w-0 flex-wrap items-center gap-1.5 self-center">
                                        <span x-show="filtering && r.it.parent" class="text-caption text-text-muted [overflow-wrap:anywhere]" x-text="r.it.parent ? '← ' + name(item(r.it.parent)) : ''"></span>
                                        <x-bs-admin::badge soft pill color="warning" x-show="r.unsaved" title="{{ $u('unsaved_tip') }}"><span class="size-1.5 rounded-full bg-current"></span><span class="{{ $chipHide }}">{{ $u('unsaved') }}</span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="secondary" x-show="r.it.options.hidden" title="{{ $u('hidden_tip') }}"><x-bs-admin::icon name="ri-eye-off" class="size-3.5" /><span class="{{ $chipHide }}">{{ $u('hidden') }}</span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="primary" x-show="r.it.options.cta" title="{{ $u('cta_tip') }}"><x-bs-admin::icon name="ri-cursor" class="size-3.5" /><span class="{{ $chipHide }}">{{ $u('cta') }}</span></x-bs-admin::badge>
                                        <x-bs-admin::badge pill color="danger" x-show="r.it.options.badge"><span x-text="badgeText(r.it)"></span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="danger" x-show="r.deep" x-bind:title="{{ $j($u('deep_tip')) }}.replace(':n', maxDepth)"><x-bs-admin::icon name="ri-node-tree" class="size-3.5" /><span class="{{ $chipHide }}">{{ $u('deep') }}</span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="primary" x-show="r.it.options.mega" title="{{ $u('mega_tip') }}"><x-bs-admin::icon name="ri-layout-grid" class="size-3.5" /><span class="{{ $chipHide }}" x-text="r.it.options.mega ? {{ $j($u('mega_n')) }}.replace(':n', r.it.options.mega.columns) : ''"></span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="secondary" x-show="desc(r.it)" x-bind:title="desc(r.it)"><x-bs-admin::icon name="ri-text" class="size-3.5" /><span class="{{ $chipHide }}">{{ $u('has_desc') }}</span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="secondary" x-show="r.it.options.device !== 'all'"><x-bs-admin::icon name="ri-device" class="size-3.5" /><span class="{{ $chipHide }}" x-text="r.it.options.device === 'desktop' ? {{ $j($u('only_pc')) }} : {{ $j($u('only_phone')) }}"></span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="success" x-show="r.it.source && r.it.source.moved && !r.state" title="{{ $u('fixed_tip') }}"><x-bs-admin::icon name="ri-check" class="size-3.5" /><span class="{{ $chipHide }}">{{ $u('fixed') }}</span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="warning" x-show="r.state === 'unpublished' || (r.untr && !r.state)" x-bind:title="r.state ? r.stateLabel : {{ $j($u('untr_tip')) }}"><x-bs-admin::icon name="ri-eye-off" class="size-3.5" /><span class="{{ $chipHide }}" x-text="r.state ? r.stateLabel : {{ $j($u('untr')) }}"></span></x-bs-admin::badge>
                                        <x-bs-admin::badge soft pill color="danger" x-show="r.state === 'trashed' || r.state === 'gone'" x-bind:title="r.stateLabel"><x-bs-admin::icon name="ri-error-warning" class="size-3.5" /><span class="{{ $chipHide }}" x-text="r.stateLabel"></span></x-bs-admin::badge>
                                    </span>
                                    <x-slot:actions>
                                        <span class="whitespace-nowrap text-caption text-text-muted @max-[26.875rem]:hidden" x-text="r.src"></span>
                                        <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-on:click="moveUp(r.k)" x-bind:disabled="filtering"
                                            x-bind:title="{{ $j(__('admin.tree.move_up').' (Alt ↑)') }}" x-bind:aria-label="{{ $j(__('admin.tree.move_up')) }} + ' — ' + r.name"><x-bs-admin::icon name="ri-arrow-up-s" class="size-5" /></x-bs-admin::button>
                                        <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-on:click="moveDown(r.k)" x-bind:disabled="filtering"
                                            x-bind:title="{{ $j(__('admin.tree.move_down').' (Alt ↓)') }}" x-bind:aria-label="{{ $j(__('admin.tree.move_down')) }} + ' — ' + r.name"><x-bs-admin::icon name="ri-arrow-down-s" class="size-5" /></x-bs-admin::button>
                                        <x-bs-admin::list.actions name="" name-bind="r.name" :title="$u('more_actions')">
                                            <x-slot:menu>
                                                <x-bs-admin::list.menu-item icon="ri-pencil" x-on:click="pick(r.k)">{{ $u('edit') }}</x-bs-admin::list.menu-item>
                                                <template x-if="srcEdit(r.it)">
                                                    <a role="menuitem" class="list-mi" x-bind:href="srcEdit(r.it)"><x-bs-admin::icon name="ri-external-link" class="list-mi-i" /><span x-text="{{ $j($u('src_open')) }}.replace(':kind', kindName(r.it).toLowerCase())"></span></a>
                                                </template>
                                                <x-bs-admin::list.menu-item icon="ri-eye-off" x-on:click="toggleHide(r.k)"><span x-text="r.it.options.hidden ? {{ $j($u('show')) }} : {{ $j($u('hide')) }}"></span></x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-sep />
                                                <x-bs-admin::tree.move-menu row="r" />
                                                <x-bs-admin::list.menu-item icon="ri-drag-move-2" x-on:click="mvOpen(r.k)" x-bind:aria-disabled="filtering ? 'true' : null">{{ $u('move_to') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-item icon="ri-file-copy" x-on:click="r.k.startsWith('new:') || copy(r.k)" x-bind:aria-disabled="r.k.startsWith('new:') ? 'true' : null">{{ $u('dup') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-sep />
                                                <x-bs-admin::list.menu-item icon="ri-history" x-on:click="(r.unsaved && !r.k.startsWith('new:')) && revert(r.k)" x-bind:aria-disabled="!r.unsaved || r.k.startsWith('new:') ? 'true' : null">{{ $u('revert') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-sep />
                                                <x-bs-admin::list.menu-item icon="ri-delete-bin" danger x-on:click="remove(r.k)">{{ $u('remove') }}</x-bs-admin::list.menu-item>
                                            </x-slot:menu>
                                        </x-bs-admin::list.actions>
                                    </x-slot:actions>

                                    {{-- The inline editor --}}
                                    <x-slot:below>
                                    <template x-if="sel === r.k">
                                        <div class="border-t border-border bg-surface-muted px-4 pb-4 pt-3.5">
                                            <div class="grid grid-cols-2 gap-3.5 @max-[37.5rem]:grid-cols-1">
                                                <label class="flex min-w-0 flex-col gap-1.5">
                                                    <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_label') }}<x-bs-admin::badge soft pill color="secondary"><span x-text="loc.toUpperCase()"></span></x-bs-admin::badge></span>
                                                    <x-bs-admin::input id="mn-ed-label" maxlength="120" x-bind:value="label(r.it)" x-on:change="setLabel(r.k, $event.target.value)"
                                                        x-bind:placeholder="onMain ? fallbackName(r.it) : (label(r.it, {{ $j($main) }}) || fallbackName(r.it))" x-bind:aria-invalid="labelErr(r.it)" aria-describedby="mn-lbl-hint" />
                                                    <span id="mn-lbl-hint" class="text-caption leading-normal" :class="labelErr(r.it) ? 'font-semibold text-danger-text' : 'text-text-muted'"
                                                          x-text="labelErr(r.it) ? @js($u('f_label_err')) : (onMain ? @js($u('f_label_hint')) : @js($u('f_label_src')).replace(':text', label(r.it, @js($main)) || fallbackName(r.it)))"></span>
                                                </label>
                                                <label class="flex min-w-0 flex-col gap-1.5">
                                                    <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_url') }}<x-bs-admin::badge soft pill color="secondary" x-show="!onMain"><x-bs-admin::icon name="ri-lock" class="size-3" />{{ $u('shared') }}</x-bs-admin::badge></span>
                                                    <x-bs-admin::input x-bind:value="r.it.link.type === 'url' ? r.it.link.url : srcText(r.it)" x-bind:disabled="!onMain || r.it.link.type !== 'url'"
                                                        x-on:change="editUrl(r.k, $event.target.value)" placeholder="/lien-he" />
                                                    <span class="text-caption leading-normal text-text-muted"
                                                          x-text="!onMain ? @js($u('f_url_shared')).replace(':lang', locName(@js($main))) : (r.it.link.type === 'url' ? @js($u('f_url_hint')) : @js($u('f_url_auto')).replace(':kind', kindName(r.it).toLowerCase()))"></span>
                                                </label>
                                            </div>
                                            <div class="mt-3.5 grid grid-cols-2 gap-3.5 @max-[37.5rem]:grid-cols-1">
                                                <div class="flex min-w-0 flex-col gap-1.5">
                                                    <label class="flex min-w-0 flex-col gap-1.5">
                                                        <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_desc') }}<x-bs-admin::badge soft pill color="secondary"><span x-text="loc.toUpperCase()"></span></x-bs-admin::badge>
                                                            <span class="ml-auto font-normal" :class="desc(r.it).length > descMax(r.it) ? 'text-warning-text' : 'text-text-muted'" x-text="desc(r.it).length + '/' + descMax(r.it)"></span></span>
                                                        <textarea rows="3" maxlength="500" class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted leading-relaxed" :value="desc(r.it)" x-on:change="setDesc(r.k, $event.target.value.trim())"
                                                                  :placeholder="@js($u('f_desc_ph'))"></textarea>
                                                    </label>
                                                    <span class="flex flex-wrap items-center gap-2">
                                                        <span class="min-w-0 flex-[1_1_160px] text-caption leading-normal text-text-muted"
                                                              x-text="desc(r.it).length > descMax(r.it) ? @js($u('f_desc_long')).replace(':n', descMax(r.it)) : (r.it.options.mega ? @js($u('f_desc_mega')) : @js($u('f_desc_hint')))"></span>
                                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" x-show="descSrc(r.it)" x-on:click="descFromSource(r.k)" title="{{ $u('desc_src_tip') }}"><x-bs-admin::icon name="ri-magic" class="size-4" />{{ $u('desc_src') }}</x-bs-admin::button>
                                                    </span>
                                                </div>
                                                <div class="flex min-w-0 flex-col gap-1.5">
                                                    <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_icon') }}<span class="font-normal text-text-muted">{{ $u('optional') }}</span></span>
                                                    <span class="relative flex flex-wrap items-center gap-2" x-on:click.outside="iconOpen = false">
                                                        <x-bs-admin::button variant="outline" color="secondary" x-on:click.stop="iconOpen = !iconOpen" x-bind:aria-expanded="iconOpen">
                                                            <template x-if="r.it.options.icon"><svg class="size-5" aria-hidden="true"><use :href="'#mn-ic-' + r.it.options.icon"></use></svg></template>
                                                            <span x-text="r.it.options.icon ? {{ $j($u('icon_change')) }} : {{ $j($u('icon_pick')) }}"></span><x-bs-admin::icon name="ri-arrow-down-s" class="size-4" />
                                                        </x-bs-admin::button>
                                                        <div x-show="iconOpen" x-on:click.stop class="absolute right-0 top-full z-30 mt-1.5 box-border w-[min(308px,calc(100vw-32px))] rounded-md border border-border bg-surface p-2.5 shadow-card">
                                                            <x-bs-admin::search :label="$u('icon_search')" x-model="iconQ" />
                                                            <div class="mt-2 flex max-h-[230px] flex-col gap-2 overflow-auto">
                                                                <template x-for="ig in iconGroups()" :key="ig.group">
                                                                    <div class="flex flex-col gap-1.5" role="group" :aria-label="ig.group">
                                                                        <span class="text-caption font-bold uppercase tracking-[.06em] text-text-muted" x-text="ig.group"></span>
                                                                        <span class="flex flex-wrap gap-1.5">
                                                                            <template x-for="ic in ig.icons" :key="ic.name">
                                                                                <x-bs-admin::button variant="outline" color="secondary" icon x-bind:title="ic.label" x-bind:aria-label="ic.label" x-bind:aria-pressed="r.it.options.icon === ic.name"
                                                                                    x-on:click="setIcon(r.k, ic.name)"><svg class="size-5" aria-hidden="true"><use :href="'#mn-ic-' + ic.name"></use></svg></x-bs-admin::button>
                                                                            </template>
                                                                        </span>
                                                                    </div>
                                                                </template>
                                                                <p x-show="!iconGroups().length" class="w-full px-1 py-3.5 text-center text-caption leading-relaxed text-text-muted">{{ $u('icon_none_found') }}</p>
                                                            </div>
                                                            <div class="mt-2 flex items-center gap-2 border-t border-border-soft pt-2">
                                                                <span class="flex-auto text-caption text-text-muted" x-text="@js($u('icon_count')).replace(':n', iconTotal)"></span>
                                                                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-show="r.it.options.icon" x-on:click="setIcon(r.k, null)"><x-bs-admin::icon name="ri-close" class="size-4" />{{ $u('icon_clear') }}</x-bs-admin::button>
                                                            </div>
                                                        </div>
                                                    </span>
                                                </div>
                                            </div>
                                            <template x-if="canMega(r.it)">
                                                <div class="mt-3.5 flex flex-wrap items-center gap-4 rounded-md border border-border bg-surface px-3 py-2.5">
                                                    <span class="flex"><button type="button" role="switch" class="switch-row" :aria-checked="!!r.it.options.mega" x-on:click="setMega(r.k, !r.it.options.mega)">
                                                        <span class="text-small text-text-strong">{{ $u('mega_on') }}</span>
                                                    </button></span>
                                                    <label x-show="r.it.options.mega"><span class="sr-only">{{ $u('mega_cols') }}</span>
                                                        <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted w-auto" title="{{ $u('mega_cols') }}" x-on:change="setCols(r.k, $event.target.value)">
                                                            @foreach ([2, 3, 4] as $n)
                                                                <option value="{{ $n }}" x-bind:selected="r.it.options.mega && r.it.options.mega.columns === {{ $n }}">{{ $u('cols_n', ['n' => $n]) }}</option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    <span class="min-w-0 flex-[1_1_200px] text-caption leading-normal text-text-muted">{{ $u('mega_hint') }}</span>
                                                </div>
                                            </template>
                                            <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1">
                                                @foreach (['show' => '!r.it.options.hidden', 'new_tab' => 'r.it.options.new_tab', 'cta' => 'r.it.options.cta'] as $key => $on)
                                                    <span class="flex"><button type="button" role="switch" class="switch-row" x-bind:aria-checked="{{ $on }}"
                                                            x-on:click="{{ $key === 'show' ? "setOpt(r.k, 'hidden', !r.it.options.hidden)" : "setOpt(r.k, '".$key."', !r.it.options.".$key.')' }}">
                                                        <span class="text-small text-text-strong">{{ $u('sw_'.$key) }}</span>
                                                    </button></span>
                                                @endforeach
                                            </div>
                                            <div class="mt-3 flex flex-wrap items-center gap-2.5">
                                                <label class="flex flex-wrap items-center gap-2.5">
                                                    <span class="text-caption font-semibold text-text">{{ $u('f_badge') }}</span>
                                                    <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted w-auto" title="{{ $u('f_badge_tip') }}" x-on:change="setBadge(r.k, $event.target.value)">
                                                        <option value="" x-bind:selected="!r.it.options.badge">{{ $u('badge_none') }}</option>
                                                        @foreach (['new', 'hot', 'sale', 'soon'] as $b)
                                                            <option value="{{ $b }}" x-bind:selected="r.it.options.badge && r.it.options.badge.kind === '{{ $b }}'">{{ $u('badge_'.$b) }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <template x-if="r.it.options.badge">
                                                    <span class="flex min-w-0 flex-[1_1_260px] flex-wrap items-center gap-2.5">
                                                        <label><span class="sr-only">{{ $u('f_badge_until') }}</span>
                                                            <input type="date" class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted w-auto" title="{{ $u('f_badge_until') }}" :value="r.it.options.badge.until || ''" x-on:change="setBadgeUntil(r.k, $event.target.value)" /></label>
                                                        <span class="min-w-0 flex-[1_1_180px] text-caption leading-normal text-text-muted" x-text="r.it.options.badge.until ? @js($u('badge_until_on')) : @js($u('badge_until_off'))"></span>
                                                    </span>
                                                </template>
                                            </div>
                                            <div class="mt-3 flex flex-wrap items-center gap-2.5" role="group" aria-label="{{ $u('f_device') }}">
                                                <span class="text-caption font-semibold text-text">{{ $u('f_device') }}</span>
                                                @foreach (['all' => ['ri-device', 'dev_all'], 'desktop' => ['ri-computer', 'only_pc'], 'phone' => ['ri-smartphone', 'only_phone']] as $dv => [$ic, $lbl])
                                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-bind:aria-pressed="r.it.options.device === '{{ $dv }}'" x-on:click="setOpt(r.k, 'device', '{{ $dv }}')">
                                                        <x-bs-admin::icon :name="$ic" class="size-4" />{{ $u($lbl) }}</x-bs-admin::button>
                                                @endforeach
                                            </div>
                                            <div class="mt-3.5 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                                                <span class="min-w-0 flex-[1_1_220px] text-caption leading-relaxed text-text-muted" x-text="@js($u('ed_foot')).replace(':kind', kindName(r.it))"></span>
                                                <x-bs-admin::button variant="outline" color="secondary" x-on:click="closeEditor()">{{ $u('done') }}</x-bs-admin::button>
                                            </div>
                                        </div>
                                    </template>
                                    </x-slot:below>
                                </x-bs-admin::tree.row>
                            </template>
                        </x-bs-admin::tree>

                        <x-bs-admin::alert color="danger" icon="ri-information" class="!rounded-none border-t border-border text-caption leading-relaxed" x-show="items.some(it => depthMap()[it.key] + 1 > maxDepth)">
                            <span x-text="{{ $j($u('depth_over')) }}.replace(':n', maxDepth)"></span>
                        </x-bs-admin::alert>
                        <x-bs-admin::alert color="warning" icon="ri-information" class="!rounded-none border-t border-border text-caption leading-relaxed"
                            x-show="!items.some(it => depthMap()[it.key] + 1 > maxDepth) && items.some(it => depthMap()[it.key] + 1 >= maxDepth)">
                            <span x-text="(menu.limits.mega ? {{ $j($u('depth_at_mega')) }} : {{ $j($u('depth_at')) }}).replace(':n', maxDepth)"></span>
                        </x-bs-admin::alert>
                        <div class="flex flex-wrap items-center gap-2 border-t border-border px-3.5 py-2">
                            <span class="shrink-0 text-text-faint"><x-bs-admin::icon name="ri-keyboard" class="size-4" /></span>
                            <span class="min-w-0 flex-[1_1_220px] text-caption leading-relaxed text-text-muted">{{ $u('keys_note') }}</span>
                            <x-bs-admin::tree.keys class="w-full" />
                        </div>
                    </x-bs-admin::card>
                    </template>

                    {{-- What visitors will see --}}
                    <x-bs-admin::card x-show="menu">
                        <x-slot:header>
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                            <span class="{{ $title }}">{{ $u('preview') }}</span>
                            <span class="ml-auto flex flex-wrap items-center gap-1.5" role="group" aria-label="{{ $u('preview') }}">
                                @foreach (['pc' => ['ri-computer', 'pv_pc', 'pv_pc_tip'], 'mb' => ['ri-smartphone', 'pv_mb', 'pv_mb_tip']] as $dv => [$ic, $lbl, $tip])
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-bind:aria-pressed="device === '{{ $dv }}'" title="{{ $u($tip) }}" x-on:click="device = '{{ $dv }}'; pvOpen = null">
                                        <x-bs-admin::icon :name="$ic" class="size-4" />{{ $u($lbl) }}</x-bs-admin::button>
                                @endforeach
                            </span>
                            </div>
                        </x-slot:header>
                        {{-- The preview draws the website's look: light, fixed colours on purpose. --}}
                        <div x-show="device === 'pc'">
                            <div class="relative flex flex-wrap items-center gap-1.5 rounded-md border border-border bg-surface px-3.5 py-3" x-on:click.outside="pvOpen = null">
                                <span class="mr-2 text-small font-bold text-text-strong">{{ $siteName }}</span>
                                <template x-for="it in pvTop()" :key="it.key">
                                    <span :class="it.options.mega ? '' : 'relative'">
                                        <button type="button" class="hit-area inline-flex min-h-control-row items-center gap-1 rounded px-2 text-small"
                                                :class="it.options.cta ? 'bg-primary font-semibold text-on-primary' : (pvOpen === it.key ? 'bg-surface-hover text-primary-text' : 'text-text hover:text-primary-text')"
                                                :aria-expanded="hasKids(it.key) ? pvOpen === it.key : null" x-on:click="pvOpen = pvOpen === it.key ? null : it.key">
                                            <template x-if="it.options.icon"><svg class="size-4" aria-hidden="true"><use :href="'#mn-ic-' + it.options.icon"></use></svg></template>
                                            <span x-text="name(it)"></span>
                                            <span x-show="it.options.badge" class="rounded-full bg-danger px-1.5 text-caption font-bold text-on-primary" x-text="badgeText(it)"></span>
                                            <span x-show="pvKids(it.key).length"><x-bs-admin::icon name="ri-arrow-down-s" class="size-4" /></span>
                                        </button>
                                        <template x-if="pvOpen === it.key && pvKids(it.key).length && !it.options.mega">
                                            <span class="absolute left-0 top-full z-10 flex min-w-[186px] flex-col rounded-md border border-border bg-surface py-1 shadow-lg">
                                                <template x-for="k in pvKids(it.key)" :key="k.key">
                                                    <span class="flex flex-col gap-0.5 px-3 py-2">
                                                        <span class="whitespace-nowrap text-small text-text" x-text="name(k)"></span>
                                                        <span x-show="desc(k)" class="max-w-[230px] text-caption leading-normal text-text-muted" x-text="desc(k)"></span>
                                                    </span>
                                                </template>
                                            </span>
                                        </template>
                                        <template x-if="pvOpen === it.key && pvKids(it.key).length && it.options.mega">
                                            <span class="absolute inset-x-0 top-full z-10 mt-1.5 grid gap-[18px] rounded-md border border-border bg-surface p-[18px] shadow-lg"
                                                  :style="'grid-template-columns:' + (desc(it) ? 'minmax(0,1.1fr) ' : '') + 'repeat(' + it.options.mega.columns + ',minmax(0,1fr))'">
                                                <template x-if="desc(it)">
                                                    <span class="flex flex-col gap-2 border-r border-border pr-[18px]">
                                                        <span class="text-body font-bold text-text-strong" x-text="name(it)"></span>
                                                        <span class="text-caption leading-relaxed text-text" x-text="desc(it)"></span>
                                                    </span>
                                                </template>
                                                <template x-for="(col, ci) in pvCols(it)" :key="ci">
                                                    <span class="flex flex-col gap-3.5">
                                                        <template x-for="k in col" :key="k.key">
                                                            <span class="flex flex-col gap-0.5">
                                                                <span class="text-small font-semibold text-text-strong" x-text="name(k)"></span>
                                                                <span x-show="desc(k)" class="text-caption leading-normal text-text-muted" x-text="desc(k)"></span>
                                                            </span>
                                                        </template>
                                                    </span>
                                                </template>
                                            </span>
                                        </template>
                                    </span>
                                </template>
                            </div>
                            <p x-show="pvCrowded()" class="mt-2.5 flex items-center gap-2 text-caption leading-relaxed text-warning-text">
                                <x-bs-admin::icon name="ri-error-warning" class="size-4 shrink-0" />{{ $u('crowded') }}
                            </p>
                        </div>
                        <div x-show="device === 'mb'" class="mx-auto w-full max-w-[300px] overflow-hidden rounded-lg border border-border bg-surface">
                            <div class="flex items-center gap-2 border-b border-border px-3 py-2.5"><x-bs-admin::icon name="menu" class="size-4 text-text" /><span class="text-small font-bold text-text-strong">{{ $siteName }}</span></div>
                            <template x-for="p in pvMobile()" :key="p.k">
                                <div class="flex items-center border-t border-border-soft py-2.5 pr-3 text-small first:border-t-0" :class="p.d ? 'pl-7 text-text-muted' : 'pl-3 font-semibold text-text-strong'">
                                    <span x-text="p.name"></span>
                                    <span x-show="p.kids && !p.d" class="ml-auto text-text-faint"><x-bs-admin::icon name="ri-arrow-right-s" class="size-4" /></span>
                                </div>
                            </template>
                        </div>
                    </x-bs-admin::card>
                </div>

                {{-- Right column: add to the menu --}}
                <div class="flex flex-col gap-4 @max-[75.625rem]:col-start-2 @max-[53.75rem]:order-4" x-show="menu">
                    <x-bs-admin::card flush>
                        <x-slot:header>
                            <button type="button" class="{{ $head }}" x-on:click="fold2.add = !fold2.add" :aria-expanded="!fold2.add">
                                <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.add && '-rotate-90'" />
                                <span class="{{ $title }}">{{ $u('add') }}</span>
                            </button>
                        </x-slot:header>
                        <div x-show="!fold2.add">
                            <div class="flex flex-wrap gap-1.5 px-3 py-2.5" role="group" aria-label="{{ $u('add') }}">
                                @foreach (['page' => 'tab_page', 'category' => 'tab_category', 'product' => 'tab_product', 'special' => 'tab_special', 'url' => 'tab_url'] as $k => $lbl)
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-bind:aria-pressed="tab === '{{ $k }}'" x-on:click="setTab('{{ $k }}')">{{ $u($lbl) }}</x-bs-admin::button>
                                @endforeach
                            </div>
                            <div x-show="sel" class="flex flex-wrap items-center gap-1.5 px-3 pb-2.5">
                                <span class="text-caption text-text-muted">{{ $u('add_to') }}</span>
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-bind:aria-pressed="addTo !== 'sel'" x-on:click="addTo = 'end'">{{ $u('add_end') }}</x-bs-admin::button>
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-bind:aria-pressed="addTo === 'sel'" title="{{ $u('add_in_tip') }}" x-on:click="addTo = 'sel'"
                                    ><span x-text="{{ $j($u('add_in')) }}.replace(':name', sel ? name(item(sel)) : '')"></span></x-bs-admin::button>
                            </div>

                            <div x-show="tab === 'url'" class="flex flex-col gap-2.5 px-3 pb-3.5 pt-1">
                                <x-bs-admin::input id="mn-new-label" :label="$u('f_label')" x-model="newLabel" maxlength="120" placeholder="{{ $u('new_label_ph') }}" />
                                <x-bs-admin::input id="mn-new-url" :label="$u('f_address')" x-model="newUrl" inputmode="url" autocomplete="url" placeholder="{{ $u('new_url_ph') }}" aria-describedby="mn-url-hint" />
                                <span id="mn-url-hint" class="text-caption leading-normal" :class="newUrl.trim() && !url.ok ? 'text-danger-text' : 'text-text-muted'"
                                      x-text="!newUrl.trim() ? @js($u('url_empty')) : (url.ok ? @js($u('url_will')).replace(':url', url.url) + (url.note ? ' — ' + url.note : '') : url.msg)"></span>
                                <x-bs-admin::button variant="outline" color="secondary" x-on:click="addUrl()" x-bind:disabled="!newLabel.trim() || !url.ok"><x-bs-admin::icon name="ri-add" class="size-4" />{{ $u('add_link') }}</x-bs-admin::button>
                            </div>

                            <div x-show="tab !== 'url'">
                                <div class="px-3 pb-2.5">
                                    <x-bs-admin::search :label="$u('find_page')" x-model="search" x-on:input="findSoon()"
                                        x-bind:aria-label="{{ \Illuminate\Support\Js::from(['page' => $u('find_page'), 'category' => $u('find_category'), 'product' => $u('find_product'), 'special' => $u('find_special')])->toHtml() }}[tab]"
                                        x-bind:placeholder="{{ \Illuminate\Support\Js::from(['page' => $u('find_page'), 'category' => $u('find_category'), 'product' => $u('find_product'), 'special' => $u('find_special')])->toHtml() }}[tab]" />
                                </div>
                                <div class="max-h-[300px] overflow-auto border-t border-border" :aria-busy="searching">
                                    <template x-for="s in srcRows()" :key="s.key">
                                        <button type="button" class="hit-area flex min-h-menu-row w-full items-center gap-2.5 border-t border-border-soft px-3 py-2 text-left first:border-t-0 hover:bg-surface-muted disabled:cursor-not-allowed"
                                                x-bind:disabled="s.used" :aria-pressed="!!picked[s.key]" x-on:click="togglePick(s)" :title="s.used ? @js($u('already_tip')) : ''">
                                            <span class="grid size-[18px] shrink-0 place-items-center rounded-sm border" :class="picked[s.key] ? 'border-primary bg-primary text-on-primary' : 'border-border-input'"><x-bs-admin::icon name="ri-check" class="size-3.5" x-show="picked[s.key]" /></span>
                                            <span class="flex min-w-0 flex-auto flex-col gap-0.5">
                                                <span class="text-small leading-snug [overflow-wrap:anywhere]" :class="s.used ? 'text-text-muted' : 'text-text-strong'" x-text="s.title"></span>
                                                <span class="text-caption leading-snug text-text-muted [overflow-wrap:anywhere]" x-text="s.meta"></span>
                                            </span>
                                            <x-bs-admin::badge soft pill color="secondary" x-show="s.used">{{ $u('already') }}</x-bs-admin::badge>
                                            <x-bs-admin::badge soft pill color="warning" x-show="s.draft">{{ $u('unpublished') }}</x-bs-admin::badge>
                                        </button>
                                    </template>
                                    <p x-show="!srcRows().length && !searching" class="px-3.5 py-5 text-center text-caption leading-relaxed text-text-muted">{{ $u('no_match') }}</p>
                                    <p x-show="searching && !srcRows().length" role="status" class="px-3.5 py-5 text-center text-caption text-text-muted">{{ $u('searching') }}</p>
                                </div>
                                <div class="border-t border-border px-3 py-2.5">
                                    <x-bs-admin::button variant="outline" color="secondary" class="w-full" x-on:click="addPicked()" x-bind:disabled="!pickedN">
                                        <x-bs-admin::icon name="ri-add" class="size-4" /><span x-text="pickedN ? {{ $j($u('add_n')) }}.replace(':n', pickedN) : {{ $j($u('pick_first')) }}"></span>
                                    </x-bs-admin::button>
                                </div>
                            </div>
                        </div>
                    </x-bs-admin::card>
                </div>
            </div>
            </div>
        </div>
        </template>
    </div>

    {{-- Unsaved changes bar --}}
    <div x-cloak x-show="screen === 'data' && dirty().n" class="sticky bottom-0 z-[5] -mx-4 mt-4 flex flex-wrap items-center gap-3 border-t border-border bg-surface px-6 py-3 shadow-[0_-1px_2px_rgba(56,65,74,.08)] sm:-mx-6">
        <span class="shrink-0 text-warning-text"><x-bs-admin::icon name="ri-error-warning" class="size-4" /></span>
        <span class="text-small leading-relaxed text-text" x-text="@js($u('unsaved_bar')).replace(':what', dirty().text)"></span>
        <span class="flex-auto"></span>
        <x-bs-admin::button variant="outline" color="secondary" x-on:click="reset()" x-bind:disabled="saveBusy" title="{{ $u('reset_tip') }}"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />{{ $u('reset') }}</x-bs-admin::button>
        <x-bs-admin::button x-on:click="save()" x-bind:disabled="saveBusy"><x-bs-admin::icon name="ri-save-3" class="size-4" /><span x-text="saveBusy ? {{ $j($u('saving')) }} : {{ $j($u('save')) }}">{{ $u('save') }}</span></x-bs-admin::button>
    </div>

    {{-- Ask before acting --}}
    <x-bs-admin::confirm-dialog state="ask" confirm="askOk()" cancel="askClose()">
        <x-bs-admin::confirm-line><span x-text="ask && ask.body"></span></x-bs-admin::confirm-line>
        <x-slot:alt>
            <x-bs-admin::button variant="outline" color="secondary" x-show="ask && ask.alt" x-on:click="askAlt()"><span x-text="ask && ask.alt">{{ $u('leaveDrop') }}</span></x-bs-admin::button>
        </x-slot:alt>
    </x-bs-admin::confirm-dialog>

    {{-- "Chuyển đến…" --}}
    <x-bs-admin::modal state="mv" close="mvClose()" :close-label="$u('close')">
        <x-slot:heading><span x-text="mv ? {{ $j($u('mv_title')) }}.replace(':name', name(item(mv.k))) : ''"></span></x-slot:heading>
        <template x-if="mv">
            <div class="flex min-h-0 flex-col gap-2.5">
                <x-bs-admin::search :label="$u('mv_search')" id="mn-mv-q" x-model="mv.q" autofocus />
                <p class="text-caption leading-relaxed text-text-muted" x-text="@js($u('mv_hint')).replace(':n', maxDepth)"></p>
                <div role="radiogroup" aria-label="{{ $u('mv_where') }}" class="flex max-h-[min(46dvh,360px)] flex-col gap-1.5 overflow-y-auto">
                    <template x-for="o in mvOptions()" :key="o.key">
                        <label class="flex cursor-pointer items-start gap-2.5 rounded border border-border px-3 py-2 has-[:checked]:border-primary-text has-[:checked]:bg-primary-soft has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60"
                               :style="'margin-left:' + (o.lv * 14) + 'px'">
                            <input type="radio" name="mn-mv" class="mt-1" :checked="mv.pick === o.key" x-bind:disabled="o.dis" x-on:change="mv.pick = o.key" />
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-small font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]" x-text="o.name"></span>
                                <span class="text-caption leading-normal text-text-muted" x-text="o.sub"></span>
                            </span>
                        </label>
                    </template>
                    <p x-show="!mvOptions().length" class="text-caption text-text-muted" x-text="@js($u('mv_none')).replace(':q', mv.q)"></p>
                </div>
            </div>
        </template>
        <x-slot:footer>
            <x-bs-admin::button variant="outline" color="secondary" x-on:click="mvClose()">{{ $u('cancel') }}</x-bs-admin::button>
            <x-bs-admin::button class="max-w-full" x-bind:disabled="!mv || !mv.pick" x-on:click="mvGo()"><span class="[overflow-wrap:anywhere]" x-text="mvBtn">{{ $u('mvPick') }}</span></x-bs-admin::button>
        </x-slot:footer>
    </x-bs-admin::modal>

    {{-- Messages: the shared toast; a refusal or an error stays until closed --}}
    <x-bs-admin::toast state="toast" dismiss="toastClose" undo="toastUndo" raised />
    <span class="sr-only" role="status" aria-live="polite" x-text="live"></span>
</div>
