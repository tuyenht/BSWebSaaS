{{--
    A catalog product. A dark band holds the breadcrumb (home → the product's categories → the
    product); below it the photos sit beside the main category's name, the page's single h1, the
    summary and the contact box (call and Zalo from the site's business identity); the description
    follows in its own band, then up to four other products of the same category.
    The site's own blocks (LayoutSlots) sit in four places: before the order buttons (in place of the
    "price on request" line), after them (a bulleted list there reads as ticks in two columns), as
    shared parts after the description, and in the band at the end. The description and the shared
    parts split at their headings (`parts`, two or more): a bar of links to each part stays under the
    header while the description scrolls; it is plain anchor links, and its small script only marks
    the part being read.
    Photos: the first is the page's main image (eager, high priority, srcset), every other one and
    each thumbnail load lazily. Each thumbnail and the main photo are plain links to the photo, so
    they work without script; the switcher script (loaded only with more than one photo) swaps the
    main photo in place, shows the arrows and keeps the "1 / 4" count. Everything comes server-built
    from the controller; no id reaches the markup.
    The admin's preview ($preview) adds one line above the header saying it is a preview; its skip link
    stays in the frame while every other link opens outside it (the head's <base target="_blank">).
--}}
@php
    $sfPhotos = $images ?? [];
    $sfCount = count($sfPhotos);
    $sfInfo = is_array($product ?? null) ? $product : [];
    $sfContact = is_array($sfInfo['contact'] ?? null) ? $sfInfo['contact'] : null;
    $sfRelated = $related ?? [];
    $sfHasBody = trim(strip_tags((string) $description, '<img><iframe><video><table>')) !== '';
    $sfSlots = is_array($slotHtml ?? null) ? $slotHtml : [];
    $sfBefore = trim((string) ($sfSlots['product_before_action'] ?? ''));
    $sfAfter = trim((string) ($sfSlots['product_after_action'] ?? ''));
    $sfParts = $parts ?? [];
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung"@if (! empty($preview)) target="_self"@endif>{{ __('storefront.skip_to_content') }}</a>
@if (! empty($preview))
    <p class="sf-preview" role="note">{{ __(! empty($previewLive) ? 'storefront.preview_band_live' : 'storefront.preview_band') }}</p>
@endif
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="product-page" id="noi-dung" tabindex="-1">
        <article class="product">
            <div class="product-band">
                <div class="sf-container">
                    @include('theme::partials.sf-breadcrumb', ['crumbs' => $crumbsShown ?? $breadcrumb ?? [], 'tone' => 'dark', 'class' => 'product__crumbs'])
                </div>
            </div>
            <div class="sf-container product__top">
@if ($sfCount > 0)
                <div class="product__gallery" role="group" aria-label="{{ __('storefront.product_photos') }}" data-sf-gallery>
                    <div class="product__stage">
                        <a class="product__main" href="{{ $sfPhotos[0]['url'] }}" data-sf-gallery-main>
                            @include('theme::partials.sf-img', ['src' => $sfPhotos[0]['url'], 'srcset' => $sfPhotos[0]['srcset'], 'sizes' => '(min-width:80rem) 36.5rem, (min-width:1024px) calc((100vw - 5.5rem)/2), calc(100vw - 2rem)', 'alt' => $sfPhotos[0]['alt'], 'w' => $sfPhotos[0]['width'], 'h' => $sfPhotos[0]['height'], 'priority' => 'high', 'class' => 'product__image'])
                        </a>
@if (($sfInfo['featured'] ?? false) || ($sfInfo['bestseller'] ?? false))
                        <ul class="product__badges">
@if ($sfInfo['featured'] ?? false)
                            <li class="product__badge">{{ __('storefront.badge_featured') }}</li>
@endif
@if ($sfInfo['bestseller'] ?? false)
                            <li class="product__badge">{{ __('storefront.badge_bestseller') }}</li>
@endif
                        </ul>
@endif
@if ($sfCount > 1)
                        <span class="product__count" aria-live="polite" data-sf-gallery-count>1 / {{ $sfCount }}</span>
                        <button type="button" class="sf-slider__btn product__nav product__nav--prev" data-sf-gallery-step="-1" aria-label="{{ __('storefront.prev_photo') }}" hidden>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" class="sf-slider__btn product__nav product__nav--next" data-sf-gallery-step="1" aria-label="{{ __('storefront.next_photo') }}" hidden>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
                        </button>
@endif
                    </div>
@if ($sfCount > 1)
                    <ul class="product__thumbs">
@foreach ($sfPhotos as $index => $photo)
                        <li><a class="product__thumb" href="{{ $photo['url'] }}" aria-label="{{ __('storefront.view_photo', ['n' => $index + 1]) }}" data-sf-gallery-thumb data-srcset="{{ $photo['srcset'] ?? '' }}"@if ($index === 0) aria-current="true"@endif>@include('theme::partials.sf-img', ['src' => $photo['thumb'], 'w' => $photo['width'], 'h' => $photo['height'], 'class' => 'product__thumb-img'])</a></li>
@endforeach
                    </ul>
@endif
                </div>
@endif
                <div class="product__info">
@if (($sfInfo['group'] ?? '') !== '')
                    <p class="product__group">{{ $sfInfo['group'] }}</p>
@endif
                    <h1 class="product__title">{{ $title }}</h1>
@if (($sfInfo['summary'] ?? '') !== '')
                    <p class="product__summary">{{ $sfInfo['summary'] }}</p>
@endif
@if ($sfContact !== null || ($sfInfo['quote'] ?? null) !== null || $sfBefore !== '')
                    <div class="product__contact">
@if ($sfBefore !== '')
                        <div class="product__lead">{!! $sfBefore !!}</div>
@else
                        <p class="product__price">{{ __('storefront.price_on_request') }}</p>
@endif
@if (($sfInfo['quote'] ?? null) !== null || ($sfContact !== null && $sfContact['call'] !== null))
                        <div class="product__actions">
@if (($sfInfo['quote'] ?? null) !== null)
                            <a class="product__quote" href="{{ $sfInfo['quote'] }}">{{ __('storefront.request_quote') }}</a>
@endif
@if ($sfContact !== null && $sfContact['call'] !== null)
                            <a class="product__call" href="{{ $sfContact['call'] }}">{{ __('storefront.call_number', ['phone' => $sfContact['number']]) }}</a>
@endif
                        </div>
@endif
@if ($sfContact !== null && $sfContact['zalo'] !== null)
                        <a class="product__zalo" href="{{ $sfContact['zalo'] }}" target="_blank" rel="noopener">{{ __('storefront.zalo_message') }}</a>
@endif
                    </div>
@endif
@if ($sfAfter !== '')
                    <div class="product__extra">{!! $sfAfter !!}</div>
@endif
                </div>
            </div>
@if ($sfHasBody)
            <section class="product__details" aria-labelledby="thong-tin-san-pham">
                <div class="sf-container">
                    <h2 class="sr-only" id="thong-tin-san-pham">{{ __('storefront.product_info') }}</h2>
                    <div class="product__sheet">
@if ($sfParts !== [])
                        <nav class="product-toc" aria-label="{{ __('storefront.product_contents') }}">
                            <ul class="product-toc__list">
@foreach ($sfParts as $part)
                                <li><a class="product-toc__link" href="#{{ $part['id'] }}">{{ $part['label'] }}</a></li>
@endforeach
                            </ul>
                        </nav>
@endif
                        {{-- $description is the product's pre-sanitized description HTML (HTMLPurifier at write time),
                             then the shared parts' block HTML, rendered raw exactly like a post body's. --}}
                        <div class="product__body sf-prose sf-prose--article{{ $sfParts !== [] ? ' product__body--parts' : '' }}">{!! $description !!}</div>
                    </div>
                </div>
            </section>
@endif
        </article>
@if ($sfRelated !== [])
        <section class="post-related post-related--plain" aria-labelledby="san-pham-cung-nhom">
            <div class="sf-container">
                @include('theme::partials.sf-section-head', ['heading' => __('storefront.related_products'), 'description' => '', 'headId' => 'san-pham-cung-nhom'])
                <ul class="sf-grid sf-grid--4s">
@foreach ($sfRelated as $card)
                    <li>@include('theme::partials.sf-card', ['card' => $card, 'kind' => 'product', 'level' => 'h3', 'sizes' => '(min-width:1360px) 298px, (min-width:1024px) calc((100vw - 10.5rem)/4), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)'])</li>
@endforeach
                </ul>
            </div>
        </section>
@endif
@if (trim((string) (($slotHtml ?? [])['after_product'] ?? '')) !== '')
        <div class="slot-band">{!! $slotHtml['after_product'] !!}</div>
@endif
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
