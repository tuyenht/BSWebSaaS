{{-- Recursive menu list (design.md §13). Each node is {label, url, active, attrs, children}.
     The tree is depth-bounded by the resolver, so this self-include always terminates. A top-level
     item with children is a disclosure: a parent with no page of its own (#) is a button, one with
     a page keeps its link plus a toggle button. Its panel opens on hover, on focus, and on click,
     Enter or Space (storefront.js keeps aria-expanded true). --}}
<ul class="site-nav__list site-nav__list--level-{{ $level }}"@isset($panelId) id="{{ $panelId }}"@endisset>
    @foreach ($items as $item)
@if ($level === 0 && ! empty($item['children']))
        @php($panel = 'sm-'.$loop->index)
        <li class="site-nav__item site-nav__item--parent{{ \App\Modules\Theme\Chrome\StorefrontChrome::inTrail($item) ? ' is-trail' : '' }}" data-disclosure>
            @if ($item['url'] === '#')
                <button type="button" class="site-nav__link site-nav__toggle" aria-expanded="false" aria-controls="{{ $panel }}">{{ $item['label'] }}@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
            @else
                <a class="site-nav__link" href="{{ $item['url'] }}"
                    @if (! empty($item['active'])) aria-current="page" @endif
                    @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach
                >{{ $item['label'] }}</a><button type="button" class="site-nav__toggle site-nav__toggle--split" aria-expanded="false" aria-controls="{{ $panel }}" aria-label="{{ __('storefront.submenu_toggle', ['label' => $item['label']]) }}">@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 16])</button>
            @endif
            @include('theme::partials.menu-list', ['items' => $item['children'], 'level' => 1, 'panelId' => $panel])
        </li>
@else
        <li class="site-nav__item">
            <a class="site-nav__link" href="{{ $item['url'] }}"
                @if (! empty($item['active'])) aria-current="page" @endif
                @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach
            >{{ $item['label'] }}</a>
            @if (! empty($item['children']))
                @include('theme::partials.menu-list', ['items' => $item['children'], 'level' => $level + 1, 'panelId' => null])
            @endif
        </li>
@endif
    @endforeach
</ul>
