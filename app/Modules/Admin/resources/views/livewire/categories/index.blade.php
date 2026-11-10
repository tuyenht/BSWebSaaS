{{--
    Chuyên mục (design 10), one section's category tree. The server draws the rows (the shared
    <x-bs-admin::tree host>, each row carrying its category as data-node) and makes every change;
    the screen's Alpine part 'bsCategories' (resources/js/admin-categories.js, loaded through the
    shared lazy loader) holds the search and filters, the editor drawer, the dialogs and the message.
    Names, paths and descriptions are only ever shown as text.
--}}
@php
    $u = fn (string $key, array $r = []): string => __('categories.ui_'.$key, $r);
    $j = fn (string $text): string => \Illuminate\Support\Js::from($text)->toHtml();
    $noun = $products ? $u('noun_products') : $u('noun_posts');
    $layoutName = fn (?string $name): string => $name === null ? $u('tpl_default') : (\Illuminate\Support\Facades\Lang::has('categories.ui_layout_'.$name) ? $u('layout_'.$name) : $name);
@endphp

<div class="@container">
@if ($state !== 'data')
    <div class="mx-auto flex w-full max-w-[1400px] flex-col gap-4">
        <x-bs-admin::page-header :title="$label !== '' ? __('categories.title', ['section' => $label]) : $u('crumb')" class="!mb-0" />
        <x-bs-admin::card>
            <div class="mx-auto flex max-w-xl flex-col items-center gap-3 py-8 text-center">
                <span class="inline-flex size-12 items-center justify-center rounded-full bg-surface-muted text-text-muted"><x-bs-admin::icon name="ri-lock-2" class="size-6" /></span>
                @if ($state === 'locked')
                    <h2 class="text-h3 font-semibold text-text-strong">{{ __('admin.catalog') }}</h2>
                    <p class="text-body text-text-muted">{{ $u('locked_text') }}</p>
                    @if ($supportMail !== '')
                        <x-bs-admin::button :href="'mailto:'.$supportMail">
                            <x-bs-admin::icon name="ri-customer-service-2" class="size-4" /> {{ $u('support') }}
                        </x-bs-admin::button>
                    @endif
                @else
                    <h2 class="text-h3 font-semibold text-text-strong">{{ $u('noperm_title') }}</h2>
                    <p class="text-body text-text-muted">{{ $role ? $u('noperm_text', ['role' => $role]) : $u('noperm_text_plain') }}</p>
                    <x-bs-admin::button variant="outline" color="secondary" :href="route('admin.entries.index')">
                        <x-bs-admin::icon name="ri-arrow-left" class="size-4" /> {{ $u('back_list_plain') }}
                    </x-bs-admin::button>
                @endif
            </div>
        </x-bs-admin::card>
    </div>
@else
    @php
        $main = $screen['main'];
        $locales = $screen['locales'];
        $multi = count($locales) > 1;
        $langNames = collect($locales)->mapWithKeys(fn (string $c): array => [$c => \Illuminate\Support\Facades\Lang::has('categories.ui_lang_'.$c) ? $u('lang_'.$c) : strtoupper($c)])->all();
        $rows = $screen['rows'];
        $depth = $screen['limits']['depth'];
        $total = collect($rows)->filter(fn (array $r): bool => ! $r['node']['locked'])->count();
        $lowerLabel = mb_strtolower($label);
        $texts = collect([
            'netFail', 'retry', 'reload', 'reloaded', 'filtered', 'moved', 'movedUnder', 'movedInto', 'movedOut', 'undone',
            'mvHere', 'mvLockDepth', 'mvLock', 'mvLevel', 'mvTop', 'mvUnder', 'cxOk', 'mvPick', 'mvGoTop', 'mvGoUnder', 'mvGo',
            'cxTitle', 'cxText', 'cxLine', 'nounProducts', 'nounPosts', 'mergeTitle', 'delTitle', 'mergeLead', 'nKids', 'and',
            'delLead', 'delLeadNone', 'noProducts', 'noPosts', 'home', 'fxPagesKids', 'fxPage', 'fxItemsMove', 'fxItemsDetach',
            'fxRedirects', 'fxMenu', 'fxKidsUp', 'outer', 'mergeOk', 'delOk', 'mergeOkItems', 'delOkMany', 'andItems', 'delAnd',
            'delMoveItems', 'delDetachItems', 'pickTarget', 'cxLeaveTitle', 'cxLeaveLine', 'merged', 'deletedKids', 'deleted',
            'movedItems', 'movedItemsTo', 'detachedItems', 'tooDeepAdd', 'editTitle', 'addChildTitle', 'addTitle', 'lineSrc',
            'lineFull', 'lineEmpty', 'slugPage', 'slugAuto', 'slugKeep', 'slugWarn', 'outerOpt', 'unsavedTitle', 'unsavedText',
            'unsavedDrop', 'unsavedSave', 'saved', 'added', 'copied', 'example', 'tplGone', 'lockedOpt', 'onSave', 'saveMove',
            'cxSaveTitle', 'save',
        ])->mapWithKeys(fn (string $k): array => [$k => $u(\Illuminate\Support\Str::snake($k))])->all();
        $config = [
            'section' => $screen['section']['key'], 'label' => $label, 'main' => $main, 'locales' => $locales, 'langNames' => $langNames,
            'bases' => $bases, 'layouts' => $layouts, 'detailLayouts' => $detailLayouts, 'products' => $products, 'canManage' => $canManage, 'limits' => $screen['limits'], 'texts' => $texts,
        ];
        // a few names to start from, for the standard sections (a section of the website's own has none)
        $exampleSet = ['posts' => 'n', 'services' => 's', 'projects' => 'j', 'catalog' => 'p'][$screen['section']['key']] ?? null;
        $examples = $exampleSet === null ? [] : array_map(fn (int $i): string => 'ex_'.$exampleSet.$i, [1, 2, 3]);
        $namePh = $u($examples[0] ?? ($products ? 'ex_p1' : 'ex_n1'));
        $roleText = $limitNames !== null ? $u('role_text_limited', ['list' => implode(', ', $limitNames)]) : $u('role_text');
        $off = $canManage ? null : 'true';
        $col = 'shrink-0 text-small text-text-muted';
        $tplCol = $col.' w-[calc(11.25rem+var(--user-text-boost)*10)] @max-[63.99rem]:hidden';
        $cntCol = $col.' flex w-[calc(6rem+var(--user-text-boost)*4)] justify-end @max-[47.99rem]:hidden';
    @endphp
    <div x-ignore wire:ignore.self data-bs-lazy="bsCategories" x-data="bsCategories(@js($config))" x-on:category-cover-picked.window="coverPicked($event.detail)"
        class="mx-auto flex w-full max-w-[1400px] flex-col gap-4">
        <x-bs-admin::page-header :title="__('categories.title', ['section' => $label])" :subtitle="$products ? $u('desc_products') : $u('desc_posts', ['section' => $lowerLabel])" class="!mb-0">
            <x-slot:breadcrumbs><a href="{{ $listUrl }}" wire:navigate class="underline-offset-2 hover:underline">{{ $label }}</a> › {{ $u('crumb') }}</x-slot:breadcrumbs>
            <x-slot:actions>
                <x-bs-admin::button x-on:click="openAdd(null)" aria-disabled="{{ $off }}" :title="$canManage ? null : $u('no_manage')">
                    <x-bs-admin::icon name="ri-add" class="size-4" /> {{ $u('add') }}
                </x-bs-admin::button>
            </x-slot:actions>
        </x-bs-admin::page-header>

        {{-- shown until the screen's part has started (one more download for this screen only); the
             shared loader marks the root when the download is lost, and the CSS swaps the two blocks --}}
        <div x-show="false">
            <x-bs-admin::card>
                <div data-bs-lazy-wait aria-busy="true" class="flex flex-col gap-3">
                    @foreach ([['', 'max-w-70'], ['ms-6', 'max-w-55'], ['ms-12', 'max-w-45'], ['ms-6', 'max-w-60']] as [$indent, $width])
                        <div class="flex items-center gap-3"><span class="size-5 rounded bg-surface-muted {{ $indent }}"></span><span class="h-4 flex-1 rounded bg-surface-muted {{ $width }}"></span></div>
                    @endforeach
                    <p class="text-small text-text-muted">{{ $u('loading') }}</p>
                </div>
                <div data-bs-lazy-error class="mx-auto flex max-w-xl flex-col items-center gap-3 py-8 text-center">
                    <span class="inline-flex size-12 items-center justify-center rounded-full bg-danger-soft text-danger-text"><x-bs-admin::icon name="ri-wifi-off" class="size-6" /></span>
                    <h2 class="text-h3 font-semibold text-text-strong">{{ $u('error_title') }}</h2>
                    <p role="alert" class="text-body text-text-muted">{{ $u('error_text') }}</p>
                    <x-bs-admin::button data-bs-lazy-retry><x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ $u('retry') }}</x-bs-admin::button>
                </div>
            </x-bs-admin::card>
        </div>

        @if ($total === 0)
            <x-bs-admin::card x-cloak>
                <div class="mx-auto flex max-w-xl flex-col items-center gap-3 py-8 text-center">
                    <span class="inline-flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary-text"><x-bs-admin::icon name="ri-folders" class="size-6" /></span>
                    <h2 class="text-h3 font-semibold text-text-strong">{{ $u('empty_title') }}</h2>
                    <p class="text-body text-text-muted">{{ $products ? $u('empty_text_products') : $u('empty_text', ['section' => $lowerLabel]) }}</p>
                    <x-bs-admin::button x-on:click="openAdd(null)" aria-disabled="{{ $off }}"><x-bs-admin::icon name="ri-add" class="size-4" /> {{ $u('empty_title') }}</x-bs-admin::button>
                    @if ($canManage && $examples !== [])
                        <p class="text-small text-text-muted">{{ $u('examples') }}</p>
                        <div class="flex flex-wrap justify-center gap-2">
                            @foreach ($examples as $ex)
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="addExample({{ $j($u($ex)) }})"><x-bs-admin::icon name="ri-add" class="size-4" /> {{ $u($ex) }}</x-bs-admin::button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </x-bs-admin::card>
        @endif

        <div x-cloak @class(['flex flex-col gap-3', 'hidden' => $total === 0 && count($rows) <= 1])>
            @if ($role)
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-small text-text-muted @max-[56.24rem]:order-last" role="note">
                    <x-bs-admin::icon name="ri-shield-user" class="size-4 shrink-0" />
                    <x-bs-admin::badge soft pill color="primary">{{ $role }}</x-bs-admin::badge>
                    <x-bs-admin::button size="sm" variant="ghost" color="primary" x-on:click="roleOpen = !roleOpen" x-bind:aria-expanded="roleOpen.toString()">
                        <span x-text="roleOpen ? {{ $j($u('role_less')) }} : {{ $j($u('role_more')) }}">{{ $u('role_more') }}</span>
                    </x-bs-admin::button>
                    <span x-show="roleOpen" class="min-w-0 basis-full [overflow-wrap:anywhere]">{{ $roleText }}</span>
                </div>
            @endif

            <x-bs-admin::card flush>
                <section aria-label="{{ $u('tree_card') }}">
                    {{-- tools --}}
                    <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                        <span class="text-small font-semibold text-text-strong whitespace-nowrap @max-[56.24rem]:hidden" x-text="fill({{ $j($u('total')) }}, { n: counts.total })">{{ $u('total', ['n' => $total]) }}</span>
                        <div class="min-w-48 flex-[1_1_16rem]">
                            <x-bs-admin::search :label="$u('search_label')" :placeholder="$u('search_ph', ['n' => $total])" x-model="q" data-cm-q />
                        </div>
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="toggleChips()" x-bind:aria-expanded="(fOpen || chip !== 'all').toString()">
                            <x-bs-admin::icon name="ri-filter-3" class="size-4" /> <span x-text="fOpen || chip !== 'all' ? {{ $j($u('chips_off')) }} : {{ $j($u('chips')) }}">{{ $u('chips') }}</span>
                        </x-bs-admin::button>
                        <div x-show="fOpen || chip !== 'all'" role="group" aria-label="{{ $u('chips_group') }}" class="flex basis-full flex-wrap gap-2 @min-[56.25rem]:order-none">
                            <span class="basis-full text-small font-semibold text-text-strong @min-[56.25rem]:hidden" x-text="fill({{ $j($u('total')) }}, { n: counts.total })">{{ $u('total', ['n' => $total]) }}</span>
                            @foreach (array_filter(['all' => ['ri-list-check', $u('chip_all')], 'empty' => ['ri-inbox', $products ? $u('no_products') : $u('no_posts')], 'tr' => $multi ? ['ri-translate-2', $u('chip_tr')] : null]) as $id => [$icon, $text])
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="chip = '{{ $id }}'" x-bind:aria-pressed="(chip === '{{ $id }}').toString()">
                                    <x-bs-admin::icon :name="$icon" class="size-4" /> {{ $text }}
                                    <x-bs-admin::badge soft pill color="secondary"><span x-text="{{ $id === 'all' ? 'counts.total' : 'counts.'.$id }}"></span></x-bs-admin::badge>
                                </x-bs-admin::button>
                            @endforeach
                        </div>
                        <span class="flex-1 @max-[37.49rem]:hidden"></span>
                        <x-bs-admin::dropdown align="end" class="not-any-pointer-fine:hidden">
                            <x-slot:trigger>
                                <x-bs-admin::button size="sm" variant="ghost" color="primary"><x-bs-admin::icon name="ri-question" class="size-4" /> <span class="@max-[56.24rem]:sr-only">{{ __('admin.tree.keys') }}</span></x-bs-admin::button>
                            </x-slot:trigger>
                            <div class="w-[min(22rem,calc(100vw-2rem))] px-3 py-2"><x-bs-admin::tree.keys open /></div>
                        </x-bs-admin::dropdown>
                        <x-bs-admin::button size="sm" variant="ghost" color="primary" x-show="!filtering && nested" x-on:click="foldAll()">
                            <span x-show="folded"><x-bs-admin::icon name="ri-expand-up-down" class="size-4" /></span>
                            <span x-show="!folded"><x-bs-admin::icon name="ri-collapse-vertical" class="size-4" /></span>
                            <span class="@max-[56.24rem]:hidden" x-text="folded ? {{ $j($u('open_all')) }} : {{ $j($u('close_all')) }}">{{ $u('close_all') }}</span>
                            <span class="@min-[56.25rem]:hidden" x-text="folded ? {{ $j($u('open_short')) }} : {{ $j($u('close_short')) }}">{{ $u('close_short') }}</span>
                        </x-bs-admin::button>
                    </div>

                    {{-- the filter's note, or nothing matches --}}
                    <div x-show="filtering && shown > 0" class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-4 py-2 text-small text-text">
                        <x-bs-admin::icon name="ri-filter-3" class="size-4 shrink-0" />
                        <span class="min-w-0 flex-1" x-text="fill({{ $j($u('filter_note')) }}, { shown, total: counts.total })"></span>
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="clearFilter()"><x-bs-admin::icon name="ri-close" class="size-4" /> {{ $u('clear_filter') }}</x-bs-admin::button>
                    </div>
                    <div x-show="filtering && shown === 0" class="flex flex-col items-center gap-3 px-4 py-6 text-center">
                        <p class="text-body text-text-muted [overflow-wrap:anywhere]" x-text="q.trim() ? fill({{ $j($u('no_match')) }}, { q: q.trim() }) + (chip !== 'all' ? {{ $j($u('no_match_chip')) }} : '.') : {{ $j($u('no_match_filter')) }}"></p>
                        <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="clearFilter()"><x-bs-admin::icon name="ri-close" class="size-4" /> {{ $u('clear_filter') }}</x-bs-admin::button>
                    </div>

                    {{-- column heads --}}
                    <div class="flex items-center gap-2 border-b border-border px-3 py-2 text-caption font-semibold uppercase tracking-wide text-text-muted @max-[47.99rem]:hidden" aria-hidden="true">
                        <span class="w-6 shrink-0"></span>
                        <span class="min-w-0 flex-1">{{ $u('col_name') }}</span>
                        <span class="{{ $tplCol }} !text-caption">{{ $u('col_tpl') }}</span>
                        <span class="{{ $cntCol }} !text-caption">{{ $products ? $u('col_products') : $u('col_posts') }}</span>
                        <span class="w-[calc(13rem+var(--user-text-boost)*8)] shrink-0 text-right">{{ $u('col_act') }}</span>
                    </div>

                    <x-bs-admin::tree host move="treeMove" narrow-step="1.25rem" :label="$u('tree_label', ['section' => $lowerLabel])" :max-depth="$depth" :noun="$u('tree_noun')">
                        @foreach ($rows as $r)
                            @php
                                $n = $r['node'];
                                $isRoot = $n['locked'];
                                $missing = array_values(array_filter($locales, fn (string $c): bool => $c !== $main && ! (isset($n['names'][$c], $n['slugs'][$c]))));
                                $alt = collect($n['names'])->except($main)->filter()->map(fn (string $v, string $c): string => strtoupper($c).' '.$v)->implode(' · ');
                                $count = $n['items'] > 0 ? $n['items'].' '.$noun : ($products ? $u('no_products') : $u('no_posts'));
                                $slug = '/'.($n['slugs'][$main] ?? '');
                                $tpl = $layoutName($n['listTemplate']);
                                $lockWhy = ! $isRoot && ! $n['movable'] ? __('categories.reason_outside_category_limits') : null;
                                $data = collect($n)->only(['key', 'parent', 'name', 'names', 'slugs', 'descriptions', 'seoTitles', 'seoDescriptions', 'trail', 'listTemplate', 'detailTemplate', 'cover', 'items', 'locked', 'editable', 'movable'])->all();
                                $canChild = $canManage && $r['level'] < $depth;
                            @endphp
                            <x-bs-admin::tree.row :row="$r" :name="$n['name']" :locked="$lockWhy" :drag-off="$isRoot ? $u('root_tip', ['section' => $label, 'slug' => $slug]) : null"
                                wire:key="cm-{{ $n['key'] }}" data-node="{{ json_encode($data, JSON_UNESCAPED_UNICODE) }}">
                                <span class="flex min-w-0 flex-1 flex-col">
                                    @if ($n['of'] !== null)
                                        <span x-bind:class="filtering ? 'text-caption text-text-muted [overflow-wrap:anywhere]' : 'sr-only'" class="sr-only">{{ $u('of', ['name' => $n['of']]) }}</span>
                                    @endif
                                    <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        <x-bs-admin::tree.name x-on:click="openEdit($el.closest('[data-tree-row]').dataset.k)" :title="$n['name']"
                                            aria-label="{{ $u('row_aria', ['name' => $n['name'], 'level' => $r['level'], 'count' => $count]) }}"><span x-text="part($el, 0)">{{ $n['name'] }}</span><mark x-show="q.trim()" x-text="part($el, 1)" class="rounded-sm bg-warning-soft text-inherit"></mark><span x-text="part($el, 2)"></span></x-bs-admin::tree.name>
                                        @if ($isRoot)
                                            <x-bs-admin::badge soft pill color="primary" :title="$u('root_tip', ['section' => $label, 'slug' => $slug])"><x-bs-admin::icon name="ri-home-4" class="size-3.5" /> {{ $u('root') }}</x-bs-admin::badge>
                                        @endif
                                    </span>
                                    @if ($alt !== '')
                                        <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ $alt }}</span>
                                    @endif
                                </span>
                                <x-slot:sub>
                                    <span class="list-meta mt-0">
                                        <span class="list-m font-mono text-caption">{{ $slug }}</span>
                                        <span class="list-m @min-[48rem]:hidden">{{ $count }}</span>
                                        <span class="list-m @min-[64rem]:hidden">{{ $tpl }}</span>
                                        <x-bs-admin::list.langs :locales="$locales" :source="$main" :name="$n['name']"
                                            :has="array_values(array_filter($locales, fn (string $c): bool => ! in_array($c, $missing, true) && isset($n['names'][$c])))"
                                            :run="$canManage && $n['editable'] ? fn (string $c): string => 'openEdit('.$j($n['key']).', '.$j($c).')' : null"
                                            :why="$u('no_manage')" />
                                    </span>
                                </x-slot:sub>
                                <x-slot:actions>
                                    <span class="{{ $tplCol }}" title="{{ $n['listTemplate'] === null ? $u('tpl_default_tip') : $u('tpl_own_tip') }}">{{ $tpl }}</span>
                                    <span class="{{ $cntCol }}">
                                        @if ($n['items'] > 0)
                                            <a href="{{ $listUrl }}" wire:navigate class="hit-area font-semibold text-primary-text underline-offset-2 hover:underline">{{ $count }}</a>
                                        @else
                                            <span>{{ $count }}</span>
                                        @endif
                                    </span>
                                    <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="openEdit({{ $j($n['key']) }})" aria-label="{{ $u('edit_of', ['name' => $n['name']]) }}" class="@max-[20.6rem]:hidden">
                                        <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ $u('edit') }}
                                    </x-bs-admin::button>
                                    @if ($r['level'] < $depth)
                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="openAdd({{ $j($n['key']) }}, true)" aria-disabled="{{ $off }}"
                                            aria-label="{{ $u('add_child_of', ['name' => $n['name']]) }}" :title="$canManage ? $u('add_child_of', ['name' => $n['name']]) : $u('no_manage')" class="@max-[37.49rem]:hidden">
                                            <x-bs-admin::icon name="ri-add" class="size-4" /> {{ $u('child') }}
                                        </x-bs-admin::button>
                                    @else
                                        <span class="invisible @max-[37.49rem]:hidden" aria-hidden="true"><x-bs-admin::button size="sm" variant="outline" color="secondary" tabindex="-1"><x-bs-admin::icon name="ri-add" class="size-4" /> {{ $u('child') }}</x-bs-admin::button></span>
                                    @endif
                                    <x-bs-admin::list.actions :name="$n['name']" :title="$n['name']">
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-pencil" x-on:click="openEdit({{ $j($n['key']) }})">{{ $u('edit') }}</x-bs-admin::list.menu-item>
                                            @if ($canChild)
                                                <x-bs-admin::list.menu-item icon="ri-add" x-on:click="openAdd({{ $j($n['key']) }}, true)">{{ $u('add_child') }}</x-bs-admin::list.menu-item>
                                            @elseif (! $isRoot)
                                                <x-bs-admin::list.menu-item icon="ri-add" off :why="$canManage ? $u('too_deep_child', ['n' => $depth]) : $u('no_manage')">{{ $u('add_child') }}</x-bs-admin::list.menu-item>
                                            @endif
                                            @if ($missing !== [])
                                                <x-bs-admin::list.menu-item icon="ri-translate-2" x-on:click="openEdit({{ $j($n['key']) }}, '{{ $missing[0] }}')">{{ $u('add_tr') }}</x-bs-admin::list.menu-item>
                                            @endif
                                            @if ($n['items'] > 0)
                                                <x-bs-admin::list.menu-item icon="ri-file-list-3" :href="$listUrl">{{ $u('see_items', ['n' => $n['items'], 'noun' => $noun]) }}</x-bs-admin::list.menu-item>
                                            @endif
                                            <x-bs-admin::list.menu-sep />
                                            <x-bs-admin::list.menu-item icon="ri-external-link" x-on:click="visit({{ $j($n['key']) }})">{{ $u('visit') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-item icon="ri-links" x-on:click="copyLink({{ $j($n['key']) }})">{{ $u('copy') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-sep />
                                            @if ($isRoot)
                                                <span class="list-mwhy flex gap-2"><x-bs-admin::icon name="ri-information" class="size-4 shrink-0" /><span>{{ $u('root_note', ['section' => $label]) }}</span></span>
                                            @else
                                                <x-bs-admin::tree.move-menu :row="$r" />
                                                <x-bs-admin::list.menu-item icon="ri-drag-move-2" x-on:click="openMv({{ $j($n['key']) }})" :off="$lockWhy !== null || ! $canManage" :why="$lockWhy">{{ $u('move_to') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-sep />
                                                <x-bs-admin::list.menu-item icon="ri-git-merge" x-on:click="openDel({{ $j($n['key']) }}, true)" :off="$lockWhy !== null || ! $canManage" :why="$lockWhy">{{ $u('merge') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-sep />
                                                <x-bs-admin::list.menu-item icon="ri-delete-bin" danger x-on:click="openDel({{ $j($n['key']) }})" :off="$lockWhy !== null || ! $canManage" :why="$lockWhy">{{ $u('delete') }}</x-bs-admin::list.menu-item>
                                            @endif
                                        </x-slot:menu>
                                    </x-bs-admin::list.actions>
                                </x-slot:actions>
                            </x-bs-admin::tree.row>
                        @endforeach
                    </x-bs-admin::tree>

                    <p class="flex items-start gap-2 border-t border-border px-4 py-3 text-small text-text-muted">
                        <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" />
                        <span><span class="not-any-pointer-fine:hidden">{{ $u('foot_fine') }}</span><span class="hidden not-any-pointer-fine:inline">{{ $u('foot_touch') }}</span> · {{ $u('foot_depth', ['n' => $depth]) }}</span>
                    </p>
                </section>
            </x-bs-admin::card>
        </div>

        {{-- the editor --}}
        <x-bs-admin::drawer state="pan" close="panClose()" :title="$u('edit_title')">
            <x-slot:heading><span x-text="panTitle"></span></x-slot:heading>
            <div class="flex flex-col gap-4">
                <template x-if="pan && pan.err">
                    <x-bs-admin::alert color="danger" icon="ri-error-warning">
                        <span role="alert" x-text="pan.err === 'network' ? {{ $j($u('save_net')) }} : pan.errText"></span>
                        <span class="mt-2 flex" x-show="pan.err !== 'refused'">
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="panRetry()"><x-bs-admin::icon name="ri-refresh" class="size-4" /> <span x-text="pan.err === 'gone' ? {{ $j($u('reload')) }} : {{ $j($u('retry')) }}"></span></x-bs-admin::button>
                        </span>
                    </x-bs-admin::alert>
                </template>
                @if ($multi)
                    <div class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ $u('langs') }}">
                            <x-bs-admin::icon name="ri-translate-2" class="size-5 text-text-muted" />
                            @foreach ($locales as $c)
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="pickLang('{{ $c }}')" x-bind:aria-pressed="(cur === '{{ $c }}').toString()"
                                    x-bind:title="{{ $j($langNames[$c]) }} + (pan && langSt('{{ $c }}') !== 'full' ? ' — ' + {{ $j($u('lang_none')) }} : '') {{ $c === $main ? '+ '.$j(' · '.$u('lang_src_short')) : '' }}">
                                    <span class="size-2 rounded-full" x-bind:class="pan && langSt('{{ $c }}') === 'full' ? 'bg-success-text' : 'bg-warning-text'" aria-hidden="true"></span>
                                    {{ $langNames[$c] }} <span class="text-caption text-text-muted">{{ strtoupper($c) }}</span>
                                    @if ($c === $main)<x-bs-admin::icon name="ri-star-fill" class="size-3.5 text-warning-text" />@endif
                                </x-bs-admin::button>
                            @endforeach
                        </div>
                        <p class="flex items-start gap-2 text-small text-text-muted"><x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" /><span x-text="pan ? lineText : ''"></span></p>
                    </div>
                @endif

                <div class="flex flex-col gap-1.5">
                    <label for="cm-name" class="text-small font-semibold text-text-strong">{{ $u('f_name') }} <span class="text-danger-text" aria-hidden="true">*</span>@if ($multi) <span class="text-caption text-text-muted" x-text="cur.toUpperCase()"></span>@endif</label>
                    <x-bs-admin::input id="cm-name" maxlength="{{ $screen['limits']['name'] }}" aria-required="true" aria-describedby="cm-name-m" x-model="fName"
                        x-bind:dir="'ltr'" x-on:blur="pan && cur === {{ $j($main) }} && (pan.touched = true)" x-bind:aria-invalid="pan && nameBad ? 'true' : null" placeholder="{{ $namePh }}" />
                    <p id="cm-name-m" x-show="pan && nameBad" role="alert" class="text-small text-danger-text">{{ __('categories.reason_name_invalid') }}</p>
                    <p x-show="!(pan && nameBad)" class="text-small text-text-muted">{{ $u('f_name_hint') }}</p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="cm-slug" class="text-small font-semibold text-text-strong">{{ $u('f_slug') }}@if ($multi) <span class="text-caption text-text-muted" x-text="cur.toUpperCase()"></span>@endif</label>
                    <div class="flex min-w-0 items-stretch overflow-hidden rounded border border-border-input bg-surface-muted focus-ring-within">
                        <span class="flex min-w-0 max-w-[50%] items-center border-r border-border-input px-2 font-mono text-caption text-text-muted [overflow-wrap:anywhere]" x-text="(@js($bases)[cur] || '')" x-bind:title="(@js($bases)[cur] || '')"></span>
                        <input id="cm-slug" type="text" dir="ltr" inputmode="url" autocapitalize="off" spellcheck="false" maxlength="{{ $screen['limits']['slug'] }}" placeholder="tin-cong-ty"
                            x-model="fSlug" aria-describedby="cm-slug-m" x-bind:aria-invalid="pan && slugBad ? 'true' : null"
                            class="field min-w-0 flex-1 border-0 bg-transparent px-2 font-mono" />
                    </div>
                    <p id="cm-slug-m" x-show="pan && slugBad" role="alert" class="text-small text-danger-text">{{ __('categories.reason_slug_invalid') }}</p>
                    <p x-show="pan && slugOk" class="flex items-center gap-1 text-small text-success-text"><x-bs-admin::icon name="ri-checkbox-circle" class="size-4" /> {{ $u('slug_ok') }}</p>
                    <p class="text-small text-text-muted [overflow-wrap:anywhere]" x-text="pan ? slugHint : ''"></p>
                    <span x-show="pan && slugRegen"><x-bs-admin::button size="sm" variant="ghost" color="primary" x-on:click="regen()"><x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ $u('regen') }}</x-bs-admin::button></span>
                    <template x-if="pan && slugChanged && !slugBad">
                        <x-bs-admin::alert color="warning" icon="ri-route"><span x-text="slugWarn"></span></x-bs-admin::alert>
                    </template>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="cm-parent" class="text-small font-semibold text-text-strong">{{ $u('f_parent') }}</label>
                    <template x-if="pan && isRootNode">
                        <p class="flex items-center gap-2 rounded border border-border bg-surface-muted px-3 py-2 text-body text-text"><x-bs-admin::icon name="ri-lock" class="size-4" /> <span>{{ $u('parent_lock', ['section' => $label]) }}</span></p>
                    </template>
                    <template x-if="pan && !isRootNode">
                        <div class="flex flex-col gap-1.5">
                            <select id="cm-parent" x-model="pan.d.parent" x-on:change="pan.confirmed = false" class="field w-full rounded border border-border-input bg-surface-muted px-3">
                                <template x-for="g in parentGroups" :key="g.label">
                                    <optgroup x-bind:label="g.label">
                                        <template x-for="o in g.options" :key="o.key"><option x-bind:value="o.key" x-text="o.label" x-bind:disabled="o.off" x-bind:selected="o.key === pan.d.parent"></option></template>
                                    </optgroup>
                                </template>
                            </select>
                            <p class="text-small text-text-muted">{{ $u('parent_hint', ['n' => $depth]) }}</p>
                            <template x-if="panCx">
                                <x-bs-admin::alert color="warning">
                                    <span role="alert"><b>{{ $u('on_save') }}</b>
                                        <ul class="ms-4 list-disc"><template x-for="ln in panCxLines"><li x-text="ln"></li></template></ul></span>
                                </x-bs-admin::alert>
                            </template>
                        </div>
                    </template>
                </div>

                <div>
                    <x-bs-admin::button variant="ghost" color="primary" x-on:click="pan.more = !pan.more" x-bind:aria-expanded="(!!(pan && pan.more)).toString()" aria-controls="cm-more">
                        <span x-show="!(pan && pan.more)"><x-bs-admin::icon name="ri-arrow-down-s" class="size-4" /></span><span x-show="pan && pan.more"><x-bs-admin::icon name="ri-arrow-up-s" class="size-4" /></span> {{ $u('more') }}
                    </x-bs-admin::button>
                </div>
                <div id="cm-more" x-show="pan && pan.more" class="flex flex-col gap-4">
                    @foreach (['listTemplate' => [$u('f_tpl_list'), $u('f_tpl_hint')], 'detailTemplate' => [$products ? $u('f_tpl_product') : $u('f_tpl_post'), null]] as $field => [$text, $hint])
                        <div class="flex flex-col gap-1.5">
                            <label for="cm-{{ $field }}" class="text-small font-semibold text-text-strong">{{ $text }}</label>
                            <select id="cm-{{ $field }}" x-model="pan.d.{{ $field }}" class="field w-full rounded border border-border-input bg-surface-muted px-3">
                                <option value="">{{ $u('tpl_default_opt') }}</option>
                                <template x-if="goneTpl('{{ $field }}')"><option x-bind:value="goneTpl('{{ $field }}')" x-bind:selected="pan.d.{{ $field }} === goneTpl('{{ $field }}')" x-text="fill({{ $j($u('tpl_gone')) }}, { name: goneTpl('{{ $field }}') })"></option></template>
                                <template x-if="pan && pan.node && pan.node['{{ $field }}'] === '@@parent'"><option value="@@parent" x-bind:selected="pan.d.{{ $field }} === '@@parent'">{{ $layoutName(\App\Modules\Theme\Rendering\TemplateCatalog::PARENT) }}</option></template>
                                @foreach ($field === 'detailTemplate' ? $detailLayouts : $layouts as $layout)
                                    <option value="{{ $layout }}">{{ $layoutName($layout) }}</option>
                                @endforeach
                            </select>
                            @if ($hint)<p class="text-small text-text-muted">{{ $hint }}</p>@endif
                        </div>
                    @endforeach
                    <div class="flex flex-col gap-1.5">
                        <label for="cm-desc" class="text-small font-semibold text-text-strong">{{ $u('f_desc') }}@if ($multi) <span class="text-caption text-text-muted" x-text="cur.toUpperCase()"></span>@endif</label>
                        <textarea id="cm-desc" rows="3" maxlength="{{ $screen['limits']['description'] }}" x-model="pan.d.descriptions[cur]" placeholder="{{ $products ? $u('desc_ph_products') : $u('desc_ph_posts') }}"
                            class="field w-full rounded border border-border-input bg-surface-muted px-3"></textarea>
                        <p class="text-small text-text-muted">{{ $u('f_desc_hint') }}</p>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <span class="text-small font-semibold text-text-strong">{{ $u('f_cover') }}</span>
                        <div class="flex flex-wrap items-center gap-3">
                            <template x-if="pan && pan.d.cover"><img x-bind:src="pan.d.cover.url" alt="" class="size-16 rounded border border-border object-cover" /></template>
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="pickCover()"><x-bs-admin::icon name="ri-image-add" class="size-4" /> {{ $u('pick_cover') }}</x-bs-admin::button>
                            <span x-show="pan && pan.d.cover"><x-bs-admin::button size="sm" variant="ghost" color="danger" x-on:click="dropCover()"><x-bs-admin::icon name="ri-close" class="size-4" /> {{ $u('drop_cover') }}</x-bs-admin::button></span>
                        </div>
                    </div>
                    <x-bs-admin::seo-fields title-model="seoTitle" description-model="seoDescription" page-title-model="seoName" slug-model="seoSlug"
                        :kind="$products ? 'product' : 'post'" :address="['base' => $bases[$main] ?? '', 'home' => null, 'homeSlug' => 'home']" :locale="$main" :multi-locale="$multi" id="cm-seo" />
                </div>
            </div>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="secondary" x-on:click="panClose()">{{ __('admin.cancel') }}</x-bs-admin::button>
                <span x-show="pan && pan.mode === 'add'"><x-bs-admin::button variant="outline" color="secondary" x-on:click="save(true)" x-bind:aria-disabled="pan && pan.saving ? 'true' : null"><x-bs-admin::icon name="ri-add" class="size-4" /> {{ $u('save_new') }}</x-bs-admin::button></span>
                <x-bs-admin::button x-on:click="save()" x-bind:aria-disabled="pan && pan.saving ? 'true' : null">
                    <x-bs-admin::icon name="ri-save-3" class="size-4" /> <span x-text="pan && pan.saving ? {{ $j($u('saving')) }} : (pan ? saveText : {{ $j($u('save')) }})">{{ $u('save') }}</span>
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::drawer>

        {{-- "Chuyển đến…" --}}
        <x-bs-admin::modal state="mv" close="mvClose()">
            <x-slot:heading><span x-text="mv ? fill({{ $j($u('mv_title')) }}, { name: mv.name }) : ''"></span></x-slot:heading>
            <div class="flex flex-col gap-3">
                <x-bs-admin::search :label="$u('mv_search')" :placeholder="$u('ex_n2')" id="cm-mv-q" x-bind:value="mv ? mv.q : ''" x-on:input="if (mv) { mv.q = $event.target.value; mvQuery(); }" />
                <p class="text-small text-text-muted">{{ $u('mv_hint', ['n' => $depth]) }}</p>
                <div role="radiogroup" aria-label="{{ $u('mv_group') }}" class="flex flex-col gap-1.5">
                    <template x-for="g in (mv && mv.groups) || []" :key="g.section || 'top'">
                        <div class="flex flex-col gap-1.5">
                            <p class="mt-2 text-caption font-semibold uppercase tracking-wide text-text-muted" x-text="g.label + (g.current ? {{ $j(' '.$u('mv_current')) }} : '')"></p>
                            <p x-show="g.locked" role="note" class="text-small text-text-muted">{{ __('categories.reason_workflow_governed') }}</p>
                            <template x-for="o in g.options" :key="o.key || 'top'">
                                <label class="flex min-h-hit cursor-pointer items-start gap-3 rounded border border-border px-3 py-2 has-[:checked]:border-primary-text has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-70"
                                    x-bind:style="'padding-left:' + (12 + Math.max(0, o.level - 1) * 16) + 'px'">
                                    <input type="radio" name="cm-mv" class="mt-1" x-bind:disabled="!o.ok" x-bind:checked="mv.to && mv.to.key === o.key" x-on:change="mvPick(o, g)" />
                                    <span class="flex min-w-0 flex-col"><span class="font-semibold text-text-strong [overflow-wrap:anywhere]" x-text="o.key === null ? {{ $j($u('mv_top')) }} : (o.root ? fill({{ $j($u('mv_under')) }}, { section: g.label }) : o.name)"></span><span class="text-small text-text-muted" x-text="mvSub(o)"></span></span>
                                </label>
                            </template>
                        </div>
                    </template>
                    <p x-show="mv && mv.groups && !mv.groups.some((g) => g.options.length)" class="text-small text-text-muted" x-text="mv ? fill({{ $j($u('mv_none')) }}, { q: mv.q }) : ''"></p>
                </div>
                <template x-if="mv && mv.err"><x-bs-admin::alert color="danger"><span role="alert" x-text="mv.err"></span></x-bs-admin::alert></template>
            </div>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="secondary" x-on:click="mvClose()">{{ __('admin.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button x-on:click="mvGo()" x-bind:aria-disabled="mv && mv.to ? null : 'true'"><span x-text="mv ? mvBtn : {{ $j($u('mv_pick')) }}">{{ $u('mv_pick') }}</span></x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>

        {{-- delete and merge --}}
        @foreach (['del' => ['ri-delete-bin', false], 'merge' => ['ri-git-merge', true]] as $kind => [$icon, $isMerge])
            <x-bs-admin::confirm-dialog state="del && {{ $isMerge ? '' : '!' }}del.merge ? delQ : null" :icon="$icon" danger confirm="delGo()" cancel="delClose()">
                <template x-if="del && {{ $isMerge ? '' : '!' }}del.merge">
                    <div class="flex flex-col gap-3 text-left">
                        <p x-text="delLead"></p>
                        <template x-if="!del.merge && del.p && del.p.children > 0">
                            <fieldset class="flex flex-col gap-2 rounded border border-border p-3">
                                <legend class="px-1 text-small font-semibold text-text-strong" x-text="fill({{ $j($u('n_kids')) }}, { n: del.p.children })"></legend>
                                <label class="flex min-h-hit items-start gap-3"><input type="radio" name="cm-dk" class="mt-1" x-bind:checked="del.children === 'lift'" x-on:change="delPick('children', 'lift')" /><span>{{ $u('kids_up') }}<span class="block text-small text-text-muted" x-text="fill({{ $j($u('kids_up_sub')) }}, { parent: del.parentName ? '&quot;' + del.parentName + '&quot;' : {{ $j($u('outer')) }}, noun: {{ $j($products ? $u('noun_products_cap') : $u('noun_posts_cap')) }} })"></span></span></label>
                                <label class="flex min-h-hit items-start gap-3"><input type="radio" name="cm-dk" class="mt-1" x-bind:checked="del.children === 'delete'" x-on:change="delPick('children', 'delete')" /><span>{{ $u('kids_del') }}<span class="block text-small text-text-muted" x-text="fill({{ $j($u('kids_del_sub')) }}, { n: Math.max(0, branch(del.k).length - 1), noun: {{ $j($noun) }} })"></span></span></label>
                            </fieldset>
                        </template>
                        <template x-if="del.p && (del.p.items > 0 || del.merge)">
                            <fieldset class="flex flex-col gap-2 rounded border border-border p-3">
                                <legend class="px-1 text-small font-semibold text-text-strong" x-text="del.merge ? {{ $j($u('merge_into')) }} : del.p.items + ' ' + {{ $j($noun) }} + {{ $j(' '.$u('attached')) }}"></legend>
                                <label class="flex min-h-hit items-start gap-3"><input type="radio" name="cm-di" class="mt-1" x-bind:checked="delMove" x-on:change="delPick('items', 'reassign')" /><span>{{ $u('items_move') }}<span class="block text-small text-text-muted" x-text="del.merge ? fill({{ $j($u('items_move_merge')) }}, { name: del.name }) : {{ $j($u('items_move_sub')) }}"></span></span></label>
                                <div x-show="delMove" class="ps-7">
                                    <select id="cm-dt" x-model="del.target" x-on:change="delLoad()" aria-label="{{ $u('items_move') }}" class="field w-full rounded border border-border-input bg-surface-muted px-3">
                                        <option value="">{{ $u('pick_cat') }}</option>
                                        <template x-for="g in del.p.targets" :key="g.section || 'top'">
                                            <optgroup x-bind:label="g.label">
                                                <template x-for="o in g.options" :key="o.key"><option x-bind:value="o.key" x-bind:disabled="!o.ok" x-bind:selected="o.key === del.target"
                                                    x-text="(o.root ? fill({{ $j($u('mv_under')) }}, { section: g.label }) : '  '.repeat(Math.max(0, o.level - 1)) + (o.level > 1 ? '└ ' : '') + o.name) + (o.ok ? '' : ' — ' + {{ $j($u('locked_opt')) }})"></option></template>
                                            </optgroup>
                                        </template>
                                    </select>
                                </div>
                                <template x-if="!del.merge">
                                    <label class="flex min-h-hit items-start gap-3" x-bind:class="del.p.governed || del.p.limitedMembers.length ? 'opacity-70' : ''"><input type="radio" name="cm-di" class="mt-1" x-bind:checked="!delMove" x-bind:disabled="del.p.governed || del.p.limitedMembers.length > 0" x-on:change="delPick('items', 'detach')" /><span>{{ $u('items_detach') }}<span class="block text-small text-text-muted"
                                        x-text="del.p.limitedMembers.length ? fill({{ $j($u('detach_scoped')) }}, { n: del.p.limitedMembers.length }) : del.p.governed ? {{ $j($u('detach_gov', ['section' => $label])) }} : {{ $j($products ? $u('detach_sub_products') : $u('detach_sub_posts')) }}"></span></span></label>
                                </template>
                                <p x-show="del.p.governed" role="note" class="text-small text-text-muted">{{ __('categories.reason_workflow_governed') }}</p>
                            </fieldset>
                        </template>
                        <template x-if="del.p && del.p.limitedMembers.length">
                            <p role="note" class="text-small text-text-muted" x-text="del.target ? fill({{ $j($u('scope_follow')) }}, { n: del.p.limitedMembers.length, name: delTargetName }) : fill({{ $j($u('scope_pick')) }}, { n: del.p.limitedMembers.length })"></p>
                        </template>
                        <template x-if="del.merge && del.p && del.p.children > 0">
                            <x-bs-admin::alert color="info"><span x-text="fill({{ $j($u('merge_kids')) }}, { n: del.p.children, parent: del.parentName ? fill({{ $j($u('under_q')) }}, { name: del.parentName }) : {{ $j($u('outer')) }}, name: delTargetName || '…' })"></span></x-bs-admin::alert>
                        </template>
                        <template x-if="del.p && del.p.sectionChange">
                            <x-bs-admin::alert color="warning"><span role="alert" x-text="fill({{ $j($u('cx_leave_line')) }}, { n: del.p.leavingItems, from: {{ $j($label) }} })"></span></x-bs-admin::alert>
                        </template>
                        <x-bs-admin::alert color="info">
                            <b>{{ $u('after') }}</b>
                            <ul class="ms-4 list-disc"><template x-for="f in delFx"><li x-text="f"></li></template></ul>
                            <span class="mt-1.5 block text-small">{{ $u('no_undo') }}</span>
                        </x-bs-admin::alert>
                        <template x-if="del.err"><x-bs-admin::alert color="danger"><span role="alert" x-text="del.err"></span></x-bs-admin::alert></template>
                    </div>
                </template>
            </x-bs-admin::confirm-dialog>
        @endforeach

        {{-- a question: a move to another section, or unsaved changes --}}
        <x-bs-admin::confirm-dialog state="ask" confirm="askOk()" cancel="askClose()" icon="ri-error-warning">
            <template x-if="ask && ask.lead"><p x-text="ask.lead"></p></template>
            <template x-for="ln in (ask && ask.lines) || []"><x-bs-admin::confirm-line><span x-text="ln"></span></x-bs-admin::confirm-line></template>
            <x-slot:alt>
                <span x-show="ask && ask.alt"><x-bs-admin::button variant="outline" color="primary" x-on:click="askAltGo()"><span x-text="ask && ask.alt ? ask.alt : {{ $j($u('unsaved_save')) }}">{{ $u('unsaved_save') }}</span></x-bs-admin::button></span>
            </x-slot:alt>
        </x-bs-admin::confirm-dialog>

        {{-- the address to copy by hand when the browser would not --}}
        <x-bs-admin::modal state="copyUrl" close="copyUrl = null" size="sm" :title="$u('copy')">
            <div class="flex flex-col gap-1.5">
                <label for="cm-copy" class="text-small font-semibold text-text-strong">{{ $u('copy_label') }}</label>
                <x-bs-admin::input id="cm-copy" readonly dir="ltr" x-bind:value="copyUrl" />
                <p class="text-small text-text-muted">{{ $u('copy_hint') }}</p>
            </div>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="secondary" x-on:click="copyUrl = null">{{ __('admin.close') }}</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>

        <x-bs-admin::toast state="toast" dismiss="toastClose" undo="toastUndo" action="toastAct" />
    </div>
    @include('admin::partials.media-picker-modal', ['pickedIds' => []])
@endif
</div>
