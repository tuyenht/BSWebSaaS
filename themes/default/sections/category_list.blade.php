{{--
    "Chuyên mục" in a side column (a block slot only): the categories of the page's root branch, as
    links, with no post counts. Drawn from the page (`$sfSlot['categories']`); nothing without any.
--}}
@php
    $sfCategories = is_array($sfSlot['categories'] ?? null) ? $sfSlot['categories'] : [];
@endphp
@if ($sfCategories !== [])
@php
    // Each drawing gets its own heading id, so the block twice on one page never repeats one.
    $sfSlotN = (int) request()->attributes->get('sf_slot_heading', 0) + 1;
    request()->attributes->set('sf_slot_heading', $sfSlotN);
    $sfTitleId = 'slot-categories-title-'.$sfSlotN;
@endphp
<section class="slot-categories" aria-labelledby="{{ $sfTitleId }}">
    <h2 class="slot-title" id="{{ $sfTitleId }}">{{ ($props['heading'] ?? '') !== '' ? $props['heading'] : __('storefront.categories') }}</h2>
    <ul class="slot-categories__list">
@foreach ($sfCategories as $sfCategory)
@php
    $sfHref = \App\Modules\Shared\Support\SafeLink::href($sfCategory['url'] ?? null);
@endphp
        <li><a class="slot-categories__link" @if ($sfHref !== null) href="{{ $sfHref }}"@endif @if ($sfCategory['current'] ?? false) aria-current="page"@endif>{{ $sfCategory['name'] }}</a></li>
@endforeach
    </ul>
</section>
@endif
