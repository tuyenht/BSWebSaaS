@props([
    'at',
    'text',
    'full',
    'exact' => false,
])
@php
    // A record's date as words ("Sửa hôm nay, 10:20", "Sửa 3 ngày trước", "Đăng lúc 23/11/2026 · 08:00"):
    // a <time> that carries the exact moment for machines, and for a screen reader the whole day and
    // hour after the words, so it is never only behind a hover. $exact: the words already hold the
    // whole moment (a scheduled date), so nothing is added.
    //
    // $at: the moment in the website's clock. $text: the words shown. $full: "16/11/2026 · 10:20".
@endphp
<time {{ $attributes->merge(['datetime' => $at->toIso8601String(), 'title' => $full]) }}>{{ $text }}{!! $exact ? '' : '<span class="sr-only"> ('.e($full).')</span>' !!}</time>
