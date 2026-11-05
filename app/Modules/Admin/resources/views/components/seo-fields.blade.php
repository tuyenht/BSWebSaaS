@props([
    // The form's field names (Livewire properties). `excerpt` null = this form has no short description.
    'excerpt' => null,
    'titleModel' => 'seoTitle',
    'descriptionModel' => 'seoDescription',
    // The page's own title: the preview shows it while the Google title is empty.
    'pageTitleModel' => 'title',
    // The slug field: the preview's address ends with it as it is typed.
    'slugModel' => 'slug',
    // post · page · product: picks the short description's hint and example.
    'kind' => 'page',
    // The preview's address around the slug (SeoFields::address): the site's primary domain, the
    // language prefix and the path before the slug; `home` replaces it all for the home slug.
    'address' => ['base' => '', 'home' => null, 'homeSlug' => 'home'],
    'locale' => 'vi',
    'multiLocale' => false,
    'id' => 'seo',
])
@php
    use App\Modules\Admin\Support\SeoFields;
    use Illuminate\Support\Js;

    // "Hiện thế nào trên Google" (design 07 #pe-seo, the post editor's view-model in 05, the product
    // editor's card in 09): the short description card (posts and pages), then the collapsible card
    // with the Google title and description, each with its length meter, and the Google preview.
    // Meters and preview run in the browser (admin-seo-fields.js) on the deferred wire:model values;
    // the limits are SeoFields::LIMITS, the one table. Nothing here is rendered unescaped.
    $limits = SeoFields::LIMITS;
    $excerptLimits = $limits['excerpt'];
    $cardFields = array_values(array_filter([$titleModel, $descriptionModel]));
    $config = SeoFields::clientConfig(
        ['excerpt' => $excerpt, 'title' => $titleModel, 'description' => $descriptionModel, 'pageTitle' => $pageTitleModel, 'slug' => $slugModel],
        $address,
        $errors->hasAny($cardFields),
    );
    $bandIcons = ['empty' => 'ri-edit', 'short' => 'ri-arrow-up', 'near' => 'ri-arrow-right-up', 'good' => 'ri-check', 'long' => 'ri-error-warning', 'over' => 'ri-close-circle'];
    // `field` (the shared size) is written on each control itself, so the size check sees it.
    $field = 'block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text';
    $label = 'inline-flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5 gap-y-1 text-small font-semibold';
    $labelRow = 'mb-1.5 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1';
    $error = 'mt-1 text-small text-danger-text';
    $chip = $multiLocale ? strtoupper($locale) : null;
@endphp
<div data-seo-fields class="flex flex-col gap-4" x-data="bsSeoFields({{ Js::from($config) }})">
    @if ($excerpt !== null)
        <section class="rounded-md border border-border bg-surface px-5 py-4 shadow-card">
            <div class="{{ $labelRow }}">
                <label for="{{ $id }}-excerpt" class="{{ $label }} text-text-strong">
                    <span>{{ __('admin.seo.excerpt_label') }}</span>
                    @if ($chip)
                        <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                    @endif
                </label>
                <span id="{{ $id }}-excerpt-c" class="seo-count" :data-b="excerptMeter.band" data-b="empty">
                    @foreach ($bandIcons as $band => $icon)
                        <x-bs-admin::icon :name="$icon" x-show="excerptMeter.band === '{{ $band }}'" x-cloak />
                    @endforeach
                    <span x-text="excerptMeter.text"></span>
                </span>
            </div>
            <textarea id="{{ $id }}-excerpt" wire:model="{{ $excerpt }}" rows="3" maxlength="{{ SeoFields::hardCap('excerpt') }}" lang="{{ $locale }}"
                      placeholder="{{ __($kind === 'post' ? 'admin.seo.excerpt_placeholder_post' : 'admin.seo.excerpt_placeholder_page') }}"
                      aria-describedby="{{ $id }}-excerpt-c {{ $id }}-excerpt-h @error($excerpt) {{ $id }}-excerpt-e @enderror" @error($excerpt) aria-invalid="true" @enderror
                      class="field {{ $field }} resize-y py-2.5"></textarea>
            <span class="seo-meter" :data-b="excerptMeter.band" aria-hidden="true">
                <span class="seo-meter__zone" :style="{ left: excerptMeter.lo + '%', width: excerptMeter.zw + '%' }"></span>
                <span class="seo-meter__fill" :style="{ width: excerptMeter.pct + '%' }"></span>
                <span class="seo-meter__tick" :style="{ left: excerptMeter.tick + '%' }"></span>
            </span>
            @error($excerpt) <p id="{{ $id }}-excerpt-e" class="{{ $error }}">{{ $message }}</p> @enderror
            <p id="{{ $id }}-excerpt-h" class="mt-1.5 text-caption text-text-muted">
                {{ __($kind === 'post' ? 'admin.seo.excerpt_hint_post' : 'admin.seo.excerpt_hint_page', ['lo' => $excerptLimits['best'][0], 'hi' => $excerptLimits['best'][1], 'cap' => SeoFields::hardCap('excerpt')]) }}
            </p>
        </section>
    @endif

    <section id="{{ $id }}-card" class="overflow-hidden rounded-md border border-border bg-surface shadow-card">
        <h2 class="m-0">
            <button type="button" id="{{ $id }}-head" x-on:click="toggle()" :aria-expanded="open ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $id }}-body"
                    class="flex min-h-hit w-full items-center gap-2.5 px-5 py-3 text-left transition-colors hover:bg-surface-hover">
                <x-bs-admin::icon name="ri-search-eye" class="size-(--icon-md) shrink-0 text-primary-text" />
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="text-small font-semibold text-text-strong">{{ __('admin.seo.card_title') }}</span>
                    <span x-show="!open" class="text-caption text-text-muted">{{ __('admin.seo.card_hint') }}</span>
                </span>
                @if ($chip)
                    <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                @endif
                <x-bs-admin::icon name="ri-arrow-down-s" class="seo-chev ms-auto size-(--icon-md) shrink-0 text-text-muted" ::data-open="open ? 'true' : 'false'" />
            </button>
        </h2>

        <div id="{{ $id }}-body" x-show="open" x-cloak role="region" aria-labelledby="{{ $id }}-head" class="flex flex-col gap-4 px-5 pt-1 pb-5">
            <div>
                <div class="{{ $labelRow }}">
                    <label for="{{ $id }}-title" class="{{ $label }} text-text">{{ __('admin.seo.title_label') }}</label>
                    <span id="{{ $id }}-title-c" class="seo-count" :data-b="titleMeter.band" data-b="empty">
                        @foreach ($bandIcons as $band => $icon)
                            <x-bs-admin::icon :name="$icon" x-show="titleMeter.band === '{{ $band }}'" x-cloak />
                        @endforeach
                        <span x-text="titleMeter.text"></span>
                    </span>
                </div>
                <input type="text" id="{{ $id }}-title" wire:model="{{ $titleModel }}" maxlength="{{ SeoFields::hardCap('seoTitle') }}" lang="{{ $locale }}"
                       placeholder="{{ __('admin.seo.title_placeholder') }}" aria-describedby="{{ $id }}-title-c @error($titleModel) {{ $id }}-title-e @enderror" @error($titleModel) aria-invalid="true" @enderror
                       class="field {{ $field }}">
                <span class="seo-meter" :data-b="titleMeter.band" aria-hidden="true">
                    <span class="seo-meter__zone" :style="{ left: titleMeter.lo + '%', width: titleMeter.zw + '%' }"></span>
                    <span class="seo-meter__fill" :style="{ width: titleMeter.pct + '%' }"></span>
                    <span class="seo-meter__tick" :style="{ left: titleMeter.tick + '%' }"></span>
                </span>
                @error($titleModel) <p id="{{ $id }}-title-e" class="{{ $error }}" x-init="open = true">{{ $message }}</p> @enderror
            </div>

            <div>
                <div class="{{ $labelRow }}">
                    <label for="{{ $id }}-description" class="{{ $label }} text-text">{{ __('admin.seo.description_label') }}</label>
                    <span id="{{ $id }}-description-c" class="seo-count" :data-b="descriptionMeter.band" data-b="empty">
                        @foreach ($bandIcons as $band => $icon)
                            <x-bs-admin::icon :name="$icon" x-show="descriptionMeter.band === '{{ $band }}'" x-cloak />
                        @endforeach
                        <span x-text="descriptionMeter.text"></span>
                    </span>
                </div>
                {{-- One line on Google: Enter adds no line break (a pasted one is removed on save). An
                     Enter that confirms a word being composed (Vietnamese, Japanese, Chinese input)
                     is left to the input method. --}}
                <textarea id="{{ $id }}-description" wire:model="{{ $descriptionModel }}" rows="2" maxlength="{{ SeoFields::hardCap('seoDesc') }}" lang="{{ $locale }}"
                          placeholder="{{ __('admin.seo.description_placeholder') }}" aria-describedby="{{ $id }}-description-c @error($descriptionModel) {{ $id }}-description-e @enderror" @error($descriptionModel) aria-invalid="true" @enderror
                          x-on:keydown.enter="if (! $event.isComposing && $event.keyCode !== 229) $event.preventDefault()" class="field {{ $field }} resize-y py-2.5"></textarea>
                <span class="seo-meter" :data-b="descriptionMeter.band" aria-hidden="true">
                    <span class="seo-meter__zone" :style="{ left: descriptionMeter.lo + '%', width: descriptionMeter.zw + '%' }"></span>
                    <span class="seo-meter__fill" :style="{ width: descriptionMeter.pct + '%' }"></span>
                    <span class="seo-meter__tick" :style="{ left: descriptionMeter.tick + '%' }"></span>
                </span>
                @error($descriptionModel) <p id="{{ $id }}-description-e" class="{{ $error }}" x-init="open = true">{{ $message }}</p> @enderror
            </div>

            <x-bs-admin::seo-preview :address="$address['base']" :locale="$locale" />
        </div>
    </section>
</div>
