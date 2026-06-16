<section class="richtext">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
    @foreach ((array) ($props['paragraphs'] ?? []) as $paragraph)
        <p>{{ is_scalar($paragraph) ? $paragraph : '' }}</p>
    @endforeach
</section>
