{{--
    Menu of the rich header, desktop and mobile alike (CSS sets the look by the nav's variant). A
    top-level item with children is a disclosure — no role=menu: a parent with no page of its own
    (#) is a button with the label, one with a page keeps its link plus a chevron button. The
    panel opens on click, Enter or Space and closes on Esc, outside click or focus leaving
    (storefront.js); hover and keyboard focus open it without a script too. Deeper levels are
    plain nested lists; a leaf whose link is only # (no page yet) is left out. The parent of the
    current page is marked (is-trail). $idPrefix keeps the desktop and mobile panel ids apart.
--}}
<ul class="sf-nav__list{{ $depth > 0 ? ' sf-nav__nested' : '' }}">
    @foreach ($items as $item)
        @if ($depth === 0 && ! empty($item['children']))
            @php($panel = $idPrefix.'-'.$loop->index)
            @php($trail = \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item) ? ' is-trail' : '')
            <li class="sf-nav__item sf-nav__item--parent" data-disclosure>
                @if ($item['url'] === '#')
                    <button type="button" class="sf-nav__link sf-nav__toggle{{ $trail }}" aria-expanded="false" aria-controls="{{ $panel }}"><span>{{ $item['label'] }}</span>@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
                @else
                    <a class="sf-nav__link{{ $trail }}" href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @endif @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>{{ $item['label'] }}</a><button type="button" class="sf-nav__chev{{ $trail }}" aria-expanded="false" aria-controls="{{ $panel }}" aria-label="{{ __('storefront.submenu_toggle', ['label' => $item['label']]) }}">@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
                @endif
                <div class="sf-nav__panel" id="{{ $panel }}">
                    @include('theme::partials.nav-disclosure', ['items' => $item['children'], 'depth' => 1])
                </div>
            </li>
        @elseif ($item['url'] !== '#' || ! empty($item['children']))
            <li class="sf-nav__item">
                <a class="{{ $depth === 0 ? 'sf-nav__link' : 'sf-nav__sublink' }}" href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @endif @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>{{ $item['label'] }}</a>
                @if (! empty($item['children']))
                    @include('theme::partials.nav-disclosure', ['items' => $item['children'], 'depth' => $depth + 1])
                @endif
            </li>
        @endif
    @endforeach
</ul>
