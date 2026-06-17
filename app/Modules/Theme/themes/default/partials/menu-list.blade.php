{{-- Recursive menu list (design.md §13). Each node is {label, url, active, attrs, children}.
     The tree is depth-bounded by the resolver, so this self-include always terminates. --}}
<ul class="site-nav__list site-nav__list--level-{{ $level }}">
    @foreach ($items as $item)
        <li class="site-nav__item">
            <a class="site-nav__link" href="{{ $item['url'] }}"
                @if (! empty($item['active'])) aria-current="page" @endif
                @foreach (($item['attrs'] ?? []) as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach
            >{{ $item['label'] }}</a>
            @if (! empty($item['children']))
                @include('theme::partials.menu-list', ['items' => $item['children'], 'level' => $level + 1])
            @endif
        </li>
    @endforeach
</ul>
