<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    @include('theme::partials.header', ['menu' => $menu ?? []])
    <article class="post">
        @isset($category)
            <nav class="post__breadcrumb" aria-label="Breadcrumb">
                <a href="{{ $category['url'] }}">{{ $category['name'] }}</a>
            </nav>
        @endisset
        <h1 class="post__title">{{ $title }}</h1>
        {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). Post
             bodies reuse the block-list Page-JSON (D1), so they render exactly like a page. --}}
        <div class="post__body">{!! $sections !!}</div>
    </article>
</body>
</html>
