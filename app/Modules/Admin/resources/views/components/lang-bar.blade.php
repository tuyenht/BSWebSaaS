@props([
    'langs' => [],
    'manage' => null,
    'focus' => null,
])
@php
    // The language bar of an editor that writes one version per language (board 07 «Bài viết / Trang —
    // trình sửa», the same bar on board 09 for products): which version is open and the state of every
    // version (App\Modules\Admin\Support\LangBar), and the way to another one.
    //
    // $langs: LangBar::items() — one per enabled language, `current` the version open. Each opens with
    // its own link (wire:navigate), so a version with unsaved changes asks first through the page's
    // shared unsaved-changes dialog, in the words of a switch (data-ud-*). $manage: the site's
    // languages settings, or null for a member who may not change them. $focus: the id of the field
    // that takes the focus when a version has been opened from this bar.
    //
    // Wide: one pill per language (a status dot, the name, a star on the source), the open version's
    // state, then how many versions are complete. When the pills do not all fit, the last ones
    // (never the source nor the open one) give way to «+N», which opens the panel of every version;
    // when even those two do not fit, below 700px of bar, or with more than ten languages, one
    // selector naming the open version opens the same panel. The panel lists every version with its
    // state and filled fields, a search box from six languages, and «Quản lý ngôn ngữ». The line
    // under the bar says what to do with the open version. Behaviour: resources/js/admin-lang-bar.js.
    $langs = array_values($langs);
    $cur = collect($langs)->firstWhere('current', true) ?? ($langs[0] ?? null);
@endphp
@if ($cur !== null && count($langs) > 1)
    @php
        $Js = \Illuminate\Support\Js::class;
        $source = collect($langs)->firstWhere('source', true) ?? $cur;
        $full = count(array_filter($langs, fn (array $l): bool => $l['done'] >= $l['total']));
        $short = count(array_filter($langs, fn (array $l): bool => ! $l['source'] && $l['done'] < $l['total']));
        $state = fn (array $l): string => __('admin.lb.k_'.$l['kind']);
        $tip = fn (array $l): string => __('admin.lb.pill_tip', ['lang' => $l['label'], 'state' => $state($l), 'fields' => __('admin.lb.fields', ['done' => $l['done'], 'total' => $l['total']])])
            .($l['public'] ? '' : ' · '.__('admin.lb.not_public'));
        $note = match ($cur['kind']) {
            'source' => __('admin.lb.n_source', ['code' => strtoupper($cur['code'])]).($short > 0 ? ' '.__('admin.lb.n_source_missing', ['count' => $short]) : ''),
            'stale' => __('admin.lb.n_stale', ['lang' => $cur['label']]),
            'empty' => $cur['source'] ? __('admin.lb.n_partial', ['lang' => $cur['label'], 'count' => $cur['total']]) : __('admin.lb.n_empty', ['lang' => $cur['label'], 'source' => $source['label']]),
            'partial' => __('admin.lb.n_partial', ['lang' => $cur['label'], 'count' => $cur['total'] - $cur['done']]),
            default => ! $cur['public'] ? __('admin.lb.n_saved_hidden', ['lang' => $cur['label']]) : __($cur['live'] ? 'admin.lb.n_saved_live' : 'admin.lb.n_saved', ['lang' => $cur['label']]),
        };
        $tone = match ($cur['kind']) { 'stale' => 'danger', 'empty', 'partial' => 'warning', default => 'plain' };
        $noteIcon = match ($cur['kind']) { 'source' => 'ri-star-fill', 'stale', 'partial' => 'ri-error-warning', 'empty' => 'ri-translate-2', default => 'ri-checkbox-circle' };
        // A narrow bar shows the note's first part and «Xem thêm» (the board's short note).
        $noteHead = preg_match('/\s—\s|\.(\s|$)/u', $note, $m, PREG_OFFSET_CAPTURE) === 1 ? substr($note, 0, $m[0][1]) : $note;
        $noteFolds = trim($noteHead) !== '' && rtrim(trim($noteHead), '.') !== rtrim(trim($note), '.');
        $compact = count($langs) > \App\Modules\Admin\Support\LangBar::PILLS_MAX;
        $search = count($langs) >= \App\Modules\Admin\Support\LangBar::SEARCH_FROM;
        $switch = fn (array $l): array => [
            'data-ud-body' => __('admin.lb.sw_body', ['from' => $cur['label'], 'to' => $l['label']]),
            'data-ud-save' => __('admin.lb.sw_save'),
            'data-ud-discard' => __('admin.lb.sw_discard'),
        ];
        $config = [
            'focus' => $focus,
            'compact' => $compact,
            'more' => __('admin.lb.more_aria', ['count' => '__N__']),
            'langs' => array_map(fn (array $l): array => ['code' => $l['code'], 'label' => $l['label']], $langs),
        ];
        $glyph = 'lb-g';
    @endphp
    <div class="lb" role="group" aria-label="{{ __('admin.lb.group') }}" data-lang-bar data-current="{{ $cur['code'] }}" @if ($compact) data-compact @endif
         x-ignore wire:ignore.self data-bs-lazy="bsLangBar" x-data="bsLangBar({{ $Js::from($config) }})"
         x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true) }" x-on:click.outside="close(false)">
        <div class="lb-row">
            <span class="lb-ic" title="{{ __('admin.lb.icon_tip') }}"><x-bs-admin::icon name="ri-translate-2" class="{{ $glyph }}" /></span>
            <div class="lb-pills" x-ref="pills">
                @foreach ($langs as $l)
                    <a href="{{ $l['href'] }}" class="lb-pill" data-lb-pill="{{ $l['code'] }}" data-k="{{ $l['kind'] }}" @if ($l['source'] || $l['current']) data-keep @endif
                       title="{{ $tip($l) }}" aria-label="{{ $tip($l) }}" x-bind:hidden="cut.includes({{ $Js::from($l['code']) }})"
                       @if ($l['current']) aria-current="true" x-on:click.prevent="close(false)" @else wire:navigate x-on:click="pick($el)" {{ new \Illuminate\View\ComponentAttributeBag($switch($l)) }} @endif>
                        <span class="lb-dot" data-k="{{ $l['kind'] }}" aria-hidden="true"></span>
                        <span class="lb-nm">{{ $l['label'] }}</span>
                        @if ($l['source'])
                            <span class="lb-star" title="{{ __('admin.lb.source_tip') }}"><x-bs-admin::icon name="ri-star-fill" class="{{ $glyph }}" /></span>
                        @endif
                    </a>
                @endforeach
            </div>
            <div class="lb-anchor" x-ref="anchor" x-on:focusout="leave($event)">
                <button type="button" class="lb-sel" x-ref="sel" title="{{ __('admin.lb.sel_tip') }}" aria-label="{{ __('admin.lb.sel_aria', ['lang' => $cur['label']]) }}"
                        aria-haspopup="dialog" aria-controls="lb-panel" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'"
                        x-on:click="toggle($el)" x-on:keydown.down.prevent="show($el)">
                    <span class="lb-dot" data-k="{{ $cur['kind'] }}" aria-hidden="true"></span>
                    <span class="lb-sel-nm">{{ $cur['label'] }}</span>
                    <span class="lb-sel-cd" aria-hidden="true">{{ strtoupper($cur['code']) }}</span>
                    @if ($cur['source'])
                        <span class="lb-star" title="{{ __('admin.lb.source_tip') }}"><x-bs-admin::icon name="ri-star-fill" class="{{ $glyph }}" /></span>
                    @endif
                    <x-bs-admin::icon name="ri-arrow-down-s" class="{{ $glyph }} lb-chev" />
                </button>
                <button type="button" class="lb-more" x-ref="more" hidden x-bind:hidden="over === 0" title="{{ __('admin.lb.more_tip') }}"
                        x-bind:aria-label="moreLabel()" aria-haspopup="dialog" aria-controls="lb-panel" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'"
                        x-on:click="toggle($el)" x-on:keydown.down.prevent="show($el)">
                    <span x-text="'+' + over"></span>
                    <x-bs-admin::icon name="ri-arrow-down-s" class="{{ $glyph }} lb-chev" />
                </button>
                <x-bs-admin::lang-panel class="lb-panel" id="lb-panel" :label="__('admin.lb.panel')" x-ref="panel" x-show="open" x-cloak :none="$search ? '! anyHit()' : null">
                    <div class="lb-ph">
                        <span class="lb-ph-t">{{ __('admin.lb.panel_head') }}</span>
                        <span class="lb-ph-n">{{ __('admin.lb.panel_full', ['done' => $full, 'total' => count($langs)]) }}</span>
                    </div>
                    @if ($search)
                        <label class="lb-search" data-focus-wrap>
                            <x-bs-admin::icon name="ri-search" class="{{ $glyph }}" />
                            <input type="search" class="border-0 bg-transparent p-0" x-ref="q" x-model="q" aria-label="{{ __('admin.lb.search_aria') }}" placeholder="{{ __('admin.lb.search') }}"
                                   autocomplete="off" x-on:keydown.down.prevent="step(1, true)">
                        </label>
                    @endif
                    <div class="lb-list" role="menu" aria-label="{{ __('admin.lb.menu') }}" x-ref="menu"
                         x-on:keydown.down.prevent="step(1)" x-on:keydown.up.prevent="step(-1)" x-on:keydown.home.prevent="edge(false)" x-on:keydown.end.prevent="edge(true)">
                        @foreach ($langs as $l)
                            <a href="{{ $l['href'] }}" role="menuitemradio" aria-checked="{{ $l['current'] ? 'true' : 'false' }}" tabindex="{{ $l['current'] ? '0' : '-1' }}"
                               class="lb-it" data-lb-row="{{ $l['code'] }}" x-show="hit({{ $Js::from($l['code']) }})"
                               @if ($l['current']) x-on:click.prevent="close(true)" @else wire:navigate x-on:click="pick($el)" {{ new \Illuminate\View\ComponentAttributeBag($switch($l)) }} @endif>
                                <span class="lb-dot" data-k="{{ $l['kind'] }}" aria-hidden="true"></span>
                                <span class="lb-it-tx">
                                    <span class="lb-it-nm">{{ $l['label'] }}</span>
                                    <span class="lb-it-sub">{{ $state($l) }}{{ $l['public'] ? '' : ' · '.__('admin.lb.not_public') }}</span>
                                </span>
                                @if ($l['source'])
                                    <span class="lb-star" title="{{ __('admin.lb.source_tip') }}"><x-bs-admin::icon name="ri-star-fill" class="{{ $glyph }}" /></span>
                                @endif
                                <span class="lb-it-n" title="{{ __('admin.lb.fields', ['done' => $l['done'], 'total' => $l['total']]) }}">{{ $l['done'] }}/{{ $l['total'] }}</span>
                            </a>
                        @endforeach
                    </div>
                    @if ($manage !== null)
                        <x-slot:foot>
                            <div class="lb-pf">
                                <a href="{{ $manage }}" wire:navigate class="lb-manage">
                                    <x-bs-admin::icon name="ri-settings-3" class="{{ $glyph }}" />
                                    <span>{{ __('admin.lb.manage') }}</span>
                                </a>
                            </div>
                        </x-slot:foot>
                    @endif
                </x-bs-admin::lang-panel>
            </div>
            <span class="lb-chip" data-k="{{ $cur['kind'] }}"><span class="lb-dot" data-k="{{ $cur['kind'] }}" aria-hidden="true"></span>{{ $state($cur) }}</span>
            <span class="lb-gap" aria-hidden="true"></span>
            <span class="lb-div" aria-hidden="true"></span>
            <span class="lb-prog" title="{{ __('admin.lb.prog_tip', ['done' => $full, 'total' => count($langs)]) }}">
                <span class="lb-bar" aria-hidden="true"><span class="lb-fill" @if ($full === count($langs)) data-full @endif style="width: {{ (int) round($full / count($langs) * 100) }}%"></span></span>
                <span aria-hidden="true">{{ $full }}/{{ count($langs) }}</span>
                <span class="sr-only">{{ __('admin.lb.prog_tip', ['done' => $full, 'total' => count($langs)]) }}</span>
            </span>
        </div>
        <div class="lb-note" id="lb-note" data-tone="{{ $tone }}" x-bind:data-open="noteOpen ? '' : null">
            <x-bs-admin::icon :name="$noteIcon" class="{{ $glyph }} lb-note-i" />
            <span class="lb-note-full">{{ $note }}</span>
            @if ($noteFolds)
                <span class="lb-note-head">{{ $noteHead }}</span>
                <button type="button" class="lb-note-tg" aria-expanded="false" x-bind:aria-expanded="noteOpen ? 'true' : 'false'" aria-controls="lb-note" x-on:click="noteOpen = ! noteOpen">
                    <span x-show="! noteOpen">{{ __('admin.lb.note_more') }}</span>
                    <span x-show="noteOpen" x-cloak>{{ __('admin.lb.note_less') }}</span>
                </button>
            @endif
        </div>
    </div>
@endif
