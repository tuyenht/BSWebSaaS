{{--
    "Chuyên mục" in a side column (a block slot only): the categories of the page's root branch, as
    links, with no post counts. Drawn from the page (`$sfSlot['categories']`); nothing without any.
--}}
@php($sfCategories = is_array($sfSlot['categories'] ?? null) ? $sfSlot['categories'] : [])
@if ($sfCategories !== [])
<section class="slot-categories" aria-labelledby="slot-categories-title">
    <h2 class="slot-title" id="slot-categories-title">{{ ($props['heading'] ?? '') !== '' ? $props['heading'] : __('storefront.categories') }}</h2>
    <ul class="slot-categories__list">
@foreach ($sfCategories as $sfCategory)
@php($sfHref = \App\Modules\Shared\Support\SafeLink::href($sfCategory['url'] ?? null))
        <li><a class="slot-categories__link" @if ($sfHref !== null) href="{{ $sfHref }}"@endif @if ($sfCategory['current'] ?? false) aria-current="page"@endif>{{ $sfCategory['name'] }}</a></li>
@endforeach
    </ul>
</section>
@endif
