@php
    use App\Modules\Admin\Livewire\SeoSettings;
    use App\Modules\Admin\Support\SeoFields;
    use App\Modules\Theme\Theming\SiteSeo;
    use Illuminate\Support\Js;

    // "Google & mạng xã hội" (design: settings section `seo`), in the frame "Thông tin
    // chung" uses: the language bar on a site with more than one language, one card per group (one
    // open at a time, the first by default) and the page's own save bar. Per-language fields carry
    // the language code; on another language than the source the shared fields are read-only and
    // marked "Chung". A field's explanation opens in place under its name with the (i) button. The
    // previews (Google, and a link shared on Facebook or Zalo) follow the fields as they are typed,
    // in the browser (bsSeoFields, the editors' preview part): no request per keystroke. In "Cho
    // Google tìm thấy", a card says whether AI bots can read the website ($aiCard, from the kept
    // check and the draft); while a check runs it asks again every few seconds, and only then.
    // The title template's "+ Tên trang" / "+ Tên website" buttons add their placeholder at the
    // end of the draft in the browser and send it like a typed and left field.
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $localeNames = (array) config('locales.labels');
    $nameOf = static fn (string $lc): string => (string) ($localeNames[$lc] ?? strtoupper($lc));
    $multi = count($locales) > 1;
    $onSource = $lang === $defaultLocale;
    $lockShared = $multi && ! $onSource;
    $limits = SeoFields::LIMITS;

    // Placeholders the title template's buttons add: [placeholder, label key, accessible-name key].
    $inserts = [['%tieu-de%', 'admin.seoset.ins.page', 'admin.seoset.ins.page_aria'], ['%ten-website%', 'admin.seoset.ins.site', 'admin.seoset.ins.site_aria']];

    $hintOf = static fn (string $key): ?string => match ($key) {
        'title' => __('admin.seoset.hint.title', ['n' => $limits['seoTitle']['display']]),
        'template' => __('admin.seoset.hint.template'),
        'description' => __('admin.seoset.hint.description', ['lo' => $limits['seoDesc']['best'][0], 'hi' => $limits['seoDesc']['best'][1]]),
        'indexable' => __('admin.seoset.hint.indexable').($platformHidden ? ' '.__('admin.seoset.hint.platform') : ''),
        'sitemap' => __('admin.seoset.hint.sitemap'),
        'robots' => __('admin.seoset.hint.robots'),
        default => null,
    };
    $caps = ['title' => SeoFields::hardCap('seoTitle'), 'template' => SeoFields::hardCap('seoTitle'), 'description' => SeoFields::hardCap('seoDesc'), 'robots' => SiteSeo::ROBOTS_MAX];
    $placeholders = ['title' => __('admin.seo.title_placeholder'), 'template' => '%tieu-de% | %ten-website%', 'description' => __('admin.seo.description_placeholder'), 'robots' => "User-agent: *\nDisallow:"];

    // A field is shown when the one it depends on is on (the design's `when`).
    $shown = static fn (string $key): bool => match ($key) {
        'sitemap' => $form['indexable'],
        default => true,
    };
    $groupKeys = array_keys(SeoSettings::GROUPS);
    $errorCount = count($errors->keys());
    $firstErrorGroup = array_key_first($groupErrors) ?? $groupKeys[0];
    $readOnly = 'bg-surface-muted text-text-muted';
    $label = 'text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong';
    $noteBox = 'col-span-full flex items-start gap-2 rounded-md border border-info-text/30 bg-info-soft px-3 py-2.5 text-caption leading-relaxed text-info-text';
    $infoBox = 'rounded-lg border border-border bg-surface px-3 py-2.5 text-caption leading-relaxed text-text [overflow-wrap:break-word]';
    $mutedCard = 'col-span-full rounded-lg border border-border bg-surface-muted';
@endphp
<x-bs-admin::settings-shell active="seo" :unsaved="$changes">
    <div class="@container/seo flex flex-col gap-4" data-set-form
         x-data="{ open: {{ Js::from($groupKeys[0]) }}, info: null }"
         x-on:seo-first-error.window="open = $event.detail.group; $nextTick(() => $el.querySelector('[aria-invalid=true]:not([disabled]), [data-ferr-focus]')?.focus())"
         x-on:keydown.escape="if (info) { const b = $el.querySelector('[aria-controls=\'' + info + '\']'); info = null; b?.focus() }">

        {{-- The language being edited (only on a site with more than one) --}}
        @if ($multi)
            <div class="flex flex-wrap items-center gap-2.5 rounded-md border border-border-strong bg-surface-muted px-2.5 py-1.5 max-md:py-1">
                <x-bs-admin::icon name="ri-translate-2" class="{{ $glyph }} text-text-muted" title="{{ __('admin.gs.lang_title') }}" />
                <div role="group" aria-label="{{ __('admin.gs.lang_title') }}" class="flex min-w-0 flex-wrap items-center gap-1.5 p-px">
                    @foreach ($locales as $lc)
                        <button type="button" wire:click="setLang({{ Js::from($lc) }})" aria-pressed="{{ $lc === $lang ? 'true' : 'false' }}" lang="{{ $lc }}"
                                @class(['hit-area-row inline-flex min-h-control-row items-center gap-1.5 rounded-md border px-2.5 text-small font-medium',
                                    'border-primary-text bg-surface text-primary-text shadow-[inset_0_0_0_1px_var(--color-primary-text)]' => $lc === $lang,
                                    'border-border-input bg-surface text-text hover:bg-surface-hover' => $lc !== $lang])>
                            {{ $nameOf($lc) }}
                            @if ($lc === $defaultLocale)
                                <x-bs-admin::icon name="ri-star-fill" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0 text-warning-base" title="{{ __('admin.gs.lang_src') }}" />
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($errorCount > 0)
            <div class="flex items-start gap-2 rounded-md bg-danger-soft px-3 py-2.5 text-small text-danger-text" role="alert">
                <x-bs-admin::icon name="ri-error-warning" class="{{ $glyph }} mt-px" />
                <span class="min-w-0">{{ __('admin.gs.banner', ['count' => $errorCount]) }}</span>
            </div>
        @endif

        <div class="flex flex-col gap-3">
            @foreach (SeoSettings::GROUPS as $group => $fields)
                @php
                    $visible = array_values(array_filter($fields, fn (array $f): bool => ! in_array($f[1], ['note', 'preview', 'aibot'], true) && $shown($f[0])));
                    $bodyId = 'seo-g-'.$group;
                @endphp
                <div class="overflow-hidden rounded-[9px] border bg-surface"
                     x-bind:class="open === {{ Js::from($group) }} ? 'border-primary-text/25 shadow-card' : 'border-border'">
                    <h2>
                        <button type="button" class="flex min-h-control-row w-full items-center gap-2.5 px-4 py-3 text-start hover:bg-surface-hover"
                                x-bind:class="open === {{ Js::from($group) }} && 'bg-primary-soft/60'"
                                x-on:click="open = open === {{ Js::from($group) }} ? null : {{ Js::from($group) }}"
                                x-bind:aria-expanded="open === {{ Js::from($group) }} ? 'true' : 'false'" aria-controls="{{ $bodyId }}">
                            <span class="inline-flex shrink-0 text-text-muted" x-show="open !== {{ Js::from($group) }}"><x-bs-admin::icon name="ri-add" :class="$glyph" /></span>
                            <span class="inline-flex shrink-0 text-primary-text" x-show="open === {{ Js::from($group) }}" x-cloak><x-bs-admin::icon name="ri-subtract" :class="$glyph" /></span>
                            <span class="min-w-0 flex-1 text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.seoset.g.'.$group) }}</span>
                            @if (($groupErrors[$group] ?? 0) > 0)
                                <span class="shrink-0 rounded-full bg-danger-soft px-2 py-0.5 text-caption font-bold whitespace-nowrap text-danger-text">{{ __('admin.gs.errs', ['count' => $groupErrors[$group]]) }}</span>
                            @endif
                            <span class="shrink-0 text-caption whitespace-nowrap text-text-muted">{{ __('admin.gs.items', ['count' => count($visible)]) }}</span>
                        </button>
                    </h2>
                    <div id="{{ $bodyId }}" x-show="open === {{ Js::from($group) }}" @if ($group !== $groupKeys[0]) x-cloak @endif
                         class="grid items-start gap-x-5 gap-y-4 border-t border-border px-4 pt-4 pb-[18px] @min-[45rem]/seo:grid-cols-2">
                        @foreach ($fields as [$key, $kind, $perLang])
                            @continue(! $shown($key))
                            @php
                                $bag = $perLang ? "i18n.{$lang}.{$key}" : "form.{$key}";
                                $fid = 'seo-'.$key;
                                $hint = $hintOf($key);
                                $infoId = $fid.'-info';
                                $errId = $fid.'-err';
                                $bad = $errors->has($bag);
                                $locked = ! $perLang && $lockShared;
                                $describedBy = trim(($bad ? $errId : '').' '.($hint !== null ? $infoId : ''));
                                $source = $perLang && ! $onSource ? ($i18nDraft[$defaultLocale][$key] ?? '') : '';
                                $ph = $source !== '' ? $source : ($placeholders[$key] ?? '');
                            @endphp

                            @if ($kind === 'note')
                                <p class="{{ $noteBox }}" role="note">
                                    <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
                                    @if ($key === 'domain_note' && $domainsLink !== null)
                                        <span class="min-w-0">{{ __('admin.seoset.domain_note_before') }}<a href="{{ $domainsLink }}" wire:navigate class="hit-area-row font-semibold underline underline-offset-2">{{ __('admin.seoset.domain_note_link') }}</a>{{ __('admin.seoset.domain_note_after') }}</span>
                                    @else
                                        <span class="min-w-0">{{ __('admin.seoset.'.$key) }}</span>
                                    @endif
                                </p>
                                @continue
                            @endif

                            @if ($kind === 'aibot')
                                {{-- Can AI bots read the website? The words change in place (job-card's live region);
                                     "Kiểm tra lại" stays reachable while it waits, saying why (aria-disabled + the line). --}}
                                @php
                                    $limitId = $fid.'-limit';
                                    $waiting = $aiCard['busy'] || $aiCard['limited'];
                                @endphp
                                <x-bs-admin::job-card class="col-span-full" :tone="$aiCard['tone']" :icon="$aiCard['icon']" :spin="$aiCard['busy']" wire:key="seo-f-{{ $key }}" data-ai-check>
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-body leading-[1.45] font-semibold text-text-strong">
                                        <span>{{ __('admin.seoset.f.'.$key) }}</span>
                                        <span @class(['text-success-text' => $aiCard['tone'] === 'done', 'text-info-text' => $aiCard['tone'] === 'info',
                                            'text-warning-text' => $aiCard['tone'] === 'warn'])>{{ $aiCard['status'] }}</span>
                                    </div>
                                    @foreach ($aiCard['lines'] as $line)
                                        <p class="mt-0.5 text-small leading-normal text-pretty text-text-muted">{{ $line['message'] }}</p>
                                        @if ($line['fix'] !== null)
                                            <p class="mt-0.5 text-small leading-normal text-pretty text-text">{{ $line['fix'] }}</p>
                                        @endif
                                    @endforeach
                                    @if ($aiCard['at'] !== null)
                                        <p class="mt-0.5 text-small leading-normal text-text-muted">{{ $aiCard['at'] }}</p>
                                    @endif
                                    @if ($aiCard['limited'])
                                        <p id="{{ $limitId }}" class="mt-0.5 text-small leading-normal text-pretty text-text-muted">{{ $aiCard['limit'] }}</p>
                                    @endif
                                    <x-slot:actions>
                                        <x-bs-admin::button variant="outline" color="secondary" wire:click="recheck" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                                            :aria-disabled="$waiting ? 'true' : 'false'" :aria-describedby="$aiCard['limited'] ? $limitId : null">
                                            @if ($aiCard['busy'])
                                                <x-bs-admin::icon name="ri-loader-4" :class="$glyph.' animate-spin motion-reduce:animate-none'" /><span>{{ __('admin.seoset.ai.running') }}</span>
                                            @else
                                                <span wire:loading.remove wire:target="recheck" class="inline-flex items-center gap-2"><x-bs-admin::icon name="ri-refresh" :class="$glyph" /><span>{{ __('admin.seoset.ai.recheck') }}</span></span>
                                                <span wire:loading.inline-flex wire:target="recheck" class="items-center gap-2"><x-bs-admin::icon name="ri-loader-4" :class="$glyph.' animate-spin motion-reduce:animate-none'" /><span>{{ __('admin.seoset.ai.running') }}</span></span>
                                            @endif
                                        </x-bs-admin::button>
                                    </x-slot:actions>
                                </x-bs-admin::job-card>
                                {{-- Asks again while the check runs; once the minute is over, once more so the button is free again. --}}
                                @if ($aiCard['busy'])
                                    <span hidden wire:key="seo-ai-poll" wire:poll.3s></span>
                                @elseif ($aiCard['limited'])
                                    <span hidden wire:key="seo-ai-free-{{ $aiCard['unlockIn'] }}" wire:poll.{{ $aiCard['unlockIn'] + 1 }}s></span>
                                @endif
                                @continue
                            @endif

                            @if ($kind === 'preview')
                                {{-- The previews: Google, and the card a shared link shows on Facebook / Zalo. --}}
                                <div class="col-span-full grid gap-3 @min-[34rem]/seo:grid-cols-2" wire:key="seo-pv-{{ $lang }}" x-data="bsSeoFields({{ Js::from($previewConfig) }})">
                                    <x-bs-admin::seo-preview class="min-w-0" :address="$home" :locale="$lang" :heading="__('admin.seoset.preview_google')" />
                                    <div class="min-w-0">
                                        <h3 class="mb-2 text-caption font-semibold tracking-wide text-text-muted uppercase">{{ __('admin.seoset.preview_share') }}</h3>
                                        <div role="group" aria-label="{{ __('admin.seoset.preview_share') }}" class="overflow-hidden rounded-md border border-border bg-surface" lang="{{ $lang }}">
                                            <div class="flex aspect-[1200/630] items-center justify-center bg-surface-hover p-2 text-center text-small text-text-muted">
                                                @if ($shareImage !== '')
                                                    <img src="{{ $shareImage }}" alt="{{ __('admin.seoset.share_image_alt') }}" class="size-full object-cover" loading="lazy" decoding="async">
                                                @else
                                                    <span>{{ __('admin.seoset.share_no_image') }}</span>
                                                @endif
                                            </div>
                                            <div class="border-t border-border bg-surface-muted px-3 pt-2 pb-2.5">
                                                <div class="text-caption text-text-muted uppercase [overflow-wrap:anywhere]">{{ $host }}</div>
                                                <div class="text-body font-semibold text-text-strong [overflow-wrap:anywhere]" x-text="previewTitle.join('')"></div>
                                                <div class="text-small text-text-muted [overflow-wrap:anywhere]" x-text="previewDescription.join('')"></div>
                                            </div>
                                        </div>
                                        @if ($shareImage !== '' && $shareSaved)
                                            <p class="mt-1.5 text-caption text-text-muted">{{ __('admin.seoset.share_image_from_site') }}</p>
                                        @endif
                                    </div>
                                </div>
                                @continue
                            @endif

                            @if ($kind === 'toggle')
                                <div class="col-span-full flex items-start justify-between gap-4" wire:key="seo-f-{{ $key }}">
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <span id="{{ $fid }}-l" class="{{ $label }}">{{ __('admin.seoset.f.'.$key) }}</span>
                                        @if ($hint !== null)
                                            <button type="button" class="hit-area-row inline-flex items-center gap-1 self-start text-caption text-text-muted hover:text-primary-text"
                                                    x-on:click="info = info === '{{ $infoId }}' ? null : '{{ $infoId }}'" x-bind:aria-expanded="info === '{{ $infoId }}' ? 'true' : 'false'"
                                                    aria-controls="{{ $infoId }}" aria-label="{{ __('admin.gs.detail_aria', ['label' => __('admin.seoset.f.'.$key)]) }}">
                                                <x-bs-admin::icon name="ri-information" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]" />{{ __('admin.gs.detail') }}
                                            </button>
                                            <div id="{{ $infoId }}" role="note" x-show="info === '{{ $infoId }}'" x-cloak class="{{ $infoBox }}">{{ $hint }}</div>
                                        @endif
                                    </div>
                                    <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $form[$key] ? 'true' : 'false' }}" aria-labelledby="{{ $fid }}-l"
                                            @if ($locked) aria-disabled="true" disabled @else wire:click="{{ $key === 'indexable' ? 'toggleIndexable' : 'toggleSitemap' }}" @endif></button>
                                </div>
                                @continue
                            @endif

                            @if ($kind === 'image')
                                {{-- The share image: one picture from the Image library ("Đổi ảnh" / the empty box open it), kept in the
                                     draft until saved; "Gỡ ảnh" empties the slot and the logo is used. Read-only on another language. --}}
                                @php($imgBad = $errors->has('share_image'))
                                <div class="col-span-full flex min-w-0 flex-col gap-1.5" wire:key="seo-f-{{ $key }}" data-share-image>
                                    <span id="{{ $fid }}-l" class="flex flex-wrap items-center gap-x-[7px] gap-y-1 {{ $label }}">
                                        {{ __('admin.seoset.f.'.$key) }}
                                        @if ($locked)
                                            <span class="inline-flex shrink-0 items-center gap-1 rounded-sm bg-surface-muted px-1.5 py-px text-caption font-bold text-text-muted" title="{{ __('admin.gs.shared_title') }}">
                                                <x-bs-admin::icon name="ri-lock" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))]" />{{ __('admin.gs.shared') }}
                                            </span>
                                        @endif
                                    </span>
                                    @if ($shareSlot !== null)
                                        <div role="group" aria-labelledby="{{ $fid }}-l" class="flex flex-wrap items-center gap-3.5 rounded-lg border bg-surface p-3 {{ $imgBad ? 'border-danger-text' : 'border-border' }}"
                                             @if ($imgBad && ($locked || ! $canPick)) tabindex="-1" data-ferr-focus aria-describedby="{{ $errId }}" @endif>
                                            <img src="{{ $shareSlot['thumb'] }}" alt="" class="size-[58px] shrink-0 rounded-md border border-border object-cover" loading="lazy" decoding="async">
                                            <div class="flex min-w-[8rem] flex-1 flex-col gap-0.5">
                                                <span class="text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong [overflow-wrap:anywhere]">{{ $shareSlot['name'] }}</span>
                                                <span class="text-caption text-text-muted">{{ $shareSlot['meta'] }}</span>
                                            </div>
                                            @unless ($locked)
                                                <div class="flex shrink-0 items-center gap-[7px]">
                                                    @if ($canPick)
                                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="openImagePicker" data-share-change
                                                                            :data-ferr-focus="$imgBad" :aria-describedby="trim(($imgBad ? $errId.' ' : '').$fid.'-hint')">{{ __('admin.seoset.share_change') }}</x-bs-admin::button>
                                                    @endif
                                                    <x-bs-admin::tooltip :text="__('admin.seoset.share_remove')">
                                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" icon wire:click="removeShareImage" :aria-label="__('admin.seoset.share_remove')">
                                                            <x-bs-admin::icon name="ri-delete-bin-6" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]" />
                                                        </x-bs-admin::button>
                                                    </x-bs-admin::tooltip>
                                                </div>
                                            @endunless
                                        </div>
                                    @elseif ($canPick && ! $locked)
                                        <button type="button" wire:click="openImagePicker" data-share-change aria-labelledby="{{ $fid }}-l {{ $fid }}-pick"
                                                @if ($imgBad) data-ferr-focus @endif aria-describedby="{{ trim(($imgBad ? $errId.' ' : '').$fid.'-hint') }}"
                                                class="flex min-h-control w-full items-center gap-3.5 rounded-lg border border-dashed bg-surface p-4 text-start hover:border-primary-text {{ $imgBad ? 'border-danger-text' : 'border-border-strong' }}">
                                            <x-bs-admin::icon name="ri-image-add" class="size-6 shrink-0 text-text-muted" />
                                            <span id="{{ $fid }}-pick" class="min-w-0 text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('admin.seoset.share_pick') }}</span>
                                        </button>
                                    @else
                                        <div class="flex items-center gap-3.5 rounded-lg border border-dashed border-border-strong bg-surface-muted p-4"
                                             @if ($imgBad) tabindex="-1" data-ferr-focus aria-describedby="{{ $errId }}" @endif>
                                            <x-bs-admin::icon name="ri-image-add" class="size-6 shrink-0 text-text-muted" />
                                            <span class="min-w-0 text-[calc(0.78125rem+var(--user-text-boost))] text-text-muted">{{ __('admin.seoset.share_none') }}</span>
                                        </div>
                                    @endif
                                    <span id="{{ $fid }}-hint" class="text-caption leading-normal text-text-muted">{{ __('admin.seoset.hint.'.$key) }}</span>
                                    @error('share_image')
                                        <span id="{{ $errId }}" data-ferr class="inline-flex items-start gap-[5px] text-caption text-danger-text">
                                            <x-bs-admin::icon name="ri-error-warning" class="mt-px size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" /><span class="min-w-0">{{ $message }}</span>
                                        </span>
                                    @enderror
                                </div>
                                @continue
                            @endif

                            <div @class(['flex min-w-0 flex-col gap-1.5', 'col-span-full' => $kind !== 'text', 'max-md:gap-3.5' => $hint !== null]) wire:key="seo-f-{{ $key }}-{{ $perLang ? $lang : 'shared' }}">
                                <span class="flex min-h-control flex-wrap items-center gap-x-[7px] max-md:min-h-7 gap-y-1 {{ $label }}">
                                    <label for="{{ $fid }}" class="cursor-pointer">{{ __('admin.seoset.f.'.$key) }}</label>
                                    @if ($perLang && $multi)
                                        <span class="inline-flex shrink-0 items-center rounded-sm bg-primary-soft px-1.5 py-px text-caption font-bold text-primary-text uppercase">{{ $lang }}</span>
                                    @endif
                                    @if ($locked)
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-sm bg-surface-muted px-1.5 py-px text-caption font-bold text-text-muted" title="{{ __('admin.gs.shared_title') }}">
                                            <x-bs-admin::icon name="ri-lock" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))]" />{{ __('admin.gs.shared') }}
                                        </span>
                                    @endif
                                    @if ($hint !== null)
                                        <button type="button" class="hit-area inline-flex size-control shrink-0 items-center justify-center rounded-md text-text-muted hover:text-primary-text aria-expanded:text-primary-text max-md:size-7"
                                                x-on:click="info = info === '{{ $infoId }}' ? null : '{{ $infoId }}'" x-bind:aria-expanded="info === '{{ $infoId }}' ? 'true' : 'false'"
                                                aria-controls="{{ $infoId }}" aria-label="{{ __('admin.gs.info_aria') }}">
                                            <x-bs-admin::icon name="ri-information" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]" />
                                        </button>
                                    @endif
                                </span>
                                @if ($hint !== null)
                                    <div id="{{ $infoId }}" role="note" x-show="info === '{{ $infoId }}'" x-cloak class="{{ $infoBox }}">{{ $hint }}</div>
                                @endif

                                @if ($kind === 'text')
                                    <input id="{{ $fid }}" type="text" wire:model.live.blur="{{ $bag }}" placeholder="{{ $ph }}" maxlength="{{ $caps[$key] }}" @if ($locked) readonly @endif
                                           @if ($perLang) lang="{{ $lang }}" @endif aria-invalid="{{ $bad ? 'true' : 'false' }}" @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                                           class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder {{ $bad ? 'border-danger-text' : 'border-border-input' }} {{ $locked ? $readOnly : '' }}">
                                    @if ($key === 'template' && ! $locked)
                                        {{-- Adds the placeholder at the end, one space apart unless the text already ends in one. --}}
                                        <div class="flex flex-wrap gap-2" data-template-insert
                                             x-data="{ insert(token) { const field = document.getElementById({{ Js::from($fid) }}); const text = field.value; const next = text + (text !== '' && ! /\s$/.test(text) ? ' ' : '') + token; field.value = next; $wire.$set({{ Js::from($bag) }}, next) } }">
                                            @foreach ($inserts as [$token, $insLabel, $insAria])
                                                <x-bs-admin::button size="sm" variant="outline" color="secondary" x-on:click="insert({{ Js::from($token) }})" :aria-label="__($insAria)">{{ __($insLabel) }}</x-bs-admin::button>
                                            @endforeach
                                        </div>
                                    @endif
                                @else
                                    {{-- The description is one line on Google: Enter adds no line break (a pasted one is removed on save);
                                         an Enter confirming a word being composed is left to the input method. robots.txt keeps its lines. --}}
                                    <textarea id="{{ $fid }}" rows="{{ $kind === 'code' ? 6 : 3 }}" wire:model.live.blur="{{ $bag }}" placeholder="{{ $ph }}" maxlength="{{ $caps[$key] }}" @if ($locked) readonly @endif
                                              @if ($perLang) lang="{{ $lang }}" @endif @if ($kind === 'code') spellcheck="false" autocapitalize="off" translate="no" @endif
                                              @if ($kind === 'textarea') x-on:keydown.enter="if (! $event.isComposing && $event.keyCode !== 229) $event.preventDefault()" @endif
                                              aria-invalid="{{ $bad ? 'true' : 'false' }}" @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                                              @class(['field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder min-h-[5.5rem] resize-y py-2',
                                                  'font-mono' => $kind === 'code', 'border-danger-text' => $bad, 'border-border-input' => ! $bad, $readOnly => $locked])></textarea>
                                    @if ($key === 'robots' && ! $form['indexable'])
                                        <span class="inline-flex items-start gap-[5px] text-caption text-warning-text">
                                            <x-bs-admin::icon name="ri-eye-off" class="mt-px size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" /><span class="min-w-0">{{ __('admin.seoset.robots_hidden') }}</span>
                                        </span>
                                    @endif
                                @endif

                                @error($bag)
                                    <span id="{{ $errId }}" data-ferr class="inline-flex items-start gap-[5px] text-caption text-danger-text">
                                        <x-bs-admin::icon name="ri-error-warning" class="mt-px size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" /><span class="min-w-0">{{ $message }}</span>
                                    </span>
                                @enderror
                            </div>
                            @if ($key === 'robots' && $robotsNote !== null)
                                <p class="{{ $noteBox }}" role="note" data-robots-note>
                                    <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
                                    <span class="min-w-0">{{ $robotsNote }}</span>
                                </p>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- The page's own save bar, kept in view at the bottom --}}
        <div class="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-between gap-2.5 border-t border-border-strong bg-surface px-1 py-3" role="region" aria-label="{{ __('admin.gs.savebar') }}" data-set-savebar @if ($changes === 0) data-clean @endif>
            <div role="status" aria-live="polite" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-caption leading-snug">
                @if ($changes > 0)
                    <span class="inline-flex items-center gap-1.5 font-semibold text-warning-text"><x-bs-admin::icon name="ri-error-warning" :class="$glyph" />{{ __('admin.gs.changes', ['count' => $changes]) }}</span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-text-muted"><x-bs-admin::icon name="ri-checkbox-circle" :class="$glyph" />{{ __('admin.gs.no_changes') }}</span>
                @endif
                @if ($errorCount > 0)
                    <span class="inline-flex items-center gap-1.5 font-semibold text-danger-text"><x-bs-admin::icon name="ri-close-circle" :class="$glyph" />{{ __('admin.gs.errs', ['count' => $errorCount]) }}</span>
                    <button type="button" class="hit-area-row text-caption font-semibold text-primary-text underline underline-offset-2"
                            x-on:click="$dispatch('seo-first-error', { group: {{ Js::from($firstErrorGroup) }} })">{{ __('admin.gs.first_error') }}</button>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <x-bs-admin::button variant="outline" color="secondary" wire:click="resetChanges" wire:loading.attr="disabled" :disabled="$changes === 0">{{ __('admin.gs.discard') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" wire:click="save" wire:loading.attr="disabled">
                    <x-bs-admin::icon name="ri-save-3" :class="$glyph" /><span>{{ __('admin.gs.save') }}</span>
                </x-bs-admin::button>
            </div>
        </div>
    </div>

    {{-- The shared Image library, opened by the share image slot (one picture goes back). --}}
    @include('admin::partials.media-picker-modal', ['pickedIds' => []])

    {{-- Turning Google off asks first and names what happens. --}}
    <x-bs-admin::confirm-dialog model="confirmHide" :title="__('admin.seoset.cf_title')" icon="ri-eye-off" :ok="__('admin.seoset.cf_ok')" confirm="hideFromGoogle" cancel="cancelHide">
        <x-bs-admin::confirm-line icon="ri-search-eye">{{ __('admin.seoset.cf_l1') }}</x-bs-admin::confirm-line>
        <x-bs-admin::confirm-line icon="ri-forbid">{{ __('admin.seoset.cf_l2') }}</x-bs-admin::confirm-line>
        <x-bs-admin::confirm-line icon="ri-time">{{ __('admin.seoset.cf_l3') }}</x-bs-admin::confirm-line>
    </x-bs-admin::confirm-dialog>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
