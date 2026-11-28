@php
    use App\Modules\Admin\Livewire\Settings\Redirects;
    use App\Modules\Admin\Support\Redirects\RedirectDirectory;
    use App\Modules\Cms\Models\Redirect;
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    // «Chuyển hướng» (design board c-chuyen-huong). The page is the list's size container: a row is a
    // card below 900 (the old address, the arrow and the new one, the kind at its top right, the
    // language and the source on a line under it, Sửa / Khôi phục and ⋮ at its bottom right) and a
    // table row from 900. Addresses are code-like text: monospace, wrapped anywhere, never clipped.
    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    $glyph = 'size-[calc(1rem+var(--user-text-boost))] shrink-0';
@endphp
<div>
    @if (! $allowed)
        {{-- Not this member's to open: the page keeps its own title (the frame lists only what the member may open). --}}
        <div class="flex flex-col gap-4">
            <header class="flex flex-col gap-1">
                <nav aria-label="{{ __('admin.breadcrumb') }}" class="flex flex-wrap items-center gap-1 text-small text-text-muted">
                    <span>{{ __('admin.domains_crumb_settings') }}</span>
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <span>{{ __('admin.rd.crumb_group') }}</span>
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <span aria-current="page" class="text-text-strong">{{ __('admin.rd.title') }}</span>
                </nav>
                <x-bs-admin::title-row :title="__('admin.rd.title')">
                </x-bs-admin::title-row>
                <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.rd.desc') }}</p>
            </header>
            <div class="flex flex-col items-start gap-2 rounded-md border border-border bg-surface p-5 shadow-card">
                <x-bs-admin::icon name="ri-forbid" class="size-6 text-text-muted" />
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.rd.noperm_title') }}</h2>
                <p class="m-0 text-text-muted">{{ __('admin.rd.noperm_body', ['role' => $role !== '' ? $role : __('admin.domains_noperm_role_none')]) }}</p>
            </div>
        </div>
    @else
    {{-- Inside the settings frame (design board «Khung quản trị», section «Chuyển hướng»): the frame draws the
         section's name, note and short description, and the add button ends its title row. --}}
    <x-bs-admin::settings-shell active="redirects" card="tight">
        @if (! $failed)
            <x-slot:aside>
                <x-bs-admin::button color="primary" id="rd-add" data-guide="add" class="shrink-0" wire:click="openAdd" :label="__('admin.rd.add')" :short="__('admin.rd.add_short')"
                    :title="__('admin.rd.add_title')">
                    <x-bs-admin::icon name="ri-add" class="size-4" />
                </x-bs-admin::button>
            </x-slot:aside>
        @endif
    <div class="@container/tv flex flex-col gap-4">
    @if ($failed)
        {{-- the page itself in this state (its heading says it), not a message that arrives later: no live region --}}
        <div class="flex flex-col items-start gap-2 rounded-md border border-border bg-surface p-5 shadow-card" wire:loading.remove wire:target="$refresh">
            <x-bs-admin::icon name="ri-wifi-off" class="size-6 text-danger-text" />
            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.rd.error_title') }}</h2>
            <p class="m-0 text-text-muted">{{ __('admin.rd.error_body') }}</p>
            <x-bs-admin::button variant="outline" color="primary" wire:click="$refresh">
                <x-bs-admin::icon name="ri-refresh" :class="$glyph" />
                <span>{{ __('admin.rd.retry') }}</span>
            </x-bs-admin::button>
        </div>
        <div wire:loading.flex wire:target="$refresh" aria-busy="true" aria-label="{{ __('admin.rd.loading') }}"
             class="flex-col gap-3 rounded-md border border-border bg-surface p-4 shadow-card">
            <div class="h-11 rounded bg-surface-hover"></div>
            <div class="h-11 rounded bg-surface-hover"></div>
            <div class="h-13 rounded bg-surface-hover"></div>
        </div>
    @else
        @php
            $inTrash = $tab === RedirectDirectory::TAB_TRASH;
            $tabCounts = $counts['tabs'];
            $groups = array_map(fn (string $k): array => ['key' => $k, 'label' => __('admin.rd.tab_'.$k), 'count' => $tabCounts[$k] ?? 0, 'trash' => $k === RedirectDirectory::TAB_TRASH],
                RedirectDirectory::TABS);
            $srcLabel = fn (string $s): string => __($s === Redirect::SOURCE_AUTO ? 'admin.rd.src_auto' : 'admin.rd.src_manual');
            $codeLabel = fn (int $c): string => __($c === 302 ? 'admin.rd.kind_302' : 'admin.rd.kind_301');
            $sections = [[
                'key' => 'tab', 'label' => __('admin.rd.f_group'), 'icon' => 'ri-route', 'model' => 'tab', 'value' => $tab, 'default' => RedirectDirectory::TAB_LIVE,
                'sheet' => true, 'class' => 'min-[900px]:hidden', 'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count']], $groups),
            ]];
            if ($multi) {
                $sections[] = ['key' => 'lang', 'label' => __('admin.rd.f_lang'), 'icon' => 'ri-translate-2', 'model' => 'locales', 'multiple' => true, 'value' => $locales,
                    'reset' => "removeFilter('lang', '*')",
                    'options' => array_map(fn (string $lc): array => ['value' => $lc, 'label' => Redirects::localeName($lc), 'count' => $counts['locales'][$lc] ?? 0], $siteLocales)];
            }
            $sections[] = ['key' => 'src', 'label' => __('admin.rd.f_src'), 'icon' => 'ri-git-branch', 'model' => 'sources', 'multiple' => true, 'value' => $sources,
                'reset' => "removeFilter('src', '*')",
                'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => $srcLabel($s), 'count' => $counts['sources'][$s] ?? 0], [Redirect::SOURCE_AUTO, Redirect::SOURCE_MANUAL])];
            $sections[] = ['key' => 'kind', 'label' => __('admin.rd.f_kind'), 'icon' => 'ri-arrow-right-double', 'model' => 'codes', 'multiple' => true, 'value' => $codes,
                'reset' => "removeFilter('kind', '*')",
                'options' => array_map(fn (int $c): array => ['value' => (string) $c, 'label' => $codeLabel($c), 'count' => $counts['codes'][$c] ?? 0], Redirect::STATUS_CODES)];
            $q = trim($search);
            $active = count($locales) + count($sources) + count($codes);
            $filtering = $q !== '' || $active > 0;
            $chips = [];
            if ($q !== '') {
                $chips[] = ['label' => __('admin.rd.chip_search', ['q' => $q]), 'remove' => 'clearSearch', 'aria' => __('admin.rd.chip_search_aria')];
            }
            foreach ([['lang', $locales, 'f_lang', fn (string $v): string => Redirects::localeName($v)], ['src', $sources, 'f_src', $srcLabel], ['kind', $codes, 'f_kind', fn (string $v): string => $codeLabel((int) $v)]] as [$kind, $values, $head, $name]) {
                foreach ($values as $v) {
                    $text = __('admin.rd.chip', ['head' => __('admin.rd.'.$head), 'value' => $name($v)]);
                    $chips[] = ['label' => $text, 'remove' => 'removeFilter('.Js::from($kind).', '.Js::from($v).')', 'aria' => __('admin.rd.chip_aria', ['what' => $text])];
                }
            }
            $tabTotal = $tabCounts[$tab] ?? 0;
            $sorts = [
                ['key' => RedirectDirectory::SORT_NEW, 'label' => __('admin.rd.sort_new')],
                ['key' => RedirectDirectory::SORT_OLD, 'label' => __('admin.rd.sort_old')],
                ['key' => RedirectDirectory::SORT_AZ, 'label' => __('admin.rd.sort_az')],
            ];
            $pageUlids = array_values(array_filter(array_column($rows, 'ulid'), 'is_string'));
            $pageAllOn = $pageUlids !== [] && array_diff($pageUlids, $picked) === [];
            $allState = $pageAllOn ? true : (array_intersect($pageUlids, $picked) !== [] ? 'mixed' : false);
            $selectCall = 'togglePick('.Js::from($pageUlids).')';
            $firstUse = $tab === RedirectDirectory::TAB_LIVE && ($tabCounts[RedirectDirectory::TAB_LIVE] ?? 0) === 0 && ! $filtering;
            $noMatchText = $inTrash && ! $filtering ? __('admin.rd.empty_trash')
                : ($tab === RedirectDirectory::TAB_WARN && ! $filtering ? __('admin.rd.empty_warn') : __('admin.rd.nomatch'));
            $start = ($page - 1) * $per + 1;
            $busy = 'search,tab,locales,sources,codes,per,older,newer,setSort,clearFilters,removeFilter,clearSearch,$refresh';
            $pickN = $picks['n'];
            $tempN = $picks['temp'];
        @endphp

        <section aria-label="{{ __('admin.rd.list_label') }}" class="min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:shadow-card">
          <div class="@container/lt flex min-w-0 flex-col">
            <x-bs-admin::group-row class="px-2" :groups="$groups" :active="$tab" model="tab" :label="__('admin.rd.tabs_label')" />
            <div class="flex flex-wrap items-center gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4">
                <div class="min-w-0 flex-[1_1_12rem] text-body min-[900px]:max-w-[30em]">
                    <x-bs-admin::search id="rd-search" data-guide="search" :label="__('admin.rd.search_label')" placeholder="/gioi-thieu"
                        :clear-label="__('admin.rd.search_clear')" clear-action="clearSearch" :clear-on="$q !== ''"
                        maxlength="{{ RedirectDirectory::SEARCH_MAX }}" wire:model.live.debounce.300ms="search" />
                </div>
                @if ($pageUlids !== [])
                    <div class="min-w-0 @min-[56.25rem]/lt:hidden">
                        <x-bs-admin::list.check :checked="$allState" :label="__('admin.rd.sel_all', ['n' => $num(count($pageUlids))])" :aria-label="__('admin.rd.sel_all_aria')" visible wire:click="{{ $selectCall }}" />
                    </div>
                @endif
                <div class="flex shrink-0 flex-nowrap items-center gap-2 min-[900px]:ms-auto">
                    <x-bs-admin::filter-panel class="flex-nowrap" :sections="$sections" :label="__('admin.list.filter')"
                        :active="$active" :active-narrow="$active + ($tab !== RedirectDirectory::TAB_LIVE ? 1 : 0)"
                        :results="__('admin.rd.f_see', ['n' => $num($matched)])" clear="clearFilters" />
                    <x-bs-admin::sort-menu :options="$sorts" :active="$sort" :lit="$sort !== RedirectDirectory::SORT_NEW" set="setSort" />
                </div>
            </div>

            <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1"
                :group="$tab !== RedirectDirectory::TAB_LIVE ? __('admin.rd.tab_'.$tab) : null"
                :summary="$filtering ? __('admin.rd.match_of', ['n' => $num($matched), 'm' => $num($tabTotal)]) : null"
                :chips="$chips" :clear="$filtering ? 'clearFilters' : null" />

            @if ($pickN > 0)
                <x-bs-admin::list.bulk :label="__('admin.rd.bulk_region')" :status="__('admin.rd.bulk_selected', ['n' => $num($pickN)])"
                    clear="clearPicked" :clear-label="__('admin.rd.bulk_clear')"
                    :skip="! $inTrash && $tempN < $pickN ? __('admin.rd.bulk_skip', ['n' => $num($pickN - $tempN)]) : null">
                    @if ($inTrash)
                        <x-bs-admin::button size="sm" variant="soft" color="primary" wire:click="restorePicked">
                            <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('admin.rd.bulk_restore', ['n' => $num($pickN)]) }}
                        </x-bs-admin::button>
                    @else
                        <x-bs-admin::button size="sm" variant="soft" color="primary" aria-disabled="{{ $tempN === 0 ? 'true' : 'false' }}" class="aria-disabled:opacity-60" wire:click="makePickedPermanent">
                            <x-bs-admin::icon name="ri-arrow-right-double" class="size-4" /> {{ __('admin.rd.bulk_301', ['n' => $num($tempN)]) }}
                        </x-bs-admin::button>
                        <x-bs-admin::button size="sm" variant="soft" color="danger" wire:click="trashPicked">
                            <x-bs-admin::icon name="ri-delete-bin" class="size-4" /> {{ __('admin.rd.bulk_trash', ['n' => $num($pickN)]) }}
                        </x-bs-admin::button>
                    @endif
                </x-bs-admin::list.bulk>
            @endif

            <div class="min-w-0 @max-[56.25rem]/lt:order-3 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface">
                <x-bs-admin::list kind="facts" data-cols="redirects" :label="__('admin.rd.list_label')" :busy="$busy">
                    @if ($rows !== [])
                        <x-bs-admin::list.head>
                            <x-bs-admin::list.cell head col="chk">
                                <x-bs-admin::list.check :checked="$allState" :label="__('admin.rd.sel_all_aria')" wire:click="{{ $selectCall }}" />
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="main">{{ __('admin.rd.col_paths') }}</x-bs-admin::list.cell>
                            @if ($multi)
                                <x-bs-admin::list.cell head col="f1">{{ __('admin.rd.col_lang') }}</x-bs-admin::list.cell>
                            @endif
                            <x-bs-admin::list.cell head col="f2">{{ __('admin.rd.col_kind') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="f3">{{ __('admin.rd.col_src') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="act">{{ __('admin.actions') }}</x-bs-admin::list.cell>
                        </x-bs-admin::list.head>
                    @endif

                    @forelse ($rows as $r)
                        @php
                            $key = (string) $r['ulid'];
                            $on = in_array($key, $picked, true);
                            $trashed = $r['deleted_at'] !== null;
                            $made = $siteTime->forDisplay($r['created_at']);
                            $source = $r['source'] === Redirect::SOURCE_AUTO
                                ? ($r['target_kind'] !== null && $r['target_name'] !== null
                                    ? __('admin.rd.src_auto_for', ['what' => __('admin.rd.auto_for_'.$r['target_kind'], ['name' => $r['target_name']])])
                                    : __('admin.rd.src_auto'))
                                : __('admin.rd.src_manual_on', ['date' => $made?->format('d/m/Y') ?? '']);
                            $langName = Redirects::localeName($r['locale']);
                        @endphp
                        <x-bs-admin::list.row wire:key="rd-{{ $key }}" :selected="$on">
                            <x-bs-admin::list.cell col="chk">
                                <x-bs-admin::list.check :checked="$on" :label="__('admin.rd.sel_one', ['from' => $r['from']])" wire:click="togglePick({{ Js::from([$key]) }})" />
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="main">
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span class="font-mono text-small text-text-strong [overflow-wrap:anywhere]" dir="ltr">{{ $r['from'] }}</span>
                                    <span class="flex min-w-0 items-start gap-1">
                                        <x-bs-admin::icon name="ri-arrow-right" class="mt-0.5 size-4 shrink-0 text-text-muted" />
                                        <span class="sr-only">{{ __('admin.rd.to_sr') }}</span>
                                        <span class="min-w-0 font-mono text-small text-primary-text [overflow-wrap:anywhere]" dir="ltr">{{ $r['to'] }}</span>
                                    </span>
                                    @if ($r['external_host'] !== null)
                                        <span class="flex min-w-0 items-start gap-1 text-small text-text-muted">
                                            <x-bs-admin::icon name="ri-external-link" class="mt-0.5 size-4 shrink-0" />
                                            <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.rd.to_external', ['host' => $r['external_host']]) }}</span>
                                        </span>
                                        @if (! $externalOn && ! $trashed)
                                            <span class="self-start"><x-bs-admin::badge color="warning" soft pill class="whitespace-normal leading-snug"><x-bs-admin::icon name="ri-error-warning" class="size-3.5 shrink-0" />{{ __('admin.rd.chip_external_off') }}</x-bs-admin::badge></span>
                                        @endif
                                    @endif
                                    @if ($r['loop'])
                                        <span class="self-start"><x-bs-admin::badge color="danger" soft pill class="whitespace-normal leading-snug"><x-bs-admin::icon name="ri-loop-left" class="size-3.5 shrink-0" />{{ __('admin.rd.chip_loop', ['chain' => $r['from'].' → '.$r['to'].' → '.$r['from']]) }}</x-bs-admin::badge></span>
                                    @elseif ($r['page'])
                                        <span class="self-start"><x-bs-admin::badge color="warning" soft pill class="whitespace-normal leading-snug"><x-bs-admin::icon name="ri-error-warning" class="size-3.5 shrink-0" />{{ __('admin.rd.chip_page') }}</x-bs-admin::badge></span>
                                    @endif
                                    <p class="list-line m-0">
                                        @if ($multi)<span>{{ $langName }}</span>@endif
                                        <span>{{ $source }}</span>
                                    </p>
                                </div>
                            </x-bs-admin::list.cell>
                            @if ($multi)
                                <x-bs-admin::list.cell col="f1">
                                    <span class="inline-flex h-6 min-w-8 items-center justify-center rounded border border-border-input px-1.5 text-caption font-semibold text-text-strong" title="{{ $langName }}">
                                        <span aria-hidden="true">{{ strtoupper($r['locale']) }}</span><span class="sr-only">{{ $langName }}</span>
                                    </span>
                                </x-bs-admin::list.cell>
                            @endif
                            <x-bs-admin::list.cell col="f2">
                                @if ($r['code'] === 302)
                                    <x-bs-admin::badge color="info" soft pill><x-bs-admin::icon name="ri-time" class="size-3.5 shrink-0" />{{ __('admin.rd.kind_302') }}</x-bs-admin::badge>
                                @else
                                    <x-bs-admin::badge color="success" soft pill><x-bs-admin::icon name="ri-arrow-right-double" class="size-3.5 shrink-0" />{{ __('admin.rd.kind_301') }}</x-bs-admin::badge>
                                @endif
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="f3">{{ $source }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="act">
                                <x-bs-admin::list.actions :name="$r['from']" :title="__('admin.rd.menu_title', ['from' => $r['from']])">
                                    @if ($trashed)
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn :aria-label="__('admin.rd.restore_aria', ['from' => $r['from']])" wire:click="restoreOne({{ Js::from($key) }})">
                                            <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('admin.rd.restore') }}
                                        </x-bs-admin::button>
                                    @else
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn :aria-label="__('admin.rd.edit_aria', ['from' => $r['from']])" wire:click="openEdit({{ Js::from($key) }})">
                                            <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('admin.rd.edit') }}
                                        </x-bs-admin::button>
                                    @endif
                                    <x-slot:menu>
                                        @if ($trashed)
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin-2" danger wire:click="askPurge({{ Js::from($key) }})">{{ __('admin.rd.purge') }}</x-bs-admin::list.menu-item>
                                        @else
                                            <x-bs-admin::list.menu-item icon="ri-external-link" :href="$paths->publicUrl($r['from'], $r['locale'])" target="_blank" rel="noopener">{{ __('admin.rd.try') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-item icon="ri-arrow-left-right" wire:click="switchCode({{ Js::from($key) }})">{{ __($r['code'] === 301 ? 'admin.rd.to_302' : 'admin.rd.to_301') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-sep />
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger wire:click="trashOne({{ Js::from($key) }})">{{ __('admin.rd.trash') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                </x-bs-admin::list.actions>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @empty
                        <x-bs-admin::list.row>
                            <x-bs-admin::list.cell col="full">
                                @if ($firstUse)
                                    <x-bs-admin::list.state icon="ri-route" :title="__('admin.rd.first_title')">
                                        {{ __('admin.rd.first_text') }}
                                        <x-slot:action>
                                            <x-bs-admin::button color="primary" wire:click="openAdd"><x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('admin.rd.add') }}</x-bs-admin::button>
                                        </x-slot:action>
                                    </x-bs-admin::list.state>
                                @else
                                    <x-bs-admin::list.state icon="ri-search">
                                        {{ $noMatchText }}
                                        @if ($filtering)
                                            <x-slot:action>
                                                <x-bs-admin::button variant="outline" color="primary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                                            </x-slot:action>
                                        @endif
                                    </x-bs-admin::list.state>
                                @endif
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @endforelse
                </x-bs-admin::list>

                @if ($rows !== [])
                    <x-bs-admin::list.pager :older="$older" :newer="$newer" :sizes="Redirects::PER_PAGE" model="per"
                        :label="__('admin.rd.pager_label')" :per-label="__('admin.rd.per_page')"
                        :prev="__('admin.rd.prev')" :next="__('admin.rd.next')">
                        <span class="text-small text-text-muted">{{ __('admin.rd.range', ['from' => $num($start), 'to' => $num($start + count($rows) - 1), 'n' => $num($matched)]) }}</span>
                    </x-bs-admin::list.pager>
                @endif
            </div>
          </div>
        </section>

        {{-- Add / edit --}}
        @if ($edOpen)
            @php
                $fromErr = $errors->first('edFrom');
                $toErr = $errors->first('edTo');
            @endphp
            <x-bs-admin::modal model="edOpen" :title="__($edUlid === null ? 'admin.rd.ed_add' : 'admin.rd.ed_edit')" :close-label="__('admin.rd.ed_close')">
                <div class="flex flex-col gap-4" x-on:rd-first-error.window="$nextTick(() => { const f = $el.querySelector('[aria-invalid=true]'); f && f.focus() })">
                    @if ($edAuto)
                        <x-bs-admin::alert color="info" icon="ri-information">{{ __('admin.rd.ed_auto', ['what' => $edAutoFor]) }}</x-bs-admin::alert>
                    @endif
                    @if ($multi)
                        <div class="flex flex-col gap-1.5">
                            <label for="rd-lang" class="text-body font-medium text-text-strong">{{ __('admin.rd.ed_lang') }}</label>
                            <select id="rd-lang" wire:model.live="edLocale" aria-describedby="rd-lang-h" class="field w-full rounded border border-border-input bg-surface px-3 text-text-strong">
                                @foreach ($siteLocales as $lc)
                                    <option value="{{ $lc }}">{{ Redirects::localeName($lc) }}</option>
                                @endforeach
                            </select>
                            <p id="rd-lang-h" class="m-0 text-small text-text-muted">{{ __('admin.rd.ed_lang_hint') }}</p>
                        </div>
                    @endif
                    <div class="flex flex-col gap-1.5">
                        <label for="rd-from" class="text-body font-medium text-text-strong">{{ __('admin.rd.ed_from') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.rd.required') }}</span></label>
                        <div class="flex min-w-0 items-stretch overflow-hidden rounded border border-border-input bg-surface focus-within:border-primary-text has-aria-invalid:border-danger-text">
                            <span class="flex max-w-[45%] items-center truncate border-e border-border bg-surface-muted px-2 font-mono text-small text-text-muted" dir="ltr" title="{{ $host }}">{{ $host }}{{ $multi && $edLocale !== $defaultLocale ? '/'.$edLocale : '' }}</span>
                            <input id="rd-from" type="text" wire:model.blur="edFrom" placeholder="/gioi-thieu-cu" inputmode="url" autocapitalize="off" autocomplete="off" spellcheck="false" dir="ltr" autofocus
                                aria-describedby="rd-from-d" @if ($fromErr !== '') aria-invalid="true" @endif
                                class="field min-w-0 flex-1 border-0 bg-transparent px-2.5 font-mono text-text-strong -outline-offset-2">
                        </div>
                        @if ($fromErr !== '')
                            <x-bs-admin::field-error id="rd-from-d">{{ $fromErr }}</x-bs-admin::field-error>
                        @else
                            <p id="rd-from-d" class="m-0 text-small text-text-muted">{{ __('admin.rd.ed_from_hint') }}</p>
                        @endif
                        @if ($pageWarn && $fromErr === '')
                            <p class="m-0 flex items-start gap-1.5 text-small text-warning-text">
                                <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" />
                                <span>{{ __('admin.rd.ed_page_warn') }}</span>
                            </p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="rd-to" class="text-body font-medium text-text-strong">{{ __('admin.rd.ed_to') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.rd.required') }}</span></label>
                        <input id="rd-to" type="text" wire:model.blur="edTo" placeholder="/gioi-thieu" inputmode="url" autocapitalize="off" autocomplete="off" spellcheck="false" dir="ltr"
                            aria-describedby="rd-to-d" @if ($toErr !== '') aria-invalid="true" @endif
                            class="field w-full rounded border border-border-input bg-surface px-3 font-mono text-text-strong aria-invalid:border-danger-text">
                        @if ($toErr !== '')
                            <x-bs-admin::field-error id="rd-to-d">{{ $toErr }}</x-bs-admin::field-error>
                        @else
                            <p id="rd-to-d" class="m-0 text-small text-text-muted">{{ $external ? __('admin.rd.ed_to_hint_ext') : __('admin.rd.ed_to_hint') }}</p>
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        <span id="rd-kind-l" class="text-body font-medium text-text-strong">{{ __('admin.rd.ed_kind') }}</span>
                        <x-bs-admin::choice-cards class="grid-cols-1!" labelledby="rd-kind-l" pick="setEdCode" :options="[
                            ['value' => '301', 'label' => __('admin.rd.ed_301'), 'sub' => __('admin.rd.ed_301_sub'), 'checked' => $edCode === 301],
                            ['value' => '302', 'label' => __('admin.rd.kind_302'), 'sub' => __('admin.rd.ed_302_sub'), 'checked' => $edCode === 302],
                        ]" />
                    </div>
                </div>
                <x-slot:footer>
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="closeEditor">{{ __('admin.cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button color="primary" wire:click="save" wire:loading.attr="aria-busy" wire:target="save">
                        <x-bs-admin::icon name="ri-save-3" class="size-4" /> {{ __('admin.rd.ed_save') }}
                    </x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>
        @endif

        {{-- «Xóa vĩnh viễn» --}}
        @if ($purgeOpen)
            <x-bs-admin::confirm-dialog model="purgeOpen" :title="__('admin.rd.purge_title', ['from' => $purgingPath])" icon="ri-delete-bin-2" danger
                :ok="__('admin.rd.purge_ok')" confirm="purgeNow" cancel="cancelPurge">
                <x-bs-admin::confirm-line icon="ri-error-warning">{{ __('admin.rd.purge_body') }}</x-bs-admin::confirm-line>
            </x-bs-admin::confirm-dialog>
        @endif
    @endif
    </div>
    </x-bs-admin::settings-shell>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :undo="$undoKeys !== [] ? 'undoTrash' : null" :seq="$toastSeq" raised />
</div>
