{{--
    "Chuyên mục" in a side column (a block slot only): the categories of the page's root branch, as
    links, with each one's post count after its name when `counts` is "show". With `source` = posts it
    lists the posts of the page's own category instead (a row with an arrow, the post being read dark
    and marked aria-current), e.g. the other capabilities beside a capability. Drawn from the page
    (`$sfSlot['categories']` or `$sfSlot['posts']`); nothing without any.
--}}
@php
    $sfFromPosts = ($props['source'] ?? null) === 'posts';
    $sfCounts = ! $sfFromPosts && ($props['counts'] ?? null) === 'show';
    $sfCategories = is_array($sfSlot[$sfFromPosts ? 'posts' : 'categories'] ?? null) ? $sfSlot[$sfFromPosts ? 'posts' : 'categories'] : [];
@endphp
@if ($sfCategories !== [])
@php
    // Each drawing gets its own heading id, so the block twice on one page never repeats one.
    $sfSlotN = (int) request()->attributes->get('sf_slot_heading', 0) + 1;
    request()->attributes->set('sf_slot_heading', $sfSlotN);
    $sfTitleId = 'slot-categories-title-'.$sfSlotN;
@endphp
<section class="slot-categories{{ $sfFromPosts ? ' slot-categories--posts' : '' }}{{ $sfCounts ? ' slot-categories--counts' : '' }}" aria-labelledby="{{ $sfTitleId }}">
    <h2 class="slot-title" id="{{ $sfTitleId }}">{{ ($props['heading'] ?? '') !== '' ? $props['heading'] : __('storefront.categories') }}</h2>
    <ul class="slot-categories__list">
@foreach ($sfCategories as $sfCategory)
@php
    $sfHref = \App\Modules\Shared\Support\SafeLink::href($sfCategory['url'] ?? null);
@endphp
        <li><a class="slot-categories__link" @if ($sfHref !== null) href="{{ $sfHref }}"@endif @if ($sfCategory['current'] ?? false) aria-current="page"@endif><span class="slot-categories__name">{{ $sfCategory['name'] }}</span>@if ($sfFromPosts)<span class="slot-categories__arrow" aria-hidden="true">→</span>@elseif ($sfCounts && ($sfCategory['count'] ?? null) !== null)<span class="slot-categories__count">{{ $sfCategory['count'] }}<span class="sr-only"> {{ __('storefront.category_count_label') }}</span></span>@endif</a></li>
@endforeach
    </ul>
</section>
@endif
