<section class="latest-posts">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
    <ul class="latest-posts__list">
        @foreach ($posts as $post)
            <li class="latest-posts__item">{{ $post['title'] ?? '' }}</li>
        @endforeach
    </ul>
</section>
