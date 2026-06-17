<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seoTitle }}</title>
    @if (! empty($seoDescription))
        <meta name="description" content="{{ $seoDescription }}">
    @endif
    @if (! empty($pagination['prevUrl']))
        <link rel="prev" href="{{ $pagination['prevUrl'] }}">
    @endif
    @if (! empty($pagination['nextUrl']))
        <link rel="next" href="{{ $pagination['nextUrl'] }}">
    @endif
    {{-- Per-site design tokens over the shared template (values are sanitized server-side). --}}
    <style>:root{@foreach (($tokens ?? []) as $name => $value)--{{ $name }}:{{ $value }};@endforeach}</style>
</head>
<body>
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
                <nav class="pagination" aria-label="Phân trang">
                    @if (! empty($pagination['prevUrl']))
                        <a class="pagination__prev" rel="prev" href="{{ $pagination['prevUrl'] }}">← Trang trước</a>
                    @endif
                    @if (! empty($pagination['nextUrl']))
                        <a class="pagination__next" rel="next" href="{{ $pagination['nextUrl'] }}">Trang sau →</a>
                    @endif
                </nav>
            @endif
        @else
            <p class="category__empty">Chưa có bài viết nào.</p>
        @endif
    </main>
</body>
</html>
