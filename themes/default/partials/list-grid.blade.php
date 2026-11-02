{{--
    A category list page as a card grid (layouts grid-product / grid-project / grid-news). The
    category title band carries the page's single h1 (no photo: a solid band), so card titles are
    h2. Chips link to the child categories' own pages. The first card's photo is the likely LCP
    image (high priority), the next two load eagerly, the rest lazily. `cards` and `chips` are
    built by the controller from stored routes; no id reaches the markup.
--}}
@php
    $sfKind = in_array($kind ?? 'news', ['product', 'project', 'news'], true) ? $kind : 'news';
    $sfGrid = $sfKind === 'product' ? 'sf-grid--3s' : 'sf-grid--3m';
    $sfSizes = $sfKind === 'product'
        ? '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)'
        : '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:768px) calc((100vw - 5rem)/2), calc(100vw - 2rem)';
    $sfCards = $cards ?? [];
    $sfChips = $chips ?? [];
    $sfAll = collect($sfChips)->first();
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="sf-list sf-list--{{ $sfKind }}" id="noi-dung" tabindex="-1">
        @include('theme::sections.page_header', ['props' => ['title' => $title, '_lead' => true]])
        <div class="sf-container sf-list__body">
@if ($sfChips !== [])
            <nav class="sf-chips" aria-label="{{ __('storefront.filter_categories') }}">
                <ul class="sf-chips__list">
@foreach ($sfChips as $chip)
                    <li><a class="sf-chips__chip" href="{{ $chip['url'] }}"@if ($chip['current']) aria-current="page"@endif>{{ $chip['name'] }}</a></li>
@endforeach
                </ul>
            </nav>
@endif
@if (count($sfCards) > 0)
            <ul class="sf-grid {{ $sfGrid }}">
@foreach ($sfCards as $index => $card)
                <li>@include('theme::partials.sf-card', ['card' => $card, 'kind' => $sfKind, 'level' => 'h2', 'sizes' => $sfSizes, 'priority' => $index === 0 ? 'high' : ($index < 3 ? 'eager' : 'lazy')])</li>
@endforeach
            </ul>
@if (! empty($pagination['prevUrl']) || ! empty($pagination['nextUrl']) || ! empty($pagination['firstUrl']))
            <nav class="pagination" aria-label="{{ __('storefront.pagination') }}">
@if (! empty($pagination['firstUrl']))
                <a class="pagination__first" href="{{ $pagination['firstUrl'] }}">{{ __('storefront.back_to_first') }}</a>
@endif
@if (! empty($pagination['prevUrl']))
                <a class="pagination__prev" rel="prev" href="{{ $pagination['prevUrl'] }}">{{ __('storefront.prev_page') }}</a>
@endif
@if (! empty($pagination['nextUrl']))
                <a class="pagination__next" rel="next" href="{{ $pagination['nextUrl'] }}">{{ __('storefront.next_page') }}</a>
@endif
            </nav>
@endif
@else
            <div class="sf-empty-box">
                <p>{{ __($emptyKey ?? 'storefront.no_posts') }}</p>
@if ($sfAll !== null && $sfAll['all'] && ! $sfAll['current'])
                <a class="sf-empty-box__link" href="{{ $sfAll['url'] }}">{{ $sfAll['name'] }}</a>
@endif
            </div>
@endif
        </div>
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
