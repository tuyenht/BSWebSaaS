<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). --}}
    <main>{!! $sections !!}</main>
</body>
</html>
