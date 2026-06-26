<section class="entry-grid">
    @if (! empty($props['heading']))
        <h2 class="entry-grid__heading">{{ $props['heading'] }}</h2>
    @endif

    {{-- $items are SERVER-resolved cards (EntryGridComponent::data), not author Page-JSON props —
         hence a data var ($card), like latest_posts' $post. url = a stored route path; image = a
         site meta field. Neither is author-injected Page-JSON, so they are not type:url props. --}}
    @if (count($items) > 0)
        <ul class="entry-grid__list">
            @foreach ($items as $card)
                <li class="entry-grid__card">
                    @if (! empty($card['image']))
                        {{-- Explicit width/height reserve space (no CLS); below-fold grid → lazy. --}}
                        <img class="entry-grid__image" src="{{ $card['image'] }}" alt="{{ $card['title'] ?? '' }}" width="480" height="320" loading="lazy">
                    @endif
                    <h3 class="entry-grid__title">
                        @if (! empty($card['url']))
                            <a href="{{ $card['url'] }}">{{ $card['title'] ?? '' }}</a>
                        @else
                            {{ $card['title'] ?? '' }}
                        @endif
                    </h3>
                    @if (! empty($card['sector']))
                        <span class="entry-grid__sector">{{ $card['sector'] }}</span>
                    @endif
                    @if (! empty($card['short_outcome']))
                        <p class="entry-grid__outcome">{{ $card['short_outcome'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="entry-grid__empty">Chưa có nội dung.</p>
    @endif
</section>
