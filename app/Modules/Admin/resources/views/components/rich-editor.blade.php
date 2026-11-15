@props([
    'target',              // Livewire property the HTML syncs into
    'value' => '',
    'rows' => 8,
    'placeholder' => null, // shown while the editor is empty
])
{{-- ONE-LINE rich editor (design 06, the shared writing frame): <x-bs-admin::rich-editor target="description" :value="$description" />
     The frame is ours: a one-row formatting bar (the everyday buttons, the rest behind «Thêm công cụ»),
     the writing area (Jodit, its own toolbar and status bar switched off) and a footer with the block
     under the caret and the counts. resources/js/admin-rich-editor.js drives it.
     Livewire 4 bridge, DOM-only by design ($wire.$set defaults to live = a request per keystroke):
     · content OUT: the island mirrors the HTML into the hidden [data-rich-sync] input (OUTSIDE
       wire:ignore) and fires `input` — plain deferred wire:model carries it on save.
     · actions IN:  the image and video buttons .click() the hidden [data-rich-action] buttons, which
       are real wire:click handlers (the host uses the WithMediaLibraryPicker trait).
     wire:ignore keeps the frame and Jodit's DOM out of morphs. --}}
@php
    $paras = [
        ['p', 'ri-text', __('admin.rte.p')],
        ['h2', 'ri-h-2', __('admin.rte.h2')],
        ['h3', 'ri-h-3', __('admin.rte.h3')],
        ['h4', 'ri-h-4', __('admin.rte.h4')],
        ['h5', 'ri-h-5', __('admin.rte.h5')],
        ['blockquote', 'ri-double-quotes-l', __('admin.rte.quote')],
    ];
    $sizes = [12, 14, 16, 20, 24];
    // The palette of the design: ten fixed tones, then ten hues at three lightnesses (HSL 70% saturation).
    $hex = static function (int $h, float $s, float $l): string {
        $a = $s * min($l, 1 - $l);
        $f = static function (int $n) use ($h, $l, $a): string {
            $k = fmod($n + $h / 30, 12);

            return str_pad(dechex((int) round(255 * ($l - $a * max(-1, min($k - 3, 9 - $k, 1))))), 2, '0', STR_PAD_LEFT);
        };

        return '#'.$f(0).$f(8).$f(4);
    };
    $colors = ['#212529', '#3c4765', '#878a99', '#ced4da', '#e9ebec', '#ffffff', '#2050a0', '#3577f1', '#0ab39c', '#f06548'];
    foreach ([0, 30, 45, 90, 150, 190, 210, 260, 300, 330] as $hue) {
        foreach ([72, 58, 44] as $light) {
            $colors[] = $hex($hue, 0.7, $light / 100);
        }
    }
    $chars = ['©', '®', '™', '°', '±', '×', '÷', '≈', '≤', '≥', '≠', 'µ', 'Ø', 'Ω', 'α', 'β', 'π', '½', '¼', '¾', '€', '₫', '§', '→', '←', '↑', '↓', '•', '–', '—', '«', '»'];
    // Words the script needs after the first paint (titles that flip, the link check, block names).
    $words = [
        'more' => __('admin.rte.more'), 'moreTitle' => __('admin.rte.more_title'),
        'less' => __('admin.rte.less'), 'lessTitle' => __('admin.rte.less_title'),
        'full' => __('admin.rte.full'), 'fullExit' => __('admin.rte.full_exit'),
        'linkEmpty' => __('admin.rte.link_empty'), 'linkGood' => __('admin.rte.link_good'), 'linkBad' => __('admin.rte.link_bad'),
        'unlinkNone' => __('admin.rte.unlink_none'), 'linkChanged' => __('admin.rte.link_changed'), 'sizeNeed' => __('admin.rte.size_need'), 'cell' => __('admin.rte.cell'),
        'minutes' => __('admin.rte.minutes'), 'pick' => __('admin.rte.block_pick'),
        'colorText' => __('admin.rte.color_text_one'), 'colorBg' => __('admin.rte.color_bg_one'),
        'sheet' => ['para' => __('admin.rte.g_style'), 'size' => __('admin.rte.size'), 'color' => __('admin.rte.color')],
        'blocks' => [
            'p' => __('admin.rte.b_p'), 'h' => __('admin.rte.b_h'), 'blockquote' => __('admin.rte.b_quote'),
            'li' => __('admin.rte.b_li'), 'oli' => __('admin.rte.b_oli'), 'td' => __('admin.rte.b_td'), 'th' => __('admin.rte.b_th'),
            'table' => __('admin.rte.table'), 'figure' => __('admin.rte.b_figure'), 'figcaption' => __('admin.rte.b_caption'),
            'pre' => __('admin.rte.b_pre'), 'ul' => __('admin.rte.ul'), 'ol' => __('admin.rte.ol'),
        ],
    ];
    $tool = static fn (string $cmd, string $icon, string $label, array $extra = []): array => compact('cmd', 'icon', 'label', 'extra');
    $everyday = [
        [__('admin.rte.g_bi'), [$tool('bold', 'ri-bold', __('admin.rte.bold')), $tool('italic', 'ri-italic', __('admin.rte.italic'))]],
        [__('admin.rte.g_list'), [$tool('ul', 'ri-list-unordered', __('admin.rte.ul')), $tool('ol', 'ri-list-ordered-2', __('admin.rte.ol'))]],
        [__('admin.rte.g_link'), [$tool('link', 'ri-link', __('admin.rte.link'))]],
        [__('admin.rte.g_image'), [$tool('libraryImage', 'ri-image-add', __('admin.rte.image'))]],
        [__('admin.rte.g_video'), [$tool('embedVideo', 'ri-video-add', __('admin.rte.video'))]],
        [__('admin.rte.g_undo'), [$tool('undo', 'ri-arrow-go-back', __('admin.rte.undo')), $tool('redo', 'ri-arrow-go-forward', __('admin.rte.redo'))]],
    ];
    $more = [
        [__('admin.rte.g_more'), [
            $tool('underline', 'ri-underline', __('admin.rte.underline')), $tool('strikethrough', 'ri-strikethrough', __('admin.rte.strike')),
            $tool('superscript', 'ri-superscript', __('admin.rte.sup')), $tool('subscript', 'ri-subscript', __('admin.rte.sub')),
        ]],
        [__('admin.rte.g_size_color'), 'menus'],
        [__('admin.rte.g_align'), [
            $tool('left', 'ri-align-left', __('admin.rte.align_left')), $tool('center', 'ri-align-center', __('admin.rte.align_center')),
            $tool('right', 'ri-align-right', __('admin.rte.align_right')), $tool('justify', 'ri-align-justify', __('admin.rte.align_justify')),
        ]],
        [__('admin.rte.g_indent'), [$tool('outdent', 'ri-indent-decrease', __('admin.rte.outdent')), $tool('indent', 'ri-indent-increase', __('admin.rte.indent'))]],
        [__('admin.rte.g_clear'), [$tool('unlink', 'ri-link-unlink', __('admin.rte.unlink')), $tool('eraser', 'ri-format-clear', __('admin.rte.clear'))]],
        [__('admin.rte.g_insert'), [
            $tool('table', 'ri-table-2', __('admin.rte.table')), $tool('hr', 'ri-separator', __('admin.rte.hr')),
            $tool('symbols', 'ri-omega', __('admin.rte.chars')),
        ]],
        [__('admin.rte.g_view'), [
            $tool('source', 'ri-code-s-slash', __('admin.rte.source'), ['aria-pressed' => 'false']),
            $tool('fullsize', 'ri-fullscreen', __('admin.rte.full'), ['aria-pressed' => 'false']),
        ]],
    ];
@endphp
<div wire:ignore class="rte" data-rte="{{ $target }}" data-rte-words="{{ json_encode($words, JSON_UNESCAPED_UNICODE) }}">
    <div class="rte-frame">
        <div class="rte-tbw" data-rows="1">
            <div class="rte-tbs">
                <button type="button" class="rte-arr l" data-rte-arr="-1" title="{{ __('admin.rte.arr_l_title') }}" aria-label="{{ __('admin.rte.arr_l') }}" hidden>
                    <x-bs-admin::icon name="ri-arrow-left-s" />
                </button>
                <div class="rte-tb" role="toolbar" aria-label="{{ __('admin.rte.toolbar') }}">
                    <span class="rte-g rte-gk" role="group" aria-label="{{ __('admin.rte.g_style') }}">
                        <button type="button" class="pe-tb-txt" data-rte-menu="para" aria-haspopup="true" aria-expanded="false" title="{{ __('admin.rte.style_title') }}">
                            <x-bs-admin::icon name="ri-paragraph" />{{ __('admin.rte.style') }}<x-bs-admin::icon name="ri-arrow-down-s" class="rte-ar" />
                        </button>
                        <div class="rte-dd" data-rte-dd="para" hidden>
                            @foreach ($paras as [$tag, $icon, $label])
                                <button type="button" class="rte-dd-i" data-rte-block="{{ $tag }}"><x-bs-admin::icon :name="$icon" />{{ $label }}</button>
                            @endforeach
                        </div>
                    </span>
                    @foreach ($everyday as [$group, $tools])
                        <span class="rte-g" role="group" aria-label="{{ $group }}">
                            @foreach ($tools as $t)
                                <button type="button" class="pe-tb" data-rte-cmd="{{ $t['cmd'] }}" title="{{ $t['label'] }}" aria-label="{{ $t['label'] }}"><x-bs-admin::icon :name="$t['icon']" /></button>
                            @endforeach
                        </span>
                    @endforeach
                    @foreach ($more as [$group, $tools])
                        <span class="rte-g" data-rte-more role="group" aria-label="{{ $group }}" hidden>
                            @if ($tools === 'menus')
                                <span class="rte-mw">
                                    <button type="button" class="pe-tb-txt" data-rte-menu="size" aria-haspopup="true" aria-expanded="false" title="{{ __('admin.rte.size_title') }}" aria-label="{{ __('admin.rte.size') }}">
                                        <x-bs-admin::icon name="ri-font-size-2" /><x-bs-admin::icon name="ri-arrow-down-s" class="rte-ar" />
                                    </button>
                                    <div class="rte-dd rte-dd-size" data-rte-dd="size" hidden>
                                        @foreach ($sizes as $px)
                                            <button type="button" class="rte-dd-i" data-rte-size="{{ $px }}" style="font-size:{{ min($px, 18) }}px">{{ __('admin.rte.size_'.$px) }} · {{ $px }}px</button>
                                        @endforeach
                                    </div>
                                </span>
                                <span class="rte-mw">
                                    <button type="button" class="pe-tb" data-rte-menu="color" aria-haspopup="true" aria-expanded="false" title="{{ __('admin.rte.color') }}" aria-label="{{ __('admin.rte.color') }}">
                                        <x-bs-admin::icon name="ri-font-color" />
                                    </button>
                                    <div class="rte-dd rte-dd-color" data-rte-dd="color" data-tab="text" hidden>
                                        <div class="rte-ctabs">
                                            <button type="button" data-rte-ctab="text" aria-pressed="true">{{ __('admin.rte.color_text') }}</button>
                                            <button type="button" data-rte-ctab="bg" aria-pressed="false">{{ __('admin.rte.color_bg') }}</button>
                                        </div>
                                        <div class="rte-cbody">
                                            <div class="rte-sw">
                                                @foreach ($colors as $c)
                                                    <button type="button" data-rte-color="{{ $c }}" style="background:{{ $c }}" title="{{ __('admin.rte.color_text_one', ['hex' => $c]) }}" aria-label="{{ __('admin.rte.color_text_one', ['hex' => $c]) }}"></button>
                                                @endforeach
                                            </div>
                                            <div class="rte-cust">
                                                <label title="{{ __('admin.rte.color_custom') }}">
                                                    <x-bs-admin::icon name="ri-palette" class="size-5 text-primary-text" />
                                                    <input type="color" data-rte-custom aria-label="{{ __('admin.rte.color_custom') }}">
                                                </label>
                                                <span>{{ __('admin.rte.color_custom') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </span>
                            @else
                                @foreach ($tools as $t)
                                    <button type="button" class="pe-tb" data-rte-cmd="{{ $t['cmd'] }}" title="{{ $t['label'] }}" aria-label="{{ $t['label'] }}" @foreach ($t['extra'] as $k => $v) {{ $k }}="{{ $v }}" @endforeach>
                                        <x-bs-admin::icon :name="$t['icon']" />@if ($t['cmd'] === 'fullsize')<x-bs-admin::icon name="ri-fullscreen-exit" />@endif
                                    </button>
                                @endforeach
                            @endif
                        </span>
                    @endforeach
                </div>
                <button type="button" class="rte-arr r" data-rte-arr="1" title="{{ __('admin.rte.arr_r_title') }}" aria-label="{{ __('admin.rte.arr_r') }}" hidden>
                    <x-bs-admin::icon name="ri-arrow-right-s" />
                </button>
            </div>
            <button type="button" class="pe-tb-txt rte-tgl" data-rte-toggle aria-expanded="false" title="{{ __('admin.rte.more_title') }}" aria-label="{{ __('admin.rte.more') }}">
                <x-bs-admin::icon name="ri-expand-left-right" class="rte-tgl-i" /><x-bs-admin::icon name="ri-contract-left-right" class="rte-tgl-i rte-tgl-c" /><span class="rte-tgl-t">{{ __('admin.rte.more') }}</span>
            </button>
        </div>

        <div class="rte-body">
            <textarea id="rich-editor-{{ $target }}" data-rich-editor data-rich-target="{{ $target }}"
                      @if ($placeholder !== null) data-rich-placeholder="{{ $placeholder }}" @endif
                      rows="{{ $rows }}" {{ $attributes }}>{{ $value }}</textarea>
        </div>

        <div class="rte-foot">
            <button type="button" class="rte-tag" data-rte-tag hidden></button>
            <span class="rte-counts">
                <span>{{ __('admin.rte.chars_count') }} <strong data-rte-n="chars">0</strong></span><span aria-hidden="true" class="rte-dot">·</span>
                <span>{{ __('admin.rte.words_count') }} <strong data-rte-n="words">0</strong></span><span aria-hidden="true" class="rte-dot">·</span>
                <span>{{ __('admin.rte.read') }} <strong data-rte-n="read">{{ __('admin.rte.minutes', ['n' => 1]) }}</strong></span>
            </span>
        </div>
    </div>

    {{-- On a narrow or touch screen the three lists open as a sheet from the bottom (the dropdowns would be clipped by the scrolling bar). --}}
    <dialog class="rte-sheet" data-rte-sheet aria-labelledby="rte-sheet-t-{{ $target }}">
        <div class="rte-sh-h">
            <span class="rte-sh-t" id="rte-sheet-t-{{ $target }}"></span>
            <button type="button" class="rte-sh-x" data-rte-close title="{{ __('admin.rte.close_esc') }}" aria-label="{{ __('admin.rte.close') }}"><x-bs-admin::icon name="ri-close" class="size-5" /></button>
        </div>
        <div class="rte-sh-b"></div>
    </dialog>

    <dialog class="rte-dlg" data-rte-link aria-labelledby="rte-link-t-{{ $target }}">
        <div class="rte-dlg-h"><x-bs-admin::icon name="ri-link" class="size-6 text-primary-text" /><span id="rte-link-t-{{ $target }}">{{ __('admin.rte.link_title') }}</span></div>
        <p class="rte-dlg-p">{{ __('admin.rte.link_help') }}</p>
        <input type="url" inputmode="url" autocomplete="url" class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text" data-rte-link-in aria-label="{{ __('admin.rte.link_input') }}" placeholder="{{ __('admin.rte.link_ph') }}">
        <p class="rte-note" data-rte-link-note data-state="empty" aria-live="polite">
            <x-bs-admin::icon name="ri-information" class="rte-ni rte-ni-empty size-4" /><x-bs-admin::icon name="ri-checkbox-circle-line" class="rte-ni rte-ni-good size-4" /><x-bs-admin::icon name="ri-error-warning" class="rte-ni rte-ni-bad size-4" /><span>{{ __('admin.rte.link_empty') }}</span>
        </p>
        <div class="rte-dlg-f">
            <button type="button" class="rte-dlg-x" data-rte-close>{{ __('admin.rte.cancel') }}</button>
            <button type="button" class="rte-dlg-ok" data-rte-link-ok aria-disabled="true"><x-bs-admin::icon name="ri-check" class="size-[1.15em]" />{{ __('admin.rte.link_ok') }}</button>
        </div>
    </dialog>

    <dialog class="rte-dlg rte-dlg-ch" data-rte-chars aria-labelledby="rte-ch-t-{{ $target }}">
        <div class="rte-dlg-h"><x-bs-admin::icon name="ri-omega" class="size-6 text-primary-text" /><span id="rte-ch-t-{{ $target }}">{{ __('admin.rte.chars_title') }}</span></div>
        <div class="rte-chs">
            @foreach ($chars as $c)
                <button type="button" data-rte-char="{{ $c }}" title="{{ __('admin.rte.char_one', ['c' => $c]) }}">{{ $c }}</button>
            @endforeach
        </div>
    </dialog>
</div>
<input type="hidden" data-rich-sync="{{ $target }}" wire:model="{{ $target }}">
<span class="hidden" aria-hidden="true">
    <button type="button" data-rich-action="image" tabindex="-1" wire:click="openImagePicker('editor')"></button>
    <button type="button" data-rich-action="video" tabindex="-1" wire:click="openVideoDialog"></button>
</span>
