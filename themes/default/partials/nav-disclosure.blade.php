{{--
    Menu of the rich header, desktop and mobile alike (CSS sets the look by the nav's variant). A
    top-level item with children is a disclosure — no role=menu: a parent with no page of its own
    (#) is a button with the label, one with a page keeps its link plus a chevron button. The
    panel opens on click, Enter or Space and closes on Esc, outside click or focus leaving
    (storefront.js); hover and keyboard focus open it without a script too. Deeper levels are
    plain nested lists; a leaf whose link is only # (no page yet) is left out. An item on the way to
    the current page is marked (is-trail, aria-current="true"): the parent of the current page, and the
    section a post or product sits in (StorefrontChrome::withTrail). $idPrefix keeps the desktop and mobile panel ids apart.
    Item options: an icon, a badge and (below the top level) a description go in the label
    (StorefrontChrome::menuLabel); "Hiện trên" hides the row on the other device by class; a
    top-level "nút nổi bật" is drawn as a button; a top-level item set to a large panel lays its
    children out in columns (--sf-mega-cols), and stays a plain list in the phone drawer.
    In the phone drawer ($drawer) every parent on the first and second level is ONE button (label and
    chevron, the whole row toggles it); a parent with a page of its own starts its panel with a
    "Tổng quan <name>" row linking to that page ($overview, StorefrontChrome::drawerOverview), which carries
    the current-page mark when that page is the one shown. The branch holding the current page is open on
    render and marked (is-trail); below the top level only the current page itself is marked. Item icons
    are drawn at 20px. The desktop bar's submenus always start closed.
--}}
@php($sfDrawer = ! empty($drawer))
<ul class="sf-nav__list{{ $depth > 0 ? ' sf-nav__nested' : '' }}">
    @if (! empty($overview))
        <li class="sf-nav__item"><a class="sf-nav__sublink" href="{{ $overview['url'] }}"@if ($overview['active']) aria-current="page"@endif><span class="sf-nav__t">{{ $overview['label'] }}</span></a></li>
    @endif
    @foreach ($items as $item)
        @if ($sfDrawer && $depth < 2 && ! empty($item['children']))
            @php($panel = $idPrefix.'-'.$loop->index)
            @php($open = ! empty($item['active']) || \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item))
            <li class="sf-nav__item sf-nav__item--parent{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuItemClass($item) }}{{ $open ? ' is-open' : '' }}" data-disclosure>
                <button type="button" class="{{ $depth === 0 ? 'sf-nav__link' : 'sf-nav__sublink' }} sf-nav__toggle{{ $open ? ' is-trail' : '' }}"@if ($open) aria-current="true"@endif aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $panel }}">{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($item, false, true) }}<span class="sf-nav__caret" aria-hidden="true">@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</span></button>
                <div class="sf-nav__panel" id="{{ $panel }}">
                    @include('theme::partials.nav-disclosure', ['items' => $item['children'], 'depth' => $depth + 1, 'idPrefix' => $panel, 'overview' => \App\Modules\Theme\Chrome\StorefrontChrome::drawerOverview($item)])
                </div>
            </li>
        @elseif ($depth === 0 && ! empty($item['children']))
            @php($panel = $idPrefix.'-'.$loop->index)
            @php($trail = \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item) ? ' is-trail' : '')
            <li class="sf-nav__item sf-nav__item--parent{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuItemClass($item) }}" data-disclosure>
                @if ($item['url'] === '#')
                    <button type="button" class="sf-nav__link sf-nav__toggle{{ $trail }}"@if ($trail !== '') aria-current="true"@endif aria-expanded="false" aria-controls="{{ $panel }}"><span>{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($item) }}</span>@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
                @else
                    <a class="sf-nav__link{{ $trail }}" href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @elseif ($trail !== '') aria-current="true" @endif @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($item) }}</a><button type="button" class="sf-nav__chev{{ $trail }}" aria-expanded="false" aria-controls="{{ $panel }}" aria-label="{{ __('storefront.submenu_toggle', ['label' => $item['label']]) }}">@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
                @endif
                <div class="sf-nav__panel{{ ! empty($item['mega']) ? ' sf-nav__panel--mega' : '' }}" id="{{ $panel }}"@if (! empty($item['mega'])) style="--sf-mega-cols: {{ (int) $item['mega'] }}"@endif>
                    @include('theme::partials.nav-disclosure', ['items' => $item['children'], 'depth' => 1, 'overview' => null])
                </div>
            </li>
        @elseif ($item['url'] !== '#' || ! empty($item['children']))
            {{-- The drawer marks the trail on its top level only: inside an open group the current page alone stands out. --}}
            @php($trail = (! $sfDrawer || $depth === 0) && \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item) ? ' is-trail' : '')
            <li class="sf-nav__item{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuItemClass($item) }}">
                <a class="{{ $depth === 0 ? 'sf-nav__link' : 'sf-nav__sublink' }}{{ $depth === 0 && ! empty($item['cta']) ? ' sf-nav__link--cta' : '' }}{{ $trail }}" href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @elseif ($trail !== '') aria-current="true" @endif @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($item, $depth > 0, $sfDrawer) }}</a>
                @if (! empty($item['children']))
                    @include('theme::partials.nav-disclosure', ['items' => $item['children'], 'depth' => $depth + 1, 'overview' => null])
                @endif
            </li>
        @endif
    @endforeach
</ul>
