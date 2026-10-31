@php
    // A record's meta line under its name: short facts joined by " · ", wrapping between them,
    // never clipped. A piece is a <span class="list-m">; data-for="au" marks the author (shown
    // only while the author column is not), data-for="when" the date (shown below 600, where the
    // status cell drops its date line).
@endphp
<div {{ $attributes->merge(['class' => 'list-meta']) }}>{{ $slot }}</div>
