<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seoTitle }}</title>
    @if (! empty($seoDescription))
        <meta name="description" content="{{ $seoDescription }}">
    @endif
    {{-- Per-site design tokens over the shared template (values are sanitized server-side). --}}
    <style>:root{@foreach (($tokens ?? []) as $name => $value)--{{ $name }}:{{ $value }};@endforeach}</style>
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
