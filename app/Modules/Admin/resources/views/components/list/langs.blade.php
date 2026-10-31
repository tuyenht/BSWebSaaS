@props([
    'locales' => [],
    'has' => [],
    'source' => null,
    'name' => '',
    'href' => null,
    'why' => null,
    'show' => 'true',
])
@php
    // A record's language versions in its meta line, as one piece: the "Chưa dịch: EN" note (shown
    // whenever a translation is missing, whatever the member's column choice) and a chip per site
    // locale. A member who may edit gets each chip as a link to that locale's editor (`href`, a
    // closure taking the locale); otherwise the chip opens a small note saying why (`why`). The
    // source locale carries a star; a missing one is drawn dashed (with a "+" when it can be added).
    // `show` is the Alpine expression of the member's column choice: off hides the chips that have
    // content (below 600 all chips; the note stays). With more than four locales one summary button
    // opens a menu of the versions instead of the chips.
    $locales = array_values($locales);
    $has = array_values($has);
    $canEdit = $href instanceof \Closure;
    $langName = function (string $lc): string {
        $key = 'entries.language_'.$lc;
        if (\Illuminate\Support\Facades\Lang::has($key)) {
            return __($key);
        }
        $n = class_exists(\Locale::class) ? (string) \Locale::getDisplayLanguage($lc, app()->getLocale()) : '';

        return $n !== '' && $n !== $lc ? mb_strtoupper(mb_substr($n, 0, 1)).mb_substr($n, 1) : strtoupper($lc);
    };
    $langLow = fn (string $lc): string => \Illuminate\Support\Facades\Lang::has('entries.language_lower_'.$lc) ? __('entries.language_lower_'.$lc) : $langName($lc);
    $missing = array_values(array_filter($locales, fn (string $lc): bool => ! in_array($lc, $has, true)));
    $summary = count($locales) > 4;
    $state = fn (string $lc): string => $lc === $source ? 'src' : (in_array($lc, $has, true) ? 'full' : 'empty');
    $Js = \Illuminate\Support\Js::class;
@endphp
@if ($locales !== [])
    <span class="list-m" data-for="langs"@if ($missing === []) x-show="{{ $show }}"@endif>
        <span class="list-lcs">
            @if ($missing !== [])
                @php $missLabel = __('entries.lc_missing', ['lang' => implode(', ', array_map($langLow, $missing))]); @endphp
                <span class="list-lmiss" role="note" title="{{ $missLabel }}" aria-label="{{ $missLabel }}">
                    <x-bs-admin::icon name="ri-translate-2" class="size-[calc(0.875rem+var(--user-text-boost))] shrink-0 list-lmiss-i" />
                    <span>{{ __('entries.lc_missing_note', ['codes' => implode(', ', array_map('strtoupper', $missing))]) }}</span>
                </span>
            @endif

            @if ($summary)
                @php
                    $sum = $missing === []
                        ? __('entries.lc_sum_full', ['count' => count($locales)])
                        : (count($missing) <= 2
                            ? __('entries.lc_sum_few', ['count' => count($missing), 'langs' => implode(', ', array_map($langLow, $missing))])
                            : __('entries.lc_sum_many', ['count' => count($missing)]));
                    $stText = fn (string $lc): string => __('entries.lc_st_'.['src' => 'source', 'full' => 'has', 'empty' => 'missing'][$state($lc)]);
                    $sumTip = collect($locales)->map(fn (string $lc): string => __('entries.lc_tip_item', ['lang' => $langName($lc), 'state' => $stText($lc)]))->implode(' · ');
                @endphp
                <span class="list-lsw" x-show="{{ $show }}"
                    x-data="{ open: false, close(back) { this.open = false; if (back) { this.$refs.sum.focus() } } }"
                    x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="close(true)">
                    <button type="button" class="list-lsum" data-ok="{{ $missing === [] ? '1' : '0' }}" x-ref="sum"
                        title="{{ $sumTip }}" aria-label="{{ __('entries.lc_sum_aria', ['title' => $name, 'sum' => $sum]) }}"
                        aria-haspopup="menu" aria-expanded="false" x-bind:aria-expanded="open.toString()"
                        x-on:click="open = ! open; open && $nextTick(() => $focus.within($refs.menu).first())">
                        <x-bs-admin::icon :name="$missing === [] ? 'ri-checkbox-circle' : 'ri-error-warning'" class="size-[calc(1rem+var(--user-text-boost))] shrink-0 list-lsum-i" />
                        <span>{{ $sum }}</span>
                    </button>
                    <div class="list-menu list-lmenu" x-show="open" x-cloak>
                        <p class="list-lmh" aria-hidden="true">{{ __('entries.lc_menu_head') }}</p>
                        <div x-ref="menu" role="menu" aria-label="{{ __('entries.lc_menu_label', ['title' => $name]) }}"
                            x-on:keydown.down.prevent="$focus.within($refs.menu).wrap().next()" x-on:keydown.up.prevent="$focus.within($refs.menu).wrap().prev()"
                            x-on:keydown.home.prevent="$focus.within($refs.menu).first()" x-on:keydown.end.prevent="$focus.within($refs.menu).last()"
                            x-on:keydown.tab="open = false">
                            @foreach ($locales as $lc)
                                @php $s = $state($lc); @endphp
                                @if ($canEdit)
                                    <a href="{{ $href($lc) }}" wire:navigate role="menuitem" class="list-mi" x-on:click="close(false)">
                                        <span class="list-mi-tx">{{ $langName($lc) }} <span class="list-mi-st">{{ $stText($lc) }}</span></span>
                                        <span class="list-mi-act">{{ $s === 'empty' ? __('entries.add_translation') : __('entries.edit_action') }}</span>
                                    </a>
                                @else
                                    <button type="button" role="menuitem" aria-disabled="true" class="list-mi">
                                        <span class="list-mi-tx">{{ $langName($lc) }} <span class="list-mi-st">{{ $stText($lc) }}</span></span>
                                    </button>
                                @endif
                            @endforeach
                        </div>
                        @if (! $canEdit && $why)
                            <p class="list-lmw"><x-bs-admin::icon name="ri-lock" class="size-[calc(0.875rem+var(--user-text-boost))] shrink-0 list-lmw-i" /> {{ $why }}</p>
                        @endif
                    </div>
                </span>
            @else
                <span class="list-lcg" x-bind:data-off="({{ $show }}) ? null : ''"
                    @unless ($canEdit) x-data="{ pop: null }" x-on:click.outside="pop = null"
                        x-on:keydown.escape.stop="if (pop) { const c = pop; pop = null; $refs['lc_' + c].focus() }" @endunless>
                    @foreach ($locales as $lc)
                        @php
                            $s = $state($lc);
                            $lang = $langLow($lc);
                        @endphp
                        @if ($canEdit)
                            @php
                                $tip = $s === 'empty'
                                    ? __('entries.lc_add_tip', ['lang' => $lang])
                                    : __($s === 'src' ? 'entries.lc_edit_source' : 'entries.lc_edit', ['lang' => $lang]);
                                $aria = __('entries.lc_named', ['label' => $s === 'empty' ? __('entries.lc_add', ['lang' => $lang]) : $tip, 'title' => $name]);
                            @endphp
                            <a href="{{ $href($lc) }}" wire:navigate class="list-lc" data-s="{{ $s }}" @if ($s === 'empty') data-add @endif
                                title="{{ $tip }}" aria-label="{{ $aria }}">{{ strtoupper($lc) }}@if ($s === 'src')<x-bs-admin::icon name="ri-star-fill" class="size-[calc(0.75rem+var(--user-text-boost))] shrink-0 list-lc-star" />@endif</a>
                        @else
                            @php
                                $what = $s === 'empty'
                                    ? __('entries.lc_missing', ['lang' => $lang])
                                    : __($s === 'src' ? 'entries.lc_has_source' : 'entries.lc_has', ['lang' => $langName($lc)]);
                                $label = __('entries.lc_reason', ['state' => $what, 'why' => $why]);
                            @endphp
                            <button type="button" class="list-lc" data-s="{{ $s }}" x-ref="lc_{{ $lc }}"
                                title="{{ $label }}" aria-label="{{ $label }}" aria-expanded="false"
                                x-bind:aria-expanded="(pop === {{ $Js::from($lc) }}).toString()"
                                x-on:click="pop = pop === {{ $Js::from($lc) }} ? null : {{ $Js::from($lc) }}">{{ strtoupper($lc) }}@if ($s === 'src')<x-bs-admin::icon name="ri-star-fill" class="size-[calc(0.75rem+var(--user-text-boost))] shrink-0 list-lc-star" />@endif</button>
                        @endif
                    @endforeach

                    @unless ($canEdit)
                        @foreach ($locales as $lc)
                            @php
                                $s = $state($lc);
                                $head = $s === 'src' ? __('entries.lc_pop_source', ['lang' => $langName($lc)]) : $langName($lc);
                            @endphp
                            <span class="list-menu list-pop" role="group" aria-label="{{ $head }}" x-show="pop === {{ $Js::from($lc) }}" x-cloak>
                                <span class="list-pop-h">{{ $head }}</span>
                                <span class="list-pop-t">{{ __('entries.lc_state_'.['src' => 'source', 'full' => 'has', 'empty' => 'missing'][$s]) }}</span>
                                @if ($why)
                                    <span class="list-pop-w"><x-bs-admin::icon name="ri-lock" class="size-[calc(0.875rem+var(--user-text-boost))] shrink-0 list-lmw-i" /> {{ $why }}</span>
                                @endif
                            </span>
                        @endforeach
                    @endunless
                </span>
            @endif
        </span>
    </span>
@endif
