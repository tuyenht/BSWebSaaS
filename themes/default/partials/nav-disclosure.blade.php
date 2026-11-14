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
    In the phone drawer ($drawer) the parent on the way to the current page is open on render (as the
    product tree is), item icons are drawn at 20px and the current page ends with a check mark (aria-hidden:
    aria-current says it). The desktop bar's submenus always start closed.
--}}
@php($sfDrawer = ! empty($drawer))
<ul class="sf-nav__list{{ $depth > 0 ? ' sf-nav__nested' : '' }}">
    @foreach ($items as $item)
        @if ($depth === 0 && ! empty($item['children']))
            @php($panel = $idPrefix.'-'.$loop->index)
            @php($trail = \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item) ? ' is-trail' : '')
            @php($open = $sfDrawer && $trail !== '')
            <li class="sf-nav__item sf-nav__item--parent{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuItemClass($item) }}{{ $open ? ' is-open' : '' }}" data-disclosure>
                @if ($item['url'] === '#')
                    <button type="button" class="sf-nav__link sf-nav__toggle{{ $trail }}"@if ($trail !== '') aria-current="true"@endif aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $panel }}"><span>{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($item, false, $sfDrawer) }}</span>@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
                @else
                    <a class="sf-nav__link{{ $trail }}" href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @elseif ($trail !== '') aria-current="true" @endif @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($item, false, $sfDrawer) }}@if ($sfDrawer && ! empty($item['active']))@include('theme::partials.sf-icon', ['name' => 'check', 'size' => 20, 'iconClass' => 'sf-nav__check'])@endif</a><button type="button" class="sf-nav__chev{{ $trail }}" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $panel }}" aria-label="{{ __('storefront.submenu_toggle', ['label' => $item['label']]) }}">@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
                @endif
                <div class="sf-nav__panel{{ ! empty($item['mega']) ? ' sf-nav__panel--mega' : '' }}" id="{{ $panel }}"@if (! empty($item['mega'])) style="--sf-mega-cols: {{ (int) $item['mega'] }}"@endif>
                    @include('theme::partials.nav-disclosure', ['items' => $item['children'], 'depth' => 1])
                </div>
            </li>
        @elseif ($item['url'] !== '#' || ! empty($item['children']))
            @php($trail = \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item) ? ' is-trail' : '')
            <li class="sf-nav__item{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuItemClass($item) }}">
                <a class="{{ $depth === 0 ? 'sf-nav__link' : 'sf-nav__sublink' }}{{ $depth === 0 && ! empty($item['cta']) ? ' sf-nav__link--cta' : '' }}{{ $trail }}" href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @elseif ($trail !== '') aria-current="true" @endif @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>{{ \App\Modules\Theme\Chrome\StorefrontChrome::menuLabel($item, $depth > 0, $sfDrawer) }}@if ($sfDrawer && ! empty($item['active']))@include('theme::partials.sf-icon', ['name' => 'check', 'size' => 20, 'iconClass' => 'sf-nav__check'])@endif</a>
                @if (! empty($item['children']))
                    @include('theme::partials.nav-disclosure', ['items' => $item['children'], 'depth' => $depth + 1])
                @endif
            </li>
        @endif
    @endforeach
</ul>
