<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seoTitle }}</title>
    @if (! empty($seoDescription))
        <meta name="description" content="{{ $seoDescription }}">
    @endif
</head>
<body>
    {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). --}}
    <main>{!! $sections !!}</main>
</body>
</html>
