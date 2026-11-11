{{--
    The breadcrumb "Home / … / this page", shared by the title band, list pages, posts and products.
    `crumbs` is the trail the controller built (Breadcrumbs) — the same one the head prints as
    BreadcrumbList JSON-LD, so the two always list the same levels. The last level is the page
    itself on a list or information page (`current`): plain text with aria-current, not a link; a detail
    page's trail ends at its category, a link. `tone`: light (on the page) or dark (on a band
    or a photo). Names wrap onto more lines, never clip. A trail of one level prints nothing.
--}}
@php
    $sfCrumbs = array_values(array_filter($crumbs ?? [], fn (mixed $crumb): bool => is_array($crumb) && is_string($crumb['name'] ?? null) && $crumb['name'] !== ''));
@endphp
@if (count($sfCrumbs) > 1)
<nav class="sf-crumbs{{ ($tone ?? 'light') === 'dark' ? ' sf-crumbs--dark' : '' }}{{ ! empty($class) ? ' '.$class : '' }}" aria-label="{{ __('storefront.breadcrumb') }}">
    <ol class="sf-crumbs__list">
@foreach ($sfCrumbs as $crumb)
@php($sfIsCurrent = (bool) ($crumb['current'] ?? $loop->last))
@php($sfCrumbHref = $sfIsCurrent ? null : \App\Modules\Shared\Support\SafeLink::href($crumb['href'] ?? null))
@if ($sfCrumbHref !== null)
        <li class="sf-crumbs__item"><a class="sf-crumbs__link" href="{{ $sfCrumbHref }}">{{ $crumb['name'] }}</a></li>
@else
        <li class="sf-crumbs__item"><span class="sf-crumbs__current"@if ($sfIsCurrent) aria-current="page"@endif>{{ $crumb['name'] }}</span></li>
@endif
@endforeach
    </ol>
</nav>
@endif
