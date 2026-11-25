{{-- /admin/media — the media library (design board 12): folder column, tools row, files page by page
     from a cursor, selection with move / Trash / restore / purge, and the upload panel (the shared
     picker's upload panes). Files are addressed by ulid, folders by slug. --}}
@php
    use Illuminate\Support\Js;
    use App\Modules\Admin\Livewire\Media\Library;
    use App\Modules\Admin\Support\MediaLibraryDirectory as Dir;
    use App\Modules\Tenancy\Context\SiteTime;

    $num = fn (int $n): string => number_format($n, 0, ',', '.');
    // the upload day by the site's own calendar, as «Thời gian» reads it
    $siteTime = app(SiteTime::class);
    $canPick = $trash ? $canDelete : ($canUpload || $canDelete);
    $placeName = $trash ? __('media.lib.trash') : ($folder === '-' ? __('media.lib.unfiled') : ($current?->name ?? __('media.lib.all')));
    $baseN = $trash ? $counts['trash'] : ($folder === '-' ? $counts['unfiled'] : ($current !== null ? ($treeN[$current->id] ?? 0) : $counts['all']));
    // the folders of one parent in their order, and the top-level ones (a folder goes in one of those)
    $siblings = fn ($f) => $folders->where('parent_id', $f->parent_id)->values();
    $tops = $folders->whereNull('parent_id')->values();
    // «Thời gian»: «Tùy chọn» filters (and counts) only once a day is given
    $day = fn (string $date): string => $date === '' ? '…' : implode('/', array_reverse(explode('-', $date)));
    $whenOn = $when === Dir::RANGE ? ($from !== '' || $to !== '') : $when !== '';
    $conditions = count($kinds) + count($formats) + ($trash ? 0 : count($uses)) + count($by) + ($whenOn ? 1 : 0);
    $searching = trim($search) !== '';
    $filtering = $conditions > 0 || $searching;
    $kindLabels = ['img' => __('media.lib.kind_img'), 'pdf' => __('media.lib.kind_pdf')];
    $useLabels = ['used' => __('media.lib.use_used'), 'unused' => __('media.lib.use_unused')];
    $sections = [];
    if ($canDelete) {
        $sections[] = ['key' => 'status', 'label' => __('media.lib.f_status'), 'model' => 'status', 'value' => $status, 'reset' => 'clearStatus',
            'options' => [['value' => 'live', 'label' => __('media.lib.f_live'), 'count' => $counts['all']], ['value' => 'trash', 'label' => __('media.lib.trash'), 'count' => $counts['trash']]]];
    }
    // «Thư mục» (board 12): while the folder column is hidden, the folders are chosen here, with «Quản lý thư mục»
    $folderOptions = [['value' => '', 'label' => __('media.lib.all'), 'count' => $counts['all']]];
    foreach ($folders as $f) {
        $folderOptions[] = ['value' => (string) $f->slug, 'label' => $f->name, 'count' => $treeN[$f->id] ?? 0, 'depth' => $f->parent_id !== null ? 1 : 0];
    }
    $folderOptions[] = ['value' => '-', 'label' => __('media.lib.unfiled'), 'count' => $counts['unfiled']];
    $sections[] = ['key' => 'folder', 'label' => __('media.lib.folders'), 'model' => 'folderPick', 'value' => $folderPick, 'default' => '',
        'class' => '@min-[48rem]/tv:hidden', 'options' => $folderOptions]
        + ($trash ? ['on' => false, 'summary' => '', 'suffix' => ''] : [])
        + ($canFolders ? ['action' => ['label' => __('media.lib.manage_folders'), 'icon' => 'ri-folder-settings', 'call' => 'openFolderSheet']] : []);
    $sections[] = ['key' => 'kinds', 'label' => __('media.lib.f_kind'), 'model' => 'kinds', 'multiple' => true, 'value' => $kinds, 'reset' => 'clearKinds',
        'options' => array_map(fn (string $k): array => ['value' => $k, 'label' => $kindLabels[$k]], Dir::KINDS)];
    $sections[] = ['key' => 'formats', 'label' => __('media.lib.f_format'), 'model' => 'formats', 'multiple' => true, 'value' => $formats, 'reset' => 'clearFormats',
        'options' => array_map(fn (string $f): array => ['value' => $f, 'label' => strtoupper($f)], array_keys(Dir::FORMATS))];
    if (! $trash) {
        $sections[] = ['key' => 'uses', 'label' => __('media.lib.f_use'), 'model' => 'uses', 'multiple' => true, 'value' => $uses, 'reset' => 'clearUses',
            'options' => array_map(fn (string $u): array => ['value' => $u, 'label' => $useLabels[$u]], Dir::USES)];
    }
    // «Người tạo»: offered once two people have uploaded files here (the member first); its options, like
    // every group's counts, are read while the filters are open ($tally)
    if ($manyUploaders || $by !== []) {
        $sections[] = ['key' => 'by', 'label' => __('media.lib.f_by'), 'model' => 'by', 'multiple' => true, 'value' => $by, 'reset' => 'clearBy', 'options' => $uploaders];
    }
    // «Thời gian» by upload day; «Tùy chọn» opens the two date fields
    $whenLabels = ['' => __('entries.when_all'), 'today' => __('entries.when_today'), Dir::RANGE => __('media.lib.when_custom')];
    foreach (['7', '30', '90'] as $n) {
        $whenLabels[$n] = __('entries.when_days', ['n' => $n]);
    }
    $rangeText = __('entries.when_range', ['from' => $day($from), 'to' => $day($to)]);
    $sections[] = ['key' => 'when', 'label' => __('entries.f_when'), 'model' => 'when', 'value' => $when, 'default' => '', 'reset' => 'clearWhen',
        'note' => __('media.lib.when_note'),
        'options' => array_map(fn (string $v): array => ['value' => $v, 'label' => $whenLabels[$v]] + (isset($whenCounts[$v]) ? ['count' => $whenCounts[$v]] : []), Dir::WHEN)]
        + ($when === Dir::RANGE ? ['on' => $whenOn,
            'range' => ['from' => 'from', 'to' => 'to', 'from_label' => __('entries.when_from'), 'to_label' => __('entries.when_to')]]
            + ($whenOn ? ['summary' => $rangeText, 'suffix' => ': '.$rangeText] : []) : []);
    $chips = [];
    // while the folder column is hidden, the folder being looked at is a chip too (board 12)
    $folderChip = ! $trash && $folder !== '';
    if ($folderChip) {
        $chips[] = ['label' => $placeName, 'icon' => 'ri-folder-3', 'remove' => "setFolder('')", 'aria' => __('media.lib.chip_rm_folder', ['name' => $placeName]), 'class' => '@min-[48rem]/tv:hidden'];
    }
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
    $firstN = ($pageNo - 1) * $per + 1;
    $foot = $totals['n'] > $rows->count()
        ? __('media.lib.foot_range', ['from' => $num($firstN), 'to' => $num($firstN + $rows->count() - 1), 'n' => $num($totals['n'])])
        : __('media.lib.foot_n', ['n' => $num($totals['n'])]);
    $foot .= ($trash ? __('media.lib.foot_in_trash') : '').' · '.Library::bytes($totals['bytes']);
    $extOf = fn ($m): string => strtoupper(match ($m->mime) { 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf', default => \Illuminate\Support\Str::after($m->mime, '/') });
    $dims = fn ($m): string => $m->width ? $m->width.' × '.$m->height : '—';
    $leftDays = fn ($m): int => max(0, $retention - (int) floor($m->deleted_at->diffInDays(now())));
    // the chip of a tile and of the «Nơi dùng» column (board 12): days left in the Trash, else where the file is used
    $useChip = function ($m) use ($trash, $leftDays, $useCounts): array {
        if ($trash) {
            $left = $leftDays($m);

            return ['text' => __('media.lib.days_left', ['n' => $left]), 'tone' => $left <= 2 ? 'danger' : 'warn', 'icon' => 'ri-time'];
        }
        $n = $useCounts[$m->id] ?? 0;

        return $n > 0 ? ['text' => __('media.lib.use_n', ['n' => $n]), 'tone' => 'ok', 'icon' => 'ri-links'] : ['text' => __('media.lib.use_unused'), 'tone' => 'neutral', 'icon' => 'ri-subtract'];
    };
    $chipInk = ['ok' => 'text-success-text', 'warn' => 'text-warning-text', 'danger' => 'text-danger-text', 'neutral' => 'text-text'];
    $chipFill = ['ok' => 'bg-success/12', 'warn' => 'bg-warning/20', 'danger' => 'bg-danger/12', 'neutral' => 'bg-surface-hover'];
    $listTargets = 'search,kinds,formats,uses,by,when,from,to,folderPick,status,sort,per,older,newer,setFolder,setSort,clearFilters,clearSearch,removeCondition,clearKinds,clearFormats,clearUses,clearStatus,clearBy,clearWhen';
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
        {{-- Board 12: the folder column shows while the screen is at least 48rem wide (its own width, so a shown
             sidebar counts), and from there the column and the files are one surface split by a line. --}}
        <div class="@container/tv">
        <div class="flex min-w-0 items-start gap-4 @min-[48rem]/tv:gap-0 @min-[48rem]/tv:rounded-md @min-[48rem]/tv:border @min-[48rem]/tv:border-border @min-[48rem]/tv:bg-surface @min-[48rem]/tv:shadow-card">
            {{-- Folder column (screen from 48rem) --}}
            {{-- shut: the top-level folders whose sub-folders are folded away (opening a folder unfolds it) --}}
            <nav class="sticky top-4 hidden w-[16.5rem] shrink-0 flex-col gap-0.5 rounded-md border border-border bg-surface p-2 shadow-card @min-[48rem]/tv:flex @min-[48rem]/tv:rounded-none @min-[48rem]/tv:border-0 @min-[48rem]/tv:bg-transparent @min-[48rem]/tv:px-2 @min-[48rem]/tv:py-3 @min-[48rem]/tv:shadow-none @min-[93.75rem]/tv:w-72" aria-label="{{ __('media.lib.folders') }}"
                 x-data="{ shut: {} }" x-on:media-folder-open.window="shut[$event.detail.slug] = false">
                <div class="flex items-center gap-2 px-2.5 pt-1 pb-2 text-small font-semibold text-text-muted"><x-bs-admin::icon name="ri-folder-3" class="size-4" /><span>{{ __('media.lib.folders') }}</span></div>
                <button type="button" class="{{ $folderBtn }}" aria-current="{{ ! $trash && $folder === '' ? 'true' : 'false' }}" wire:click="setFolder('')">
                    <x-bs-admin::icon name="ri-stack" class="size-4 shrink-0" /><span class="min-w-0 flex-1 truncate">{{ __('media.lib.all') }}</span><span class="text-small text-text-muted">{{ $num($counts['all']) }}</span>
                </button>
                @foreach ($folders as $f)
                    @php
                        $byId ??= $folders->keyBy('id');
                        $on = ! $trash && $current?->id === $f->id;
                        $sub = $f->parent_id !== null;
                        $kids = $kidsOf[$f->id] ?? 0;
                        $fs = Js::from((string) $f->slug);
                        $ps = $sub ? Js::from((string) $byId->get($f->parent_id)?->slug) : null;
                    @endphp
                    @if ($canFolders && $renaming === (string) $f->slug)
                        <div wire:key="mf-{{ $f->slug }}" @class(['ml-4' => $sub])>@include('admin::livewire.media.library-rename-folder', ['fid' => 'media-ren', 'label' => __('media.lib.rename_label')])</div>
                    @else
                        <div wire:key="mf-{{ $f->slug }}" @class(['flex min-w-0 items-center gap-1', 'ml-4' => $sub]) @if ($sub) x-show="!shut[{{ $ps }}]" @endif>
                            <button type="button" class="{{ $folderBtn }} min-w-0 flex-1" aria-current="{{ $on ? 'true' : 'false' }}" wire:click="setFolder({{ $fs }})" @if ($kids > 0) x-on:click="shut[{{ $fs }}] = false" @endif>
                                <x-bs-admin::icon :name="$on ? 'ri-folder-open' : 'ri-folder-3'" class="size-4 shrink-0" /><span class="min-w-0 flex-1 truncate" title="{{ $f->name }}">{{ $f->name }}@if ($sub)<span class="sr-only">{{ __('media.lib.in_folder', ['name' => $byId->get($f->parent_id)?->name ?? '']) }}</span>@endif</span><span class="text-small text-text-muted">{{ $num($treeN[$f->id] ?? 0) }}</span>
                            </button>
                            @if ($kids > 0 && ! ($canFolders && $on))
                                <x-bs-admin::button size="sm" variant="ghost" color="secondary" icon
                                    x-bind:aria-expanded="shut[{{ $fs }}] ? 'false' : 'true'"
                                    x-bind:aria-label="shut[{{ $fs }}] ? {{ Js::from(__('media.lib.kids_open', ['name' => $f->name])) }} : {{ Js::from(__('media.lib.kids_close', ['name' => $f->name])) }}"
                                    x-bind:title="shut[{{ $fs }}] ? {{ Js::from(__('media.lib.kids_open', ['name' => $f->name])) }} : {{ Js::from(__('media.lib.kids_close', ['name' => $f->name])) }}"
                                    aria-label="{{ __('media.lib.kids_close', ['name' => $f->name]) }}" aria-expanded="true"
                                    x-on:click="shut[{{ $fs }}] = !shut[{{ $fs }}]">
                                    <span x-show="!shut[{{ $fs }}]"><x-bs-admin::icon name="ri-arrow-down-s" class="size-4" /></span>
                                    <span x-show="shut[{{ $fs }}]" x-cloak><x-bs-admin::icon name="ri-arrow-right-s" class="size-4" /></span>
                                </x-bs-admin::button>
                            @endif
                            @if ($canFolders && $on)
                                @php $sib = $siblings($f); @endphp
                                <x-bs-admin::list.actions :name="$f->name">
                                    <x-slot:menu>
                                        @include('admin::livewire.media.library-folder-menu', ['f' => $f, 'first' => $sib->first()?->id === $f->id, 'last' => $sib->last()?->id === $f->id, 'kids' => $kids, 'tops' => $tops->count()])
                                    </x-slot:menu>
                                </x-bs-admin::list.actions>
                            @endif
                        </div>
                    @endif
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

            <section class="flex min-w-0 flex-1 flex-col self-stretch rounded-md border border-border bg-surface shadow-card @min-[48rem]/tv:rounded-none @min-[48rem]/tv:border-y-0 @min-[48rem]/tv:border-e-0 @min-[48rem]/tv:bg-transparent @min-[48rem]/tv:shadow-none" aria-label="{{ $placeName }}">
                {{-- below 48rem the folders are chosen in the Filter sheet («Thư mục», board 12) --}}
                @if ($newFolderOpen && $canFolders)
                    <div class="px-4 pt-3 @min-[48rem]/tv:hidden">@include('admin::livewire.media.library-new-folder', ['fid' => 'media-newf-n'])</div>
                @endif
                @if ($renaming !== '' && $canFolders && $folders->contains(fn ($f) => (string) $f->slug === $renaming))
                    <div class="px-4 pt-3 @min-[48rem]/tv:hidden">@include('admin::livewire.media.library-rename-folder', ['fid' => 'media-ren-n', 'label' => __('media.lib.rename_label_narrow')])</div>
                @endif

                {{-- Tools (board 12): from 900, row 1 = Chọn tất cả · search · Sắp xếp · grid / list, row 2 = the filter buttons;
                     below 900, row 1 = search · grid / list, row 2 = Chọn tất cả … Lọc · Sắp xếp. Chọn tất cả and Sắp xếp have
                     one copy per layout (the other is hidden), so the Tab order follows what is seen. --}}
                @php
                    $selAll = $canPick && $pageUlids !== [] && $view === 'grid';
                @endphp
                <div class="flex flex-col gap-3 border-b border-border px-4 pt-4 pb-3 min-[900px]:gap-2">
                    <div class="flex min-w-0 items-center gap-3">
                        @if ($selAll)
                            <div class="hidden shrink-0 whitespace-nowrap min-[900px]:block">
                                <x-bs-admin::list.check :checked="$allOn" :label="__('media.lib.sel_all')" :aria-label="__('media.lib.sel_all_aria')" visible wire:click="{{ $selectCall }}" />
                            </div>
                        @endif
                        <div class="min-w-0 flex-1 text-body">
                            <x-bs-admin::search id="media-search" :label="__('media.lib.search_label')" :placeholder="__('media.lib.search_hint')"
                                :clear-label="__('media.lib.search_clear')" clear-action="clearSearch" :clear-on="$searching"
                                maxlength="{{ Dir::SEARCH_MAX }}" wire:model.live.debounce.300ms="search" />
                        </div>
                        <x-bs-admin::sort-menu class="hidden min-[900px]:block" copy="wide" :options="$sortOptions" :active="$sort" :lit="$sort !== 'new'" set="setSort" />
                        <div class="inline-flex shrink-0 gap-3 rounded-full bg-primary/7 p-0.5" role="radiogroup" aria-label="{{ __('media.lib.view_label') }}">
                            <button type="button" role="radio" aria-checked="{{ $view === 'grid' ? 'true' : 'false' }}" aria-label="{{ __('media.lib.view_grid') }}" title="{{ __('media.lib.view_grid') }}" wire:click="setView('grid')"
                                    class="flex size-control items-center justify-center rounded-full text-text-muted transition-colors hover:bg-primary/12 aria-checked:bg-primary/16 aria-checked:text-primary-text aria-checked:shadow-[inset_0_0_0_1px_currentColor] any-pointer-coarse:size-hit"><x-bs-admin::icon name="ri-grid" class="size-4" /></button>
                            <button type="button" role="radio" aria-checked="{{ $view === 'list' ? 'true' : 'false' }}" aria-label="{{ __('media.lib.view_list') }}" title="{{ __('media.lib.view_list') }}" wire:click="setView('list')"
                                    class="flex size-control items-center justify-center rounded-full text-text-muted transition-colors hover:bg-primary/12 aria-checked:bg-primary/16 aria-checked:text-primary-text aria-checked:shadow-[inset_0_0_0_1px_currentColor] any-pointer-coarse:size-hit"><x-bs-admin::icon name="ri-list-check" class="size-4" /></button>
                        </div>
                    </div>
                    <x-bs-admin::tool-row class="gap-3 min-[900px]:flex-wrap">
                        @if ($selAll)
                            <div class="min-[900px]:hidden">
                                <x-bs-admin::list.check :checked="$allOn" :label="__('media.lib.sel_all')" :aria-label="__('media.lib.sel_all_aria')" visible wire:click="{{ $selectCall }}" />
                            </div>
                        @endif
                        <x-bs-admin::filter-panel class="ms-auto shrink-0 flex-nowrap min-[900px]:ms-0 min-[900px]:shrink min-[900px]:flex-wrap min-[900px]:gap-3" :sections="$sections" :label="__('admin.list.filter')"
                            :active="$conditions + ($trash ? 1 : 0)" tally="tally" :active-narrow="$conditions + ($trash ? 1 : 0) + ($folderChip ? 1 : 0)"
                            :split-at="['wide' => '@max-[47.99rem]/tv:hidden', 'narrow' => '@min-[48rem]/tv:hidden']"
                            :results="__('media.lib.f_see', ['n' => $num($totals['n'])])" clear="clearFilters" />
                        <x-bs-admin::sort-menu class="min-[900px]:hidden" :options="$sortOptions" :active="$sort" :lit="$sort !== 'new'" set="setSort" />
                    </x-bs-admin::tool-row>
                </div>

                <x-bs-admin::filter-status class="mx-1 mb-2 {{ $folderChip && count($chips) === 1 && ! $filtering ? '@min-[48rem]/tv:hidden' : '' }}"
                    :group="$trash ? __('media.lib.trash') : null"
                    :summary="$filtering ? __('media.lib.match_of', ['n' => $num($totals['n']), 'm' => $num($baseN)]) : null"
                    :chips="$chips" :clear="$filtering ? 'clearFilters' : null" />

                @if ($picked !== [] && $canPick)
                    @php
                        // «own» (board 12): the Trash button and Khôi phục act only on this person's files;
                        // one line under them says why the rest stay
                        $skipN = $mineN === null ? 0 : ($chosenN ?? count($picked)) - $mineN;
                        $skipWhy = $skipN <= 0 ? null : ($trash
                            ? __('media.lib.restore_skip', ['n' => $num($skipN)])
                            : ($mineN > 0 ? __('media.lib.trash_skip_rest', ['n' => $num($skipN)]) : __('media.lib.trash_skip_all')));
                    @endphp
                    <x-bs-admin::list.bulk :label="__('media.lib.bulk_region')" :status="__('media.lib.picked', ['n' => $num(count($picked))])" clear="clearPicked" :clear-label="__('media.lib.unpick')" :skip="$canDelete ? $skipWhy : null">
                        @if ($trash)
                            @if ($mineN === null || $mineN > 0)
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="restorePicked"><x-bs-admin::icon name="ri-arrow-go-back" class="size-4" />{{ __('media.lib.restore_n', ['n' => $num($mineN ?? count($picked))]) }}</x-bs-admin::button>
                            @endif
                            @if ($canPurge && $support)
                                {{-- support staff never delete a member's files for good: the button stays, greyed, and the Trash note says why --}}
                                <x-bs-admin::button size="sm" variant="soft" color="danger" aria-disabled="true" class="opacity-60" :title="__('admin.support.locked')"><x-bs-admin::icon name="ri-delete-bin-2" class="size-4" />{{ __('media.lib.purge_n', ['n' => $num(count($picked))]) }}</x-bs-admin::button>
                            @elseif ($canPurge)
                                <x-bs-admin::button size="sm" variant="soft" color="danger" wire:click="askPurge(false)"><x-bs-admin::icon name="ri-delete-bin-2" class="size-4" />{{ __('media.lib.purge_n', ['n' => $num(count($picked))]) }}</x-bs-admin::button>
                            @endif
                        @else
                            @if ($canUpload)
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openMove"><x-bs-admin::icon name="ri-folder-transfer" class="size-4" />{{ __('media.lib.move_n', ['n' => $num(count($picked))]) }}</x-bs-admin::button>
                            @endif
                            @if ($canDelete && ($mineN === null || $mineN > 0))
                                <x-bs-admin::button size="sm" variant="soft" color="danger" wire:click="trashPicked"><x-bs-admin::icon name="ri-delete-bin" class="size-4" />{{ $mineN === null ? __('media.lib.trash_n', ['n' => $num(count($picked))]) : __('media.lib.trash_mine_n', ['n' => $num($mineN)]) }}</x-bs-admin::button>
                            @endif
                        @endif
                    </x-bs-admin::list.bulk>
                @endif

                @if ($trash)
                    <p class="mx-4 mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-body text-text">
                        <x-bs-admin::icon name="ri-time" class="size-5 shrink-0 text-warning-text" />
                        <span class="min-w-0 flex-[1_1_16rem]">{{ __('media.lib.trash_note', ['days' => $retention]) }}@if ($canPurge && $support) {{ __('admin.support.locked') }}@endif</span>
                        @if ($counts['trash'] > 0 && $canPurge && $support)
                            <x-bs-admin::button size="sm" variant="outline" color="danger" aria-disabled="true" class="opacity-60" :title="__('admin.support.locked')"><x-bs-admin::icon name="ri-delete-bin-2" class="size-4" />{{ __('media.lib.purge_all') }}</x-bs-admin::button>
                        @elseif ($counts['trash'] > 0 && $canPurge)
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
                                    <button type="button" class="flex w-full min-w-0 flex-col text-start" id="mopen-{{ $m->ulid }}" wire:click="openDetail({{ Js::from($m->ulid) }})" aria-label="{{ __('media.lib.open', ['name' => $name]) }}" title="{{ $name }}">
                                        <span class="relative flex aspect-[4/3] w-full items-center justify-center overflow-hidden rounded-t-md bg-surface-muted">
                                            @if (str_starts_with($m->mime, 'image/'))
                                                <img src="{{ $urls->url($m->storage_key, 320) }}" alt="" loading="lazy" decoding="async" @if ($m->width) width="{{ $m->width }}" height="{{ $m->height }}" @endif class="size-full object-cover">
                                            @else
                                                <span class="flex flex-col items-center gap-1 text-small font-semibold text-text-muted"><x-bs-admin::icon :name="$m->mime === 'application/pdf' ? 'ri-file-pdf-2' : 'ri-file'" class="size-8" />{{ $extOf($m) }}</span>
                                            @endif
                                        </span>
                                        @php $chip = $useChip($m); @endphp
                                        <span data-use="{{ $chip['tone'] }}" class="mx-2.5 mt-2 flex min-w-0 items-start gap-1 text-start text-[calc(0.875rem+var(--user-text-boost))] leading-[1.4] font-medium text-balance [overflow-wrap:anywhere] {{ $chipInk[$chip['tone']] }}"><x-bs-admin::icon :name="$chip['icon']" class="mt-[0.1em] size-[1.1em] shrink-0" /><span class="min-w-0">{{ $chip['text'] }}</span></span>
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
                        {{-- the columns follow the files area's own width (board 12): below 600 the name carries «type · size ·
                             dimensions · where used»; from 600 the type, size and «Nơi dùng» columns show and the name carries the
                             dimensions and upload day; from 900 every column shows --}}
                        <div class="@container/tvm overflow-x-auto px-4 pb-3">
                            <table class="w-full border-collapse text-body text-text">
                                <caption class="sr-only">{{ $placeName }}</caption>
                                <thead>
                                    <tr class="border-b border-border text-start text-small whitespace-nowrap text-text-muted">
                                        <th scope="col" class="w-11 py-2">
                                            @if ($canPick && $pageUlids !== [])
                                                <input type="checkbox" class="size-5 rounded border-border-strong accent-primary" @checked($allOn) wire:click="{{ $selectCall }}" aria-label="{{ __('media.lib.sel_all_aria') }}">
                                            @endif
                                        </th>
                                        <th scope="col" class="w-14 py-2"><span class="sr-only">{{ __('media.lib.col_thumb') }}</span></th>
                                        <th scope="col" class="w-full py-2 text-start font-medium">{{ __('media.lib.col_name') }}</th>
                                        <th scope="col" class="hidden py-2 ps-3 text-start font-medium @min-[37.5rem]/tvm:table-cell">{{ __('media.lib.col_type') }}</th>
                                        <th scope="col" class="hidden py-2 text-start font-medium @min-[56.25rem]/tvm:table-cell">{{ __('media.lib.col_dims') }}</th>
                                        <th scope="col" class="hidden py-2 ps-3 text-end font-medium @min-[37.5rem]/tvm:table-cell">{{ __('media.lib.col_size') }}</th>
                                        <th scope="col" class="hidden py-2 ps-3 text-start font-medium @min-[37.5rem]/tvm:table-cell">{{ __('media.lib.col_use') }}</th>
                                        <th scope="col" class="hidden py-2 text-start font-medium @min-[56.25rem]/tvm:table-cell">{{ __('media.lib.col_date') }}</th>
                                        <th scope="col" class="py-2"><span class="sr-only">{{ __('admin.actions') }}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $m)
                                        @php $on = in_array($m->ulid, $picked, true); @endphp
                                        @php $name = $m->original_filename ?: $m->slug ?: $extOf($m); @endphp
                                        @php $chip = $useChip($m); @endphp
                                        <tr wire:key="ml-{{ $m->ulid }}" @class(['border-b border-border', 'bg-primary/7' => $on])>
                                            <td class="py-1.5 pe-3">
                                                @if ($canPick)
                                                    <input type="checkbox" class="size-5 rounded border-border-strong accent-primary" @checked($on) wire:click="togglePick({{ Js::from($m->ulid) }})" aria-label="{{ __($on ? 'media.lib.unpick_one' : 'media.lib.pick_one', ['name' => $name]) }}">
                                                @endif
                                            </td>
                                            <td class="py-1.5 pe-3">
                                                <button type="button" class="flex size-11 items-center justify-center overflow-hidden rounded bg-surface-muted" wire:click="openDetail({{ Js::from($m->ulid) }})" aria-label="{{ __('media.lib.open', ['name' => $name]) }}">
                                                    @if (str_starts_with($m->mime, 'image/'))
                                                        <img src="{{ $urls->url($m->storage_key, 96) }}" alt="" loading="lazy" class="size-full object-cover">
                                                    @else
                                                        <x-bs-admin::icon :name="$m->mime === 'application/pdf' ? 'ri-file-pdf-2' : 'ri-file'" class="size-5 text-text-muted" />
                                                    @endif
                                                </button>
                                            </td>
                                            <td class="max-w-0 py-1.5 pe-3">
                                                <button type="button" id="mopen-{{ $m->ulid }}" class="block max-w-full truncate text-start font-medium text-text hover:text-primary-text" wire:click="openDetail({{ Js::from($m->ulid) }})" title="{{ $name }}">{{ $name }}</button>
                                                @php $upDay = $siteTime->forDisplay($m->created_at)?->format('d/m/Y'); @endphp
                                                <span class="block truncate text-small text-text-muted @min-[37.5rem]/tvm:hidden">{{ $extOf($m) }} · {{ $dims($m) }} · {{ Library::bytes((int) $m->size) }} · {{ $chip['text'] }}</span>
                                                <span class="hidden truncate text-small text-text-muted @min-[37.5rem]/tvm:block @min-[56.25rem]/tvm:hidden">{{ $dims($m) }} · {{ $upDay }}</span>
                                            </td>
                                            <td class="hidden py-1.5 ps-3 @min-[37.5rem]/tvm:table-cell">{{ $extOf($m) }}</td>
                                            <td class="hidden py-1.5 whitespace-nowrap @min-[56.25rem]/tvm:table-cell">{{ $dims($m) }}</td>
                                            <td class="hidden py-1.5 ps-3 text-end whitespace-nowrap @min-[37.5rem]/tvm:table-cell">{{ Library::bytes((int) $m->size) }}</td>
                                            <td class="hidden py-1.5 ps-3 @min-[37.5rem]/tvm:table-cell"><span data-use="{{ $chip['tone'] }}" class="inline-flex min-h-6 max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-caption font-semibold leading-[1.4] [overflow-wrap:anywhere] @min-[48rem]/tv:whitespace-nowrap {{ $chipFill[$chip['tone']] }} {{ $chipInk[$chip['tone']] }}"><x-bs-admin::icon :name="$chip['icon']" class="size-[1.1em] shrink-0" />{{ $chip['text'] }}</span></td>
                                            <td class="hidden py-1.5 whitespace-nowrap @min-[56.25rem]/tvm:table-cell">{{ $upDay }}</td>
                                            <td class="py-1.5 ps-3 text-end whitespace-nowrap"><x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openDetail({{ Js::from($m->ulid) }})">{{ __('media.lib.details') }}</x-bs-admin::button></td>
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
        </div>
    @endif

    @if ($drawer !== null)
        @include('admin::partials.media-library-drawer', ['d' => $drawer])
    @endif

    @if ($canUpload)
        <x-bs-admin::modal model="moveOpen" :title="__('media.lib.move_title', ['n' => $num($targetN)])" :close-label="__('media.lib.cancel')">
            <fieldset class="m-0 flex flex-col gap-1 border-0 p-0">
                <legend class="sr-only">{{ __('media.lib.move_title', ['n' => $num($targetN)]) }}</legend>
                @foreach ($folders as $f)
                    <label wire:key="mv-{{ $f->slug }}" @class(['flex min-h-hit cursor-pointer items-center gap-3 rounded-md px-2 text-body text-text hover:bg-surface-muted', 'ml-7' => $f->parent_id !== null])>
                        <input type="radio" name="media-move" value="{{ $f->slug }}" wire:model.live="moveTo" class="size-5 accent-primary">
                        <x-bs-admin::icon name="ri-folder-3" class="size-4 text-text-muted" />
                        <span class="flex min-w-0 flex-col"><span class="[overflow-wrap:anywhere]">{{ $f->name }}</span><span class="text-small text-text-muted">{{ __('media.lib.folder_files', ['n' => $num($treeN[$f->id] ?? 0)]) }}</span></span>
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
                    <x-bs-admin::icon name="ri-folder-transfer" class="size-4" />{{ $moveTo === '' || $moveName === null ? __('media.lib.move_pick') : __('media.lib.move_ok', ['n' => $num($targetN), 'folder' => $moveName]) }}
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    @endif

    @if ($canFolders)
        @php $at = $current === null || $trash ? false : $folders->search(fn ($f) => $f->id === $current->id); @endphp
        <x-bs-admin::modal model="folderSheetOpen" :title="$at === false ? __('media.lib.folders') : __('media.lib.folder_sheet_title', ['name' => $current->name])" :close-label="__('media.lib.cancel')">
            {{-- the shared menu panel, always open here: arrow keys, Home and End move between the items --}}
            <div x-data="{ open: true, up: false, sheet: false, close() {} }">
                <x-bs-admin::list.menu class="flex flex-col" :label="$at === false ? __('media.lib.folders') : __('media.lib.folder_sheet_title', ['name' => $current->name])">
                <x-bs-admin::list.menu-item icon="ri-add" wire:click="openNewFolder">{{ __('media.lib.new_folder') }}</x-bs-admin::list.menu-item>
                @if ($at !== false)
                    <x-bs-admin::list.menu-sep />
                    @php $sib = $siblings($current); @endphp
                    @include('admin::livewire.media.library-folder-menu', ['f' => $current, 'first' => $sib->first()?->id === $current->id, 'last' => $sib->last()?->id === $current->id, 'kids' => $kidsOf[$current->id] ?? 0, 'tops' => $tops->count()])
                @endif
                </x-bs-admin::list.menu>
            </div>
        </x-bs-admin::modal>
    @endif

    @if ($canFolders)
        @php
            $doomed = $folderToDelete === '' ? null : $folders->firstWhere('slug', $folderToDelete);
            $doomedN = $doomed === null ? 0 : ($treeN[$doomed->id] ?? 0);
            $doomedK = $doomed === null ? 0 : ($kidsOf[$doomed->id] ?? 0);
            $doomedTitle = $doomedK > 0
                ? __('media.lib.folder_delete_title_kids', ['name' => $doomed?->name ?? '', 'k' => $num($doomedK)])
                : __('media.lib.folder_delete_title', ['name' => $doomed?->name ?? '']);
            $doomedOk = match (true) {
                $doomedK > 0 && $doomedN > 0 => __('media.lib.folder_delete_ok_kids_n', ['k' => $num($doomedK + 1), 'n' => $num($doomedN)]),
                $doomedK > 0 => __('media.lib.folder_delete_ok_kids', ['k' => $num($doomedK + 1)]),
                default => __('media.lib.folder_delete_ok', ['n' => $num($doomedN)]),
            };
        @endphp
        <x-bs-admin::confirm-dialog model="askFolderOpen" :title="$doomedTitle" icon="ri-folder-reduce" danger ok-icon="ri-folder-reduce"
            :ok="$doomedOk" confirm="confirmDeleteFolder" cancel="cancelDeleteFolder">
            @if ($doomedN > 0 || $doomedK === 0)
                <x-bs-admin::confirm-line icon="ri-image-2">{{ __($doomedK > 0 ? 'media.lib.folder_delete_l1_kids' : 'media.lib.folder_delete_l1', ['n' => $num($doomedN)]) }}</x-bs-admin::confirm-line>
                <x-bs-admin::confirm-line icon="ri-links">{{ __('media.lib.folder_delete_l2') }}</x-bs-admin::confirm-line>
            @else
                <x-bs-admin::confirm-line icon="ri-image-2">{{ __('media.lib.folder_delete_empty_kids') }}</x-bs-admin::confirm-line>
            @endif
            @if ($folderNoUndo)
                <x-bs-admin::confirm-line icon="ri-error-warning">{{ __('media.lib.folder_delete_l3') }}</x-bs-admin::confirm-line>
            @endif
            @if ($folderInBatches)
                <x-bs-admin::confirm-line icon="ri-time">{{ __('media.lib.folder_delete_l4') }}</x-bs-admin::confirm-line>
            @endif
        </x-bs-admin::confirm-dialog>
    @endif

    @if ($canFolders)
        @php
            $moving = $reparentFolder === '' ? null : $folders->firstWhere('slug', $reparentFolder);
            $intoName = $reparentTo === '' || $reparentTo === '-' ? null : $tops->firstWhere('slug', $reparentTo)?->name;
        @endphp
        <x-bs-admin::modal model="reparentOpen" :title="__('media.lib.reparent_title', ['name' => $moving?->name ?? ''])" :close-label="__('media.lib.cancel')">
            <fieldset class="m-0 flex flex-col gap-1 border-0 p-0">
                <legend class="sr-only">{{ __('media.lib.reparent_title', ['name' => $moving?->name ?? '']) }}</legend>
                @if ($moving?->parent_id !== null)
                    <label class="flex min-h-hit cursor-pointer items-center gap-3 rounded-md px-2 text-body text-text hover:bg-surface-muted">
                        <input type="radio" name="media-reparent" value="-" wire:model.live="reparentTo" class="size-5 accent-primary">
                        <x-bs-admin::icon name="ri-folder-3" class="size-4 text-text-muted" />
                        <span class="flex min-w-0 flex-col"><span>{{ __('media.lib.reparent_top') }}</span><span class="text-small text-text-muted">{{ __('media.lib.reparent_top_sub') }}</span></span>
                    </label>
                @endif
                @foreach ($tops as $t)
                    @continue($t->id === $moving?->id || $t->id === $moving?->parent_id)
                    @php $tk = $kidsOf[$t->id] ?? 0; @endphp
                    <label wire:key="rp-{{ $t->slug }}" class="flex min-h-hit cursor-pointer items-center gap-3 rounded-md px-2 text-body text-text hover:bg-surface-muted">
                        <input type="radio" name="media-reparent" value="{{ $t->slug }}" wire:model.live="reparentTo" class="size-5 accent-primary">
                        <x-bs-admin::icon name="ri-folder-3" class="size-4 text-text-muted" />
                        <span class="flex min-w-0 flex-col"><span class="[overflow-wrap:anywhere]">{{ $t->name }}</span><span class="text-small text-text-muted">{{ $tk > 0 ? __('media.lib.folder_files_kids', ['n' => $num($treeN[$t->id] ?? 0), 'k' => $num($tk)]) : __('media.lib.folder_files', ['n' => $num($treeN[$t->id] ?? 0)]) }}</span></span>
                    </label>
                @endforeach
            </fieldset>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="secondary" wire:click="cancelReparent">{{ __('media.lib.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" aria-disabled="{{ $reparentTo === '' ? 'true' : 'false' }}" class="aria-disabled:opacity-60" wire:click="confirmReparent">
                    <x-bs-admin::icon name="ri-folder-transfer" class="size-4" />{{ $reparentTo === '' ? __('media.lib.reparent_pick') : ($reparentTo === '-' ? __('media.lib.reparent_ok_top') : __('media.lib.reparent_ok', ['folder' => $intoName ?? ''])) }}
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    @endif

    @if ($canDelete && $trashKept > 0)
        {{-- «own»: some chosen files stay (someone else's, or in use); the rest go --}}
        @php
            $goN = $trashGo;
        @endphp
        <x-bs-admin::confirm-dialog model="askTrashOpen" :title="__('media.lib.ask_trash_mine_title', ['n' => $num($goN)])" icon="ri-error-warning"
            :ok="__('media.lib.ask_trash_mine_ok', ['n' => $num($goN)])" confirm="confirmTrash" cancel="cancelTrash">
            <x-bs-admin::confirm-line icon="ri-user-3">{{ __('media.lib.ask_trash_kept', ['n' => $num($trashKept)]) }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-arrow-go-back">{{ __('media.lib.ask_trash_l2', ['days' => $retention]) }}</x-bs-admin::confirm-line>
        </x-bs-admin::confirm-dialog>
    @elseif ($canDelete)
        <x-bs-admin::confirm-dialog model="askTrashOpen" :title="__('media.lib.ask_trash_title', ['n' => $num($targetN)])" icon="ri-error-warning"
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

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :undo="$undoTrash !== [] ? 'undo' : ($undoFolder !== '' ? 'undoFolderDelete' : ($undoReparent !== '' ? 'undoReparent' : null))" :seq="$toastSeq" />
</div>
