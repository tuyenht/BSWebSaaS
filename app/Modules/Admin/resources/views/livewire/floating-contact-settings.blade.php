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
    $hiddenErrors = static function (int|string $i, bool $on) use ($errors, $lang): array {
        $shown = $on ? ['value', 'icon', 'color', 'name', 'tips', 'tips.'.$lang] : ['name'];
        $out = [];
        foreach ($errors->getMessages() as $field => $messages) {
            $rest = str_starts_with((string) $field, "channels.$i.") ? substr((string) $field, strlen("channels.$i.")) : null;
            if ($rest !== null && ! in_array($rest, $shown, true) && isset($messages[0])) {
                $out[$rest] = (string) $messages[0];
            }
        }

        return $out;
    };
    $fieldName = static fn (string $field): string => match (true) {
        str_starts_with($field, 'tips.') => __('admin.fc.f_tip_in', ['lang' => $nameOf(substr($field, 5))]),
        in_array($field, ['value', 'icon', 'color', 'tips'], true) => __('admin.fc.f_'.$field),
        default => __('admin.fc.f_value'),
    };

    $phone = collect($rows)->firstWhere('key', 'phone');
    $phoneWarn = ! ($phone !== null && $phone['on'] && FloatingContact::clean('phone', $phone['value']) !== null);
    $isVertical = $theme === 'vertical';
    $hasPresets = array_values(array_filter(FloatingContact::PRESETS, fn (string $k): bool => collect($rows)->firstWhere('key', $k) === null));
    $full = count($rows) >= FloatingContact::MAX_CHANNELS;

    // The preview: the draft as the site would show it in the language being edited, with the
    // "Mua hàng" button at the end of the bar when it is on and the site has a product list (then
    // two channels fit, not three) — the normalizer decides both, as on the site.
    $word = static fn (array $b): string => $b['label'] ?? (array_key_exists($b['key'], FloatingContact::CHANNELS) ? __('storefront.fc_'.$b['key'], [], $lang) : __('storefront.fc_contact', [], $lang));
    $pvButtons = $preview['buttons'] ?? [];
    // The preview phone is narrow (about 266px inside its frame): it draws the bar as a phone of that
    // width would, four cells with "Mua hàng" counted, the ones further on dropped from the end.
    $pvBuy = $preview['buy'] ?? null;
    $pvBar = array_slice($preview['bar'] ?? [], 0, $pvBuy === null ? 4 : 3);
    $buySafe = FloatingContact::color($buyColor) ?? FloatingContact::BUY_COLOR;
@endphp
<x-bs-admin::settings-shell active="floating" :hints="count($locales) > 1 ? [FirstHints::LANG_SOURCE => $srcHint] : []">
    <div class="@container/fc flex flex-col gap-4" x-data x-on:fc-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())">

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
                            <x-bs-admin::choice-cards labelledby="fc-theme-l" pick="setTheme" class="!grid-cols-[repeat(auto-fit,minmax(min(100%,11em),1fr))] !gap-3" :options="[
                                ['value' => 'vertical', 'icon' => 'ri-apps-2', 'label' => __('admin.fc.theme_v'), 'sub' => __('admin.fc.theme_v_sub'), 'tag' => $isVertical ? '✓ '.__('admin.fc.in_use') : null, 'checked' => $isVertical],
                                ['value' => 'horizontal', 'icon' => 'ri-layout-bottom-2', 'label' => __('admin.fc.theme_h'), 'sub' => __('admin.fc.theme_h_sub'), 'tag' => ! $isVertical ? '✓ '.__('admin.fc.in_use') : null, 'checked' => ! $isVertical],
                            ]" />
                        </div>
                        @if ($isVertical)
                            <div class="flex flex-col gap-4">
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
                                <p class="text-caption leading-snug text-text-muted">{{ __('admin.fc.offset_hint', ['x' => FloatingContact::DEFAULT_OFFSET_X, 'y' => FloatingContact::DEFAULT_OFFSET_Y]) }}</p>
                            </div>
                        @endif
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
                                    <label class="relative mt-px inline-flex size-[2.125rem] shrink-0 cursor-pointer items-center justify-center rounded-lg text-white focus-ring-within" style="background: {{ $color }}" title="{{ __('admin.fc.color') }}" wire:sort:ignore>
                                        @if ($text !== null)
                                            <span class="text-[0.6875rem] font-extrabold" aria-hidden="true">{{ $text }}</span>
                                        @else
                                            <svg viewBox="0 0 24 24" class="size-[min(1.5rem,calc(1.0625rem+var(--user-text-boost)))]" fill="currentColor" aria-hidden="true" focusable="false"><path d="{{ $svg($custom ? $c['icon'] : (FloatingContact::CHANNELS[$key]['icon'] ?? null)) }}"/></svg>
                                        @endif
                                        <input type="color" value="{{ $color }}" x-on:change="$wire.set({{ Js::from("channels.$i.color") }}, $event.target.value)"
                                               aria-label="{{ __('admin.fc.color_aria', ['name' => $label]) }}" class="absolute inset-0 size-full min-h-0 min-w-0 cursor-pointer border-0 p-0 opacity-0">
                                    </label>

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
                                                <input type="text" wire:model.live.blur="channels.{{ $i }}.icon" placeholder="{{ __('admin.fc.icon_code_ph') }}" aria-label="{{ __('admin.fc.icon_code_aria', ['name' => $label]) }}"
                                                       aria-invalid="{{ $errors->has("channels.$i.icon") ? 'true' : 'false' }}" spellcheck="false" autocomplete="off"
                                                       class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder font-mono {{ $errors->has("channels.$i.icon") ? 'border-danger-text' : 'border-border-input' }}">
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
                                                </div>
                                                <span class="flex min-w-[min(10rem,100%)] flex-[1_1_calc(13rem+2*var(--user-text-boost))] items-center gap-1.5">
                                                    <x-bs-admin::icon name="ri-price-tag-3" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0 text-text-muted" title="{{ __('admin.fc.tip_title') }}" />
                                                    <input type="text" wire:model.live.blur="channels.{{ $i }}.tips.{{ $lang }}" wire:key="fc-tip-{{ $key }}-{{ $lang }}" maxlength="{{ FloatingContact::MAX_LABEL }}"
                                                           placeholder="{{ __('admin.fc.tip_ph', ['default' => $defaultTip($c, $lang)]) }}" aria-label="{{ __('admin.fc.tip_aria', ['name' => $label]) }}"
                                                           aria-invalid="{{ $errors->has("channels.$i.tips.$lang") ? 'true' : 'false' }}" lang="{{ $lang }}"
                                                           class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder {{ $errors->has("channels.$i.tips.$lang") ? 'border-danger-text' : 'border-border-input' }}">
                                                    @if (count($locales) > 1)
                                                        <span class="inline-flex shrink-0 items-center rounded-sm bg-primary-soft px-1.5 py-px text-caption font-bold text-primary-text uppercase" title="{{ __('admin.fc.lang_chip_title') }}">{{ $lang }}</span>
                                                    @endif
                                                </span>
                                            </div>
                                            @error("channels.$i.tips.$lang") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror
                                            @error("channels.$i.tips") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror
                                            @error("channels.$i.color") <p class="text-caption text-danger-text">{{ $message }}</p> @enderror
                                        @endif
                                        {{-- What blocks the save but has no field on screen (a switched-off channel's folded
                                             fields, a hover label in another language) still shows, named in plain words. --}}
                                        @foreach ($hiddenErrors($i, $c['on']) as $field => $message)
                                            <p class="text-caption text-danger-text">{{ $c['on'] ? '' : $label.' · ' }}{{ $fieldName($field) }}: {{ $message }}</p>
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
                @unless ($isVertical)
                    <div class="rounded-md border border-border bg-surface shadow-card">
                        <h2 class="border-b border-border px-4 py-3.5 {{ $title }}">{{ __('admin.fc.bar_card') }}</h2>
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
                                <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0 text-info-text" />{{ __('admin.fc.bar_note') }}
                            </p>
                        </div>
                    </div>
                @endunless
            </div>

            {{-- Preview and the "applies everywhere" note --}}
            <div class="flex min-w-0 flex-col gap-4 @min-[56rem]/fc:sticky @min-[56rem]/fc:top-[calc(var(--topbar-height)+1.375rem)]">
                {{-- The bottom bar only exists on phones: its preview is always the phone, with no device switch. --}}
                <div class="rounded-md border border-border bg-surface shadow-card" x-data="{ pick: 'desktop', get dev() { return this.$wire.theme === 'horizontal' ? 'mobile' : this.pick } }">
                    <div class="flex items-center justify-between gap-2.5 border-b border-border px-4 py-3">
                        <h2 class="{{ $name }}">{{ __('admin.fc.preview') }}@unless ($isVertical)<span class="font-normal text-text-muted"> · {{ __('admin.fc.on_phone') }}</span>@endunless</h2>
                        @if ($isVertical)
                        <div class="seg" role="group" aria-label="{{ __('admin.fc.device') }}">
                            <button type="button" x-on:click="pick = 'desktop'" x-bind:aria-pressed="dev === 'desktop' ? 'true' : 'false'" aria-pressed="true" aria-label="{{ __('admin.fc.desk') }}" title="{{ __('admin.fc.desk') }}" class="inline-flex items-center justify-center">
                                <x-bs-admin::icon name="ri-computer" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                            </button>
                            <button type="button" x-on:click="pick = 'mobile'" x-bind:aria-pressed="dev === 'mobile' ? 'true' : 'false'" aria-pressed="false" aria-label="{{ __('admin.fc.mob') }}" title="{{ __('admin.fc.mob') }}" class="inline-flex items-center justify-center">
                                <x-bs-admin::icon name="ri-smartphone" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                            </button>
                        </div>
                        @endif
                    </div>
                    <div class="flex justify-center bg-surface-muted p-5" aria-hidden="true">
                        <div class="flex flex-col overflow-hidden bg-surface"
                             x-bind:class="dev === 'mobile' ? 'min-h-[25.625rem] w-[min(17.5rem,100%)] rounded-[26px] border-[7px] border-text-strong shadow-[0_12px_30px_-10px_rgb(0_0_0/0.35)]' : 'min-h-[18.125rem] w-full rounded-[10px] shadow-[0_10px_30px_-12px_rgb(0_0_0/0.3)]'"
                             @class([
                                 'min-h-[18.125rem] w-full rounded-[10px] shadow-[0_10px_30px_-12px_rgb(0_0_0/0.3)]' => $isVertical,
                                 'min-h-[25.625rem] w-[min(17.5rem,100%)] rounded-[26px] border-[7px] border-text-strong shadow-[0_12px_30px_-10px_rgb(0_0_0/0.35)]' => ! $isVertical,
                             ])>
                            <div class="flex h-7 shrink-0 items-center gap-1.5 border-b border-border bg-surface px-3">
                                <span class="size-2 rounded-full bg-danger-base"></span><span class="size-2 rounded-full bg-warning-base"></span><span class="size-2 rounded-full bg-success-base"></span>
                            </div>
                            {{-- The frame keeps its size at least and grows to hold the whole stack (every button, any text size):
                                 the page sketch lies under it, the buttons sit in the flow at the bottom, never cut off. --}}
                            <div class="relative flex flex-1 flex-col bg-surface-muted">
                                <div class="absolute inset-x-0 top-0 flex flex-col gap-2 p-4">
                                    <div class="h-[11px] w-[55%] rounded bg-border-strong"></div><div class="h-2 w-[88%] rounded bg-border"></div><div class="h-2 w-[78%] rounded bg-border"></div>
                                    <div class="mt-1 h-16 rounded-lg bg-border"></div><div class="mt-1 h-2 w-[70%] rounded bg-border"></div>
                                </div>
                                @if ($preview === null)
                                    <p class="absolute inset-x-3.5 bottom-3.5 rounded-md border border-border bg-surface px-3 py-2.5 text-caption leading-snug text-text-muted">{{ __('admin.fc.preview_empty') }}</p>
                                @elseif ($isVertical)
                                    @php
                                        // The side and offsets as the normalizer read them (a tampered draft can hold anything).
                                        $pvSide = $preview['position'];
                                        $tipSide = ['left' => 'left-full ml-2', 'bottom' => 'bottom-full mb-2 left-1/2 -translate-x-1/2', 'right' => 'right-full mr-2'][$pvSide] ?? 'right-full mr-2';
                                        // A row at the bottom wraps as on the site (1rem in from each edge).
                                        $wrap = match ($pvSide) {
                                            'bottom' => 'w-full flex-row flex-wrap justify-center px-4',
                                            'left' => 'flex-col self-start ml-[var(--ox)]',
                                            default => 'flex-col self-end mr-[var(--ox)]',
                                        };
                                    @endphp
                                    <div class="relative z-5 mt-auto mb-[var(--oy)] flex items-center pt-3 {{ $wrap }}" x-bind:class="dev === 'mobile' ? 'gap-2.5' : 'gap-3'"
                                         style="--ox: {{ $preview['offset_x'] }}px; --oy: {{ $preview['offset_y'] }}px">
                                        @foreach ($pvButtons as $b)
                                            {{-- Switched on but not filled in yet: its place, drawn unfinished (dashed, no brand fill) — the site does not show it. --}}
                                            @php $pending = $b['pending'] ?? false; @endphp
                                            <span @class(['group relative flex items-center justify-center rounded-full', 'text-white shadow-[0_4px_12px_rgb(0_0_0/0.25)]' => ! $pending, 'border-2 border-dashed border-border-input bg-surface text-text-muted' => $pending])
                                                  x-bind:class="dev === 'mobile' ? 'size-11' : 'size-[50px]'" @if ($pending) data-fc-pending="{{ $b['key'] }}" @else style="background: {{ $b['fill'] }}" @endif>
                                                @if ($b['text'] !== null)
                                                    <span class="text-caption font-extrabold tracking-[-.01em]">{{ $b['text'] }}</span>
                                                @else
                                                    <svg viewBox="0 0 24 24" fill="currentColor" x-bind:class="dev === 'mobile' ? 'size-[23px]' : 'size-[27px]'" class="size-[27px]"><path d="{{ $b['icon'] }}"/></svg>
                                                @endif
                                                <span class="pointer-events-none absolute {{ $tipSide }} hidden rounded bg-text-strong px-2 py-1 text-caption whitespace-nowrap text-surface group-hover:block">{{ $word($b) }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="absolute inset-x-0 bottom-0 flex min-h-12 border-t border-border bg-surface shadow-[0_-3px_16px_rgb(32_21_18/0.13)]">
                                        @foreach ($pvBar as $b)
                                            @php $pending = $b['pending'] ?? false; @endphp
                                            <span @class(['flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-0.5 py-[5px]', 'border-l border-border-soft' => ! $loop->first]) @if ($pending) data-fc-pending="{{ $b['key'] }}" @endif>
                                                <span @class(['flex h-[22px] min-w-[22px] shrink-0 items-center justify-center rounded-full px-[3px]', 'text-white' => ! $pending, 'border-[1.5px] border-dashed border-border-input bg-surface text-text-muted' => $pending]) @unless ($pending) style="background: {{ $b['fill'] }}" @endunless>
                                                    @if ($b['text'] !== null)
                                                        <span class="text-caption leading-none font-extrabold">{{ $b['text'] }}</span>
                                                    @else
                                                        <svg viewBox="0 0 24 24" fill="currentColor" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))]"><path d="{{ $b['icon'] }}"/></svg>
                                                    @endif
                                                </span>
                                                <span @class(['max-w-full text-center text-caption leading-[1.2] font-semibold [overflow-wrap:break-word]', 'text-text' => ! $pending, 'text-text-muted' => $pending])>{{ $word($b) }}</span>
                                            </span>
                                        @endforeach
                                        @if ($pvBuy !== null)
                                            <span @class(['flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-0.5 py-[5px]', 'border-l border-border-soft' => $pvBar !== []])>
                                                <span class="flex h-[22px] min-w-[22px] shrink-0 items-center justify-center rounded-full px-[3px]" style="background: {{ $pvBuy['fill'] }}; color: {{ $pvBuy['ink'] }}">
                                                    <svg viewBox="0 0 24 24" fill="currentColor" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))]"><path d="{{ $svg('ri-shopping-bag-fill') }}"/></svg>
                                                </span>
                                                <span class="max-w-full text-center text-caption leading-[1.2] font-semibold text-text [overflow-wrap:break-word]">{{ __('storefront.fc_buy', [], $lang) }}</span>
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
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
