<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <main class="category">
        <h1 class="category__title">{{ $title }}</h1>

        @if (count($posts) > 0)
            <ul class="category__list">
                @foreach ($posts as $post)
                    <li class="category__item">
                        <a href="{{ $post['url'] }}">{{ $post['title'] }}</a>
                    </li>
                @endforeach
            </ul>

            @if (! empty($pagination['prevUrl']) || ! empty($pagination['nextUrl']))
                <nav class="pagination" aria-label="{{ __('storefront.pagination') }}">
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
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
