{{--
    Capability list layout (list template "grid-capability"): the category's posts as numbered rows,
    a photo and the title, the excerpt and one link side by side, the photo alternating left and right
    from a tablet up (stacked, photo first, on a phone). The category title band carries the page's
    single h1, so each row's title is h2. `cards` is built by the controller from stored routes; no id
    reaches the markup. The band before and after the rows are the category's block slots
    (before_list, after_list), e.g. the process steps and the call to action.
--}}
@php
    $sfCaps = $cards ?? [];
    // The band cover is the page's one high-priority photo; without one, the first row's photo takes the role.
    $sfLead = ! empty($band['image']);
    $sfFirstPhoto = $sfLead ? null : array_key_first(array_filter($sfCaps, fn (array $c): bool => ! empty($c['image'])));
@endphp
<!doctype html>
<html lang="{{ $lang ?? 'vi' }}"{!! ($dir ?? 'ltr') === 'rtl' ? ' dir="rtl"' : '' !!}>
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="sf-list sf-list--capability" id="noi-dung" tabindex="-1">
        @include('theme::sections.page_header', [
            'props' => ['title' => $title, 'subtitle' => $band['subtitle'] ?? '', 'image_url' => $band['image'] ?? null, '_lead' => true, '_high' => true, '_crumbs' => $breadcrumb ?? []],
            'listSrcset' => $band['srcset'] ?? null,
        ])
@if (trim((string) (($slotHtml ?? [])['before_list'] ?? '')) !== '')
        <div class="slot-band">{!! $slotHtml['before_list'] !!}</div>
@endif
        <div class="sf-container sf-list__body sf-caps">
@if (count($sfCaps) > 0)
@foreach ($sfCaps as $index => $card)
@php
    $sfCapUrl = \App\Modules\Shared\Support\SafeLink::href($card['url'] ?? null);
@endphp
            <article class="sf-cap{{ $index % 2 === 1 ? ' sf-cap--flip' : '' }}">
                <div class="sf-cap__photo{{ empty($card['image']) ? ' sf-cap__photo--empty' : '' }}">
@include('theme::partials.sf-img', ['src' => $card['image'] ?? '', 'srcset' => $card['srcset'] ?? null, 'sizes' => '(min-width:1280px) 560px, (min-width:768px) 45vw, calc(100vw - 2rem)', 'w' => 800, 'h' => 600, 'class' => 'sf-cap__img', 'priority' => $index === $sfFirstPhoto ? 'high' : 'lazy'])
                    <span class="sf-cap__no">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                </div>
                <div class="sf-cap__body">
                    <h2 class="sf-cap__title">{{ $card['title'] }}</h2>
@if (($card['excerpt'] ?? '') !== '')
                    <p class="sf-cap__text">{{ $card['excerpt'] }}</p>
@endif
@if ($sfCapUrl !== null)
                    <a class="sf-cap__more" href="{{ $sfCapUrl }}">{{ __('storefront.view_capability') }}<span class="sr-only"> {{ $card['title'] }}</span></a>
@endif
                </div>
            </article>
@endforeach
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
                <p>{{ __('storefront.no_posts') }}</p>
            </div>
@endif
        </div>
@if (trim((string) (($slotHtml ?? [])['after_list'] ?? '')) !== '')
        <div class="slot-band">{!! $slotHtml['after_list'] !!}</div>
@endif
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
