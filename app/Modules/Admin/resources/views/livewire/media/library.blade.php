{{-- /admin/media — the media library (design board 12): folder column, tools row, files page by page
     from a cursor, selection with move / Trash / restore / purge, and the upload panel (the shared
     picker's upload panes). Files are addressed by ulid, folders by slug. --}}
@php
    use Illuminate\Support\Js;
    use App\Modules\Admin\Livewire\Media\Library;
    use App\Modules\Admin\Support\MediaLibraryDirectory as Dir;

    $num = fn (int $n): string => number_format($n, 0, ',', '.');
    $canPick = $trash ? $canDelete : ($canUpload || $canDelete);
    $placeName = $trash ? __('media.lib.trash') : ($folder === '-' ? __('media.lib.unfiled') : ($current?->name ?? __('media.lib.all')));
    $baseN = $trash ? $counts['trash'] : ($folder === '-' ? $counts['unfiled'] : ($current !== null ? ($counts['byFolder'][$current->id] ?? 0) : $counts['all']));
    $conditions = count($kinds) + count($formats) + ($trash ? 0 : count($uses));
    $searching = trim($search) !== '';
    $filtering = $conditions > 0 || $searching;
    $kindLabels = ['img' => __('media.lib.kind_img'), 'pdf' => __('media.lib.kind_pdf')];
    $useLabels = ['used' => __('media.lib.use_used'), 'unused' => __('media.lib.use_unused')];
    $sections = [];
    if ($canDelete) {
        $sections[] = ['key' => 'status', 'label' => __('media.lib.f_status'), 'model' => 'status', 'value' => $status, 'reset' => 'clearStatus',
            'options' => [['value' => 'live', 'label' => __('media.lib.f_live'), 'count' => $counts['all']], ['value' => 'trash', 'label' => __('media.lib.trash'), 'count' => $counts['trash']]]];
    }
    $sections[] = ['key' => 'kinds', 'label' => __('media.lib.f_kind'), 'model' => 'kinds', 'multiple' => true, 'value' => $kinds, 'reset' => 'clearKinds',
        'options' => array_map(fn (string $k): array => ['value' => $k, 'label' => $kindLabels[$k]], Dir::KINDS)];
    $sections[] = ['key' => 'formats', 'label' => __('media.lib.f_format'), 'model' => 'formats', 'multiple' => true, 'value' => $formats, 'reset' => 'clearFormats',
        'options' => array_map(fn (string $f): array => ['value' => $f, 'label' => strtoupper($f)], array_keys(Dir::FORMATS))];
    if (! $trash) {
        $sections[] = ['key' => 'uses', 'label' => __('media.lib.f_use'), 'model' => 'uses', 'multiple' => true, 'value' => $uses, 'reset' => 'clearUses',
            'options' => array_map(fn (string $u): array => ['value' => $u, 'label' => $useLabels[$u]], Dir::USES)];
    }
    $chips = [];
    foreach ($kinds as $k) {
        $chips[] = ['label' => $kindLabels[$k], 'icon' => 'ri-filter-3', 'remove' => 'removeCondition('.Js::from('kinds').', '.Js::from($k).')', 'aria' => __('media.lib.chip_rm', ['name' => $kindLabels[$k]])];
    }
    foreach ($formats as $f) {
        $chips[] = ['label' => strtoupper($f), 'icon' => 'ri-filter-3', 'remove' => 'removeCondition('.Js::from('formats').', '.Js::from($f).')', 'aria' => __('media.lib.chip_rm_format', ['name' => strtoupper($f)])];
    }
    foreach ($trash ? [] : $uses as $u) {
        $chips[] = ['label' => $useLabels[$u], 'icon' => 'ri-filter-3', 'remove' => 'removeCondition('.Js::from('uses').', '.Js::from($u).')', 'aria' => __('media.lib.chip_rm', ['name' => $useLabels[$u]])];
    }
    $sortOptions = [['key' => 'new', 'label' => __('media.lib.sort_new')], ['key' => 'old', 'label' => __('media.lib.sort_old')],
        ['key' => 'az', 'label' => __('media.lib.sort_az')], ['key' => 'size', 'label' => __('media.lib.sort_size')]];
    $pageUlids = $rows->pluck('ulid')->map(fn ($u): string => (string) $u)->all();
    $allOn = $pageUlids !== [] && array_diff($pageUlids, $picked) === [];
    $selectCall = 'togglePage('.Js::from($pageUlids).')';
    $from = ($pageNo - 1) * $per + 1;
    $foot = $totals['n'] > $rows->count()
        ? __('media.lib.foot_range', ['from' => $num($from), 'to' => $num($from + $rows->count() - 1), 'n' => $num($totals['n'])])
        : __('media.lib.foot_n', ['n' => $num($totals['n'])]);
    $foot .= ($trash ? __('media.lib.foot_in_trash') : '').' · '.Library::bytes($totals['bytes']);
    $extOf = fn ($m): string => strtoupper(match ($m->mime) { 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf', default => \Illuminate\Support\Str::after($m->mime, '/') });
    $dims = fn ($m): string => $m->width ? $m->width.' × '.$m->height : '—';
    $leftDays = fn ($m): int => max(0, $retention - (int) floor($m->deleted_at->diffInDays(now())));
    $listTargets = 'search,kinds,formats,uses,status,sort,per,older,newer,setFolder,setSort,clearFilters,clearSearch,removeCondition,clearKinds,clearFormats,clearUses,clearStatus';
    $folderBtn = 'flex min-h-control w-full items-center gap-2 rounded-md px-2.5 text-start text-body text-text hover:bg-surface-muted aria-[current=true]:bg-primary/12 aria-[current=true]:font-semibold aria-[current=true]:text-primary-text any-pointer-coarse:min-h-hit';
@endphp
<div class="flex min-w-0 flex-col gap-4" x-data
     x-on:keydown.slash.window="if (! ['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable) { $event.preventDefault(); document.getElementById('media-search')?.focus() }">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-text-strong">{{ __('media.lib.title') }}</h1>
            <p class="mt-1 text-sm text-text-muted">
                <span class="max-[599.98px]:hidden">{{ __('media.lib.desc_long') }}</span><span class="min-[600px]:hidden">{{ __('media.lib.desc_short') }}</span>
            </p>
        </div>
        @if ($canUpload && ! $uploadOpen && ! $failed && ($counts['all'] + $counts['trash']) > 0)
            <x-bs-admin::button color="primary" wire:click="openUpload"><x-bs-admin::icon name="ri-upload-2" class="size-4" />{{ __('media.lib.upload') }}</x-bs-admin::button>
        @endif
    </div>

    @if ($uploadOpen && $canUpload)
        <section class="rounded-md border border-border bg-surface p-4 shadow-card" aria-labelledby="media-up-h">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="media-up-h" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('media.lib.up_title') }}</h2>
                <x-bs-admin::button variant="outline" color="secondary" wire:click="closeUpload"><x-bs-admin::icon name="ri-arrow-left" class="size-4" />{{ __('media.lib.up_done') }}</x-bs-admin::button>
            </div>
            <p class="mb-3 flex items-center gap-2 text-body text-text"><x-bs-admin::icon name="ri-folder-3" class="size-4 shrink-0 text-text-muted" /><span>{{ __('media.lib.up_to') }} <b>{{ __('media.lib.unfiled') }}</b></span></p>
            <div class="min-h-[320px] overflow-hidden rounded-md border border-border">
                @if ($pickerSource === 'url')
                    @include('admin::partials.media-picker-url-pane')
                @elseif ($pickerSource === 'device')
                    @include('admin::partials.media-picker-device-pane')
                @else
                    @include('admin::partials.media-picker-sources')
                @endif
            </div>
        </section>
    @endif

    @if (! $canUpload && ! $canDelete)
        <p role="note" class="m-0 flex items-start gap-2 rounded-md border border-border bg-surface-muted px-3 py-2 text-body text-text">
            <x-bs-admin::icon name="ri-eye" class="mt-0.5 size-5 shrink-0 text-info-text" />
            <span>{{ __('media.lib.read_only', ['role' => \App\Modules\Rbac\Support\RoleLabel::topOf(auth()->user()) ?? __('media.lib.read_only_role')]) }}</span>
        </p>
    @endif

    @if ($failed)
        <section class="flex flex-col items-center gap-3 rounded-md border border-border bg-surface px-4 py-10 text-center shadow-card">
            <span class="flex size-12 items-center justify-center rounded-full bg-danger/10 text-danger-text"><x-bs-admin::icon name="ri-wifi-off" class="size-6" /></span>
            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('media.lib.error_title') }}</h2>
            <p class="m-0 max-w-[52ch] text-body text-text" role="alert">{{ __('media.lib.error_body') }}</p>
            <x-bs-admin::button color="primary" wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('media.lib.retry') }}</x-bs-admin::button>
        </section>
    @elseif ($counts['all'] + $counts['trash'] === 0 && ! $uploadOpen)
        <section class="flex flex-col items-center gap-3 rounded-md border border-border bg-surface px-4 py-10 text-center shadow-card">
            <span class="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary-text"><x-bs-admin::icon name="ri-image-add" class="size-6" /></span>
            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('media.lib.first_title') }}</h2>
            <p class="m-0 max-w-[52ch] text-body text-text">{{ __('media.lib.first_body') }}</p>
            @if ($canUpload)
                <div class="mt-1 flex flex-col items-center gap-2 rounded-md border border-dashed border-border-strong px-6 py-5">
                    <x-bs-admin::button color="primary" wire:click="openUpload"><x-bs-admin::icon name="ri-upload-2" class="size-4" />{{ __('media.lib.first_btn') }}</x-bs-admin::button>
                    <span class="text-small text-text-muted">{{ __('media.lib.first_hint') }}</span>
                </div>
            @endif
        </section>
    @else
        <div class="flex min-w-0 items-start gap-4">
            {{-- Folder column (from 900px) --}}
            <nav class="sticky top-4 hidden w-60 shrink-0 flex-col gap-0.5 rounded-md border border-border bg-surface p-2 shadow-card min-[900px]:flex" aria-label="{{ __('media.lib.folders') }}">
                <div class="flex items-center gap-2 px-2.5 pt-1 pb-2 text-small font-semibold text-text-muted"><x-bs-admin::icon name="ri-folder-3" class="size-4" /><span>{{ __('media.lib.folders') }}</span></div>
                <button type="button" class="{{ $folderBtn }}" aria-current="{{ ! $trash && $folder === '' ? 'true' : 'false' }}" wire:click="setFolder('')">
                    <x-bs-admin::icon name="ri-stack" class="size-4 shrink-0" /><span class="min-w-0 flex-1 truncate">{{ __('media.lib.all') }}</span><span class="text-small text-text-muted">{{ $num($counts['all']) }}</span>
                </button>
                @foreach ($folders as $f)
                    @php $on = ! $trash && $current?->id === $f->id; @endphp
                    <button type="button" wire:key="mf-{{ $f->slug }}" class="{{ $folderBtn }}" aria-current="{{ $on ? 'true' : 'false' }}" wire:click="setFolder({{ Js::from((string) $f->slug) }})">
                        <x-bs-admin::icon :name="$on ? 'ri-folder-open' : 'ri-folder-3'" class="size-4 shrink-0" /><span class="min-w-0 flex-1 truncate" title="{{ $f->name }}">{{ $f->name }}</span><span class="text-small text-text-muted">{{ $num($counts['byFolder'][$f->id] ?? 0) }}</span>
                    </button>
                @endforeach
                <button type="button" class="{{ $folderBtn }}" aria-current="{{ ! $trash && $folder === '-' ? 'true' : 'false' }}" wire:click="setFolder('-')">
                    <x-bs-admin::icon name="ri-inbox" class="size-4 shrink-0" /><span class="min-w-0 flex-1 truncate">{{ __('media.lib.unfiled') }}</span><span class="text-small text-text-muted">{{ $num($counts['unfiled']) }}</span>
                </button>
                @if ($canFolders)
                    <div class="my-1.5 border-t border-border"></div>
                    @if ($newFolderOpen)
                        @include('admin::livewire.media.library-new-folder', ['fid' => 'media-newf'])
                    @else
                        <x-bs-admin::button size="sm" variant="ghost" color="secondary" class="justify-start" wire:click="openNewFolder"><x-bs-admin::icon name="ri-add" class="size-4" />{{ __('media.lib.new_folder') }}</x-bs-admin::button>
                    @endif
                @elseif ($folders->isEmpty())
                    <p class="m-0 px-2.5 py-1 text-small text-text-muted">{{ __('media.lib.no_folders') }}</p>
                @endif
            </nav>

            <section class="flex min-w-0 flex-1 flex-col rounded-md border border-border bg-surface shadow-card" aria-label="{{ $placeName }}">
                {{-- Folder select (below 900px) --}}
                <div class="flex items-end gap-2 px-4 pt-4 min-[900px]:hidden">
                    <div class="min-w-0 flex-1">
                        <label for="media-fsel" class="mb-1 block text-small font-medium text-text">{{ __('media.lib.folders') }}</label>
                        <select id="media-fsel" class="field w-full rounded-md border border-border-strong bg-surface px-3 text-text" wire:change="setFolder($event.target.value)">
                            <option value="" @selected(! $trash && $folder === '')>{{ __('media.lib.all') }} ({{ $num($counts['all']) }})</option>
                            @foreach ($folders as $f)
                                <option value="{{ $f->slug }}" @selected(! $trash && $current?->id === $f->id)>{{ $f->name }} ({{ $num($counts['byFolder'][$f->id] ?? 0) }})</option>
                            @endforeach
                            <option value="-" @selected(! $trash && $folder === '-')>{{ __('media.lib.unfiled') }} ({{ $num($counts['unfiled']) }})</option>
                            @if ($canDelete)
                                <option value="~trash" @selected($trash)>{{ __('media.lib.trash') }} ({{ $num($counts['trash']) }})</option>
                            @endif
                        </select>
                    </div>
                    @if ($canFolders)
                        <x-bs-admin::button variant="outline" color="secondary" icon :aria-label="__('media.lib.folder_more')" :title="__('media.lib.more')" wire:click="openFolderSheet"><x-bs-admin::icon name="ri-more-2-fill" class="size-4" /></x-bs-admin::button>
                    @endif
                </div>
                @if ($newFolderOpen && $canFolders)
                    <div class="px-4 pt-3 min-[900px]:hidden">@include('admin::livewire.media.library-new-folder', ['fid' => 'media-newf-n'])</div>
                @endif

                {{-- Tools row --}}
                <div class="flex flex-wrap items-center gap-3 px-4 pt-4 pb-3">
                    <div class="min-w-0 flex-[1_1_14rem] text-body min-[900px]:max-w-[30em]">
                        <x-bs-admin::search id="media-search" :label="__('media.lib.search_label')" :placeholder="__('media.lib.search_hint')"
                            :clear-label="__('media.lib.search_clear')" clear-action="clearSearch" :clear-on="$searching"
                            maxlength="{{ Dir::SEARCH_MAX }}" wire:model.live.debounce.300ms="search" />
                    </div>
                    <x-bs-admin::filter-panel class="shrink-0 flex-nowrap" :sections="$sections" :label="__('admin.list.filter')"
                        :active="$conditions + ($trash ? 1 : 0)" :results="__('media.lib.f_see', ['n' => $num($totals['n'])])" clear="clearFilters" />
                    <x-bs-admin::sort-menu :options="$sortOptions" :active="$sort" :lit="$sort !== 'new'" set="setSort" />
                    <div class="inline-flex shrink-0 rounded-md border border-border-strong p-0.5" role="radiogroup" aria-label="{{ __('media.lib.view_label') }}">
                        <button type="button" role="radio" aria-checked="{{ $view === 'grid' ? 'true' : 'false' }}" aria-label="{{ __('media.lib.view_grid') }}" title="{{ __('media.lib.view_grid') }}" wire:click="setView('grid')"
                                class="flex size-control items-center justify-center rounded text-text-muted aria-checked:bg-primary/12 aria-checked:text-primary-text any-pointer-coarse:size-hit"><x-bs-admin::icon name="ri-grid" class="size-4" /></button>
                        <button type="button" role="radio" aria-checked="{{ $view === 'list' ? 'true' : 'false' }}" aria-label="{{ __('media.lib.view_list') }}" title="{{ __('media.lib.view_list') }}" wire:click="setView('list')"
                                class="flex size-control items-center justify-center rounded text-text-muted aria-checked:bg-primary/12 aria-checked:text-primary-text any-pointer-coarse:size-hit"><x-bs-admin::icon name="ri-list-check" class="size-4" /></button>
                    </div>
                </div>

                <x-bs-admin::filter-status class="mx-1 mb-2"
                    :group="$trash ? __('media.lib.trash') : null"
                    :summary="$filtering ? __('media.lib.match_of', ['n' => $num($totals['n']), 'm' => $num($baseN)]) : null"
                    :chips="$chips" :clear="$filtering ? 'clearFilters' : null" />

                @if ($canPick && $pageUlids !== [] && $view === 'grid')
                    <div class="px-4 pb-2">
                        <x-bs-admin::list.check :checked="$allOn" :label="__('media.lib.sel_all')" :aria-label="__('media.lib.sel_all_aria')" visible wire:click="{{ $selectCall }}" />
                    </div>
                @endif

                @if ($picked !== [] && $canPick)
                    <x-bs-admin::list.bulk :label="__('media.lib.bulk_region')" :status="__('media.lib.picked', ['n' => $num(count($picked))])" clear="clearPicked" :clear-label="__('media.lib.unpick')">
                        @if ($trash)
                            <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="restorePicked"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />{{ __('media.lib.restore_n', ['n' => $num(count($picked))]) }}</x-bs-admin::button>
                            @if ($canPurge)
                                <x-bs-admin::button size="sm" variant="soft" color="danger" wire:click="askPurge(false)"><x-bs-admin::icon name="ri-delete-bin-2" class="size-4" />{{ __('media.lib.purge_n', ['n' => $num(count($picked))]) }}</x-bs-admin::button>
                            @endif
                        @else
                            @if ($canUpload)
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openMove"><x-bs-admin::icon name="ri-folder-transfer" class="size-4" />{{ __('media.lib.move_n', ['n' => $num(count($picked))]) }}</x-bs-admin::button>
                            @endif
                            @if ($canDelete)
                                <x-bs-admin::button size="sm" variant="soft" color="danger" wire:click="trashPicked"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ __('media.lib.trash_n', ['n' => $num(count($picked))]) }}</x-bs-admin::button>
                            @endif
                        @endif
                    </x-bs-admin::list.bulk>
                @endif

                @if ($trash)
                    <p class="mx-4 mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-body text-text">
                        <x-bs-admin::icon name="ri-time" class="size-5 shrink-0 text-warning-text" />
                        <span class="min-w-0 flex-[1_1_16rem]">{{ __('media.lib.trash_note', ['days' => $retention]) }}</span>
                        @if ($counts['trash'] > 0 && $canPurge)
                            <x-bs-admin::button size="sm" variant="outline" color="danger" wire:click="askPurge(true)"><x-bs-admin::icon name="ri-delete-bin-2" class="size-4" />{{ __('media.lib.purge_all') }}</x-bs-admin::button>
                        @endif
                    </p>
                @endif

                {{-- Loading (a list request in flight) --}}
                <div wire:loading.delay.flex wire:target="{{ $listTargets }}" class="hidden flex-col gap-3 px-4 pb-4" aria-busy="true">
                    <div class="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-3">
                        @for ($i = 0; $i < 8; $i++)
                            <div class="flex flex-col gap-2"><span class="aspect-[4/3] w-full animate-pulse rounded-md bg-surface-muted motion-reduce:animate-none"></span><span class="h-3.5 w-4/5 animate-pulse rounded bg-surface-muted motion-reduce:animate-none"></span><span class="h-3 w-1/2 animate-pulse rounded bg-surface-muted motion-reduce:animate-none"></span></div>
                        @endfor
                    </div>
                    <p role="status" class="m-0 text-small text-text-muted">{{ __('media.lib.loading') }}</p>
                </div>

                <div wire:loading.delay.remove wire:target="{{ $listTargets }}" class="min-w-0">
                    @if ($rows->isEmpty())
                        @php
                            if ($filtering && $baseN > 0) {
                                [$eT, $eX, $eI, $eB] = [__('media.lib.nomatch_title'), __('media.lib.nomatch_body', ['n' => $num($baseN)]), 'ri-filter-3', true];
                            } elseif ($trash) {
                                [$eT, $eX, $eI, $eB] = [__('media.lib.trash_empty_title'), __('media.lib.trash_empty_body', ['days' => $retention]), 'ri-delete-bin', false];
                            } elseif ($searching) {
                                [$eT, $eX, $eI, $eB] = [__('media.lib.nosearch_title', ['q' => trim($search)]), __('media.lib.nosearch_body'), 'ri-search', true];
                            } elseif ($folder === '-') {
                                [$eT, $eX, $eI, $eB] = [__('media.lib.unfiled_empty_title'), __('media.lib.unfiled_empty_body'), 'ri-folder-open', false];
                            } else {
                                [$eT, $eX, $eI, $eB] = [__('media.lib.folder_empty_title', ['name' => $placeName]), __('media.lib.folder_empty_body'), 'ri-folder-open', false];
                            }
                        @endphp
                        <div class="flex flex-col items-center gap-3 px-4 py-10 text-center">
                            <span class="flex size-12 items-center justify-center rounded-full bg-surface-muted text-text-muted"><x-bs-admin::icon :name="$eI" class="size-6" /></span>
                            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $eT }}</h2>
                            <p class="m-0 max-w-[52ch] text-body text-text">{{ $eX }}</p>
                            @if ($eB)
                                <x-bs-admin::button variant="outline" color="secondary" wire:click="clearFilters"><x-bs-admin::icon name="ri-close" class="size-4" />{{ __('media.lib.clear') }}</x-bs-admin::button>
                            @endif
                        </div>
                    @elseif ($view === 'grid')
                        <div class="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-3 px-4 pb-3" role="list" aria-label="{{ $placeName }}">
                            @foreach ($rows as $m)
                                @php $on = in_array($m->ulid, $picked, true); @endphp
                                @php $name = $m->original_filename ?: $m->slug ?: $extOf($m); @endphp
                                @php $dot = strrpos($name, '.'); @endphp
                                <div wire:key="mt-{{ $m->ulid }}" role="listitem" @class(['relative min-w-0 rounded-md border bg-surface', 'border-primary-text ring-2 ring-primary/30' => $on, 'border-border' => ! $on])>
                                    <button type="button" class="flex w-full min-w-0 flex-col text-start" wire:click="openDetail({{ Js::from($m->ulid) }})" aria-label="{{ __('media.lib.open', ['name' => $name]) }}" title="{{ $name }}">
                                        <span class="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-t-md bg-surface-muted">
                                            @if (str_starts_with($m->mime, 'image/'))
                                                <img src="{{ $urls->url($m->storage_key, 320) }}" alt="" loading="lazy" decoding="async" @if ($m->width) width="{{ $m->width }}" height="{{ $m->height }}" @endif class="size-full object-cover">
                                            @else
                                                <span class="flex flex-col items-center gap-1 text-small font-semibold text-text-muted"><x-bs-admin::icon :name="$m->mime === 'application/pdf' ? 'ri-file-pdf-2' : 'ri-file'" class="size-8" />{{ $extOf($m) }}</span>
                                            @endif
                                            @if ($trash)
                                                @php $left = $leftDays($m); @endphp
                                                <span @class(['absolute bottom-1.5 left-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-small font-medium', 'bg-danger-text text-on-primary' => $left <= 2, 'bg-warning text-on-warning' => $left > 2])><x-bs-admin::icon name="ri-time" class="size-3.5" />{{ __('media.lib.days_left', ['n' => $left]) }}</span>
                                            @endif
                                        </span>
                                        <span class="flex min-w-0 flex-col gap-0.5 px-2.5 py-2">
                                            <span class="flex min-w-0 text-body font-medium text-text"><span class="truncate">{{ $dot ? substr($name, 0, $dot) : $name }}</span><span class="shrink-0">{{ $dot ? substr($name, $dot) : '' }}</span></span>
                                            <span class="truncate text-small text-text-muted">{{ $dims($m) }} · {{ Library::bytes((int) $m->size) }}</span>
                                        </span>
                                    </button>
                                    @if ($canPick)
                                        <label class="absolute top-0 left-0 flex size-hit cursor-pointer items-center justify-center" title="{{ __($on ? 'media.lib.unpick_one' : 'media.lib.pick_one', ['name' => $name]) }}">
                                            <input type="checkbox" class="size-5 rounded border-border-strong accent-primary" @checked($on) wire:click="togglePick({{ Js::from($m->ulid) }})" aria-label="{{ __($on ? 'media.lib.unpick_one' : 'media.lib.pick_one', ['name' => $name]) }}">
                                        </label>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="overflow-x-auto px-4 pb-3">
                            <table class="w-full border-collapse text-body text-text">
                                <caption class="sr-only">{{ $placeName }}</caption>
                                <thead>
                                    <tr class="border-b border-border text-start text-small text-text-muted">
                                        <th scope="col" class="w-11 py-2">
                                            @if ($canPick && $pageUlids !== [])
                                                <input type="checkbox" class="size-5 rounded border-border-strong accent-primary" @checked($allOn) wire:click="{{ $selectCall }}" aria-label="{{ __('media.lib.sel_all_aria') }}">
                                            @endif
                                        </th>
                                        <th scope="col" class="w-14 py-2"><span class="sr-only">{{ __('media.lib.col_thumb') }}</span></th>
                                        <th scope="col" class="py-2 text-start font-medium">{{ __('media.lib.col_name') }}</th>
                                        <th scope="col" class="hidden py-2 text-start font-medium min-[900px]:table-cell">{{ __('media.lib.col_type') }}</th>
                                        <th scope="col" class="hidden py-2 text-start font-medium min-[1100px]:table-cell">{{ __('media.lib.col_dims') }}</th>
                                        <th scope="col" class="hidden py-2 text-end font-medium min-[600px]:table-cell">{{ __('media.lib.col_size') }}</th>
                                        <th scope="col" class="hidden py-2 text-start font-medium min-[1100px]:table-cell">{{ __('media.lib.col_date') }}</th>
                                        <th scope="col" class="py-2"><span class="sr-only">{{ __('admin.actions') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $m)
                                        @php $on = in_array($m->ulid, $picked, true); @endphp
                                        @php $name = $m->original_filename ?: $m->slug ?: $extOf($m); @endphp
                                        <tr wire:key="ml-{{ $m->ulid }}" @class(['border-b border-border', 'bg-primary/7' => $on])>
                                            <td class="py-1.5">
                                                @if ($canPick)
                                                    <input type="checkbox" class="size-5 rounded border-border-strong accent-primary" @checked($on) wire:click="togglePick({{ Js::from($m->ulid) }})" aria-label="{{ __($on ? 'media.lib.unpick_one' : 'media.lib.pick_one', ['name' => $name]) }}">
                                                @endif
                                            </td>
                                            <td class="py-1.5">
                                                <button type="button" class="flex size-11 items-center justify-center overflow-hidden rounded bg-surface-muted" wire:click="openDetail({{ Js::from($m->ulid) }})" aria-label="{{ __('media.lib.open', ['name' => $name]) }}">
                                                    @if (str_starts_with($m->mime, 'image/'))
                                                        <img src="{{ $urls->url($m->storage_key, 96) }}" alt="" loading="lazy" class="size-full object-cover">
                                                    @else
                                                        <x-bs-admin::icon :name="$m->mime === 'application/pdf' ? 'ri-file-pdf-2' : 'ri-file'" class="size-5 text-text-muted" />
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="max-w-0 py-1.5 pe-3">
                                                <button type="button" class="block max-w-full truncate text-start font-medium text-text hover:text-primary-text" wire:click="openDetail({{ Js::from($m->ulid) }})" title="{{ $name }}">{{ $name }}</button>
                                                <span class="block truncate text-small text-text-muted min-[1100px]:hidden">{{ $extOf($m) }} · {{ $dims($m) }} · {{ Library::bytes((int) $m->size) }}@if ($trash) · {{ __('media.lib.days_left', ['n' => $leftDays($m)]) }}@endif</span>
                                            </td>
                                            <td class="hidden py-1.5 min-[900px]:table-cell">{{ $extOf($m) }}</td>
                                            <td class="hidden py-1.5 whitespace-nowrap min-[1100px]:table-cell">{{ $dims($m) }}</td>
                                            <td class="hidden py-1.5 text-end whitespace-nowrap min-[600px]:table-cell">{{ Library::bytes((int) $m->size) }}</td>
                                            <td class="hidden py-1.5 whitespace-nowrap min-[1100px]:table-cell">{{ $m->created_at?->timezone(config('app.timezone'))->format('d/m/Y') }}</td>
                                            <td class="py-1.5 text-end"><x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openDetail({{ Js::from($m->ulid) }})">{{ __('media.lib.details') }}</x-bs-admin::button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if ($rows->isNotEmpty())
                    <p class="m-0 flex flex-wrap justify-between gap-x-4 gap-y-1 px-4 pb-2 text-small text-text-muted">
                        <span>{{ $foot }}</span>
                        <span class="max-[767.98px]:hidden">{{ __('media.lib.shortcut') }}</span>
                    </p>
                    <div class="px-4 pb-4">
                        <x-bs-admin::list.pager :older="$olderUlid" :newer="$newerUlid" :sizes="Dir::PER_PAGE" model="per"
                            :label="__('media.lib.pager_label')" :per-label="__('media.lib.per_page')"
                            :prev="__('media.lib.prev')" :next="__('media.lib.next')" />
                    </div>
                @endif
            </section>
        </div>
    @endif

    @if ($mp['detail'] ?? null)
        <div class="fixed inset-y-0 right-0 z-40 flex">
            @include('admin::partials.media-picker-detail-panel', ['mp' => $mp])
        </div>
    @endif

    @if ($canUpload)
        <x-bs-admin::modal model="moveOpen" :title="__('media.lib.move_title', ['n' => $num(count($picked))])" :close-label="__('media.lib.cancel')">
            <fieldset class="m-0 flex flex-col gap-1 border-0 p-0">
                <legend class="sr-only">{{ __('media.lib.move_title', ['n' => $num(count($picked))]) }}</legend>
                @foreach ($folders as $f)
                    <label wire:key="mv-{{ $f->slug }}" class="flex min-h-hit cursor-pointer items-center gap-3 rounded-md px-2 text-body text-text hover:bg-surface-muted">
                        <input type="radio" name="media-move" value="{{ $f->slug }}" wire:model.live="moveTo" class="size-5 accent-primary">
                        <x-bs-admin::icon name="ri-folder-3" class="size-4 text-text-muted" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ $f->name }}</span>
                    </label>
                @endforeach
                <label class="flex min-h-hit cursor-pointer items-center gap-3 rounded-md px-2 text-body text-text hover:bg-surface-muted">
                    <input type="radio" name="media-move" value="-" wire:model.live="moveTo" class="size-5 accent-primary">
                    <x-bs-admin::icon name="ri-inbox" class="size-4 text-text-muted" /><span>{{ __('media.lib.unfiled') }}</span>
                </label>
            </fieldset>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="secondary" wire:click="cancelMove">{{ __('media.lib.cancel') }}</x-bs-admin::button>
                @php $moveName = $moveTo === '-' ? __('media.lib.unfiled') : $folders->firstWhere('slug', $moveTo)?->name; @endphp
                <x-bs-admin::button color="primary" aria-disabled="{{ $moveTo === '' ? 'true' : 'false' }}" class="aria-disabled:opacity-60" wire:click="confirmMove">
                    <x-bs-admin::icon name="ri-folder-transfer" class="size-4" />{{ $moveTo === '' || $moveName === null ? __('media.lib.move_pick') : __('media.lib.move_ok', ['n' => $num(count($picked)), 'folder' => $moveName]) }}
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    @endif

    @if ($canFolders)
        <x-bs-admin::modal model="folderSheetOpen" :title="$placeName" :close-label="__('media.lib.cancel')">
            <button type="button" class="flex min-h-hit w-full items-center gap-3 rounded-md px-2 text-start text-body text-text hover:bg-surface-muted" wire:click="openNewFolder">
                <x-bs-admin::icon name="ri-add" class="size-4" />{{ __('media.lib.new_folder') }}
            </button>
        </x-bs-admin::modal>
    @endif

    @if ($canDelete)
        <x-bs-admin::confirm-dialog model="askTrashOpen" :title="__('media.lib.ask_trash_title', ['n' => $num(count($picked))])" icon="ri-error-warning"
            :ok="__('media.lib.ask_trash_ok')" confirm="confirmTrash" cancel="cancelTrash">
            <x-bs-admin::confirm-line icon="ri-links">{{ __('media.lib.ask_trash_l1') }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-arrow-go-back">{{ __('media.lib.ask_trash_l2', ['days' => $retention]) }}</x-bs-admin::confirm-line>
        </x-bs-admin::confirm-dialog>
    @endif

    @if ($canPurge)
        <x-bs-admin::confirm-dialog model="askPurgeOpen" :title="$purgeAll ? __('media.lib.ask_purge_all_title') : __('media.lib.ask_purge_title', ['n' => $num($purge['n'])])" icon="ri-delete-bin-2" danger
            :ok="__('media.lib.ask_purge_ok', ['n' => $num($purge['n'])])" confirm="confirmPurge" cancel="cancelPurge">
            <x-bs-admin::confirm-line icon="ri-delete-bin-2">{{ __('media.lib.ask_purge_l1', ['n' => $num($purge['n']), 'size' => Library::bytes($purge['bytes'])]) }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-image-2">{{ __('media.lib.ask_purge_l2') }}</x-bs-admin::confirm-line>
        </x-bs-admin::confirm-dialog>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :undo="$undoTrash !== [] ? 'undo' : null" :seq="$toastSeq" />
</div>
