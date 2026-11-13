@php
    use App\Modules\Admin\Support\FirstHints;
    use App\Modules\Theme\Chrome\ContactIcons;
    use App\Modules\Theme\Chrome\FloatingContact;
    use Illuminate\Support\Js;

    // "Nút liên hệ nổi" (design c-floating-contact-panel): the language of the hover labels, then the
    // switch and the look, the channels (order, colour, value, label), the bottom-bar options and a
    // preview; the page saves its own draft (Đã lưu / Chưa lưu · Lưu thay đổi). Layout by the page's
    // width: two columns from 56rem, else one column with the preview under the settings.
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $svg = static fn (?string $code): string => ContactIcons::path($code) ?? ContactIcons::path('ri-global-line') ?? '';
    $title = 'text-[calc(0.90625rem+var(--user-text-boost))] font-semibold text-text-strong';
    $name = 'text-[calc(0.84375rem+var(--user-text-boost))] font-semibold text-text-strong';
    $lbl = 'mb-2 block text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text';
    // Placeholders show the shape of a value; they are examples, not words to translate.
    $examples = ['phone' => '0912345678', 'zalo' => '0912345678', 'messenger' => '100063789074118', 'email' => __('admin.fc.ph_email'), 'sms' => '0912345678',
        'whatsapp' => '0912345678', 'telegram' => '@username', 'viber' => '0912345678', 'tiktok' => 'https://tiktok.com/@…', 'youtube' => 'https://youtube.com/@…',
        'instagram' => 'https://instagram.com/…', 'maps' => 'https://maps.google.com/…'];
    $localeNames = (array) config('locales.labels');
    $nameOf = static fn (string $lc): string => (string) ($localeNames[$lc] ?? strtoupper($lc));

    $isCustom = static fn (string $key): bool => FloatingContact::typeOf($key) === 'custom';
    $chName = static fn (array $c): string => $isCustom($c['key'])
        ? (trim($c['name']) !== '' ? trim($c['name']) : __('admin.fc.custom_label'))
        : __('admin.fc.ch.'.$c['key']);
    // The hover label a channel shows in $lc when none is typed: its own word in that language.
    $defaultTip = static fn (array $c, string $lc): string => $isCustom($c['key'])
        ? (trim($c['name']) !== '' ? trim($c['name']) : __('storefront.fc_contact', [], $lc))
        : __('storefront.fc_'.$c['key'], [], $lc);
    // Only a checked colour is printed into a style (a tampered draft can hold anything).
    $colorOf = static fn (array $c): string => FloatingContact::color($c['color']) ?? (FloatingContact::CHANNELS[$c['key']]['color'] ?? FloatingContact::CUSTOM_COLOR);

    // Channels switched on, and how many of them have a hover label in each language (the source
    // language counts every one: an empty label there shows the channel's own word).
    $onChannels = array_values(array_filter($rows, fn (array $c): bool => $c['on']));
    $filled = count($onChannels);
    $langInfo = [];
    foreach ($locales as $lc) {
        $isSrc = $lc === $defaultLocale;
        $done = $isSrc ? $filled : count(array_filter($onChannels, fn (array $c): bool => trim((string) ($c['tips'][$lc] ?? '')) !== ''));
        $kind = $isSrc ? 'src' : ($done === 0 && $filled > 0 ? 'empty' : ($done < $filled ? 'partial' : 'ok'));
        $label = match ($kind) {
            'src' => __('admin.fc.st_src'),
            'ok' => __('admin.fc.st_ok'),
            'partial' => __('admin.fc.st_partial', ['count' => $filled - $done]),
            default => __('admin.fc.st_empty'),
        };
        $pub = in_array($lc, $publicLocales, true);
        $langInfo[$lc] = ['code' => $lc, 'name' => $nameOf($lc), 'src' => $isSrc, 'kind' => $kind, 'done' => $done, 'label' => $label, 'pub' => $pub,
            'title' => $nameOf($lc).' — '.$label.' · '.__('admin.fc.lang_count', ['done' => $done, 'all' => $filled]).($pub ? '' : ' · '.__('admin.fc.st_hidden'))];
    }
    $cur = $langInfo[$lang] ?? reset($langInfo);
    $complete = count(array_filter($langInfo, fn (array $l): bool => $l['done'] >= $filled));
    $prog = $complete.'/'.count($langInfo);
    $dotClass = static fn (string $kind): string => match ($kind) {
        'src', 'ok' => 'bg-success-base',
        'partial' => 'bg-warning-base',
        default => 'border-[1.5px] border-dashed border-text-faint',
    };
    $missingLangs = count(array_filter($langInfo, fn (array $l): bool => ! $l['src'] && $l['done'] < $filled));
    // On the source language the bar first explains what the source is, with "Đã hiểu"; once the
    // member has put that away it lives in the section's (i) note and only the live part stays (how
    // many languages still lack labels) — on a phone inside the bar's own row, otherwise under it.
    // The other languages' lines are live information and always show.
    $srcHint = __('admin.fc.note_src', ['code' => strtoupper($defaultLocale)]);
    $srcHintOk = FirstHints::dismissed(FirstHints::LANG_SOURCE);
    $missingText = $missingLangs > 0 ? __('admin.fc.note_src_missing', ['count' => $missingLangs]) : '';
    $note = match (true) {
        $cur['kind'] === 'empty' => ['text' => __('admin.fc.note_empty', ['name' => $cur['name']]), 'icon' => 'ri-translate-2', 'tone' => 'warning'],
        $cur['kind'] === 'partial' => ['text' => __('admin.fc.note_partial', ['count' => $filled - $cur['done'], 'name' => $cur['name']]), 'icon' => 'ri-error-warning', 'tone' => 'warning'],
        $cur['src'] && ! $srcHintOk => ['text' => $srcHint.($missingText !== '' ? ' '.$missingText : ''), 'icon' => 'ri-star-fill', 'tone' => 'src'],
        $cur['src'] => $missingText !== '' ? ['text' => $missingText, 'icon' => 'ri-star-fill', 'tone' => 'src'] : null,
        default => ['text' => __('admin.fc.note_ok', ['name' => $cur['name']]), 'icon' => 'ri-checkbox-circle', 'tone' => 'ok'],
    };
    $noteOk = $cur['src'] && ! $srcHintOk;
    $leftInBar = $cur['src'] && $srcHintOk && $missingText !== '';
    $noteTone = $note === null ? '' : ['warning' => 'bg-warning-soft text-warning-text', 'src' => 'bg-primary-soft text-text', 'ok' => 'bg-success-soft text-success-text'][$note['tone']];
    $manyLangs = count($locales) > 5;

    // A channel's errors that no field on screen shows: on a switched-off row every field but the
    // name is folded away; on a row switched on, only the labels of the other languages are.
    // The fields of the channel's "Tùy chỉnh nút" group (colour, the hover label in the language being
    // edited, its own icon picture) are on screen whether the channel is on or off; while the group is
    // closed their errors are repeated under the row ($groupErrors), so a closed group hides none.
    $groupFields = ['color', 'tips', 'tips.'.$lang, 'img'];
    $hiddenErrors = static function (int|string $i, bool $on) use ($errors, $groupFields): array {
        $shown = $on ? ['value', 'icon', 'name', ...$groupFields] : ['name', ...$groupFields];
        $out = [];
        foreach ($errors->getMessages() as $field => $messages) {
            $rest = str_starts_with((string) $field, "channels.$i.") ? substr((string) $field, strlen("channels.$i.")) : null;
            if ($rest !== null && ! in_array($rest, $shown, true) && isset($messages[0])) {
                $out[$rest] = (string) $messages[0];
            }
        }

        return $out;
    };
    $groupErrors = static function (int|string $i) use ($errors, $groupFields): array {
        $out = [];
        foreach ($groupFields as $field) {
            foreach ($errors->get("channels.$i.$field") as $message) {
                $out[] = [$field, (string) $message];
            }
        }

        return $out;
    };
    // The colours the group offers besides the channel's own default (the custom colour picks any other).
    $palette = ['#0ea5e9', '#16a34a', '#dc2626', '#f97316', '#7c3aed', '#db2777', '#111827'];
    $icoH = 'flex flex-wrap items-baseline gap-x-2.5 gap-y-1 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text-strong';
    $icoN = 'text-[calc(0.75rem+var(--user-text-boost))] font-normal leading-[1.45] text-text-muted';
    // A swatch: 28px with a mouse at the default text size, a 44px target on touch and from the next text size up.
    $swatch = 'inline-flex size-[max(1.75rem,var(--spacing-tap-cell))] shrink-0 cursor-pointer items-center justify-center rounded-full any-pointer-coarse:size-hit';
    $fieldName = static fn (string $field): string => match (true) {
        str_starts_with($field, 'tips.') => __('admin.fc.f_tip_in', ['lang' => $nameOf(substr($field, 5))]),
        in_array($field, ['value', 'icon', 'color', 'tips', 'img'], true) => __('admin.fc.f_'.$field),
        default => __('admin.fc.f_value'),
    };

    $phone = collect($rows)->firstWhere('key', 'phone');
    $phoneWarn = ! ($phone !== null && $phone['on'] && FloatingContact::clean('phone', $phone['value']) !== null);
    $isV = $layout === 'vertical';
    $isC = $layout === 'combo';
    $isH = $layout === 'horizontal';
    $hasPresets = array_values(array_filter(FloatingContact::PRESETS, fn (string $k): bool => collect($rows)->firstWhere('key', $k) === null));
    $full = count($rows) >= FloatingContact::MAX_CHANNELS;

    $buySafe = FloatingContact::color($buyColor) ?? FloatingContact::BUY_COLOR;
@endphp
<x-bs-admin::settings-shell active="floating" :hints="count($locales) > 1 ? [FirstHints::LANG_SOURCE => $srcHint] : []">
    {{-- `ico`: the channel whose "Tùy chỉnh nút" group is open (one at a time). The first field in error
         gets the focus after a refused save, its group or folded "mã Remix Icon" opened first. --}}
    <div class="@container/fc flex flex-col gap-4"
         x-data="{ ico: null }"
         x-on:fc-first-error.window="$nextTick(() => { const el = $el.querySelector('[aria-invalid=true]'); if (! el) { return; } const g = el.closest('[data-fc-ico]'); if (g) { ico = g.dataset.fcIco; } const d = el.closest('details'); if (d) { d.open = true; } $nextTick(() => el.focus()); })">

        {{-- Language of the hover labels (only on a site with more than one) --}}
        @if (count($locales) > 1)
            <div class="rounded-md border border-border-strong bg-surface-muted">
                <div class="flex flex-wrap items-center gap-2.5 px-2.5 py-1.5 max-md:py-1">
                    <x-bs-admin::icon name="ri-translate-2" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 text-text-muted" title="{{ __('admin.fc.lang_icon_title') }}" />

                    @unless ($manyLangs)
                        <div role="group" aria-label="{{ __('admin.fc.lang_icon_title') }}" class="hidden min-w-0 flex-wrap items-center gap-1.5 p-px @min-[40rem]/fc:flex">
                            @foreach ($langInfo as $l)
                                <button type="button" wire:click="setLang({{ Js::from($l['code']) }})" aria-pressed="{{ $l['code'] === $lang ? 'true' : 'false' }}" title="{{ $l['title'] }}"
                                        @class(['hit-area-row inline-flex min-h-control-row items-center gap-1.5 rounded-md border px-2.5 text-small font-medium',
                                            'border-primary-text bg-surface text-primary-text shadow-[inset_0_0_0_1px_var(--color-primary-text)]' => $l['code'] === $lang,
                                            'border-border-input bg-surface text-text hover:bg-surface-hover' => $l['code'] !== $lang])>
                                    <span class="size-2 shrink-0 rounded-full {{ $dotClass($l['kind']) }}" aria-hidden="true"></span>{{ $l['name'] }}
                                    @if ($l['src'])
                                        <x-bs-admin::icon name="ri-star-fill" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0 text-warning-base" title="{{ __('admin.fc.lang_src') }}" />
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endunless

                    {{-- The current language and every language with its state: the only picker on a narrow page or a site with many languages --}}
                    <div x-data="{ open: false, q: '' }" x-on:keydown.escape="if (open) { open = false; $refs.opener.focus() }" x-on:click.outside="open = false"
                         @class(['relative flex', '@min-[40rem]/fc:hidden' => ! $manyLangs])>
                        <button type="button" x-ref="opener" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="true" title="{{ $cur['title'] }}"
                                class="hit-area-row inline-flex min-h-control-row items-center gap-1.5 rounded-md border border-primary-text bg-surface px-2.5 text-small font-medium text-primary-text">
                            <span class="size-2 shrink-0 rounded-full {{ $dotClass($cur['kind']) }}" aria-hidden="true"></span>
                            <span>{{ $cur['name'] }}</span>
                            <span class="text-caption font-bold uppercase text-text-muted">{{ $cur['code'] }}</span>
                            @if ($cur['src'])
                                <x-bs-admin::icon name="ri-star-fill" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0 text-warning-base" title="{{ __('admin.fc.lang_src') }}" />
                            @endif
                            <x-bs-admin::icon name="ri-arrow-down-s" class="{{ $glyph }} text-text-muted" />
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                             class="absolute top-[calc(100%+6px)] left-0 z-90 max-h-[21.25rem] w-max max-w-[min(18.5rem,calc(100vw-2rem))] min-w-60 overflow-y-auto rounded-lg border border-border-strong bg-surface p-1.5 shadow-lg">
                            <div class="flex items-baseline justify-between gap-2.5 px-2 pt-2 pb-1.5">
                                <span class="text-caption font-bold tracking-[.04em] text-text-muted uppercase">{{ __('admin.fc.lang_panel_title') }}</span>
                                <span class="text-caption whitespace-nowrap text-text-muted">{{ __('admin.fc.lang_prog', ['prog' => $prog]) }}</span>
                            </div>
                            @if (count($locales) >= 6)
                                <label class="field mx-1 mb-1.5 flex items-center gap-2 rounded-md border border-border-input bg-surface px-2.5 focus-ring-within">
                                    <x-bs-admin::icon name="ri-search" class="{{ $glyph }} text-text-muted" />
                                    <input type="search" x-model="q" placeholder="{{ __('admin.fc.lang_search') }}" aria-label="{{ __('admin.fc.lang_search') }}"
                                           class="min-w-0 flex-1 border-0 bg-transparent text-body text-text-strong outline-0">
                                </label>
                            @endif
                            @foreach ($langInfo as $l)
                                <button type="button" wire:click="setLang({{ Js::from($l['code']) }})" x-on:click="open = false" aria-pressed="{{ $l['code'] === $lang ? 'true' : 'false' }}"
                                        x-show="q === '' || {{ Js::from(mb_strtolower($l['name'].' '.$l['code'])) }}.includes(q.toLowerCase())"
                                        @class(['flex min-h-menu-row w-full flex-wrap items-center gap-x-2.5 gap-y-0.5 rounded-md px-2.5 py-1.5 text-start hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch',
                                            'bg-primary-soft' => $l['code'] === $lang])>
                                    <span class="size-2 shrink-0 rounded-full {{ $dotClass($l['kind']) }}" aria-hidden="true"></span>
                                    <span class="flex min-w-0 flex-[1_1_8.75rem] flex-col gap-px">
                                        <span @class(['text-small text-text-strong', 'font-semibold' => $l['code'] === $lang])>{{ $l['name'] }}</span>
                                        <span class="text-caption text-text-muted">{{ $l['label'] }}{{ $l['pub'] ? '' : ' · '.__('admin.fc.st_hidden') }}</span>
                                    </span>
                                    <span class="ms-auto text-caption whitespace-nowrap text-text-muted">{{ __('admin.fc.lang_count', ['done' => $l['done'], 'all' => $filled]) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <span class="hidden items-center gap-1.5 text-caption text-text-muted @min-[50rem]/fc:inline-flex">
                        <span class="size-2 shrink-0 rounded-full {{ $dotClass($cur['kind']) }}" aria-hidden="true"></span>{{ $cur['label'] }}
                    </span>
                    @if ($leftInBar)
                        <span class="min-w-0 flex-[1_1_8em] text-caption leading-[1.35] text-text-muted [overflow-wrap:break-word] [text-wrap:pretty] md:hidden" data-lang-left>{{ $missingText }}</span>
                    @endif
                    <span @class(['min-w-2 flex-auto', 'max-md:hidden' => $leftInBar])></span>
                    <span @class(['h-5 w-px shrink-0 bg-border-strong', 'max-md:hidden' => $leftInBar]) aria-hidden="true"></span>
                    <span class="shrink-0 text-caption whitespace-nowrap text-text-muted" title="{{ __('admin.fc.lang_prog_title') }}">{{ $prog }}</span>
                </div>
                @if ($note !== null)
                    <div @class(['flex items-start gap-2 border-t border-border px-3 py-2 text-caption leading-normal '.$noteTone, 'flex-wrap gap-y-1.5' => $noteOk, 'max-md:hidden' => $leftInBar])>
                        <x-bs-admin::icon :name="$note['icon']" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
                        <span @class(['min-w-0', 'flex-[1_1_14em]' => $noteOk])>{{ $note['text'] }}</span>
                        @if ($noteOk)
                            <x-bs-admin::hint-ok :key="FirstHints::LANG_SOURCE" class="ms-auto" />
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <div class="grid items-start gap-5 @min-[56rem]/fc:grid-cols-[minmax(0,1fr)_minmax(0,26.25rem)]">
            <div class="flex min-w-0 flex-col gap-4">

                {{-- On/off and the look --}}
                <div class="rounded-md border border-border bg-surface shadow-card">
                    <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3.5">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-text">
                                <x-bs-admin::icon name="ri-customer-service-2" class="size-[min(1.5rem,calc(1.1875rem+var(--user-text-boost)))]" />
                            </span>
                            <div class="min-w-0">
                                <h2 class="{{ $title }}" id="fc-enable-l">{{ __('admin.fc.enable') }}</h2>
                                <p class="text-caption text-text-muted">{{ __('admin.fc.enable_sub') }}</p>
                            </div>
                        </div>
                        @php $state = $enabled ? __('admin.fc.on') : __('admin.fc.off'); @endphp
                        <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="toggleEnabled"
                                aria-label="{{ __('admin.fc.enable_aria', ['state' => $state]) }}" title="{{ __('admin.fc.enable_aria', ['state' => $state]) }}"></button>
                    </div>
                    <div class="flex flex-col gap-4.5 p-4">
                        <div>
                            <span id="fc-theme-l" class="{{ $lbl }}">{{ __('admin.fc.theme_label') }}</span>
                            <x-bs-admin::choice-cards labelledby="fc-theme-l" pick="setLayout" class="!grid-cols-[repeat(auto-fit,minmax(min(100%,11em),1fr))] !gap-3" :options="[
                                ['value' => 'combo', 'icon' => 'ri-device', 'label' => __('admin.fc.layout_c'), 'badge' => __('admin.fc.recommended'), 'sub' => __('admin.fc.layout_c_sub'), 'tag' => $isC ? '✓ '.__('admin.fc.in_use') : null, 'checked' => $isC],
                                ['value' => 'vertical', 'icon' => 'ri-apps-2', 'label' => __('admin.fc.theme_v'), 'sub' => __('admin.fc.layout_v_sub'), 'tag' => $isV ? '✓ '.__('admin.fc.in_use') : null, 'checked' => $isV],
                                ['value' => 'horizontal', 'icon' => 'ri-layout-bottom-2', 'label' => __('admin.fc.layout_h'), 'sub' => __('admin.fc.layout_h_sub'), 'tag' => $isH ? '✓ '.__('admin.fc.in_use') : null, 'checked' => $isH],
                            ]" />
                        </div>
                        @unless ($isH)
                            <div class="flex flex-col gap-2.5">
                                <div>
                                    <span id="fc-pos-l" class="{{ $lbl }}">{{ __('admin.fc.pos_label') }}</span>
                                    <div class="seg" role="group" aria-labelledby="fc-pos-l">
                                        @foreach (['right' => 'ri-align-right', 'left' => 'ri-align-left', 'bottom' => 'ri-align-bottom'] as $p => $icon)
                                            <button type="button" aria-pressed="{{ $position === $p ? 'true' : 'false' }}" wire:click="setPosition({{ Js::from($p) }})" class="inline-flex items-center justify-center gap-1.5">
                                                <x-bs-admin::icon :name="$icon" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.fc.pos_'.$p) }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                                <p class="text-caption leading-snug text-text-muted">@if ($isV){{ $dial ? __('admin.fc.dial_note_on', ['n' => FloatingContact::DIAL_FROM - 1]) : __('admin.fc.dial_note_off') }} @endif{{ __('admin.fc.offset_default', ['x' => FloatingContact::DEFAULT_OFFSET_X, 'y' => FloatingContact::DEFAULT_OFFSET_Y]) }}@if ($position === 'bottom') {{ __('admin.fc.pos_bottom_phone_only') }}@endif</p>
                                <details class="group" wire:ignore.self @if ($offsetX !== FloatingContact::DEFAULT_OFFSET_X || $offsetY !== FloatingContact::DEFAULT_OFFSET_Y) open @endif>
                                    <summary class="hit-area inline-flex min-h-8 cursor-pointer list-none items-center gap-1 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text [&::-webkit-details-marker]:hidden">
                                        <x-bs-admin::icon name="ri-arrow-right-s" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none" />{{ __('admin.fc.more_settings') }}
                                    </summary>
                                    <div class="flex flex-col gap-3.5 pt-2.5">
                                        <div class="grid grid-cols-1 gap-3.5 @min-[28rem]/fc:grid-cols-2">
                                            @foreach (['offsetX' => [FloatingContact::MAX_OFFSET_X, 'offset_x'], 'offsetY' => [FloatingContact::MAX_OFFSET_Y, 'offset_y']] as $prop => [$max, $key])
                                                <div>
                                                    <div class="mb-2 flex items-center justify-between">
                                                        <label for="fc-{{ $key }}" class="text-caption font-semibold text-text">{{ __('admin.fc.'.$key) }}</label>
                                                        <span class="text-caption text-text-muted">{{ $this->{$prop} }}px</span>
                                                    </div>
                                                    <input id="fc-{{ $key }}" type="range" min="0" max="{{ $max }}" step="2" wire:model.live.debounce.150ms="{{ $prop }}"
                                                           aria-valuetext="{{ __('admin.fc.offset_text', ['n' => $this->{$prop}]) }}"
                                                           class="block h-hit w-full cursor-pointer accent-primary">
                                                </div>
                                            @endforeach
                                        </div>
                                        @if ($isV)
                                            <div class="flex items-center justify-between gap-3 border-t border-border-soft pt-3">
                                                <div class="min-w-0">
                                                    <p id="fc-dial-l" class="text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text">{{ __('admin.fc.dial') }}</p>
                                                    <p class="text-caption leading-normal text-text-muted">{{ __('admin.fc.dial_sub') }}</p>
                                                </div>
                                                <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $dial ? 'true' : 'false' }}" aria-labelledby="fc-dial-l" wire:click="toggleDial"></button>
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            </div>
                        @endunless
                    </div>
                </div>

                {{-- The channels --}}
                <div class="rounded-md border border-border bg-surface shadow-card">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3.5">
                        <h2 class="{{ $title }}">{{ __('admin.fc.channels') }}</h2>
                        <span class="text-caption text-text-muted">{!! __('admin.fc.active_count', ['count' => '<b class="text-success-text">'.$filled.'</b>']) !!} · {{ __('admin.fc.reorder_hint') }}</span>
                    </div>
                    @if ($phoneWarn)
                        <div class="mx-4 mt-3.5 flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2.5 text-small text-warning-text" role="note">
                            <x-bs-admin::icon name="ri-error-warning" class="{{ $glyph }} mt-px" />
                            <span class="min-w-0">{!! __('admin.fc.phone_warn', ['what' => '<b>'.e(__('admin.fc.phone_warn_what')).'</b>', 'a' => '<code class="font-mono">0912345678</code>', 'b' => '<code class="font-mono">+84912345678</code>']) !!}</span>
                        </div>
                    @endif
                    @error('channels')
                        <p class="mx-4 mt-3.5 rounded-md bg-danger-soft px-3 py-2.5 text-small text-danger-text" role="alert">{{ $message }}</p>
                    @enderror

                    <div class="flex flex-col gap-2.5 px-4 pt-3 pb-4">
                        <div class="flex flex-col gap-2.5" wire:sort="sortChannel">
                            @foreach ($rows as $i => $c)
                                @php
                                    $key = $c['key'];
                                    $custom = $isCustom($key);
                                    $type = (string) FloatingContact::typeOf($key);
                                    $label = $chName($c);
                                    $color = $colorOf($c);
                                    $valueError = $errors->first("channels.$i.value");
                                    $hint = __('admin.fc.hint.'.($custom ? 'custom' : $key));
                                    $text = FloatingContact::CHANNELS[$key]['text'] ?? null;
                                    $swState = $c['on'] ? __('admin.fc.on') : __('admin.fc.off');
                                    // Switched on with nothing typed: the site leaves the button out until it is filled in.
                                    $unfilled = $c['on'] && trim($c['value']) === '' && $valueError === '';
                                    // The channel's own icon picture, when the draft names one of this site's library (ContactIconImages).
                                    $pic = $c['img'] !== '' ? ($icons[$c['img']] ?? null) : null;
                                @endphp
                                <div wire:key="fc-ch-{{ $key }}" wire:sort:item="{{ $key }}"
                                     @class(['flex items-start gap-2.5 rounded-lg border bg-surface p-3', 'border-border' => $c['on'], 'border-dashed border-border bg-surface-muted' => ! $c['on']])>
                                    <span wire:sort:handle class="mt-2 flex shrink-0 cursor-grab items-center text-text-faint any-pointer-coarse:hidden" title="{{ __('admin.fc.drag') }}">
                                        <x-bs-admin::icon name="ri-draggable" class="{{ $glyph }}" />
                                    </span>
                                    <div class="flex shrink-0 flex-col gap-2" wire:sort:ignore>
                                        @foreach ([[-1, 'ri-arrow-up-s', 'up', $loop->first], [1, 'ri-arrow-down-s', 'down', $loop->last]] as [$step, $icon, $dir, $edge])
                                            <button type="button" wire:click="move({{ Js::from($key) }}, {{ $step }})" @disabled($edge)
                                                    aria-label="{{ __('admin.fc.'.$dir, ['name' => $label]) }}" title="{{ __('admin.fc.'.$dir, ['name' => $label]) }}"
                                                    class="inline-flex size-6 items-center justify-center rounded text-text-muted hover:bg-surface-hover hover:text-text disabled:opacity-40 any-pointer-coarse:size-11">
                                                <x-bs-admin::icon :name="$icon" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                                            </button>
                                        @endforeach
                                    </div>
                                    {{-- The channel's icon disc opens its "Tùy chỉnh nút" group (one open at a time; "Xong" or the disc closes it). --}}
                                    <button type="button" id="fc-ico-t-{{ $key }}" wire:sort:ignore
                                            x-on:click="ico = ico === {{ Js::from($key) }} ? null : {{ Js::from($key) }}"
                                            aria-expanded="false" x-bind:aria-expanded="ico === {{ Js::from($key) }} ? 'true' : 'false'" aria-controls="fc-ico-p-{{ $key }}"
                                            aria-label="{{ __('admin.fc.ico_t_aria', ['name' => $label]) }}" title="{{ __('admin.fc.ico_t') }}"
                                            class="hit-area relative mt-px inline-flex size-[2.125rem] shrink-0 cursor-pointer items-center justify-center rounded-lg border-0 p-0 text-white" style="background: {{ $pic !== null ? 'var(--color-surface)' : $color }}">
                                        @if ($pic !== null)
                                            <img src="{{ $pic['src'] }}" alt="" width="{{ $pic['w'] }}" height="{{ $pic['h'] }}" class="absolute inset-0 size-full rounded-[inherit] object-cover" decoding="async">
                                        @elseif ($key === 'zalo')
                                            <svg viewBox="0 0 24 24" class="size-[min(1.5rem,calc(1.0625rem+var(--user-text-boost)))]" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2.5C6.2 2.5 1.5 6.3 1.5 11c0 2.6 1.4 4.9 3.7 6.5l-.9 3.4a.5.5 0 0 0 .7.6l3.9-2.1c1 .3 2 .4 3.1.4 5.8 0 10.5-3.8 10.5-8.5S17.8 2.5 12 2.5z"/><path style="fill: {{ $color }}" d="M7.7 6.6h8.6v1.9l-5.8 5.9h5.8v1.9H7.7v-1.9l5.8-5.9H7.7z"/></svg>
                                        @elseif ($text !== null)
                                            <span class="text-[0.6875rem] font-extrabold" aria-hidden="true">{{ $text }}</span>
                                        @else
                                            <svg viewBox="0 0 24 24" class="size-[min(1.5rem,calc(1.0625rem+var(--user-text-boost)))]" fill="currentColor" aria-hidden="true" focusable="false"><path d="{{ $svg($custom ? $c['icon'] : (FloatingContact::CHANNELS[$key]['icon'] ?? null)) }}"/></svg>
                                        @endif
                                        <span class="absolute -end-[5px] -bottom-[5px] inline-flex size-4 items-center justify-center rounded-full bg-surface text-text shadow-[0_0_0_1px_var(--color-border-strong)]" aria-hidden="true">
                                            <x-bs-admin::icon name="ri-pencil" class="size-2.5" />
                                        </span>
                                    </button>

                                    <div class="flex min-w-0 flex-1 flex-col gap-1.5" wire:sort:ignore>
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            @if ($custom)
                                                <input type="text" wire:model.live.blur="channels.{{ $i }}.name" maxlength="{{ FloatingContact::MAX_LABEL }}"
                                                       placeholder="{{ __('admin.fc.custom_name_ph') }}" aria-label="{{ __('admin.fc.custom_name_aria') }}"
                                                       aria-invalid="{{ $errors->has("channels.$i.name") ? 'true' : 'false' }}"
                                                       class="field field-row min-w-[min(10rem,100%)] max-w-70 flex-[1_1_10rem] rounded-md border border-border-input bg-surface px-2.5 font-semibold text-text-strong">
                                            @else
                                                <span class="{{ $name }}">{{ $label }}</span>
                                            @endif
                                            <div class="flex shrink-0 items-center gap-2">
                                                <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $c['on'] ? 'true' : 'false' }}" wire:click="toggleChannel({{ Js::from($key) }})"
                                                        aria-label="{{ __('admin.fc.sw_aria', ['name' => $label, 'state' => $swState]) }}" title="{{ __('admin.fc.sw_aria', ['name' => $label, 'state' => $swState]) }}"></button>
                                                @unless (in_array($key, FloatingContact::CORE, true))
                                                    <button type="button" wire:click="deleteChannel({{ Js::from($key) }})" aria-label="{{ __('admin.fc.delete_aria', ['name' => $label]) }}" title="{{ __('admin.fc.delete') }}"
                                                            class="inline-flex size-[1.625rem] items-center justify-center rounded-md border border-border bg-surface text-danger-text hover:bg-danger-soft any-pointer-coarse:size-11">
                                                        <x-bs-admin::icon name="ri-delete-bin" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]" />
                                                    </button>
                                                @endunless
                                            </div>
                                        </div>
                                        @error("channels.$i.name") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror

                                        {{-- "Tùy chỉnh nút": the button's colour, the words on it (hover label, in the language being
                                             edited) and its own icon picture (PNG or WebP, at most 50 KB: the browser checks the file,
                                             uploads it to the site's media library and hands its ulid to useIcon(); "Dùng mặc định" goes
                                             back to the glyph; the outcome is read out by the shared toast). Opened by the icon disc. --}}
                                        <div id="fc-ico-p-{{ $key }}" role="group" aria-label="{{ __('admin.fc.ico_t_aria', ['name' => $label]) }}" data-fc-ico="{{ $key }}"
                                             x-show="ico === {{ Js::from($key) }}" x-cloak
                                             class="mt-0.5 flex flex-col gap-2.5 rounded-[10px] border border-border bg-surface-muted px-3.5 py-3">
                                            <p class="{{ $icoH }}"><span>{{ __('admin.fc.ico_color_h') }}</span><span class="{{ $icoN }}">{{ __('admin.fc.ico_color_n') }}</span></p>
                                            @php
                                                $ownColor = $c['color'] !== '' && ! in_array($c['color'], $palette, true) ? FloatingContact::color($c['color']) : null;
                                                $defaultFill = $key === 'messenger' ? FloatingContact::MESSENGER_FILL : (FloatingContact::CHANNELS[$key]['color'] ?? FloatingContact::CUSTOM_COLOR);
                                                $ring = '0 0 0 2px var(--color-surface), 0 0 0 4px var(--color-primary-text)';
                                            @endphp
                                            <div class="flex flex-wrap items-center gap-2.5">
                                                @foreach ([['', $defaultFill, __('admin.fc.ico_sw_default')], ...array_map(fn (string $v): array => [$v, $v, __('admin.fc.ico_sw', ['color' => $v])], $palette)] as [$swValue, $fill, $swTitle])
                                                    <button type="button" wire:click="$set({{ Js::from("channels.$i.color") }}, {{ Js::from($swValue) }})" aria-pressed="{{ $c['color'] === $swValue ? 'true' : 'false' }}"
                                                            aria-label="{{ $swTitle }}" title="{{ $swTitle }}" class="{{ $swatch }}">
                                                        <span class="block size-7 rounded-full" style="background: {{ $fill }}; box-shadow: {{ $c['color'] === $swValue ? $ring : 'inset 0 0 0 1px rgb(0 0 0 / 0.15)' }}" aria-hidden="true"></span>
                                                    </button>
                                                @endforeach
                                                <label class="{{ $swatch }} focus-ring-within relative" title="{{ __('admin.fc.ico_cc') }}">
                                                    <span class="inline-flex size-7 items-center justify-center rounded-full text-white [text-shadow:0_0_3px_rgb(0_0_0/0.6)]" aria-hidden="true"
                                                          style="{{ $ownColor !== null ? 'background: '.$ownColor.'; box-shadow: '.$ring : 'background: conic-gradient(from 0deg, #f43f5e, #f59e0b, #22c55e, #06b6d4, #3b82f6, #a855f7, #f43f5e); box-shadow: inset 0 0 0 1px var(--color-border-strong)' }}">
                                                        <x-bs-admin::icon name="ri-add" class="size-3.5" />
                                                    </span>
                                                    <input type="color" value="{{ $color }}" x-on:change="$wire.set({{ Js::from("channels.$i.color") }}, $event.target.value)"
                                                           aria-label="{{ __('admin.fc.ico_cc_aria') }}" class="absolute inset-0 size-full min-h-0 min-w-0 cursor-pointer border-0 p-0 opacity-0">
                                                </label>
                                            </div>
                                            @error("channels.$i.color") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror

                                            <p class="{{ $icoH }}"><span>{{ __('admin.fc.ico_tip_h') }}</span><span class="{{ $icoN }}">{{ __('admin.fc.ico_tip_n') }}</span></p>
                                            <span class="flex w-full max-w-[26rem] items-center gap-1.5">
                                                <x-bs-admin::icon name="ri-price-tag-3" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0 text-text-muted" title="{{ __('admin.fc.tip_title') }}" />
                                                <input type="text" wire:model.live.blur="channels.{{ $i }}.tips.{{ $lang }}" wire:key="fc-tip-{{ $key }}-{{ $lang }}" maxlength="{{ FloatingContact::MAX_LABEL }}"
                                                       placeholder="{{ __('admin.fc.tip_ph', ['default' => $defaultTip($c, $lang)]) }}" aria-label="{{ __('admin.fc.tip_aria', ['name' => $label]) }}"
                                                       aria-invalid="{{ $errors->has("channels.$i.tips.$lang") ? 'true' : 'false' }}" lang="{{ $lang }}"
                                                       class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder {{ $errors->has("channels.$i.tips.$lang") ? 'border-danger-text' : 'border-border-input' }}">
                                                @if (count($locales) > 1)
                                                    <span class="inline-flex shrink-0 items-center rounded-sm bg-primary-soft px-1.5 py-px text-caption font-bold text-primary-text uppercase" title="{{ __('admin.fc.lang_chip_title') }}">{{ $lang }}</span>
                                                @endif
                                            </span>
                                            @error("channels.$i.tips.$lang") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror
                                            @error("channels.$i.tips") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror

                                            <div class="flex flex-col gap-2" x-data="{ busy: false }">
                                                <p class="{{ $icoH }}"><span>{{ __('admin.fc.ico_h') }}</span></p>
                                                <div class="flex flex-wrap items-center gap-3">
                                                    <span class="relative inline-flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full text-white shadow-[0_3px_8px_rgb(0_0_0/0.18)]" style="background: {{ $pic !== null ? 'var(--color-surface)' : $color }}" aria-hidden="true">
                                                        @if ($pic !== null)
                                                            <img src="{{ $pic['src'] }}" alt="" width="{{ $pic['w'] }}" height="{{ $pic['h'] }}" class="absolute inset-0 size-full object-cover" decoding="async">
                                                        @elseif ($key === 'zalo')
                                                            <svg viewBox="0 0 24 24" class="size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))]" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2.5C6.2 2.5 1.5 6.3 1.5 11c0 2.6 1.4 4.9 3.7 6.5l-.9 3.4a.5.5 0 0 0 .7.6l3.9-2.1c1 .3 2 .4 3.1.4 5.8 0 10.5-3.8 10.5-8.5S17.8 2.5 12 2.5z"/><path style="fill: {{ $color }}" d="M7.7 6.6h8.6v1.9l-5.8 5.9h5.8v1.9H7.7v-1.9l5.8-5.9H7.7z"/></svg>
                                                        @elseif ($text !== null)
                                                            <span class="text-caption font-extrabold">{{ $text }}</span>
                                                        @else
                                                            <svg viewBox="0 0 24 24" class="size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))]" fill="currentColor" focusable="false"><path d="{{ $svg($custom ? $c['icon'] : (FloatingContact::CHANNELS[$key]['icon'] ?? null)) }}"/></svg>
                                                        @endif
                                                    </span>
                                                    <div class="flex flex-wrap gap-2">
                                                        <label class="hit-area focus-ring-within relative inline-flex min-h-control-row cursor-pointer items-center gap-1.5 rounded-[7px] border border-border-input bg-surface px-3 text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-text-strong hover:border-primary-text"
                                                               x-bind:aria-busy="busy ? 'true' : 'false'">
                                                            <x-bs-admin::icon name="ri-upload-2" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0" />
                                                            <span x-show="! busy">{{ $pic !== null ? __('admin.fc.ico_change') : __('admin.fc.ico_upload') }}</span>
                                                            <span x-show="busy" x-cloak>{{ __('admin.fc.ico_busy') }}</span>
                                                            <input type="file" accept="image/png,image/webp" aria-label="{{ __('admin.fc.ico_upload_aria', ['name' => $label]) }}" x-bind:disabled="busy"
                                                                   class="absolute inset-0 size-full min-h-0 min-w-0 cursor-pointer opacity-0"
                                                                   x-on:change="window.bsFloatingContact.pickIcon($event, $wire, {{ Js::from($key) }}, {{ Js::from(route('admin.media.upload')) }}, $data)">
                                                        </label>
                                                        @if ($pic !== null)
                                                            <button type="button" wire:click="clearIcon({{ Js::from($key) }})" aria-label="{{ __('admin.fc.ico_default_aria', ['name' => $label]) }}"
                                                                    class="hit-area inline-flex min-h-control-row items-center gap-1.5 rounded-[7px] border border-transparent px-3 text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-text hover:bg-primary-soft">
                                                                <x-bs-admin::icon name="ri-arrow-go-back" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.fc.ico_default') }}
                                                            </button>
                                                        @endif
                                                    </div>
                                                </div>
                                                <p class="{{ $icoN }}">{{ __('admin.fc.ico_note') }}</p>
                                                @error("channels.$i.img") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror
                                            </div>
                                            <div class="flex justify-end">
                                                <x-bs-admin::button size="sm" x-on:click="ico = null; $nextTick(() => document.getElementById('fc-ico-t-{{ $key }}')?.focus())">{{ __('admin.fc.ico_done') }}</x-bs-admin::button>
                                            </div>
                                        </div>

                                        @if ($c['on'])
                                            @if ($custom)
                                                <div class="grid grid-cols-[repeat(auto-fill,minmax(2.25rem,1fr))] gap-2 any-pointer-coarse:grid-cols-[repeat(auto-fill,minmax(2.75rem,1fr))]" role="group" aria-label="{{ __('admin.fc.pick_icon_group', ['name' => $label]) }}">
                                                    @foreach (ContactIcons::CUSTOM as $ic)
                                                        <button type="button" wire:click="pickIcon({{ Js::from($key) }}, {{ Js::from($ic) }})" aria-pressed="{{ $c['icon'] === $ic ? 'true' : 'false' }}"
                                                                aria-label="{{ __('admin.fc.pick_icon', ['icon' => str_replace(['ri-', '-line', '-'], ['', '', ' '], $ic)]) }}" title="{{ __('admin.fc.pick_icon', ['icon' => str_replace(['ri-', '-line', '-'], ['', '', ' '], $ic)]) }}"
                                                                @class(['inline-flex aspect-square w-full items-center justify-center rounded-md border', 'border-primary-text bg-primary-soft text-primary-text' => $c['icon'] === $ic, 'border-border bg-surface text-text-muted hover:bg-surface-hover' => $c['icon'] !== $ic])>
                                                            <svg viewBox="0 0 24 24" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))]" fill="currentColor" aria-hidden="true" focusable="false"><path d="{{ $svg($ic) }}"/></svg>
                                                        </button>
                                                    @endforeach
                                                </div>
                                                <details class="group" wire:ignore.self @if ($errors->has("channels.$i.icon") || ! in_array($c['icon'], ContactIcons::CUSTOM, true)) open @endif>
                                                    <summary class="hit-area inline-flex min-h-8 cursor-pointer list-none items-center gap-1 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text [&::-webkit-details-marker]:hidden">
                                                        <x-bs-admin::icon name="ri-arrow-right-s" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none" />{{ __('admin.fc.icon_more') }}
                                                    </summary>
                                                    <div class="pt-2">
                                                        <input type="text" wire:model.live.blur="channels.{{ $i }}.icon" placeholder="{{ __('admin.fc.icon_code_ph') }}" aria-label="{{ __('admin.fc.icon_code_aria', ['name' => $label]) }}"
                                                               aria-invalid="{{ $errors->has("channels.$i.icon") ? 'true' : 'false' }}" spellcheck="false" autocomplete="off"
                                                               class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder font-mono {{ $errors->has("channels.$i.icon") ? 'border-danger-text' : 'border-border-input' }}">
                                                    </div>
                                                </details>
                                                @error("channels.$i.icon") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror
                                            @endif
                                            <div class="flex flex-wrap items-start gap-2">
                                                <div class="min-w-[min(10rem,100%)] flex-[1.35_1_10rem]">
                                                    <input type="text" wire:model.live.blur="channels.{{ $i }}.value" maxlength="{{ FloatingContact::MAX_VALUE }}"
                                                           placeholder="{{ $custom ? 'https://…  ·  tel:  ·  mailto:' : ($examples[$key] ?? '') }}"
                                                           aria-label="{{ $label }} — {{ $hint }}" aria-invalid="{{ $valueError !== '' ? 'true' : 'false' }}" aria-describedby="fc-hint-{{ $key }}{{ $unfilled ? ' fc-empty-'.$key : '' }}"
                                                           inputmode="{{ in_array($type, ['phone', 'sms', 'whatsapp', 'viber'], true) ? 'tel' : ($type === 'email' ? 'email' : ($type === 'url' ? 'url' : 'text')) }}" autocomplete="off" spellcheck="false"
                                                           class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder {{ $valueError !== '' ? 'border-danger-text' : 'border-border-input' }}">
                                                    <p id="fc-hint-{{ $key }}" @class(['mt-1 text-caption leading-snug', 'text-danger-text' => $valueError !== '', 'text-text-muted' => $valueError === ''])>{{ $valueError !== '' ? $valueError : $hint }}</p>
                                                    @if ($unfilled)
                                                        {{-- The grey text in the field is only an example: say plainly that nothing is filled in yet. --}}
                                                        <p id="fc-empty-{{ $key }}" class="mt-0.5 text-caption leading-snug font-semibold text-warning-text">{{ __('admin.fc.empty_value') }}</p>
                                                    @endif
                                                    @if ($reuse !== null && in_array($key, \App\Modules\Admin\Livewire\FloatingContactSettings::REUSE, true) && trim($c['value']) === '')
                                                        <button type="button" wire:click="reuseNumber({{ Js::from($key) }})" class="hit-area mt-1.5 inline-flex min-h-[1.875rem] items-center gap-1.5 rounded-[7px] border border-dashed border-border-input bg-transparent px-2.5 text-caption font-semibold text-primary-text">
                                                            <x-bs-admin::icon name="ri-file-copy" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.fc.reuse', ['number' => $reuse]) }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                        {{-- What blocks the save but has no field on screen (a switched-off channel's folded
                                             fields, a hover label in another language) still shows, named in plain words; and,
                                             while the "Tùy chỉnh nút" group is closed, what is wrong inside it. --}}
                                        @foreach ($hiddenErrors($i, $c['on']) as $field => $message)
                                            <p class="text-caption text-danger-text">{{ $c['on'] ? '' : $label.' · ' }}{{ $fieldName($field) }}: {{ $message }}</p>
                                        @endforeach
                                        @foreach ($groupErrors($i) as [$field, $message])
                                            <p class="text-caption text-danger-text" x-show="ico !== {{ Js::from($key) }}">{{ $c['on'] ? '' : $label.' · ' }}{{ $fieldName($field) }}: {{ $message }}</p>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ($presetMenu)
                            <div class="grid grid-cols-1 gap-2 rounded-lg border border-border bg-surface-muted p-2.5 @min-[24rem]/fc:grid-cols-2">
                                @foreach ($hasPresets as $k)
                                    <button type="button" wire:click="addPreset({{ Js::from($k) }})" @disabled($full)
                                            class="flex min-h-control items-center gap-2 rounded-lg border border-border bg-surface px-2.5 py-2 text-start text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text hover:bg-surface-hover any-pointer-coarse:min-h-hit">
                                        <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-md text-white" style="background: {{ \App\Modules\Theme\Theming\ReadableColor::on(FloatingContact::CHANNELS[$k]['color'], '#ffffff', 3.0) ?? FloatingContact::CHANNELS[$k]['color'] }}">
                                            <svg viewBox="0 0 24 24" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]" fill="currentColor" aria-hidden="true" focusable="false"><path d="{{ $svg(FloatingContact::CHANNELS[$k]['icon']) }}"/></svg>
                                        </span>{{ __('admin.fc.ch.'.$k) }}
                                    </button>
                                @endforeach
                                @if ($hasPresets === [])
                                    <p class="col-span-full p-2 text-center text-caption text-text-muted">{{ __('admin.fc.presets_empty') }}</p>
                                @endif
                            </div>
                        @endif

                        <div class="mt-2 border-t border-dashed border-border-strong pt-3.5">
                            <p class="mb-2.5 flex items-center gap-1.5 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text">
                                <x-bs-admin::icon name="ri-add-circle" class="{{ $glyph }} text-primary-text" />{{ __('admin.fc.add_title') }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="togglePresetMenu" aria-expanded="{{ $presetMenu ? 'true' : 'false' }}"
                                        class="flex min-w-[min(12rem,100%)] flex-1 flex-col items-center gap-1 rounded-lg border-[1.5px] border-dashed border-border-strong bg-surface-muted px-2 py-3 text-primary-text hover:bg-primary-soft">
                                    <span class="flex items-center gap-1.5 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold"><x-bs-admin::icon name="ri-apps-2" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />{{ __('admin.fc.add_preset') }}</span>
                                    <span class="text-center text-caption text-text-muted">{{ __('admin.fc.add_preset_sub') }}</span>
                                </button>
                                <button type="button" wire:click="addCustom" @disabled($full)
                                        class="flex min-w-[min(12rem,100%)] flex-1 flex-col items-center gap-1 rounded-lg border-[1.5px] border-dashed border-border-strong bg-surface-muted px-2 py-3 text-primary-text hover:bg-primary-soft disabled:opacity-60">
                                    <span class="flex items-center gap-1.5 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold"><x-bs-admin::icon name="ri-add" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />{{ __('admin.fc.add_custom') }}</span>
                                    <span class="text-center text-caption text-text-muted">{{ __('admin.fc.add_custom_sub') }}</span>
                                </button>
                            </div>
                            @if ($full)
                                <p class="mt-2 text-caption text-text-muted">{{ __('admin.fc.full', ['max' => FloatingContact::MAX_CHANNELS]) }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Bottom-bar options --}}
                @unless ($isV)
                    <div class="rounded-md border border-border bg-surface shadow-card">
                        <h2 class="border-b border-border px-4 py-3.5 {{ $title }}">{{ __('admin.fc.bar_card') }}@if ($isC)<span class="font-normal text-text-muted"> · {{ __('admin.fc.on_phone') }}</span>@endif</h2>
                        <div class="flex flex-col gap-4 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p id="fc-buy-l" class="{{ $name }}">{{ __('admin.fc.buy') }}</p>
                                    <p class="text-caption leading-normal text-text-muted">{{ __('admin.fc.buy_sub') }}</p>
                                    @unless ($hasShop)
                                        <p id="fc-buy-n" class="mt-1 flex items-start gap-1.5 text-caption leading-normal text-warning-text">
                                            <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.fc.buy_no_shop') }}
                                        </p>
                                    @endunless
                                </div>
                                <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $buyOn ? 'true' : 'false' }}" aria-labelledby="fc-buy-l" @unless ($hasShop) aria-describedby="fc-buy-n" @endunless wire:click="toggleBuy"></button>
                            </div>
                            @if ($buyOn)
                                <div class="border-t border-border-soft pt-3.5">
                                    <p id="fc-buy-c" class="mb-2 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text">{{ __('admin.fc.buy_color') }} <span class="font-normal text-text-muted">{{ __('admin.fc.buy_color_sub') }}</span></p>
                                    @php $customBuy = ! in_array($buyColor, \App\Modules\Admin\Livewire\FloatingContactSettings::BUY_SWATCHES, true); @endphp
                                    <div class="flex flex-wrap items-center gap-2" role="group" aria-labelledby="fc-buy-c">
                                        @foreach (\App\Modules\Admin\Livewire\FloatingContactSettings::BUY_SWATCHES as $sw)
                                            <button type="button" wire:click="setBuyColor({{ Js::from($sw) }})" aria-pressed="{{ $buyColor === $sw ? 'true' : 'false' }}"
                                                    aria-label="{{ $sw === 'brand' ? __('admin.fc.swatch_brand') : __('admin.fc.swatch', ['color' => $sw]) }}" title="{{ $sw === 'brand' ? __('admin.fc.swatch_brand') : $sw }}"
                                                    @class(['size-[1.625rem] rounded-[7px] any-pointer-coarse:size-11', 'shadow-[0_0_0_2px_var(--color-surface),0_0_0_3px_var(--color-primary-text)]' => $buyColor === $sw, 'shadow-[inset_0_0_0_1px_rgb(0_0_0/0.18)]' => $buyColor !== $sw])
                                                    style="background: {{ $sw === 'brand' ? 'var(--color-primary)' : $sw }}"></button>
                                        @endforeach
                                        <label title="{{ __('admin.fc.buy_custom') }}" @class(['relative inline-flex size-[1.625rem] cursor-pointer items-center justify-center overflow-hidden rounded-[7px] focus-ring-within any-pointer-coarse:size-11',
                                                'shadow-[0_0_0_2px_var(--color-surface),0_0_0_3px_var(--color-primary-text)]' => $customBuy, 'bg-[conic-gradient(#f06548,#f7b84b,#0ab39c,#299cdb,#6559cc,#f06548)]' => ! $customBuy])
                                               @if ($customBuy) style="background: {{ $buySafe }}" @endif>
                                            <x-bs-admin::icon name="ri-add" class="pointer-events-none size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] text-white drop-shadow-[0_0_3px_rgb(0_0_0/0.6)]" />
                                            <input type="color" value="{{ $customBuy ? $buySafe : FloatingContact::BUY_COLOR }}" x-on:change="$wire.setBuyColor($event.target.value)" aria-label="{{ __('admin.fc.buy_custom') }}"
                                                   class="absolute inset-0 size-full cursor-pointer border-0 opacity-0">
                                        </label>
                                    </div>
                                    <p class="mt-2 text-caption leading-normal text-text-muted">{{ __('admin.fc.buy_contrast') }}</p>
                                </div>
                            @endif
                            <p class="flex items-start gap-2 text-caption leading-normal text-text-muted">
                                <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0 text-info-text" />{{ __('admin.fc.bar_note_v2', ['n' => FloatingContact::BAR_CAP]) }}
                            </p>
                            <details class="group border-t border-border-soft pt-3" wire:ignore.self>
                                <summary class="hit-area inline-flex min-h-8 cursor-pointer list-none items-center gap-1 text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text [&::-webkit-details-marker]:hidden">
                                    <x-bs-admin::icon name="ri-arrow-right-s" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 transition-transform group-open:rotate-90 motion-reduce:transition-none" />{{ __('admin.fc.tone') }} · <span class="font-medium text-text-muted">{{ __('admin.fc.tone_'.$barTone) }}</span>
                                </summary>
                                <div class="flex flex-col gap-2 pt-2.5">
                                    <span id="fc-tone-l" class="sr-only">{{ __('admin.fc.tone') }}</span>
                                    <div class="seg" role="group" aria-labelledby="fc-tone-l">
                                        @foreach (FloatingContact::BAR_TONES as $tone)
                                            <button type="button" aria-pressed="{{ $barTone === $tone ? 'true' : 'false' }}" wire:click="setBarTone({{ Js::from($tone) }})">{{ __('admin.fc.tone_'.$tone) }}</button>
                                        @endforeach
                                    </div>
                                    <p class="text-caption leading-normal text-text-muted">{{ __('admin.fc.tone_note') }}</p>
                                </div>
                            </details>
                        </div>
                    </div>
                @endunless
            </div>

            {{-- Preview and the "applies everywhere" note --}}
            <div class="flex min-w-0 flex-col gap-4 @min-[56rem]/fc:sticky @min-[56rem]/fc:top-[calc(var(--topbar-height)+1.375rem)]">
                {{-- The website's own widget in a frame (fc-preview-frame): a phone is 390px wide, a computer 768px
                     (where the site switches to its computer look), drawn smaller to fit the card. The bottom bar
                     only exists on phones: its preview is always the phone, with no device switch. The frame's
                     size, the device and the page background are this card's own (wire:ignore), so a redraw of
                     the page only swaps the frame's page. The frame runs no script (sandbox without
                     allow-scripts); this card drives it on each load: every link keeps its address only as
                     data-href (href="#"), so no click, middle click or "open in a new tab" leaves the page;
                     "Chép số" shows its "copied" word for about two seconds; the dial or "Thêm" sheet left
                     open is opened again after the frame is redrawn, only when the new one still shows a
                     channel (bsFloatingContact.reopenable: a sheet whose cells all fit on the bar is empty). --}}
                <div class="rounded-md border border-border bg-surface shadow-card"
                     x-data="{
                         pick: 'desktop', dark: false, k: 1, h: 600,
                         get dev() { return $wire.layout === 'horizontal' ? 'mobile' : this.pick },
                         get vw() { return this.dev === 'mobile' ? 390 : 768 },
                         fit() { const w = this.$refs.box ? this.$refs.box.clientWidth : 0; this.k = w > 0 ? Math.min(1, w / this.vw) : 1; this.measure(); },
                         measure() {
                             const d = this.$refs.frame ? this.$refs.frame.contentDocument : null, n = d ? d.querySelector('.sf-fc__float') : null;
                             const base = this.dev === 'mobile' ? 600 : 480;
                             this.h = n ? Math.max(base, Math.ceil(n.getBoundingClientRect().height) + 220) : base;
                         },
                         paint() { const d = this.$refs.frame ? this.$refs.frame.contentDocument : null; if (d && d.documentElement) { d.documentElement.dataset.page = this.dark ? 'dark' : 'light'; } },
                         open: null,
                         drive() {
                             const d = this.$refs.frame ? this.$refs.frame.contentDocument : null;
                             if (! d || ! d.body || d.body.dataset.fcDriven) { return; }
                             d.body.dataset.fcDriven = '1';
                             d.querySelectorAll('a[href]').forEach((a) => { a.dataset.href = a.getAttribute('href'); a.setAttribute('href', '#'); });
                             const stay = (e) => { if (e.target.closest && e.target.closest('a')) { e.preventDefault(); } };
                             d.addEventListener('click', (e) => { stay(e); this.copied(e); });
                             d.addEventListener('auxclick', stay);
                             d.addEventListener('toggle', (e) => { if (e.target.id && this.$refs.frame && this.$refs.frame.contentDocument === d) { this.open = e.newState === 'open' ? e.target.id : null; } }, true);
                             const el = this.open ? d.getElementById(this.open) : null;
                             if (el && el.showPopover && window.bsFloatingContact.reopenable(el, d.defaultView)) { try { el.showPopover(); } catch (err) { this.open = null; } } else { this.open = null; }
                         },
                         copied(e) {
                             const c = e.target.closest ? e.target.closest('[data-fc-copy]') : null;
                             if (! c) { return; }
                             const w = c.querySelector('.sf-fc__copy-t'), was = w ? w.textContent : '', sr = c.parentNode.querySelector('[role=status]');
                             if (w) { w.textContent = c.dataset.done; }
                             if (sr) { sr.textContent = c.dataset.done; }
                             setTimeout(() => { if (w) { w.textContent = was; } if (sr) { sr.textContent = ''; } }, 2000);
                         },
                     }"
                     x-init="$nextTick(() => { paint(); drive(); fit(); }); new ResizeObserver(() => fit()).observe($refs.box); $watch('dev', () => $nextTick(() => fit())); $watch('dark', () => paint())">
                    <div class="flex items-center justify-between gap-2.5 border-b border-border px-4 py-3">
                        <h2 class="{{ $name }}">{{ __('admin.fc.preview') }}@if ($isH)<span class="font-normal text-text-muted"> · {{ __('admin.fc.on_phone') }}</span>@endif</h2>
                        <div class="flex items-center gap-2" wire:ignore>
                            <div class="seg" role="group" aria-label="{{ __('admin.fc.pv_page') }}">
                                <button type="button" x-on:click="dark = false" x-bind:aria-pressed="dark ? 'false' : 'true'" aria-pressed="true" aria-label="{{ __('admin.fc.pv_light') }}" title="{{ __('admin.fc.pv_light') }}" class="inline-flex items-center justify-center">
                                    <x-bs-admin::icon name="sun" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                                </button>
                                <button type="button" x-on:click="dark = true" x-bind:aria-pressed="dark ? 'true' : 'false'" aria-pressed="false" aria-label="{{ __('admin.fc.pv_dark') }}" title="{{ __('admin.fc.pv_dark') }}" class="inline-flex items-center justify-center">
                                    <x-bs-admin::icon name="moon" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                                </button>
                            </div>
                            <div class="seg" role="group" aria-label="{{ __('admin.fc.device') }}" x-show="$wire.layout !== 'horizontal'" @if ($isH) style="display: none" @endif>
                                <button type="button" x-on:click="pick = 'desktop'" x-bind:aria-pressed="dev === 'desktop' ? 'true' : 'false'" aria-pressed="true" aria-label="{{ __('admin.fc.desk') }}" title="{{ __('admin.fc.desk') }}" class="inline-flex items-center justify-center">
                                    <x-bs-admin::icon name="ri-computer" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                                </button>
                                <button type="button" x-on:click="pick = 'mobile'" x-bind:aria-pressed="dev === 'mobile' ? 'true' : 'false'" aria-pressed="false" aria-label="{{ __('admin.fc.mob') }}" title="{{ __('admin.fc.mob') }}" class="inline-flex items-center justify-center">
                                    <x-bs-admin::icon name="ri-smartphone" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-center bg-surface-muted p-5">
                        <div class="flex w-full flex-col overflow-hidden bg-surface" wire:ignore.self
                             x-bind:class="dev === 'mobile' ? 'max-w-[20rem] rounded-[26px] border-[7px] border-text-strong shadow-[0_12px_30px_-10px_rgb(0_0_0/0.35)]' : 'rounded-[10px] shadow-[0_10px_30px_-12px_rgb(0_0_0/0.3)]'">
                            <div class="flex h-7 shrink-0 items-center gap-1.5 border-b border-border bg-surface px-3" aria-hidden="true" wire:ignore x-show="dev === 'desktop'">
                                <span class="size-2 rounded-full bg-danger-base"></span><span class="size-2 rounded-full bg-warning-base"></span><span class="size-2 rounded-full bg-success-base"></span>
                            </div>
                            <div x-ref="box" wire:ignore.self class="relative overflow-hidden bg-surface-muted" style="height: 300px"
                                 x-bind:style="'--fcp-w:' + vw + 'px;--fcp-h:' + h + 'px;--fcp-k:' + k + ';height:' + Math.round(h * k) + 'px'">
                                @if ($srcdoc !== null)
                                    <iframe x-ref="frame" srcdoc="{{ $srcdoc }}" sandbox="allow-same-origin" title="{{ __('admin.fc.preview_frame') }}" data-fc-preview
                                            x-on:load="paint(); drive(); measure()"
                                            class="absolute top-0 left-0 block border-0 bg-surface"
                                            style="width: var(--fcp-w, 768px); height: var(--fcp-h, 480px); transform: scale(var(--fcp-k, 1)); transform-origin: 0 0"></iframe>
                                @else
                                    <p class="absolute inset-x-3.5 bottom-3.5 rounded-md border border-border bg-surface px-3 py-2.5 text-caption leading-snug text-text-muted">{{ __('admin.fc.preview_empty') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- "Hiệu quả": the site's taps of the last 30 days, per channel switched on, then "Mua hàng" while
                     its switch is on in a layout with the phone bar (the board's order; opening the "Liên hệ"
                     button is not counted). The total is every counted tap, "Mua hàng" included. --}}
                @php
                    $kpiRows = array_map(fn (array $c): array => [
                        'label' => $chName($c),
                        'dot' => $c['key'] === 'messenger' && FloatingContact::color($c['color']) === null ? FloatingContact::MESSENGER_FILL : $colorOf($c),
                        'taps' => $stats['channels'][$c['key']] ?? 0,
                    ], $onChannels);
                    if ($buyOn && ($isH || $isC)) {
                        $kpiRows[] = [
                            'label' => __('admin.fc.kpi_buy'),
                            'dot' => $buyColor === 'brand' ? 'var(--color-primary)' : \App\Modules\Theme\Theming\ReadableColor::inkUnder($buySafe),
                            'taps' => $stats['channels'][\App\Modules\Storefront\Support\ContactClickCounter::BUY] ?? 0,
                        ];
                    }
                    $tapMax = max(1, ...(array_column($kpiRows, 'taps') ?: [0]));
                    $vnNumber = static fn (int $n): string => number_format($n, 0, ',', '.');
                @endphp
                <div class="rounded-md border border-border bg-surface shadow-card">
                    <div class="flex items-start justify-between gap-3 border-b border-border px-4 py-3.5">
                        <div class="min-w-0">
                            <h2 class="{{ $title }}">{{ __('admin.fc.kpi_title') }}</h2>
                            <p class="text-caption leading-normal text-text-muted">{{ __('admin.fc.kpi_sub') }}</p>
                        </div>
                        <p class="flex shrink-0 flex-col items-end leading-tight">
                            <b class="text-[calc(1.375rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $stats['total'] > 0 ? $vnNumber($stats['total']) : '—' }}</b>
                            <span class="text-small text-text-muted">{{ __('admin.fc.kpi_taps') }}</span>
                        </p>
                    </div>
                    <div class="flex flex-col gap-2.5 px-4 pt-3 pb-3.5">
                        @if ($kpiRows !== [])
                            <ul class="flex flex-col gap-[7px]" role="list">
                                @foreach ($kpiRows as $r)
                                    <li class="grid grid-cols-[0.625rem_minmax(5.5rem,auto)_1fr_2.5rem] items-center gap-[9px] text-[calc(0.78125rem+var(--user-text-boost))] text-text">
                                        <span class="size-2.5 rounded-full" style="background: {{ $r['dot'] }}" aria-hidden="true"></span>
                                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ $r['label'] }}</span>
                                        <span class="h-1.5 overflow-hidden rounded-[3px] bg-surface-muted" aria-hidden="true"><span class="block h-full rounded-[3px]" style="width: {{ $stats['total'] > 0 ? round($r['taps'] / $tapMax * 100, 1) : 0 }}%; background: {{ $r['dot'] }}"></span></span>
                                        <b class="text-end font-semibold text-text-muted">{{ $stats['total'] > 0 ? $vnNumber($r['taps']) : '—' }}</b>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($stats['total'] === 0)
                            <p class="text-caption leading-normal text-text-muted">{{ __('admin.fc.kpi_empty') }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-start gap-2.5 rounded-md border border-border bg-surface px-4 py-3.5 shadow-card">
                    <span class="inline-flex size-[2.125rem] shrink-0 items-center justify-center rounded-lg bg-success-soft text-success-text">
                        <x-bs-admin::icon name="ri-checkbox-circle" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                    </span>
                    <div class="min-w-0">
                        <p class="mb-0.5 {{ $name }}">{{ __('admin.fc.applied_title') }}</p>
                        <p class="text-caption leading-relaxed text-text-muted">{!! __('admin.fc.applied_body', ['save' => '<b>'.e(__('admin.fc.save')).'</b>']) !!}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- The page's own save bar --}}
        <div class="flex flex-wrap items-center justify-end gap-2.5 border-t border-border-strong pt-4">
            <span role="status" class="inline-flex min-h-6 items-center gap-1.5 px-2.5 text-caption leading-snug font-semibold">
                @if ($dirty)
                    <span class="inline-flex items-center gap-1.5 text-warning-text"><x-bs-admin::icon name="ri-error-warning" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))]" />{{ __('admin.fc.dirty') }}</span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-success-text"><x-bs-admin::icon name="ri-checkbox-circle" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))]" />{{ __('admin.fc.saved') }}</span>
                @endif
            </span>
            @if ($dirty)
                <x-bs-admin::button variant="outline" color="secondary" wire:click="resetChanges" wire:loading.attr="disabled">{{ __('admin.fc.discard') }}</x-bs-admin::button>
            @endif
            <x-bs-admin::button color="primary" wire:click="save" wire:loading.attr="disabled">
                <x-bs-admin::icon name="ri-save-3" :class="$glyph" /><span>{{ __('admin.fc.save') }}</span>
            </x-bs-admin::button>
        </div>
    </div>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
