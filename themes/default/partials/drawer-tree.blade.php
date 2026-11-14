{{--
    The phone menu's "Sản phẩm" tab: the product category tree (DrawerProducts). Every row with children,
    on the first and the second level alike, is a disclosure: a button with the name and a chevron whose
    panel starts with "Xem tất cả <name>" (the row's own page) and lists the children, one step further in.
    The way to the page the visitor is on is open on render (is-open, is-trail) and that page is
    aria-current="page". Panels are opened by storefront.js like the menu's; a nested one never closes the
    panel around it. $idPrefix keeps the panel ids apart (a nested panel's prefix is its parent's id). Names are Blade-escaped.
--}}
<ul class="sf-nav__list{{ $depth > 0 ? ' sf-nav__nested' : '' }}">
    @foreach ($items as $item)
        @if (! empty($item['children']))
            @php($panel = $idPrefix.'-'.$depth.'-'.$loop->index)
            @php($open = \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item))
            <li class="sf-nav__item sf-nav__item--parent{{ $open ? ' is-open' : '' }}" data-disclosure>
                <button type="button" class="{{ $depth === 0 ? 'sf-nav__link' : 'sf-nav__sublink' }} sf-nav__toggle{{ $open ? ' is-trail' : '' }}"@if ($open) aria-current="true"@endif aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $panel }}"><span>{{ $item['label'] }}</span>@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
                <div class="sf-nav__panel" id="{{ $panel }}">
                    @include('theme::partials.drawer-tree', ['items' => $item['children'], 'depth' => $depth + 1, 'idPrefix' => $panel])
                </div>
            </li>
        @else
            <li class="sf-nav__item">
                <a class="{{ $depth === 0 ? 'sf-nav__link' : 'sf-nav__sublink' }}" href="{{ $item['url'] }}"@if (! empty($item['active'])) aria-current="page"@endif>{{ $item['label'] }}</a>
            </li>
        @endif
    @endforeach
</ul>
