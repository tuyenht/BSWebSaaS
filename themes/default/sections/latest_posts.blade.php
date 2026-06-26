<section class="latest-posts">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
    <ul class="latest-posts__list">
        @foreach ($posts as $post)
            <li class="latest-posts__item">
                @if (! empty($post['url']))
                    <a href="{{ $post['url'] }}">{{ $post['title'] ?? '' }}</a>
                @else
                    {{ $post['title'] ?? '' }}
                @endif
            </li>
        @endforeach
    </ul>
</section>
