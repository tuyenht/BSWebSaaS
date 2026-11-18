@props([
    'icon',
    'title' => null,
    'kind' => null,
])
@php
    // A list's state in place of its rows (design 05): a large glyph, an optional heading, one line
    // and an optional button (the `action` slot). Used for a list with nothing yet («Chưa có bài nào»),
    // for rows that match nothing (in a full-width cell, «Bỏ lọc») and for a list that did not load
    // (kind="error": the glyph in the danger ink). Sizes live in resources/css/admin-list.css
    // (the heading takes the lead size from its class: that file keeps to the small text sizes).
@endphp
<div {{ $attributes->merge(['class' => 'list-state']) }}@if ($kind) data-k="{{ $kind }}"@endif>
    <x-bs-admin::icon :name="$icon" class="list-state-i size-9" />
    @if ($title !== null)
        <h2 class="list-state-t text-lead">{{ $title }}</h2>
    @endif
    <p class="list-state-p">{{ $slot }}</p>
    {{ $action ?? '' }}
</div>
