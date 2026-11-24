@props(['active' => '', 'unsaved' => null, 'save' => 'save', 'discard' => 'resetChanges', 'hints' => [], 'head' => true, 'invalid' => 0, 'liveErrors' => false, 'firstError' => null, 'firstErrorDetail' => []])
@php
    // The section's title row: its name, then an (i) button stuck to it (the same button and in-place
    // note as a field's explanation) that opens "Về mục <name>" right under the row, with the full
    // description and every first-time explanation the member has put away with "Đã hiểu"; under the
    // row, one short line of description (hidden while the note is open, which holds the full one).
    // A page passes `hints` (FirstHints key => the sentence as it shows on that page) for the
    // explanations it offers; only the dismissed ones go into the note.
    // `head` false keeps only the name (the page's h1) for a page whose own card header still
    // describes the section: no (i) note and no description line.
    // A page may pass a `more` slot: its "⋮" menu, put at the end of the title row on phones only,
    // and an `aside` slot: what ends the title row at every width (a page's «Hướng dẫn»).
    // A section that keeps its draft on the server passes its change count (`:unsaved="$changes"`)
    // and, when they are named otherwise, its save and discard methods. The shell then draws the one
    // save bar under the section (<x-bs-admin::save-bar>: "Chưa có thay đổi" · "Bỏ thay đổi" · "Lưu",
    // across the whole settings area, outside the cards) and the shared unsaved-changes dialog, both
    // on the same count: leaving asks first. `invalid` is the section's count of fields in error,
    // `liveErrors` says it checks them as they are left, and `firstError` / `firstErrorDetail` name
    // its window event that opens the group of the first one. A section that passes nothing (one
    // with nothing to save) has no bar and leaves freely.
    // The section menu, in the design board's groups (Navigation\SettingsMenu), each item with its icon.
    $groups = \App\Modules\Admin\Navigation\SettingsMenu::groups($active);
    $items = array_merge([], ...array_column($groups, 'items'));
    $root = \App\Modules\Admin\Support\AdminPlane::isPlatform() ? __('admin.settings_system') : __('admin.settings');
    $rootUrl = \App\Modules\Admin\Support\AdminPlane::isPlatform()
        ? ($items !== [] ? route($items[0]['route']) : null)
        : \App\Modules\Admin\Navigation\SettingsMenu::entryUrlForMember();
    $listHash = \App\Modules\Admin\Navigation\SettingsMenu::LIST_HASH;
    $current = collect($items)->firstWhere('key', $active);
    $sectionName = (string) ($current['label'] ?? '');
    $short = \Illuminate\Support\Facades\Lang::has('admin.set.sd.'.$active) ? __('admin.set.sd.'.$active) : '';
    $full = \Illuminate\Support\Facades\Lang::has('admin.set.desc.'.$active) ? __('admin.set.desc.'.$active) : '';
    $learned = array_values(array_filter((array) $hints, static fn (mixed $text, mixed $key): bool => is_string($key) && \App\Modules\Admin\Support\FirstHints::dismissed($key) && is_string($text) && $text !== '', ARRAY_FILTER_USE_BOTH));
    $hasInfo = $full !== '' || $learned !== [];
    $infoGlyph = 'size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]';
    // A page with this column folds the main menu to its icon rail so the column and the section
    // get the room: the layout prints data-sidebar-fold on <html> (the shell renders before it),
    // and app.js keeps it in step across wire:navigate through [data-set-shell]. Per request only.
    request()->attributes->set('admin.sidebar_fold', true);
@endphp
<div data-set-shell>
    {{-- ⌂ › Cài đặt › section. Below 768px the section row carries the way back instead. --}}
    @if ($sectionName !== '' && $rootUrl !== null)
        <x-bs-admin::breadcrumb class="mb-2 max-md:hidden" :items="[
            ['label' => $root, 'url' => $rootUrl],
            ['label' => $sectionName],
        ]" />
    @endif
    @if ($sectionName !== '')
        {{-- Below 768px the address ending in #settings-menu turns the frame into the list of sections
             (<html data-set-list>, admin-settings-nav.js): the title reads «Cài đặt» over the menu at
             full width; the section, its way back and its notes step out. --}}
        <div class="mb-5 flex flex-col gap-1 max-md:mb-2" x-data="{ secInfo: false }"
             x-on:keydown.escape="if (secInfo) { secInfo = false; $refs.secInfoBtn.focus() }">
            <div class="flex min-w-0 items-start gap-x-0.5">
                {{-- Below 768px: «‹» back to the list of sections. --}}
                <a href="{{ $listHash }}" data-set-back class="max-md:[[data-set-list]_&]:hidden hit-area -ms-3 inline-flex size-hit shrink-0 items-center justify-center rounded-md text-primary-text transition-colors hover:bg-surface-hover md:hidden"
                   title="{{ __('admin.set.back_aria', ['name' => $root]) }}" aria-label="{{ __('admin.set.back_aria', ['name' => $root]) }}">
                    <x-bs-admin::icon name="ri-arrow-left-s" class="size-[min(1.75rem,calc(1.5rem+var(--user-text-boost)))]" />
                </a>
                <h1 class="min-w-0 py-[max(0rem,calc((var(--spacing-control)-1.4em)/2))] text-[min(1.375rem,calc(1rem+var(--user-text-boost)))] leading-[1.4] font-semibold text-text-strong [overflow-wrap:break-word] [text-wrap:pretty] md:text-[calc(1.375rem+var(--user-text-boost))]"><span class="max-md:[[data-set-list]_&]:hidden">{{ $sectionName }}</span><span class="hidden max-md:[[data-set-list]_&]:inline" data-set-root>{{ $root }}</span></h1>
                @if ($head && $hasInfo)
                    <button type="button" x-ref="secInfoBtn" data-set-info
                            class="max-md:[[data-set-list]_&]:hidden hit-area inline-flex size-control shrink-0 items-center justify-center rounded-md text-text-muted hover:text-primary-text aria-expanded:text-primary-text"
                            x-on:click="secInfo = ! secInfo" aria-expanded="false" x-bind:aria-expanded="secInfo ? 'true' : 'false'" aria-controls="set-info"
                            title="{{ __('admin.set.info_aria', ['name' => $sectionName]) }}" aria-label="{{ __('admin.set.info_aria', ['name' => $sectionName]) }}">
                        <x-bs-admin::icon name="ri-information" :class="$infoGlyph" />
                    </button>
                @endif
                @isset($aside)
                    <div class="ms-auto shrink-0 max-md:[[data-set-list]_&]:hidden">{{ $aside }}</div>
                @endisset
                @isset($more)
                    {{-- The page's own "⋮" (phones only): what the row above the fields offers on a wide screen. --}}
                    <div class="ms-auto shrink-0 md:hidden max-md:[[data-set-list]_&]:hidden">{{ $more }}</div>
                @endisset
            </div>
            @if ($head && $hasInfo)
                <div id="set-info" role="note" aria-labelledby="set-info-t" x-show="secInfo" x-cloak
                     class="max-md:[[data-set-list]_&]:hidden max-w-[40rem] rounded-lg border border-border bg-surface px-3 py-2.5 text-caption leading-relaxed text-text [overflow-wrap:break-word]">
                    <span id="set-info-t" class="mb-1 block font-semibold text-text-strong">{{ __('admin.set.info_title', ['name' => $sectionName]) }}</span>
                    @if ($full !== '')
                        <span class="block">{{ $full }}</span>
                    @endif
                    @foreach ($learned as $text)
                        <span @class(['flex items-start gap-1.5', 'mt-1.5' => $full !== '' || ! $loop->first]) data-set-learned>
                            <x-bs-admin::icon name="ri-star-fill" class="mt-px size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0 text-warning-base" />
                            <span class="min-w-0">{{ $text }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
            @if ($head && $short !== '')
                <p class="max-md:[[data-set-list]_&]:hidden max-w-[640px] text-[calc(0.78125rem+var(--user-text-boost))] leading-[1.55] text-text-muted" @if ($hasInfo) x-show="! secInfo" @endif>{{ $short }}</p>
            @endif
            <p class="hidden max-w-[640px] text-[calc(0.78125rem+var(--user-text-boost))] leading-[1.55] text-text-muted max-md:[[data-set-list]_&]:block">{{ __('admin.set.list_desc') }}</p>
        </div>
    @endif

    <div class="flex items-start gap-5">
        {{-- Section menu (board «Khung quản trị»): a card with the search box and the groups; «Nâng cao»
             stays folded until opened, searched or holding the open section. 220px wide below 1024px,
             238px from there, kept in view while the section scrolls (its own scroll when taller). --}}
        <nav aria-label="{{ $root }}" data-set-nav
             class="w-[220px] shrink-0 lg:w-[238px] md:sticky md:top-20 md:self-start max-md:hidden max-md:[[data-set-list]_&]:block max-md:[[data-set-list]_&]:w-full">
            <div x-data="bsSetNav" wire:ignore class="rounded-lg border border-border bg-surface p-2 shadow-card md:max-h-[calc(100dvh-6rem)] md:overflow-y-auto md:overscroll-contain">
                <div class="mx-0.5 mb-2 mt-0.5">
                    <x-bs-admin::search :label="__('admin.set.search_aria')" :placeholder="__('admin.set.search_ph')" x-ref="find" x-model="q"
                                        data-placeholders="{{ __('admin.set.search_ph') }}|{{ __('admin.set.search_ph_2') }}|{{ __('admin.set.search_ph_3') }}" />
                </div>
                <p role="status" class="text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted">
                    <span x-show="none" x-cloak class="block px-3 py-3.5">{{ __('admin.set.search_none') }}</span>
                </p>
                @foreach ($groups as $group)
                    @php
                        $openNow = in_array($active, array_column($group['items'], 'key'), true);
                    @endphp
                    <div x-show="groupHit($el)" @if ($group['fold']) x-data="{ exp: @js($openNow) }" @endif>
                        @if ($group['fold'])
                            <button type="button" x-on:click="exp = ! exp" aria-controls="set-g-{{ $group['key'] }}"
                                    aria-expanded="{{ $openNow ? 'true' : 'false' }}" x-bind:aria-expanded="(exp || searching) ? 'true' : 'false'"
                                    class="mt-2 flex min-h-menu-row w-full items-center gap-1.5 rounded px-3 py-1 text-start text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-text-muted transition-colors hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
                                <span class="min-w-0 flex-1">{{ $group['label'] }}</span>
                                <x-bs-admin::icon name="ri-arrow-down-s" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0 transition-transform motion-reduce:transition-none" x-bind:class="(exp || searching) && 'rotate-180'" />
                            </button>
                        @else
                            <p data-set-ghead class="px-3 pb-[5px] pt-[11px] text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-text-muted">{{ $group['label'] }}</p>
                        @endif
                        <ul id="set-g-{{ $group['key'] }}" class="space-y-0.5" @if ($group['fold']) x-show="exp || searching" @unless ($openNow) x-cloak @endunless @endif>
                            @foreach ($group['items'] as $item)
                                @php
                                    $on = $active === $item['key'];
                                    $fullDesc = \Illuminate\Support\Facades\Lang::has('admin.set.desc.'.$item['key']) ? __('admin.set.desc.'.$item['key']) : '';
                                @endphp
                                <li x-show="hit($el.dataset.find)" data-find="{{ trim($item['label'].' '.$item['desc'].' '.$fullDesc) }}">
                                    <a href="{{ route($item['route']) }}" @if ($on) aria-current="page" x-on:click="openCurrent($event)" @else wire:navigate @endif
                                       @class([
                                           'flex min-h-menu-row items-center gap-[11px] rounded-md px-3 py-[9px] text-[calc(0.8125rem+var(--user-text-boost))] transition-colors any-pointer-coarse:min-h-menu-row-touch',
                                           'bg-primary/10 font-semibold text-primary-text' => $on,
                                           'font-medium text-text hover:bg-primary/[0.07]' => ! $on,
                                       ])>
                                        <x-bs-admin::icon :name="$item['icon']" @class(['size-[calc(1.0625rem+var(--user-text-boost))] shrink-0', 'text-primary-text' => $on, 'text-text-muted' => ! $on]) />
                                        <span class="min-w-0 flex-1 [overflow-wrap:break-word]">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </nav>

        {{-- Section content --}}
        <div class="min-w-0 flex-1 max-md:[[data-set-list]_&]:hidden" data-set-main>
            <div class="space-y-6">{{ $slot }}</div>
        </div>
    </div>

    @if ($unsaved !== null)
        {{-- No save bar over the list of sections on a phone (nothing there to save). A box-less
             wrapper: the bar keeps sticking to the settings area, not to this div. --}}
        <div class="contents max-md:[[data-set-list]_&]:hidden">
            <x-bs-admin::save-bar :changes="(int) $unsaved" :invalid="(int) $invalid" :live="(bool) $liveErrors" :save="$save" :discard="$discard"
                                  :first-error="$firstError" :first-error-detail="$firstErrorDetail" />
        </div>
        @php
            $paths = array_map(static fn (array $item): string => (string) parse_url(route($item['route']), PHP_URL_PATH), $items);
        @endphp
        <x-bs-admin::unsaved-dialog :count="(int) $unsaved" :label="$current['label'] ?? ''" :save="$save" :discard="$discard" :sections="$paths" />
    @endif
</div>
