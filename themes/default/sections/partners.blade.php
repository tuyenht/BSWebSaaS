<section class="partners">
    @if (! empty($props['heading']))
        <h2 class="partners__heading">{{ $props['heading'] }}</h2>
    @endif
    <ul class="partners__list">
        @foreach ((array) ($props['logos'] ?? []) as $item)
            @php($item = (array) $item)
            @continue(empty($item['image_url']))
            {{-- Logo = pre-optimized image (Option C R2); width/height reserve space (no CLS).
                 Linked only when the partner has a url (no dead href); item skipped if it has no image. --}}
            <li class="partners__item">
                @if (! empty($item['url']))
                    <a class="partners__link" href="{{ $item['url'] }}">
                        <img class="partners__logo" src="{{ $item['image_url'] }}" alt="{{ $item['name'] ?? '' }}" width="160" height="80" loading="lazy">
                    </a>
                @else
                    <img class="partners__logo" src="{{ $item['image_url'] }}" alt="{{ $item['name'] ?? '' }}" width="160" height="80" loading="lazy">
                @endif
            </li>
        @endforeach
    </ul>
</section>
