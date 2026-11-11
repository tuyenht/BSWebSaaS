<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="category" id="noi-dung" tabindex="-1">
        @include('theme::partials.sf-breadcrumb', ['crumbs' => $breadcrumb ?? [], 'class' => 'category__crumbs'])
        <h1 class="category__title">{{ $title }}</h1>
@if (trim((string) (($slotHtml ?? [])['before_list'] ?? '')) !== '')
        <div class="slot-band">{!! $slotHtml['before_list'] !!}</div>
@endif

        @if (count($posts) > 0)
            <ul class="category__list">
                @foreach ($posts as $post)
                    <li class="category__item">
                        <a href="{{ $post['url'] }}">{{ $post['title'] }}</a>
                    </li>
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
            <p class="category__empty">{{ __('storefront.no_posts') }}</p>
        @endif
@if (trim((string) (($slotHtml ?? [])['after_list'] ?? '')) !== '')
        <div class="slot-band">{!! $slotHtml['after_list'] !!}</div>
@endif
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
