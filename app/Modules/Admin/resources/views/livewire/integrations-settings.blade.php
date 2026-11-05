@php
    use App\Modules\Theme\Chrome\SiteVerification;

    // "Tích hợp & Theo dõi" (design c-integrations-panel), the "Xác minh & Công cụ" group: one row
    // per provider with its switch, a short explanation and, when on, the field for its code.
    // Placeholders and web addresses are examples and links, not words to translate.
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $small = 'size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0';
    $providers = [
        'google' => ['icon' => 'ri-google', 'color' => '#4285f4', 'open' => 'https://search.google.com/search-console', 'create' => 'https://search.google.com/search-console',
            'learn' => 'https://support.google.com/webmasters/answer/9008080'],
        'bing' => ['icon' => 'ri-microsoft', 'color' => 'var(--color-success-text)', 'open' => 'https://www.bing.com/webmasters', 'create' => 'https://www.bing.com/webmasters',
            'learn' => 'https://www.bing.com/webmasters/help/getting-started-checklist-15b8e2a4'],
        'facebook' => ['icon' => 'ri-meta-fill', 'color' => '#0866ff', 'open' => 'https://business.facebook.com/settings/owned-domains', 'create' => '',
            'learn' => 'https://www.facebook.com/business/help/286768115176155'],
    ];
    $onCount = count(array_filter($on));
    $needCount = 0;
    foreach (array_keys($providers) as $key) {
        if (($on[$key] ?? false) && trim((string) ($tokens[$key] ?? '')) === '') {
            $needCount++;
        }
    }
    $badge = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-caption font-semibold';
@endphp
<x-bs-admin::settings-shell active="integrations">
    <div class="flex flex-col gap-4" x-data x-on:int-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())">
        <div class="overflow-hidden rounded-md border border-border-strong bg-surface shadow-card">
            <div class="flex flex-wrap items-center gap-2.5 border-b border-border-soft bg-surface-muted px-4 py-3.5">
                <span class="inline-flex size-[1.875rem] shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-text">
                    <x-bs-admin::icon name="ri-verified-badge-line" class="{{ $glyph }}" />
                </span>
                <div class="min-w-0 flex-1 basis-32">
                    <h2 class="text-[calc(0.84375rem+var(--user-text-boost))] font-semibold break-words text-text-strong">{{ __('admin.int.group') }}</h2>
                    <p class="text-caption break-words text-text-muted">{{ __('admin.int.group_desc') }}</p>
                </div>
                <span class="{{ $badge }} bg-primary-soft text-text-muted">{{ __('admin.int.on_count', ['count' => $onCount]) }}</span>
                @if ($needCount > 0)
                    <span class="{{ $badge }} bg-warning-soft text-warning-text" title="{{ __('admin.int.need_title') }}">{{ __('admin.int.need_count', ['count' => $needCount]) }}</span>
                @endif
            </div>

            <div class="flex flex-col gap-2.5 p-3.5">
                @foreach ($providers as $key => $p)
                    @php
                        $isOn = (bool) ($on[$key] ?? false);
                        $code = SiteVerification::extract((string) ($tokens[$key] ?? ''));
                        $hasError = $errors->has('tokens.'.$key);
                        $connected = $isOn && $code !== '' && ! $hasError && SiteVerification::valid($key, $code);
                        $name = __('admin.int.p.'.$key.'.name');
                        $fieldLabel = __('admin.int.p.'.$key.'.field');
                        $state = $isOn ? __('admin.int.off') : __('admin.int.on');
                    @endphp
                    <div class="rounded-md border border-border bg-surface px-3.5 py-3" x-data="{ info: false, guide: false }" wire:key="int-{{ $key }}">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex size-[2.375rem] shrink-0 items-center justify-center rounded-lg bg-surface-muted" style="color: {{ $p['color'] }}">
                                <x-bs-admin::icon :name="$p['icon']" class="size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))]" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-[calc(0.84375rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $name }}</h3>
                                    <button type="button" class="hit-area inline-flex items-center justify-center rounded text-text-muted hover:text-primary-text" x-on:click="info = ! info" x-bind:aria-expanded="info.toString()"
                                            title="{{ __('admin.int.info_btn') }}" aria-label="{{ __('admin.int.info_aria', ['name' => $name]) }}">
                                        <x-bs-admin::icon name="ri-information" class="{{ $glyph }}" />
                                    </button>
                                    @if ($isOn && $code === '')
                                        <span class="{{ $badge }} bg-danger-soft text-danger-text" title="{{ __('admin.int.need_badge_title') }}"><x-bs-admin::icon name="ri-error-warning" class="{{ $small }}" />{{ __('admin.int.need_badge') }}</span>
                                    @endif
                                    <span class="{{ $badge }} bg-surface-muted text-text-muted" title="{{ __('admin.int.meta_title') }}"><x-bs-admin::icon name="ri-code-line" class="{{ $small }}" />{{ __('admin.int.meta_badge') }}</span>
                                </div>
                                <p class="mt-0.5 text-caption leading-normal break-words text-text-muted">{{ __('admin.int.p.'.$key.'.note') }}</p>
                                <div x-show="info" x-cloak class="mt-2 rounded-md bg-info-soft px-3 py-2 text-caption leading-relaxed text-text">
                                    {{ __('admin.int.p.'.$key.'.about') }}
                                    <a href="{{ $p['learn'] }}" target="_blank" rel="noopener" class="ml-1.5 inline-flex items-center gap-1 font-semibold text-primary-text">{{ __('admin.int.learn') }}<x-bs-admin::icon name="ri-external-link" class="{{ $small }}" /></a>
                                </div>
                            </div>
                            <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $isOn ? 'true' : 'false' }}" wire:click="toggle('{{ $key }}')"
                                    aria-label="{{ __('admin.int.switch_aria', ['state' => $state, 'name' => $name]) }}" title="{{ __('admin.int.switch_aria', ['state' => $state, 'name' => $name]) }}"></button>
                        </div>

                        @if ($isOn)
                            <div class="mt-3 flex flex-col gap-2.5 border-t border-dashed border-border pt-3">
                                <div class="max-w-xl">
                                    <label for="int-token-{{ $key }}" class="mb-1.5 block text-[calc(0.78125rem+var(--user-text-boost))] font-semibold text-text">{{ $fieldLabel }}</label>
                                    <x-bs-admin::input id="int-token-{{ $key }}" wire:model.blur="tokens.{{ $key }}" autocomplete="off" autocapitalize="off" spellcheck="false"
                                                       placeholder="{{ __('admin.int.p.'.$key.'.ph') }}" aria-label="{{ __('admin.int.field_aria', ['field' => $fieldLabel, 'name' => $name]) }}" />
                                </div>
                                <div class="flex flex-wrap items-center gap-2.5">
                                    @if ($connected)
                                        <span class="{{ $badge }} bg-success-soft text-success-text"><x-bs-admin::icon name="ri-checkbox-circle" class="{{ $small }}" />{{ __('admin.int.valid') }}</span>
                                    @endif
                                    <button type="button" class="hit-area inline-flex items-center gap-1.5 rounded text-caption font-semibold text-primary-text" x-on:click="guide = ! guide" x-bind:aria-expanded="guide.toString()">
                                        <x-bs-admin::icon name="ri-questionnaire-line" class="{{ $small }}" />{{ __('admin.int.how') }}
                                    </button>
                                </div>
                                <div x-show="guide" x-cloak class="rounded-md border border-border bg-surface-muted px-3 py-2.5">
                                    <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5 text-caption leading-relaxed text-text">
                                        @foreach ([1, 2, 3] as $step)
                                            <li>{{ __('admin.int.p.'.$key.'.step'.$step) }}</li>
                                        @endforeach
                                    </ol>
                                    <div class="mt-2.5 flex flex-wrap gap-2">
                                        <x-bs-admin::button size="sm" variant="outline" color="secondary" :href="$p['open']" :navigate="false" target="_blank" rel="noopener">
                                            <x-bs-admin::icon name="ri-external-link" class="{{ $small }}" />{{ __('admin.int.open_page') }}
                                        </x-bs-admin::button>
                                        @if ($p['create'] !== '')
                                            <x-bs-admin::button size="sm" variant="ghost" color="secondary" :href="$p['create']" :navigate="false" target="_blank" rel="noopener">
                                                <x-bs-admin::icon name="ri-add" class="{{ $small }}" />{{ __('admin.int.create_new') }}
                                            </x-bs-admin::button>
                                        @endif
                                    </div>
                                </div>
                                <p class="flex items-center gap-1.5 text-caption {{ $connected ? 'text-success-text' : 'text-text-muted' }}">
                                    <x-bs-admin::icon :name="$connected ? 'ri-checkbox-circle' : 'ri-time'" class="{{ $glyph }}" />{{ $connected ? __('admin.int.status_added') : __('admin.int.status_wait') }}
                                </p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- The page's own save bar --}}
        <div class="flex flex-wrap items-center justify-end gap-2.5 border-t border-border-strong pt-4">
            <span class="inline-flex min-h-6 items-center gap-1.5 px-2.5 text-caption leading-snug font-semibold">
                @if ($dirty)
                    <span class="inline-flex items-center gap-1.5 text-warning-text"><x-bs-admin::icon name="ri-error-warning" class="{{ $glyph }}" />{{ __('admin.int.dirty') }}</span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-success-text"><x-bs-admin::icon name="ri-checkbox-circle" class="{{ $glyph }}" />{{ __('admin.int.saved') }}</span>
                @endif
            </span>
            @if ($dirty)
                <x-bs-admin::button variant="outline" color="secondary" wire:click="resetChanges" wire:loading.attr="disabled">{{ __('admin.int.discard') }}</x-bs-admin::button>
            @endif
            <x-bs-admin::button color="primary" wire:click="save" wire:loading.attr="disabled">
                <x-bs-admin::icon name="ri-save-3" class="{{ $glyph }}" /><span>{{ __('admin.int.save') }}</span>
            </x-bs-admin::button>
        </div>
    </div>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
