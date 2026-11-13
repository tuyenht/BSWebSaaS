@php
    use App\Modules\Admin\Livewire\GeneralSettings;
    use App\Modules\Tenancy\Support\BusinessHours;
    use Illuminate\Support\Facades\Lang;
    use Illuminate\Support\Js;

    // "Thông tin chung" (design: settings section `general`): the language bar on a site with more
    // than one language, then one card per group (one open at a time, the first by default), each
    // with its fields in two columns on a wide page and one on a narrow one, and the page's own
    // save bar. Per-language fields carry the language code; on another language than the source
    // the shared fields are read-only and marked "Chung". A field's explanation opens in place
    // under its name with the (i) button.
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $localeNames = (array) config('locales.labels');
    $nameOf = static fn (string $lc): string => (string) ($localeNames[$lc] ?? strtoupper($lc));
    $multi = count($locales) > 1;
    $onSource = $lang === $defaultLocale;
    $lockShared = $multi && ! $onSource;

    // Examples of a value's shape, not words to translate.
    $examples = ['taxCode' => '0312345678', 'licenseNo' => '0312345678', 'licenseDate' => '14/03/2019', 'moitUrl' => 'http://online.gov.vn/Home/WebDetails/12345',
        'phone' => '0901 234 567', 'facebook' => 'https://facebook.com/tencongty', 'youtube' => 'https://youtube.com/@tencongty',
        'tiktok' => 'https://tiktok.com/@tencongty', 'zalo' => '0901 234 567'];
    $placeholder = static fn (string $key): string => $examples[$key] ?? (Lang::has('admin.gs.ph.'.$key) ? __('admin.gs.ph.'.$key) : '');

    $hintOf = static function (string $key) use ($province, $provinces): ?string {
        return match (true) {
            $key === 'wardId' => $province !== null ? __('admin.gs.hint.wardId', ['province' => $province->name]) : __('admin.gs.hint.wardFirst'),
            $key === 'provinceId' && $provinces->isEmpty() => __('admin.gs.no_catalog'),
            Lang::has('admin.gs.hint.'.$key) => __('admin.gs.hint.'.$key),
            default => null,
        };
    };

    $noHours = in_array($form['days'], ['', 'appointment'], true);
    $groupKeys = array_keys(GeneralSettings::GROUPS);
    $errorCount = count($errors->keys());
    $firstErrorGroup = 'name';
    foreach ($groupErrors as $g => $n) {
        $firstErrorGroup = $g;
        break;
    }

    $readOnly = 'bg-surface-muted text-text-muted';
@endphp
<x-bs-admin::settings-shell active="general" :unsaved="$changes">
    <div class="@container/gs flex flex-col gap-4" data-set-form
         x-data="{ open: {{ Js::from($groupKeys[0]) }}, info: null }"
         x-on:gs-first-error.window="open = $event.detail.group; $nextTick(() => $el.querySelector('[aria-invalid=true]:not([disabled])')?.focus())"
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
            <x-bs-admin::alert color="danger" icon="ri-error-warning">{{ __('admin.gs.banner', ['count' => $errorCount]) }}</x-bs-admin::alert>
        @endif

        <div class="flex flex-col gap-3">
            @foreach (GeneralSettings::GROUPS as $group => $fields)
                @php
                    $visible = array_values(array_filter($fields, fn (array $f): bool => $f[1] !== 'note' && ($f[0] !== 'moitUrl' || $form['moitOn'] === true)));
                    $bodyId = 'gs-g-'.$group;
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
                            <span class="min-w-0 flex-1 text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.gs.g.'.$group) }}</span>
                            @if (($groupErrors[$group] ?? 0) > 0)
                                <span class="shrink-0 rounded-full bg-danger-soft px-2 py-0.5 text-caption font-bold whitespace-nowrap text-danger-text">{{ __('admin.gs.errs', ['count' => $groupErrors[$group]]) }}</span>
                            @endif
                            <span class="shrink-0 text-caption whitespace-nowrap text-text-muted">{{ __('admin.gs.items', ['count' => count($visible)]) }}</span>
                        </button>
                    </h2>
                    <div id="{{ $bodyId }}" x-show="open === {{ Js::from($group) }}" @if ($group !== $groupKeys[0]) x-cloak @endif
                         class="grid items-start gap-x-5 gap-y-4 border-t border-border px-4 pt-4 pb-[18px] @min-[45rem]/gs:grid-cols-2">
                        @foreach ($fields as [$key, $kind, $perLang, $required])
                            @continue($key === 'moitUrl' && $form['moitOn'] !== true)
                            @php
                                $bag = $perLang ? "i18n.{$lang}.{$key}" : "form.{$key}";
                                $fid = 'gs-'.$key;
                                $hint = $kind === 'note' ? null : $hintOf($key);
                                $infoId = $fid.'-info';
                                $errId = $fid.'-err';
                                $bad = $errors->has($bag);
                                $locked = ! $perLang && $lockShared;
                                $label = __('admin.gs.f.'.$key).($required && (! $perLang || $onSource) ? ' *' : '');
                                $wide = in_array($kind, ['textarea', 'toggle', 'note'], true);
                                $describedBy = trim(($bad ? $errId : '').' '.($hint !== null ? $infoId : ''));
                                $source = $perLang && ! $onSource ? ($i18nDraft[$defaultLocale][$key] ?? '') : '';
                                $ph = $source !== '' ? $source : $placeholder($key);
                            @endphp

                            @if ($kind === 'note')
                                <p class="col-span-full flex items-start gap-2 rounded-md border border-info-text/30 bg-info-soft px-3 py-2.5 text-caption leading-relaxed text-info-text" role="note">
                                    <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
                                    <span class="min-w-0">{{ $key === 'region_note' ? __('admin.gs.region_note', ['name' => $nameOf($defaultLocale)]) : __('admin.gs.'.$key) }}</span>
                                </p>
                                @continue
                            @endif

                            @if ($kind === 'toggle')
                                <div class="col-span-full flex items-start justify-between gap-4" wire:key="gs-f-{{ $key }}">
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <span id="{{ $fid }}-l" class="text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong">{{ $label }}</span>
                                        @if ($hint !== null)
                                            <button type="button" class="hit-area-row inline-flex items-center gap-1 self-start text-caption text-text-muted hover:text-primary-text"
                                                    x-on:click="info = info === '{{ $infoId }}' ? null : '{{ $infoId }}'" x-bind:aria-expanded="info === '{{ $infoId }}' ? 'true' : 'false'"
                                                    aria-controls="{{ $infoId }}" aria-label="{{ __('admin.gs.detail_aria', ['label' => __('admin.gs.f.'.$key)]) }}">
                                                <x-bs-admin::icon name="ri-information" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]" />{{ __('admin.gs.detail') }}
                                            </button>
                                            <div id="{{ $infoId }}" role="note" x-show="info === '{{ $infoId }}'" x-cloak
                                                 class="rounded-lg border border-border bg-surface px-3 py-2.5 text-caption leading-relaxed text-text [overflow-wrap:break-word]">{{ $hint }}</div>
                                        @endif
                                    </div>
                                    <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $form[$key] === true ? 'true' : 'false' }}" aria-labelledby="{{ $fid }}-l"
                                            @if ($locked) aria-disabled="true" disabled @else wire:click="toggleMoit" @endif></button>
                                </div>
                                @continue
                            @endif

                            <div @class(['flex min-w-0 flex-col gap-1.5', 'col-span-full' => $wide, 'max-md:gap-3.5' => $hint !== null]) wire:key="gs-f-{{ $key }}-{{ $perLang ? $lang : 'shared' }}">
                                <span class="flex min-h-control flex-wrap items-center gap-x-[7px] max-md:min-h-7 gap-y-1 text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong">
                                    <label for="{{ $fid }}" class="cursor-pointer">{{ $label }}</label>
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
                                    <div id="{{ $infoId }}" role="note" x-show="info === '{{ $infoId }}'" x-cloak
                                         class="rounded-lg border border-border bg-surface px-3 py-2.5 text-caption leading-relaxed text-text [overflow-wrap:break-word]">{{ $hint }}</div>
                                @endif

                                @if ($kind === 'textarea')
                                    <textarea id="{{ $fid }}" rows="3" wire:model.live.blur="{{ $bag }}" placeholder="{{ $ph }}" maxlength="{{ GeneralSettings::MAX }}"
                                              @if ($perLang) lang="{{ $lang }}" @endif aria-invalid="{{ $bad ? 'true' : 'false' }}" @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                                              class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder min-h-[5.5rem] resize-y py-2 {{ $bad ? 'border-danger-text' : 'border-border-input' }}"></textarea>
                                @elseif ($kind === 'text')
                                    <input id="{{ $fid }}" type="{{ in_array($key, ['facebook', 'youtube', 'tiktok', 'moitUrl'], true) ? 'url' : ($key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text')) }}"
                                           wire:model.live.blur="{{ $bag }}" placeholder="{{ $ph }}" maxlength="{{ GeneralSettings::MAX }}" @if ($locked) readonly @endif
                                           @if ($perLang) lang="{{ $lang }}" @endif @if ($key === 'licenseDate') inputmode="numeric" @endif
                                           aria-invalid="{{ $bad ? 'true' : 'false' }}" @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                                           class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder {{ $bad ? 'border-danger-text' : 'border-border-input' }} {{ $locked ? $readOnly : '' }}">
                                @else
                                    @php
                                        $disabled = $locked
                                            || ($key === 'wardId' && $province === null)
                                            || ($key === 'provinceId' && $provinces->isEmpty())
                                            || (in_array($key, ['opens', 'closes'], true) && $noHours);
                                    @endphp
                                    <select id="{{ $fid }}" wire:model.live="{{ $bag }}" @disabled($disabled)
                                            aria-invalid="{{ $bad ? 'true' : 'false' }}" @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                                            class="field w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder cursor-pointer disabled:cursor-not-allowed {{ $bad ? 'border-danger-text' : 'border-border-input' }} {{ $disabled ? $readOnly : '' }}">
                                        @switch($key)
                                            @case('provinceId')
                                                <option value="">{{ __('admin.gs.pick_province') }}</option>
                                                @foreach ($provinces as $p)
                                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                                @endforeach
                                                @break
                                            @case('wardId')
                                                <option value="">{{ $province !== null ? __('admin.gs.pick_ward') : __('admin.gs.pick_ward_first') }}</option>
                                                @foreach ($wards as $w)
                                                    <option value="{{ $w->id }}">{{ $w->name }}</option>
                                                @endforeach
                                                @break
                                            @case('days')
                                                <option value="">{{ __('admin.gs.days.none') }}</option>
                                                @foreach (array_keys(BusinessHours::DAYS) as $d)
                                                    <option value="{{ $d }}">{{ __('admin.gs.days.'.$d) }}</option>
                                                @endforeach
                                                @break
                                            @case('opens')
                                            @case('closes')
                                                @foreach (BusinessHours::TIMES as $t)
                                                    <option value="{{ $t }}">{{ $t }}</option>
                                                @endforeach
                                                @break
                                            @case('timezone')
                                                @foreach ($zones as $z)
                                                    <option value="{{ $z }}">{{ Lang::has('admin.gs.tz.'.$z) ? __('admin.gs.tz.'.$z) : $z }}</option>
                                                @endforeach
                                                @break
                                            @case('dateFormat')
                                                @foreach (\App\Modules\Tenancy\Context\SiteTime::DATE_FORMATS as $df)
                                                    <option value="{{ $df }}">{{ __('admin.gs.df.'.$df) }}</option>
                                                @endforeach
                                                @break
                                        @endswitch
                                    </select>
                                @endif

                                @error($bag)
                                    <span id="{{ $errId }}" data-ferr class="inline-flex items-start gap-[5px] text-caption text-danger-text">
                                        <x-bs-admin::icon name="ri-error-warning" class="mt-px size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" /><span class="min-w-0">{{ $message }}</span>
                                    </span>
                                @enderror
                            </div>
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
                            x-on:click="$dispatch('gs-first-error', { group: {{ Js::from($firstErrorGroup) }} })">{{ __('admin.gs.first_error') }}</button>
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

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
