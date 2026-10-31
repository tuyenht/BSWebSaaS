@php
    // The column-header row of a shared list: shown from 900 only, on the same grid as the rows.
@endphp
<div role="row" {{ $attributes->merge(['class' => 'list-r list-hd']) }}>{{ $slot }}</div>
