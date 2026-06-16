<section class="features">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
    <ul class="features__list">
        @foreach ((array) ($props['items'] ?? []) as $item)
            @php($item = (array) $item)
            <li class="features__item">
                <h3>{{ $item['title'] ?? '' }}</h3>
                @if (! empty($item['text']))
                    <p>{{ $item['text'] }}</p>
                @endif
            </li>
        @endforeach
    </ul>
</section>
