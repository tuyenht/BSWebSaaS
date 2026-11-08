{{--
    The <li> of each stat (SectionProps::statEntries), shared by the stats block and the call to action's
    side stats. The server prints the final number; only a counted number (`count`) carries data-count for
    sf-stats.js, a year never counts up. A visually hidden copy carries the final text for screen readers.
    A number longer than five digits carries its length, so the stats block can shrink it to fit.
--}}
@foreach ($stats as $stat)
<li class="sf-stat">
@if ($stat['value'] !== null)
<span class="sf-stat__value" aria-hidden="true"@if ($stat['length'] > 5) style="--stat-len: {{ $stat['length'] }}"@endif><span{!! $stat['count'] ? ' data-count="'.e($stat['value']).'"' : '' !!}>{{ $stat['number'] }}</span>@if ($stat['suffix'] !== '')<span class="sf-stat__suffix">{{ $stat['suffix'] }}</span>@endif</span>
<span class="sr-only">{{ $stat['number'] }}{{ $stat['suffix'] }}</span>
@endif
@if ($stat['label'] !== '')
<span class="sf-stat__label">{{ $stat['label'] }}</span>
@endif
</li>
@endforeach
