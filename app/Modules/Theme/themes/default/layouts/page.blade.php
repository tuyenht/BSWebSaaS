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
    {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). --}}
    <main>{!! $sections !!}</main>
</body>
</html>
