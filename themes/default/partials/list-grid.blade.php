{{--
    A category list page as a card grid (layouts grid-product / grid-project / grid-news). The
    category title band carries the page's single h1, the breadcrumb, the category's description and
    its cover photo (the page's main image: eager, high priority, srcset; no cover: the solid band),
    so card titles are h2. Chips link to the child categories' own pages. The first card's photo is the likely LCP
    image (high priority); the rest load lazily, so on a phone the photos below the fold do not
    compete with it (on a wide screen the browser still fetches the ones in view at once). Products
    stand two to a row on a phone; news and project cards there are compact rows. On the first page
    of a news list with more than three posts, the newest is the wide featured card above the grid.
    `cards` and `chips` are built by the controller from stored routes; no id reaches the markup.
--}}
@php
    $sfKind = in_array($kind ?? 'news', ['product', 'project', 'news'], true) ? $kind : 'news';
    $sfGrid = $sfKind === 'product' ? 'sf-grid--3p' : 'sf-grid--3m';
    $sfSizes = $sfKind === 'product'
        ? '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:640px) calc((100vw - 5rem)/2), calc((100vw - 2.75rem)/2)'
        : '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:768px) calc((100vw - 5rem)/2), calc(100vw - 2rem)';
    $sfCards = $cards ?? [];
    // Page 1 only (no "previous" or "first page" link): a later page is a plain grid.
    $sfFeatured = $sfKind === 'news' && count($sfCards) > 3 && empty($pagination['prevUrl']) && empty($pagination['firstUrl'])
        ? array_shift($sfCards) : null;
    // The lead is the page's one high-priority photo: the band cover, else the featured card, but only
    // when that card has a photo; otherwise the first grid card that has one takes the role.
    $sfLead = ! empty($band['image']) || ($sfFeatured !== null && ! empty($sfFeatured['image']));
    $sfFirstPhoto = $sfLead ? null : array_key_first(array_filter($sfCards, fn (array $c): bool => ! empty($c['image'])));
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
        @include('theme::sections.page_header', [
            'props' => ['title' => $title, 'subtitle' => $band['subtitle'] ?? '', 'image_url' => $band['image'] ?? null, '_lead' => true, '_high' => true, '_crumbs' => $breadcrumb ?? []],
            'listSrcset' => $band['srcset'] ?? null,
        ])
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
@if ($sfFeatured !== null)
            @include('theme::partials.sf-card', ['card' => $sfFeatured, 'kind' => 'news', 'level' => 'h2', 'featured' => true, 'sizes' => '(min-width:1360px) 640px, (min-width:1024px) calc((100vw - 4rem)/2), calc(100vw - 2rem)', 'priority' => (empty($band['image']) && ! empty($sfFeatured['image'])) ? 'high' : 'lazy'])
@endif
@if (count($sfCards) > 0)
            <ul class="sf-grid {{ $sfGrid }}">
@foreach ($sfCards as $index => $card)
                <li>@include('theme::partials.sf-card', ['card' => $card, 'kind' => $sfKind, 'level' => 'h2', 'list' => true, 'sizes' => $sfSizes, 'priority' => $index === $sfFirstPhoto ? 'high' : 'lazy'])</li>
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
