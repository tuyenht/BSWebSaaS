{{-- Storefront primary navigation (design.md §13). The menu is resolved server-side to a
     primitive tree (label/url/active/children) and rendered here: semantic, zero-JS, a11y
     (landmark + aria-current). Renders nothing when no menu is bound to the region, so an
     unconfigured site is never a dead-end. Values are Blade-escaped (URLs pre-sanitized). --}}
@if (! empty($menu))
    <header class="site-header">
        <nav class="site-nav" aria-label="Điều hướng chính">
            @include('theme::partials.menu-list', ['items' => $menu, 'level' => 0])
        </nav>
    </header>
@endif
