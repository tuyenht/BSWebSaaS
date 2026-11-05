{{--
    Trình đơn (design 11). Everything on the page is drawn by the screen's Alpine part 'bsMenus'
    (resources/js/admin-menus.js) from what MenuScreen loads; every change goes through the
    component's methods ($wire.saveDraft, assign, …). Labels, descriptions and self-check messages
    are editor text and are only ever shown as text (x-text), never as HTML.
--}}
@php
    $u = fn (string $key, array $r = []): string => __('menus.ui_'.$key, $r);
    $texts = collect([
        'noName', 'fAll', 'fAllTip', 'fIssue', 'fIssueTip', 'fHidden', 'fHiddenTip', 'fUntr', 'fUntrTip',
        'dEdited', 'dAdded', 'dRemoved', 'dOrder', 'saveN', 'save', 'resetTitle', 'resetBody', 'resetOk',
        'removed', 'removedKids', 'hiddenNow', 'shownNow', 'copyNeedsSave', 'copied', 'noMoveFiltered',
        'movedLive', 'dragBad', 'dragHint', 'dragHintKids', 'mvTop', 'mvTopSub', 'mvHere', 'mvSub', 'mvPick',
        'mvGoTop', 'mvGo', 'movedTo', 'specialMissing', 'added', 'urlBad', 'urlMail', 'urlTel', 'urlHttps',
        'needName', 'staleTitle', 'staleBody', 'staleOk', 'saved', 'netErr', 'leaveTitle', 'leaveBody',
        'leaveSave', 'leaveDrop', 'renamed', 'newMenuName', 'menuCopied', 'delTitle', 'delBodyUsed', 'delBody',
        'delOk', 'menuDeleted', 'removeKidsTitle', 'removeKidsBody', 'removeKidsOk', 'langsToast', 'areaNone', 'areaMain', 'areaSame', 'areasSet', 'areaSaved', 'codeCopied',
        'checkOk', 'checkTips', 'checkBad', 'checkAndTips', 'trashKids', 'trashDays', 'restoreNeedsSave', 'restored',
    ])->mapWithKeys(fn (string $k): array => [$k => $u($k)])->all();
    $noun = __('admin.tree.noun');
    $texts += [
        'undone' => __('admin.tree.undone'),
        'redone' => __('admin.tree.redone'),
        'kept' => __('admin.tree.kept'),
        'tooDeep' => __('admin.tree.too_deep'),
        'kinds' => [
            'page' => __('menus.kind_page'), 'post' => __('menus.kind_post'), 'category' => __('menus.kind_category'),
            'product' => __('menus.kind_product'), 'url' => $u('kind_url'), 'special' => $u('kind_special'),
            'entry' => __('menus.kind_page'), 'taxonomy' => __('menus.kind_category'),
        ],
        'state' => ['unpublished' => $u('state_unpublished'), 'trashed' => $u('state_trashed'), 'gone' => $u('state_gone')],
        'badges' => ['new' => $u('badge_new'), 'hot' => $u('badge_hot'), 'sale' => $u('badge_sale'), 'soon' => $u('badge_soon')],
        'no' => [
            'atTop' => __('admin.tree.at_top'), 'atBottom' => __('admin.tree.at_bottom'),
            'noAbove' => __('admin.tree.no_above'), 'atOuter' => __('admin.tree.at_outer'), 'gone' => __('admin.tree.gone'),
        ],
        'drag' => [
            'start' => __('admin.tree.drag_start', ['noun' => $noun]),
            'child' => __('admin.tree.will_child', ['noun' => $noun]),
            'before' => __('admin.tree.will_before'),
            'after' => __('admin.tree.will_after'),
            'self' => __('admin.tree.no_self', ['noun' => $noun]),
        ],
    ];
    $config = ['initial' => $initial, 'main' => $main, 'icons' => $icons, 'texts' => $texts, 'edit' => $edit];

    // The board's shared control looks (one place on this screen; the sizes are the shared tokens).
    $card = 'rounded-md border border-border bg-surface shadow-card';
    $head = 'hit-area flex w-full items-center gap-2 border-b border-border px-4 py-3 text-left';
    $title = 'min-w-0 text-body font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]';
    $btn = 'hit-area inline-flex min-h-control-row items-center justify-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 text-caption font-medium text-text hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-55';
    $pri = 'hit-area inline-flex min-h-control items-center justify-center gap-1.5 rounded bg-primary px-3.5 py-1.5 text-body font-semibold text-on-primary shadow-sm hover:brightness-95 disabled:cursor-not-allowed disabled:bg-border-strong disabled:text-text-muted disabled:shadow-none';
    $hit = 'hit-area inline-grid size-control-row shrink-0 place-items-center rounded text-text-muted hover:bg-surface-hover hover:text-primary-text disabled:cursor-not-allowed disabled:opacity-30';
    $tab = 'hit-area inline-flex min-h-control-row items-center gap-1.5 rounded-full border border-border-strong bg-surface px-3 text-caption font-medium text-text hover:bg-surface-hover';
    $tabOn = '!border-primary-text !bg-primary-soft font-semibold !text-primary-text';
    $chip = 'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-caption font-semibold leading-tight';
    $chipGrey = $chip.' border border-border bg-surface-hover text-text-muted';
    $menuItem = 'hit-area flex min-h-menu-row w-full items-center gap-2.5 px-3 text-left text-small text-text hover:bg-surface-hover hover:text-primary-text disabled:cursor-not-allowed disabled:opacity-45';
@endphp

<div class="@container" x-data="bsMenus(@js($config))" x-on:keydown.window="keys($event)" x-on:focusin="noteFocus($event.target)" x-on:click.window="rowMenu = null; menuMore = null">
    {{-- The website icons, drawn once and reused by name (<use>). --}}
    <svg class="hidden" aria-hidden="true">
        @foreach ($icons as $icon)
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
        {{-- Title row --}}
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-[1_1_320px] flex-col gap-1">
                <span class="text-caption text-text-muted">{{ $u('crumb') }}</span>
                <h1 class="text-title font-semibold leading-snug text-text-strong">{{ __('menus.title') }}</h1>
                <p class="text-small leading-relaxed text-text-muted">{{ $u('intro') }}</p>
            </div>
            <div class="mt-0 flex max-w-full flex-wrap gap-2 @min-[37.5rem]:mt-5">
                <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="{{ $btn }} min-h-control text-small">
                    <x-bs-admin::icon name="ri-external-link" class="size-4" />{{ $u('open_site') }}
                </a>
                <button type="button" class="{{ $pri }}" x-on:click="save()" x-bind:disabled="!dirty().n || saving || screen !== 'data'"
                        x-bind:title="dirty().n ? dirty().text : @js($u('nothing_to_save'))">
                    <x-bs-admin::icon name="ri-save-3" class="size-4" /><span x-text="saving ? @js($u('saving')) : saveLabel">{{ $u('save') }}</span>
                </button>
            </div>
        </div>

        {{-- Loading --}}
        <section class="{{ $card }}" aria-busy="true" aria-label="{{ $u('loading') }}" x-show="screen === 'loading'">
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
        </section>

        {{-- Could not load --}}
        <section class="{{ $card }} flex flex-col items-center gap-2.5 px-5 py-8 text-center" x-cloak x-show="screen === 'error'">
            <x-bs-admin::icon name="ri-wifi-off" class="size-6 text-danger-text" />
            <h2 class="text-body font-semibold text-text-strong">{{ $u('error_title') }}</h2>
            <p role="alert" class="max-w-[420px] text-small leading-relaxed text-text">{{ $u('error_text') }}</p>
            <button type="button" class="{{ $pri }}" x-on:click="reload()"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ $u('retry') }}</button>
        </section>

        <template x-if="screen === 'data'">
        <div class="flex flex-col gap-4">
            {{-- Saving failed --}}
            <div x-show="saveErr" role="alert" class="flex flex-wrap items-center gap-2.5 rounded-md border border-danger-text/30 bg-danger-soft px-4 py-3 text-small text-danger-text">
                <x-bs-admin::icon name="ri-error-warning" class="size-4 shrink-0" />
                <span class="min-w-0 flex-[1_1_240px]">{{ $u('save_error') }}</span>
                <button type="button" class="{{ $btn }}" x-on:click="save()"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ $u('retry') }}</button>
            </div>

            {{-- Language being edited --}}
            <div class="{{ $card }}" x-show="multi">
                <div class="flex flex-wrap items-center gap-2.5 px-3 py-2">
                    <span title="{{ $u('lang_tip') }}" class="shrink-0 text-text-muted"><x-bs-admin::icon name="ri-translate-2" class="size-5" /></span>
                    <label class="flex min-w-0 flex-auto items-center gap-1.5 @min-[35.0625rem]:hidden">
                        <span class="sr-only">{{ $u('lang_pick') }}</span>
                        <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted w-auto min-w-0 flex-auto" x-model="loc" x-on:change="pickLoc(loc)">
                            <template x-for="l in d.locales" :key="l.code"><option :value="l.code" x-text="l.name + (l.main ? ' ★' : '')"></option></template>
                        </select>
                    </label>
                    <button type="button" class="{{ $btn }} min-h-control @min-[35.0625rem]:hidden" x-on:click="say(@js($u('langsToast')).replace(':n', d.locales.length))" title="{{ $u('langs_tip') }}" aria-label="{{ $u('langs_tip') }}">
                        <x-bs-admin::icon name="ri-list-check-2" class="size-4" /><span class="@max-[23.75rem]:hidden">{{ $u('langs') }}</span>
                    </button>
                    <div class="hidden min-w-0 flex-wrap items-center gap-1.5 @min-[35.0625rem]:flex">
                        <template x-for="l in d.locales" :key="l.code">
                            <button type="button" class="{{ $tab }} min-h-control" :class="loc === l.code && @js($tabOn)" x-on:click="pickLoc(l.code)"
                                    :aria-pressed="loc === l.code" :title="langStat(l.code).done + '/' + langStat(l.code).total + ' ' + @js($u('labels_done'))">
                                <span class="size-2 rounded-full" :class="langStat(l.code).done === langStat(l.code).total ? 'bg-success' : 'bg-warning'" aria-hidden="true"></span>
                                <span x-text="l.name"></span>
                                <template x-if="l.main"><span class="text-warning-text" aria-label="{{ $u('lang_main') }}"><x-bs-admin::icon name="ri-star-fill" class="size-3.5" /></span></template>
                            </button>
                        </template>
                    </div>
                    <span class="ml-auto hidden items-center gap-1.5 whitespace-nowrap text-caption text-text-muted @min-[52.5625rem]:inline-flex">
                        <span :class="langStat(loc).done === langStat(loc).total ? 'text-success-text' : 'text-warning-text'">
                            <x-bs-admin::icon name="ri-check" class="size-4" x-show="langStat(loc).done === langStat(loc).total" /><x-bs-admin::icon name="ri-time" class="size-4" x-show="langStat(loc).done !== langStat(loc).total" />
                        </span>
                        <span x-text="langStat(loc).done + '/' + langStat(loc).total + ' ' + @js($u('labels_done'))"></span>
                    </span>
                </div>
                <div class="flex items-start gap-2 border-t border-border-soft px-3.5 py-2 text-caption leading-relaxed"
                     :class="onMain ? 'bg-surface-muted text-text-muted' : (langStat(loc).done === langStat(loc).total ? 'bg-success-soft text-success-text' : 'bg-warning-soft text-warning-text')">
                    <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" />
                    <span x-text="onMain ? @js($u('note_main')) : (langStat(loc).done === langStat(loc).total ? @js($u('note_full')) : @js($u('note_part')).replace(':n', langStat(loc).total - langStat(loc).done)).replace(':lang', locName(loc))"></span>
                </div>
            </div>

            {{-- Self-check --}}
            <div class="{{ $card }}">
                <button type="button" class="{{ $head }}" x-on:click="fold2.check = !fold2.check" :aria-expanded="!fold2.check">
                    <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.check && '-rotate-90'" />
                    <span class="shrink-0" :class="issues.some(q => q.level !== 'tip') ? 'text-warning-text' : (issues.length ? 'text-info-text' : 'text-success-text')">
                        <x-bs-admin::icon name="ri-shield-check" class="size-4" />
                    </span>
                    <span class="{{ $title }}" x-text="checkTitle"></span>
                    <span class="ml-auto hidden text-right text-caption text-text-muted @min-[38.8125rem]:inline">{{ $u('check_sub') }}</span>
                    <span class="ml-auto shrink-0 px-1 text-small font-semibold text-primary-text underline @min-[38.8125rem]:ml-3" x-text="fold2.check ? @js($u('see')) : @js($u('less'))">{{ $u('see') }}</span>
                </button>
                <div x-show="!fold2.check">
                    <p x-show="!issues.length" class="px-4 py-3 text-small leading-relaxed text-text-muted">{{ $u('check_none') }}</p>
                    <template x-for="(q, i) in shownIssues()" :key="i">
                        <div class="flex flex-wrap items-center gap-2.5 border-t border-border-soft px-4 py-2.5">
                            <span class="shrink-0" :class="q.level === 'tip' ? 'text-info-text' : 'text-warning-text'"><x-bs-admin::icon name="ri-error-warning" class="size-4" /></span>
                            <span class="min-w-0 flex-[1_1_260px] text-small leading-relaxed text-text" x-text="q.message"></span>
                            <template x-if="q.item && item(q.item)">
                                <span class="flex flex-wrap gap-2">
                                    <button type="button" class="{{ $btn }}" x-on:click="goIssue(q)"><x-bs-admin::icon name="ri-focus-3" class="size-4" />{{ $u('see_item') }}</button>
                                    <button type="button" class="{{ $btn }}" x-on:click="remove(q.item)"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ $u('remove') }}</button>
                                </span>
                            </template>
                        </div>
                    </template>
                    <div x-show="issues.length > {{ 3 }}" class="border-t border-border-soft px-4 py-2">
                        <button type="button" class="{{ $btn }}" x-on:click="issuesAll = !issuesAll">
                            <span x-text="issuesAll ? @js($u('less')) : @js($u('more')).replace(':n', issues.length - 3)"></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-[262px_minmax(0,1fr)_288px] items-start gap-4 @max-[75.625rem]:grid-cols-[262px_minmax(0,1fr)] @max-[53.75rem]:flex @max-[53.75rem]:flex-col @max-[53.75rem]:items-stretch">
                {{-- Left column: menus, areas, what the system does --}}
                <div class="flex flex-col gap-4 @max-[53.75rem]:contents">
                    <div class="{{ $card }} @max-[53.75rem]:order-1">
                        <button type="button" class="{{ $head }}" x-on:click="fold2.menus = !fold2.menus" :aria-expanded="!fold2.menus">
                            <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.menus && '-rotate-90'" />
                            <span class="{{ $title }}">{{ __('menus.title') }}</span>
                            <span class="ml-auto text-caption text-text-muted" x-text="@js($u('menu_count')).replace(':n', d.menus.length)"></span>
                        </button>
                        <div x-show="!fold2.menus">
                            <template x-for="m in menuRows()" :key="m.key">
                                <div class="relative flex cursor-pointer flex-col gap-1 border-t border-border-soft px-3 py-2.5 first:border-t-0 hover:bg-surface-muted"
                                     :class="m.on && 'bg-primary-soft shadow-[inset_3px_0_0_var(--color-primary-text)]'" x-on:click="selectMenu(m.key)">
                                    <div class="flex items-start gap-2">
                                        <button type="button" class="hit-area min-h-6 min-w-0 flex-auto text-left text-small font-semibold leading-snug [overflow-wrap:anywhere]"
                                                :class="m.on ? 'text-primary-text' : 'text-text-strong'" :aria-current="m.on && 'true'" x-text="m.name"></button>
                                        <span class="relative @min-[53.8125rem]:hidden" x-on:click.stop>
                                            <button type="button" class="{{ $hit }}" :data-mn-mm="m.key" aria-haspopup="menu" :aria-expanded="menuMore === m.key"
                                                    :aria-label="@js($u('menu_actions')).replace(':name', m.name)" title="{{ $u('more_actions') }}" x-on:click="menuMore = menuMore === m.key ? null : m.key">
                                                <x-bs-admin::icon name="ri-more-2-fill" class="size-4" />
                                            </button>
                                            <div x-show="menuMore === m.key" role="menu" class="absolute right-0 top-full z-30 mt-1 w-60 rounded-md border border-border bg-surface py-1 shadow-lg">
                                                <div class="px-3 pb-1 pt-1.5 text-caption font-semibold text-text-muted [overflow-wrap:anywhere]" aria-hidden="true" x-text="m.name"></div>
                                                <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="startRename(m)"><x-bs-admin::icon name="ri-edit" class="size-4" />{{ $u('rename') }}</button>
                                                <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="dupMenu(m)"><x-bs-admin::icon name="ri-file-copy" class="size-4" />{{ $u('dup_menu') }}</button>
                                                <button type="button" role="menuitem" class="{{ $menuItem }} !text-danger-text" x-on:click="delMenu(m)" x-bind:disabled="m.use.length > 0" :title="m.use.length ? @js($u('del_used')).replace(':name', m.name).replace(':where', m.use.join(', ')) : ''"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ $u('del_menu') }}</button>
                                            </div>
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="min-w-0 flex-auto text-caption text-text-muted" x-text="m.on ? @js($u('items_n')).replace(':n', items.length) : ''"></span>
                                        <span class="hidden items-center gap-2 @min-[53.8125rem]:flex" x-on:click.stop>
                                            <button type="button" class="{{ $hit }}" x-on:click="startRename(m)" title="{{ $u('rename_tip') }}" aria-label="{{ $u('rename_tip') }}"><x-bs-admin::icon name="ri-edit" class="size-4" /></button>
                                            <button type="button" class="{{ $hit }}" x-on:click="dupMenu(m)" :title="@js($u('dup_tip')).replace(':name', m.name)" :aria-label="@js($u('dup_tip')).replace(':name', m.name)"><x-bs-admin::icon name="ri-file-copy" class="size-4" /></button>
                                            <button type="button" class="{{ $hit }}" x-on:click="delMenu(m)" x-bind:disabled="m.use.length > 0" :title="m.use.length ? @js($u('del_used')).replace(':name', m.name).replace(':where', m.use.join(', ')) : @js($u('del_tip')).replace(':name', m.name)" :aria-label="@js($u('del_tip')).replace(':name', m.name)"><x-bs-admin::icon name="ri-delete-bin" class="size-4" /></button>
                                        </span>
                                    </div>
                                    <template x-if="renaming === m.key">
                                        <input id="mn-rename" class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" x-model="renameVal" x-on:click.stop x-on:keydown.enter.prevent="doneRename()" x-on:blur="doneRename()" placeholder="{{ $u('menu_name') }}" aria-label="{{ $u('menu_name') }}" maxlength="120" />
                                    </template>
                                    <span class="flex items-start gap-1.5 text-caption leading-normal" :class="m.use.length ? 'text-success-text' : 'text-warning-text'">
                                        <span class="mt-0.5 shrink-0"><x-bs-admin::icon name="ri-checkbox-circle" class="size-3.5" /></span>
                                        <span x-text="m.use.length ? @js($u('used_in')).replace(':where', m.use.join(' · ')) : @js($u('unused'))"></span>
                                    </span>
                                </div>
                            </template>
                            <div class="border-t border-border-soft px-3 py-2.5">
                                <button type="button" class="{{ $btn }} w-full min-h-control" x-on:click="newMenu()"><x-bs-admin::icon name="ri-add" class="size-4" />{{ $u('new_menu') }}</button>
                            </div>
                        </div>
                    </div>

                    <div class="{{ $card }} @max-[53.75rem]:order-3">
                        <button type="button" class="{{ $head }}" x-on:click="fold2.areas = !fold2.areas" :aria-expanded="!fold2.areas">
                            <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.areas && '-rotate-90'" />
                            <span class="{{ $title }}">{{ $u('areas') }}</span>
                            <span class="ml-auto text-right text-caption text-text-muted" x-text="areasSet"></span>
                        </button>
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
                                            <button type="button" class="hit-area flex max-w-full items-center gap-1 self-start rounded border border-border bg-surface-muted px-1.5 font-mono text-caption text-text-muted"
                                                    x-on:click="copyCode(a.key)" :title="@js($u('copy_code'))"><span x-text="a.key"></span><x-bs-admin::icon name="ri-file-copy" class="size-3.5" /></button>
                                        </template>
                                        <label>
                                            <span class="sr-only" x-text="a.label"></span>
                                            <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" :title="a.help" x-on:change="setArea(a, @js($main), $event.target.value)">
                                                <template x-for="o in areaOptions(a, @js($main))" :key="o.v"><option :value="o.v" x-text="o.l" :selected="o.v === areaValue(a, @js($main))"></option></template>
                                            </select>
                                        </label>
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
                                <button type="button" class="{{ $btn }} w-full" x-show="d.areas.some(a => a.advanced)" x-on:click="advOn = !advOn" x-text="advOn ? @js($u('adv_hide')) : @js($u('adv_show'))"></button>
                                <button type="button" class="{{ $btn }} w-full" x-on:click="devOn = !devOn" title="{{ $u('dev_tip') }}">
                                    <x-bs-admin::icon name="ri-code-s-slash" class="size-4" /><span x-text="devOn ? @js($u('dev_hide')) : @js($u('dev_show'))"></span>
                                </button>
                                <p x-show="devOn" class="text-caption leading-relaxed text-text-muted">{{ $u('dev_foot') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="{{ $card }} @max-[53.75rem]:order-3" x-show="menu">
                        <button type="button" class="{{ $head }}" x-on:click="fold2.auto = !fold2.auto" :aria-expanded="!fold2.auto">
                            <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.auto && '-rotate-90'" />
                            <span class="{{ $title }}">{{ $u('auto') }}</span>
                            <span class="ml-auto shrink-0 text-caption text-text-muted" x-text="@js($u('auto_n')).replace(':n', menu && menu.autoAdd ? 4 : 3)"></span>
                        </button>
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
                                                <span class="text-text-muted">{{ $u('auto_fix_sub') }}</span>
                                            @endif
                                        </span>
                                    </span>
                                @endforeach
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Middle column: the tree and the preview --}}
                <div class="flex min-w-0 flex-col gap-4 @max-[53.75rem]:order-2">
                    <template x-if="!menu">
                        <div class="{{ $card }} flex flex-col items-center gap-2 px-5 py-9 text-center">
                            <span class="text-text-faint"><x-bs-admin::icon name="ri-node-tree" class="size-7" /></span>
                            <span class="text-small font-semibold text-text">{{ $u('no_menu_title') }}</span>
                            <span class="max-w-[340px] text-caption leading-relaxed text-text-muted">{{ $u('no_menu_text') }}</span>
                            <button type="button" class="{{ $btn }} min-h-control" x-on:click="newMenu()"><x-bs-admin::icon name="ri-add" class="size-4" />{{ $u('new_menu') }}</button>
                        </div>
                    </template>
                    <template x-if="menu">
                    <div class="{{ $card }}" x-on:dragend="dragEnd()">
                        <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                            <span class="{{ $title }}" x-text="menu.name"></span>
                            <span class="{{ $chipGrey }}" x-text="@js($u('items_n')).replace(':n', items.length)"></span>
                            <span class="{{ $chipGrey }}" :title="@js($u('depth_tip')).replace(':n', maxDepth)"><x-bs-admin::icon name="ri-node-tree" class="size-3.5" /><span x-text="@js($u('depth_n')).replace(':n', maxDepth)"></span></span>
                            <span class="ml-auto flex shrink-0 items-center gap-1.5">
                                <button type="button" class="{{ $btn }}" x-show="menu.trash.length" x-on:click="trashOpen = !trashOpen" title="{{ $u('trash_tip') }}" :aria-label="@js($u('trash_aria')).replace(':n', menu.trash.length)" :aria-expanded="trashOpen">
                                    <x-bs-admin::icon name="ri-delete-bin" class="size-4" /><span class="@max-[23.75rem]:hidden">{{ $u('trash') }}</span><span x-text="menu.trash.length"></span>
                                </button>
                                <button type="button" class="{{ $hit }}" x-on:click="undo()" x-bind:disabled="!histN" :title="histN ? @js($u('undo_tip')) : @js($u('undo_none'))" :aria-label="histN ? @js($u('undo_tip')) : @js($u('undo_none'))"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /></button>
                                <button type="button" class="{{ $hit }}" x-on:click="redo()" x-bind:disabled="!futN" :title="futN ? @js($u('redo_tip')) : @js($u('redo_none'))" :aria-label="futN ? @js($u('redo_tip')) : @js($u('redo_none'))"><x-bs-admin::icon name="ri-arrow-go-forward" class="size-4" /></button>
                                <button type="button" class="{{ $btn }} @max-[37.4375rem]:hidden" x-on:click="foldAll()" :title="(anyFolded ? @js($u('open_all')) : @js($u('close_all'))) + ' — ' + @js($u('fold_tip'))">
                                    <x-bs-admin::icon name="ri-node-tree" class="size-4" /><span x-text="anyFolded ? @js($u('open_all')) : @js($u('close_all'))"></span>
                                </button>
                            </span>
                        </div>

                        {{-- Trash --}}
                        <div x-show="trashOpen" class="border-b border-border bg-surface-muted">
                            <div class="flex items-center gap-2 px-3.5 pb-1.5 pt-2.5">
                                <span class="text-text-muted"><x-bs-admin::icon name="ri-delete-bin" class="size-4" /></span>
                                <span class="flex-auto text-caption leading-normal text-text-muted">{{ $u('trash_note') }}</span>
                                <button type="button" class="{{ $hit }}" x-on:click="trashOpen = false" title="{{ $u('trash_close') }}" aria-label="{{ $u('trash_close') }}"><x-bs-admin::icon name="ri-close" class="size-4" /></button>
                            </div>
                            <template x-for="r in menu.trash" :key="r.key">
                                <div class="flex items-center gap-2 border-t border-border-soft px-3.5 py-2">
                                    <span class="flex min-w-0 flex-auto flex-col gap-0.5">
                                        <span class="text-small leading-snug text-text-strong [overflow-wrap:anywhere]" x-text="r.label || @js($u('noName'))"></span>
                                        <span class="text-caption text-text-muted" x-text="trashMeta(r)"></span>
                                    </span>
                                    <button type="button" class="{{ $btn }}" x-on:click="restore(r)"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />{{ $u('restore') }}</button>
                                </div>
                            </template>
                        </div>

                        {{-- Search and filters --}}
                        <div class="flex flex-wrap items-center gap-2 border-b border-border px-3.5 py-2.5">
                            <label class="flex min-w-0 flex-[1_1_190px] items-center gap-2 rounded border border-border-input bg-surface px-2.5 focus-ring-within focus-within:border-primary-text">
                                <span class="text-text-muted"><x-bs-admin::icon name="ri-search" class="size-4" /></span>
                                <input type="search" x-model="q" aria-label="{{ $u('tree_search') }}" placeholder="{{ $u('tree_search_ph') }}" class="min-h-control min-w-0 flex-auto border-0 bg-transparent text-body text-text-strong" />
                            </label>
                            <span class="hidden flex-wrap gap-2 @min-[37.5rem]:flex">
                                <template x-for="f in filters()" :key="f.k">
                                    <button type="button" class="{{ $tab }}" :class="filter === f.k && @js($tabOn)" :aria-pressed="filter === f.k" :title="f.title" x-on:click="filter = f.k; sel = null">
                                        @foreach (['all' => 'ri-list-check-2', 'issue' => 'ri-information', 'hidden' => 'ri-eye-off', 'untr' => 'ri-translate-2'] as $fk => $fic)
                                            <span x-show="f.k === '{{ $fk }}'"><x-bs-admin::icon :name="$fic" class="size-4" /></span>
                                        @endforeach
                                        <span x-text="f.l + ' ' + f.n"></span>
                                    </button>
                                </template>
                            </span>
                            <label class="@min-[37.5rem]:hidden">
                                <span class="sr-only">{{ $u('filter') }}</span>
                                <select class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted w-auto" x-model="filter">
                                    <template x-for="f in filters()" :key="f.k"><option :value="f.k" x-text="f.l + ' ' + f.n" :selected="filter === f.k"></option></template>
                                </select>
                            </label>
                            <button type="button" class="{{ $btn }} min-h-control @min-[37.5rem]:hidden" x-on:click="foldAll()" title="{{ $u('fold_tip') }}">
                                <x-bs-admin::icon name="ri-node-tree" class="size-4" /><span x-text="anyFolded ? @js($u('open_all')) : @js($u('close_all'))"></span>
                            </button>
                        </div>
                        <div x-show="filtering" class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-3.5 py-2">
                            <span class="text-text-muted"><x-bs-admin::icon name="ri-filter-3" class="size-4" /></span>
                            <span class="min-w-0 flex-[1_1_220px] text-caption leading-normal text-text-muted" x-text="@js($u('filter_note')).replace(':n', rows().length)"></span>
                            <button type="button" class="{{ $btn }}" x-on:click="q = ''; filter = 'all'">{{ $u('clear_filter') }}</button>
                        </div>
                        <div x-show="dragK" class="sticky top-0 z-[15] flex items-center gap-2 border-b border-primary-text/20 bg-primary-soft px-3.5 py-2 text-caption leading-normal text-primary-text">
                            <x-bs-admin::icon name="ri-drag-move-2" class="size-4 shrink-0" /><span x-text="dragHint"></span>
                        </div>

                        <div x-show="!rows().length" class="flex flex-col items-center gap-2 px-5 py-9 text-center">
                            <span class="text-text-faint"><x-bs-admin::icon name="ri-node-tree" class="size-7" /></span>
                            <span class="text-small font-semibold text-text" x-text="filtering ? @js($u('empty_filter_title')) : @js($u('empty_title'))"></span>
                            <span class="max-w-[340px] text-caption leading-relaxed text-text-muted" x-text="filtering ? @js($u('empty_filter_text')) : @js($u('empty_text'))"></span>
                        </div>

                        {{-- The rows --}}
                        <div role="list" aria-label="{{ $u('tree_label') }}">
                            <template x-for="r in rows()" :key="r.k">
                                <div role="listitem" class="border-t border-border-soft first:border-t-0">
                                    <div x-show="drop && drop.k === r.k && drop.at === 'before'" role="presentation" x-on:dragover="dragOverPh($event)" x-on:drop="dragDrop($event)"
                                         class="mx-3.5 my-1 flex items-center gap-2 rounded border-[1.5px] border-dashed px-3 py-2 text-caption"
                                         :class="drop && drop.bad ? 'border-danger-text text-danger-text' : 'border-primary-text bg-primary-soft text-primary-text'"
                                         :style="drop && 'margin-left:' + (14 + (drop.lv - 1) * 22) + 'px'"><span x-text="drop && drop.label"></span></div>
                                    <div class="relative flex items-center gap-1.5 py-1.5 pl-1.5 pr-2.5 hover:bg-surface-muted"
                                         :class="[sel === r.k && 'bg-primary-soft', drop && drop.refK === r.k && (drop.bad ? 'ring-2 ring-inset ring-danger-text' : 'ring-2 ring-inset ring-primary-text'), dragK === r.k && 'opacity-50']"
                                         :data-mn-row="r.k" :draggable="!filtering" x-on:dragstart="dragStart($event, r.k)" x-on:dragover="dragOver($event, r.k)" x-on:drop="dragDrop($event)"
                                         x-on:keydown="rowKey($event, r.k)">
                                        <span class="shrink-0" :style="'width:' + (r.indent * 22) + 'px'"></span>
                                        <span x-show="!filtering" class="hidden shrink-0 cursor-grab text-text-faint [@media(hover:hover)_and_(pointer:fine)]:inline-flex" title="{{ __('admin.tree.grip_tip') }}"><x-bs-admin::icon name="ri-draggable" class="size-4" /></span>
                                        <button type="button" x-show="r.kids" class="{{ $hit }} !w-6" x-on:click="toggleFold(r.k)" :aria-expanded="!fold[r.k]"
                                                :title="(fold[r.k] ? @js(__('admin.tree.expand')) : @js(__('admin.tree.collapse'))).replace(':name', r.name)" :aria-label="(fold[r.k] ? @js(__('admin.tree.expand')) : @js(__('admin.tree.collapse'))).replace(':name', r.name)">
                                            <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 transition-transform" x-bind:class="fold[r.k] && '-rotate-90'" />
                                        </button>
                                        <span x-show="!r.kids" class="w-6 shrink-0"></span>
                                        <span class="shrink-0 text-text-muted">
                                            <template x-if="r.it.options.icon"><svg class="size-4" aria-hidden="true"><use :href="'#mn-ic-' + r.it.options.icon"></use></svg></template>
                                            <template x-if="!r.it.options.icon"><span>
                                                <span x-show="kind(r.it) === 'url'"><x-bs-admin::icon name="ri-links" class="size-4" /></span>
                                                <span x-show="kind(r.it) === 'special'"><x-bs-admin::icon name="ri-star" class="size-4" /></span>
                                                <span x-show="kind(r.it) === 'category'"><x-bs-admin::icon name="ri-price-tag-3" class="size-4" /></span>
                                                <span x-show="kind(r.it) === 'product'"><x-bs-admin::icon name="ri-box-3" class="size-4" /></span>
                                                <span x-show="kind(r.it) === 'page' || kind(r.it) === 'post'"><x-bs-admin::icon name="ri-file-text" class="size-4" /></span>
                                            </span></template>
                                        </span>
                                        <button type="button" data-mn-name class="hit-area flex min-h-control-row min-w-0 flex-[1_1_0%] flex-wrap items-center gap-1.5 text-left hover:text-primary-text" x-on:click="pick(r.k)" :aria-expanded="sel === r.k" title="{{ $u('edit_tip') }}">
                                            <span class="min-w-0 text-small leading-snug [overflow-wrap:anywhere]" :class="r.it.options.hidden ? 'text-text-muted line-through' : (r.lv === 1 ? 'font-semibold text-text-strong' : 'text-text')" x-text="r.name"></span>
                                            <span x-show="filtering && r.it.parent" class="text-caption text-text-muted [overflow-wrap:anywhere]" x-text="r.it.parent ? '← ' + name(item(r.it.parent)) : ''"></span>
                                            <span x-show="r.unsaved" class="{{ $chip }} bg-warning-soft text-warning-text" title="{{ $u('unsaved_tip') }}"><span class="size-1.5 rounded-full bg-current"></span><span class="@max-[32.5rem]:hidden">{{ $u('unsaved') }}</span></span>
                                            <span x-show="r.it.options.hidden" class="{{ $chip }} bg-surface-hover text-text-muted" title="{{ $u('hidden_tip') }}"><x-bs-admin::icon name="ri-eye-off" class="size-3.5" /><span class="@max-[32.5rem]:hidden">{{ $u('hidden') }}</span></span>
                                            <span x-show="r.it.options.cta" class="{{ $chip }} bg-primary-soft text-primary-text" title="{{ $u('cta_tip') }}"><x-bs-admin::icon name="ri-cursor" class="size-3.5" /><span class="@max-[32.5rem]:hidden">{{ $u('cta') }}</span></span>
                                            <span x-show="r.it.options.badge" class="{{ $chip }} bg-danger text-white" x-text="badgeText(r.it)"></span>
                                            <span x-show="r.deep" class="{{ $chip }} bg-danger-soft text-danger-text" :title="@js($u('deep_tip')).replace(':n', maxDepth)"><x-bs-admin::icon name="ri-node-tree" class="size-3.5" /><span class="@max-[32.5rem]:hidden">{{ $u('deep') }}</span></span>
                                            <span x-show="r.it.options.mega" class="{{ $chip }} bg-primary-soft text-primary-text" :title="@js($u('mega_tip'))"><x-bs-admin::icon name="ri-layout-grid" class="size-3.5" /><span class="@max-[32.5rem]:hidden" x-text="r.it.options.mega ? @js($u('mega_n')).replace(':n', r.it.options.mega.columns) : ''"></span></span>
                                            <span x-show="desc(r.it)" class="{{ $chipGrey }}" :title="desc(r.it)"><x-bs-admin::icon name="ri-text" class="size-3.5" /><span class="@max-[32.5rem]:hidden">{{ $u('has_desc') }}</span></span>
                                            <span x-show="r.it.options.device !== 'all'" class="{{ $chipGrey }}"><x-bs-admin::icon name="ri-device" class="size-3.5" /><span class="@max-[32.5rem]:hidden" x-text="r.it.options.device === 'desktop' ? @js($u('only_pc')) : @js($u('only_phone'))"></span></span>
                                            <span x-show="r.state === 'unpublished' || (r.untr && !r.state)" class="{{ $chip }} bg-warning-soft text-warning-text" :title="r.state ? r.stateLabel : @js($u('untr_tip'))"><x-bs-admin::icon name="ri-eye-off" class="size-3.5" /><span class="@max-[32.5rem]:hidden" x-text="r.state ? r.stateLabel : @js($u('untr'))"></span></span>
                                            <span x-show="r.state === 'trashed' || r.state === 'gone'" class="{{ $chip }} bg-danger-soft text-danger-text" :title="r.stateLabel"><x-bs-admin::icon name="ri-error-warning" class="size-3.5" /><span class="@max-[32.5rem]:hidden" x-text="r.stateLabel"></span></span>
                                        </button>
                                        <span class="ml-auto shrink-0 whitespace-nowrap text-caption text-text-muted @max-[26.875rem]:hidden" x-text="r.src"></span>
                                        <span class="flex shrink-0 items-center gap-1 @min-[26.9375rem]:ml-0 @max-[26.875rem]:ml-auto">
                                            <button type="button" class="{{ $hit }}" x-on:click="move('moveUp', r.k)" x-bind:disabled="filtering" :title="@js(__('admin.tree.move_up')) + ' (Alt ↑)'" :aria-label="@js(__('admin.tree.move_up')) + ' — ' + r.name"><x-bs-admin::icon name="ri-arrow-up-s" class="size-5" /></button>
                                            <button type="button" class="{{ $hit }}" x-on:click="move('moveDown', r.k)" x-bind:disabled="filtering" :title="@js(__('admin.tree.move_down')) + ' (Alt ↓)'" :aria-label="@js(__('admin.tree.move_down')) + ' — ' + r.name"><x-bs-admin::icon name="ri-arrow-down-s" class="size-5" /></button>
                                            <span class="relative" x-on:click.stop>
                                                <button type="button" class="{{ $hit }}" :data-mn-more="r.k" x-on:click="rowMenu = rowMenu === r.k ? null : r.k" title="{{ $u('more_actions') }}"
                                                        :aria-label="@js($u('item_actions')).replace(':name', r.name)" aria-haspopup="menu" :aria-expanded="rowMenu === r.k"><x-bs-admin::icon name="ri-more-2-fill" class="size-4" /></button>
                                                <template x-if="rowMenu === r.k">
                                                    <div role="menu" :aria-label="@js($u('item_actions')).replace(':name', r.name)" class="absolute right-0 top-full z-30 mt-1 w-64 rounded-md border border-border bg-surface py-1 shadow-lg">
                                                        <div class="px-3 pb-1 pt-1.5 text-caption font-semibold text-text-muted [overflow-wrap:anywhere] @min-[48rem]:hidden" aria-hidden="true" x-text="r.name"></div>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="pick(r.k)"><x-bs-admin::icon name="ri-pencil" class="size-4" />{{ $u('edit') }}</button>
                                                        <template x-if="srcEdit(r.it)"><a role="menuitem" class="{{ $menuItem }}" :href="srcEdit(r.it)"><x-bs-admin::icon name="ri-external-link" class="size-4" /><span x-text="@js($u('src_open')).replace(':kind', kindName(r.it).toLowerCase())"></span></a></template>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="toggleHide(r.k)"><x-bs-admin::icon name="ri-eye-off" class="size-4" /><span x-text="r.it.options.hidden ? @js($u('show')) : @js($u('hide'))"></span></button>
                                                        <div class="my-1 h-px bg-border"></div>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="move('indent', r.k)" x-bind:disabled="filtering"><x-bs-admin::icon name="ri-indent-increase" class="size-4" />{{ $u('indent') }}<span class="ml-auto text-caption text-text-muted">Alt →</span></button>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="move('outdent', r.k)" x-bind:disabled="filtering || !r.it.parent"><x-bs-admin::icon name="ri-indent-decrease" class="size-4" />{{ $u('outdent') }}<span class="ml-auto text-caption text-text-muted">Alt ←</span></button>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="mvOpen(r.k)" x-bind:disabled="filtering"><x-bs-admin::icon name="ri-drag-move-2" class="size-4" />{{ $u('move_to') }}</button>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="copy(r.k)" x-bind:disabled="r.k.startsWith('new:')"><x-bs-admin::icon name="ri-file-copy" class="size-4" />{{ $u('dup') }}</button>
                                                        <div class="my-1 h-px bg-border"></div>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="revert(r.k)" x-bind:disabled="!r.unsaved || r.k.startsWith('new:')"><x-bs-admin::icon name="ri-history" class="size-4" />{{ $u('revert') }}</button>
                                                        <div class="my-1 h-px bg-border"></div>
                                                        <button type="button" role="menuitem" class="{{ $menuItem }} !text-danger-text" x-on:click="remove(r.k)"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ $u('remove') }}</button>
                                                    </div>
                                                </template>
                                            </span>
                                        </span>
                                    </div>
                                    <div x-show="drop && drop.k === r.k && drop.at === 'after'" role="presentation" x-on:dragover="dragOverPh($event)" x-on:drop="dragDrop($event)"
                                         class="mx-3.5 my-1 flex items-center gap-2 rounded border-[1.5px] border-dashed px-3 py-2 text-caption"
                                         :class="drop && drop.bad ? 'border-danger-text text-danger-text' : 'border-primary-text bg-primary-soft text-primary-text'"
                                         :style="drop && 'margin-left:' + (14 + (drop.lv - 1) * 22) + 'px'"><span x-text="drop && drop.label"></span></div>

                                    {{-- The inline editor --}}
                                    <template x-if="sel === r.k">
                                        <div class="border-t border-border bg-surface-muted px-4 pb-4 pt-3.5">
                                            <div class="grid grid-cols-2 gap-3.5 @max-[37.5rem]:grid-cols-1">
                                                <label class="flex min-w-0 flex-col gap-1.5">
                                                    <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_label') }}<span class="{{ $chipGrey }}" x-text="loc.toUpperCase()"></span></span>
                                                    <input id="mn-ed-label" class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" maxlength="120" :value="label(r.it)" x-on:change="setLabel(r.k, $event.target.value)"
                                                           :placeholder="onMain ? fallbackName(r.it) : (label(r.it, @js($main)) || fallbackName(r.it))" :aria-invalid="labelErr(r.it)" aria-describedby="mn-lbl-hint" />
                                                    <span id="mn-lbl-hint" class="text-caption leading-normal" :class="labelErr(r.it) ? 'font-semibold text-danger-text' : 'text-text-muted'"
                                                          x-text="labelErr(r.it) ? @js($u('f_label_err')) : (onMain ? @js($u('f_label_hint')) : @js($u('f_label_src')).replace(':text', label(r.it, @js($main)) || fallbackName(r.it)))"></span>
                                                </label>
                                                <label class="flex min-w-0 flex-col gap-1.5">
                                                    <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_url') }}<span x-show="!onMain" class="{{ $chipGrey }}"><x-bs-admin::icon name="ri-lock" class="size-3" />{{ $u('shared') }}</span></span>
                                                    <input class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" :value="r.it.link.type === 'url' ? r.it.link.url : srcText(r.it)" x-bind:disabled="!onMain || r.it.link.type !== 'url'"
                                                           x-on:change="editUrl(r.k, $event.target.value)" placeholder="/lien-he" />
                                                    <span class="text-caption leading-normal text-text-muted"
                                                          x-text="!onMain ? @js($u('f_url_shared')).replace(':lang', locName(@js($main))) : (r.it.link.type === 'url' ? @js($u('f_url_hint')) : @js($u('f_url_auto')).replace(':kind', kindName(r.it).toLowerCase()))"></span>
                                                </label>
                                            </div>
                                            <div class="mt-3.5 grid grid-cols-2 gap-3.5 @max-[37.5rem]:grid-cols-1">
                                                <label class="flex min-w-0 flex-col gap-1.5">
                                                    <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_desc') }}<span class="{{ $chipGrey }}" x-text="loc.toUpperCase()"></span>
                                                        <span class="ml-auto font-normal" :class="desc(r.it).length > descMax(r.it) ? 'text-warning-text' : 'text-text-muted'" x-text="desc(r.it).length + '/' + descMax(r.it)"></span></span>
                                                    <textarea rows="3" maxlength="500" class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted leading-relaxed" :value="desc(r.it)" x-on:change="setDesc(r.k, $event.target.value.trim())"
                                                              :placeholder="@js($u('f_desc_ph'))"></textarea>
                                                    <span class="text-caption leading-normal text-text-muted"
                                                          x-text="desc(r.it).length > descMax(r.it) ? @js($u('f_desc_long')).replace(':n', descMax(r.it)) : (r.it.options.mega ? @js($u('f_desc_mega')) : @js($u('f_desc_hint')))"></span>
                                                </label>
                                                <div class="flex min-w-0 flex-col gap-1.5">
                                                    <span class="flex items-center gap-1.5 text-caption font-semibold text-text">{{ $u('f_icon') }}<span class="font-normal text-text-muted">{{ $u('optional') }}</span></span>
                                                    <span class="relative flex flex-wrap items-center gap-2">
                                                        <button type="button" class="{{ $btn }} min-h-control" x-on:click.stop="iconOpen = !iconOpen" :aria-expanded="iconOpen">
                                                            <template x-if="r.it.options.icon"><svg class="size-5" aria-hidden="true"><use :href="'#mn-ic-' + r.it.options.icon"></use></svg></template>
                                                            <span x-text="r.it.options.icon ? @js($u('icon_change')) : @js($u('icon_pick'))"></span><x-bs-admin::icon name="ri-arrow-down-s" class="size-4" />
                                                        </button>
                                                        <div x-show="iconOpen" x-on:click.stop class="absolute right-0 top-full z-30 mt-1.5 box-border w-[min(308px,calc(100vw-32px))] rounded-md border border-border bg-surface p-2.5 shadow-lg">
                                                            <input type="search" class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" x-model="iconQ" placeholder="{{ $u('icon_search') }}" aria-label="{{ $u('icon_search') }}" />
                                                            <div class="mt-2 flex max-h-[230px] flex-wrap gap-1.5 overflow-auto">
                                                                <template x-for="ic in iconRows()" :key="ic.name">
                                                                    <button type="button" class="hit-area grid size-control place-items-center rounded border text-text hover:bg-surface-hover"
                                                                            :class="r.it.options.icon === ic.name ? 'border-primary-text bg-primary-soft text-primary-text' : 'border-border'" :title="ic.label" :aria-label="ic.label" :aria-pressed="r.it.options.icon === ic.name"
                                                                            x-on:click="setIcon(r.k, ic.name)"><svg class="size-5" aria-hidden="true"><use :href="'#mn-ic-' + ic.name"></use></svg></button>
                                                                </template>
                                                                <p x-show="!iconRows().length" class="w-full px-1 py-3.5 text-center text-caption leading-relaxed text-text-muted">{{ $u('icon_none_found') }}</p>
                                                            </div>
                                                            <div class="mt-2 flex items-center gap-2 border-t border-border-soft pt-2">
                                                                <span class="flex-auto text-caption text-text-muted" x-text="@js($u('icon_count')).replace(':n', iconRows().length)"></span>
                                                                <button type="button" x-show="r.it.options.icon" class="{{ $btn }}" x-on:click="setIcon(r.k, null)"><x-bs-admin::icon name="ri-close" class="size-4" />{{ $u('icon_clear') }}</button>
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
                                                    <button type="button" class="{{ $tab }}" x-bind:class="r.it.options.device === '{{ $dv }}' && @js($tabOn)" x-bind:aria-pressed="r.it.options.device === '{{ $dv }}'" x-on:click="setOpt(r.k, 'device', '{{ $dv }}')">
                                                        <x-bs-admin::icon :name="$ic" class="size-4" />{{ $u($lbl) }}</button>
                                                @endforeach
                                            </div>
                                            <div class="mt-3.5 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                                                <span class="min-w-0 flex-[1_1_220px] text-caption leading-relaxed text-text-muted" x-text="@js($u('ed_foot')).replace(':kind', kindName(r.it))"></span>
                                                <button type="button" class="{{ $btn }} min-h-control" x-on:click="closeEditor()">{{ $u('done') }}</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div x-show="items.some(it => depthMap()[it.key] + 1 >= maxDepth)" class="flex items-center gap-2 border-t border-border px-3.5 py-2.5 text-caption leading-relaxed"
                             :class="items.some(it => depthMap()[it.key] + 1 > maxDepth) ? 'bg-danger-soft text-danger-text' : 'bg-warning-soft text-warning-text'">
                            <x-bs-admin::icon name="ri-information" class="size-4 shrink-0" />
                            <span x-text="items.some(it => depthMap()[it.key] + 1 > maxDepth) ? @js($u('depth_over')).replace(':n', maxDepth) : (menu.limits.mega ? @js($u('depth_at_mega')) : @js($u('depth_at'))).replace(':n', maxDepth)"></span>
                        </div>
                        <div class="flex items-center gap-2 border-t border-border px-3.5 py-2">
                            <span class="shrink-0 text-text-faint"><x-bs-admin::icon name="ri-keyboard" class="size-4" /></span>
                            <span class="text-caption leading-relaxed text-text-muted">{{ $u('keys_note') }}</span>
                        </div>
                    </div>
                    </template>

                    {{-- What visitors will see --}}
                    <div class="{{ $card }}" x-show="menu">
                        <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                            <span class="{{ $title }}">{{ $u('preview') }}</span>
                            <span class="ml-auto flex flex-wrap items-center gap-1.5" role="group" aria-label="{{ $u('preview') }}">
                                @foreach (['pc' => ['ri-computer', 'pv_pc', 'pv_pc_tip'], 'mb' => ['ri-smartphone', 'pv_mb', 'pv_mb_tip']] as $dv => [$ic, $lbl, $tip])
                                    <button type="button" class="{{ $tab }}" x-bind:class="device === '{{ $dv }}' && @js($tabOn)" x-bind:aria-pressed="device === '{{ $dv }}'" title="{{ $u($tip) }}" x-on:click="device = '{{ $dv }}'; pvOpen = null">
                                        <x-bs-admin::icon :name="$ic" class="size-4" />{{ $u($lbl) }}</button>
                                @endforeach
                            </span>
                        </div>
                        <div class="p-4">
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
                        </div>
                    </div>
                </div>

                {{-- Right column: add to the menu --}}
                <div class="flex flex-col gap-4 @max-[75.625rem]:col-start-2 @max-[53.75rem]:order-4" x-show="menu">
                    <div class="{{ $card }}">
                        <button type="button" class="{{ $head }}" x-on:click="fold2.add = !fold2.add" :aria-expanded="!fold2.add">
                            <x-bs-admin::icon name="ri-arrow-down-s" class="size-4 shrink-0 text-text-muted transition-transform" x-bind:class="fold2.add && '-rotate-90'" />
                            <span class="{{ $title }}">{{ $u('add') }}</span>
                        </button>
                        <div x-show="!fold2.add">
                            <div class="flex flex-wrap gap-1.5 px-3 py-2.5" role="group" aria-label="{{ $u('add') }}">
                                @foreach (['page' => 'tab_page', 'category' => 'tab_category', 'product' => 'tab_product', 'special' => 'tab_special', 'url' => 'tab_url'] as $k => $lbl)
                                    <button type="button" class="{{ $tab }}" x-bind:class="tab === '{{ $k }}' && @js($tabOn)" x-bind:aria-pressed="tab === '{{ $k }}'" x-on:click="setTab('{{ $k }}')">{{ $u($lbl) }}</button>
                                @endforeach
                            </div>
                            <div x-show="sel" class="flex flex-wrap items-center gap-1.5 px-3 pb-2.5">
                                <span class="text-caption text-text-muted">{{ $u('add_to') }}</span>
                                <button type="button" class="{{ $tab }}" :class="addTo !== 'sel' && @js($tabOn)" :aria-pressed="addTo !== 'sel'" x-on:click="addTo = 'end'">{{ $u('add_end') }}</button>
                                <button type="button" class="{{ $tab }}" :class="addTo === 'sel' && @js($tabOn)" :aria-pressed="addTo === 'sel'" title="{{ $u('add_in_tip') }}" x-on:click="addTo = 'sel'"
                                        x-text="@js($u('add_in')).replace(':name', sel ? name(item(sel)) : '')"></button>
                            </div>

                            <div x-show="tab === 'url'" class="flex flex-col gap-2.5 px-3 pb-3.5 pt-1">
                                <label class="flex min-w-0 flex-col gap-1.5"><span class="text-caption font-semibold text-text">{{ $u('f_label') }}</span>
                                    <input class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" x-model="newLabel" maxlength="120" placeholder="{{ $u('new_label_ph') }}" /></label>
                                <label class="flex min-w-0 flex-col gap-1.5"><span class="text-caption font-semibold text-text">{{ $u('f_address') }}</span>
                                    <input class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted" x-model="newUrl" inputmode="url" autocomplete="url" placeholder="{{ $u('new_url_ph') }}" aria-describedby="mn-url-hint" /></label>
                                <span id="mn-url-hint" class="text-caption leading-normal" :class="newUrl.trim() && !url.ok ? 'text-danger-text' : 'text-text-muted'"
                                      x-text="!newUrl.trim() ? @js($u('url_empty')) : (url.ok ? @js($u('url_will')).replace(':url', url.url) + (url.note ? ' — ' + url.note : '') : url.msg)"></span>
                                <button type="button" class="{{ $btn }} min-h-control" x-on:click="addUrl()" x-bind:disabled="!newLabel.trim() || !url.ok"><x-bs-admin::icon name="ri-add" class="size-4" />{{ $u('add_link') }}</button>
                            </div>

                            <div x-show="tab !== 'url'">
                                <div class="px-3 pb-2.5">
                                    <label class="flex items-center gap-2 rounded border border-border-input bg-surface px-2.5 focus-ring-within focus-within:border-primary-text">
                                        <span class="text-text-muted"><x-bs-admin::icon name="ri-search" class="size-4" /></span>
                                        <input type="search" x-model="search" x-on:input="findSoon()" class="min-h-control min-w-0 flex-auto border-0 bg-transparent text-body text-text-strong"
                                               :aria-label="@js(['page' => $u('find_page'), 'category' => $u('find_category'), 'product' => $u('find_product'), 'special' => $u('find_special')])[tab]"
                                               :placeholder="@js(['page' => $u('find_page'), 'category' => $u('find_category'), 'product' => $u('find_product'), 'special' => $u('find_special')])[tab]" />
                                    </label>
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
                                            <span x-show="s.used" class="{{ $chipGrey }}">{{ $u('already') }}</span>
                                            <span x-show="s.draft" class="{{ $chip }} bg-warning-soft text-warning-text">{{ $u('unpublished') }}</span>
                                        </button>
                                    </template>
                                    <p x-show="!srcRows().length && !searching" class="px-3.5 py-5 text-center text-caption leading-relaxed text-text-muted">{{ $u('no_match') }}</p>
                                    <p x-show="searching && !srcRows().length" role="status" class="px-3.5 py-5 text-center text-caption text-text-muted">{{ $u('searching') }}</p>
                                </div>
                                <div class="border-t border-border px-3 py-2.5">
                                    <button type="button" class="{{ $btn }} min-h-control w-full" x-on:click="addPicked()" x-bind:disabled="!pickedN">
                                        <x-bs-admin::icon name="ri-add" class="size-4" /><span x-text="pickedN ? @js($u('add_n')).replace(':n', pickedN) : @js($u('pick_first'))"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
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
        <button type="button" class="{{ $btn }} min-h-control" x-on:click="reset()" title="{{ $u('reset_tip') }}"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />{{ $u('reset') }}</button>
        <button type="button" class="{{ $pri }}" x-on:click="save()" x-bind:disabled="saving"><x-bs-admin::icon name="ri-save-3" class="size-4" /><span x-text="saving ? @js($u('saving')) : @js($u('save'))">{{ $u('save') }}</span></button>
    </div>

    {{-- Ask before acting --}}
    <template x-if="ask">
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/45 p-5" x-on:click.self="askClose()" x-init="askOpened()">
            <div class="{{ $card }} max-h-[calc(100dvh-40px)] w-full max-w-[470px] overflow-auto shadow-xl" role="dialog" aria-modal="true" aria-labelledby="mn-ask-t" aria-describedby="mn-ask-d" x-trap.noscroll="ask">
                <div class="border-b border-border px-4 py-3"><h2 id="mn-ask-t" class="{{ $title }}" x-text="ask.title"></h2></div>
                <p id="mn-ask-d" class="p-4 text-small leading-relaxed text-text" x-text="ask.body"></p>
                <div class="flex flex-wrap justify-end gap-2 border-t border-border px-4 py-3">
                    <button type="button" id="mn-ask-cancel" class="{{ $btn }} min-h-control" x-on:click="askClose()">{{ $u('cancel') }}</button>
                    <button type="button" x-show="ask.alt" class="{{ $btn }} min-h-control" x-on:click="askAlt()" x-text="ask.alt"></button>
                    <button type="button" class="{{ $pri }}" :class="ask.danger && '!bg-danger !text-white'" x-on:click="askOk()" x-text="ask.ok"></button>
                </div>
            </div>
        </div>
    </template>

    {{-- "Chuyển đến…" --}}
    <template x-if="mv">
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/45 p-5 @max-[37.5rem]:items-end @max-[37.5rem]:p-0" x-on:click.self="mvClose()">
            <div class="{{ $card }} flex max-h-[calc(100dvh-40px)] w-[min(520px,100%)] flex-col shadow-xl @max-[37.5rem]:rounded-b-none" role="dialog" aria-modal="true" aria-labelledby="mn-mv-t" x-trap.noscroll="mv">
                <div class="flex items-center gap-2 border-b border-border px-4 py-3">
                    <h2 id="mn-mv-t" class="{{ $title }} flex-1" x-text="@js($u('mv_title')).replace(':name', name(item(mv.k)))"></h2>
                    <button type="button" class="{{ $hit }} size-hit" x-on:click="mvClose()" aria-label="{{ $u('close') }}" title="{{ $u('close') }}"><x-bs-admin::icon name="ri-close" class="size-5" /></button>
                </div>
                <div class="flex min-h-0 flex-col gap-2.5 overflow-hidden p-4">
                    <label class="flex items-center gap-2 rounded border border-border-input bg-surface px-2.5 focus-ring-within focus-within:border-primary-text">
                        <span class="text-text-muted"><x-bs-admin::icon name="ri-search" class="size-4" /></span>
                        <input type="search" id="mn-mv-q" x-model="mv.q" aria-label="{{ $u('mv_search') }}" placeholder="{{ $u('mv_search') }}" class="min-h-control min-w-0 flex-auto border-0 bg-transparent text-body" />
                    </label>
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
                <div class="flex flex-wrap justify-end gap-2 border-t border-border px-4 py-3">
                    <button type="button" class="{{ $btn }} min-h-control" x-on:click="mvClose()">{{ $u('cancel') }}</button>
                    <button type="button" class="{{ $pri }} max-w-full" x-bind:disabled="!mv.pick" x-on:click="mvGo()"><span class="[overflow-wrap:anywhere]" x-text="mvBtn"></span></button>
                </div>
            </div>
        </div>
    </template>

    {{-- Messages --}}
    <div x-cloak x-show="toast" role="status" aria-live="polite" x-on:mouseenter="toastHold()" x-on:mouseleave="toastGo()" x-on:focusin="toastHold()" x-on:focusout="toastGo()"
         class="fixed bottom-[max(calc(26px+env(safe-area-inset-bottom,0px)),88px)] left-1/2 z-[80] flex max-w-[min(560px,calc(100vw-32px))] -translate-x-1/2 items-center gap-3 rounded-md bg-text-strong px-4 py-3 text-surface shadow-xl">
        <span class="text-small leading-relaxed" x-text="toast && toast.text"></span>
        <button type="button" x-show="toast && toast.undo" class="hit-area min-h-control-row shrink-0 whitespace-nowrap rounded border border-current px-3 text-caption font-semibold" x-on:click="toastUndo()">{{ $u('undo') }}</button>
        <button type="button" class="hit-area inline-grid size-control-row shrink-0 place-items-center rounded" x-on:click="toastClose()" aria-label="{{ $u('close') }}" title="{{ $u('close') }}"><x-bs-admin::icon name="ri-close" class="size-4" /></button>
    </div>
    <span class="sr-only" role="status" aria-live="polite" x-text="live"></span>
</div>
