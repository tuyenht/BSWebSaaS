{{--
    Page title band (inner pages). When it leads the page it carries the page's single h1 (the
    layout skips its own — PageRenderer marks it `_lead`); a later band is an h2. The background is
    a real <img> (not a CSS background) so fetchpriority="high" reaches the LCP image; alt="" since
    the heading is its text. No image (or one whose URL is not a safe web link) → a solid ink band (with the diagonal pattern when `pattern` is on). The overlay darkens the photo by
    55–80% (default 60): at 55% white text over a white photo still reads 4.76:1, so the breadcrumb
    and the subtitle are white too. The band that leads the page carries the page's breadcrumb
    (`_crumbs`, set by PageRenderer or the list layout); a library photo comes with its srcset
    (server-built: the page's `srcsets`, or `listSrcset` from the list layout, never a stored prop).
--}}
@php
    $overlay = is_numeric($props['overlay'] ?? null) ? (int) $props['overlay'] : 60;
    $overlay = max(55, min(80, $overlay));
    $heading = ! empty($props['_lead']) ? 'h1' : 'h2';
    $image = \App\Modules\Shared\Support\SafeLink::href($props['image_url'] ?? null);
    $bandSrcset = $image !== null ? ($listSrcset ?? ($srcsets[$image] ?? null)) : null;
    $bandCrumbs = ! empty($props['_lead']) && is_array($props['_crumbs'] ?? null) ? $props['_crumbs'] : [];
    // The page's days (the layout with a table of contents): the day it took effect, and the day it
    // last changed when that is a later day (PageRenderer sets `_dates` on the leading band only).
    $bandDates = ! empty($props['_lead']) && is_array($props['_dates'] ?? null) ? $props['_dates'] : [];
    $bandDay = is_string($bandDates['date'] ?? null) ? $bandDates['date'] : '';
    $bandUpdated = is_string($bandDates['updated'] ?? null) ? $bandDates['updated'] : '';
    // Without a photo the band is a plain dark one; `pattern` = on adds the thin diagonal pattern; `mark` = check adds the gold tick.
    $bandPattern = $image === null && ($props['pattern'] ?? 'off') === 'on';
    $bandMark = ($props['mark'] ?? 'none') === 'check';
@endphp
<section class="page-band{{ $bandPattern ? ' page-band--pattern' : '' }}{{ $bandMark ? ' page-band--mark' : '' }}">
    @if ($image !== null)
        <img class="page-band__bg"
             src="{{ $image }}"
@if (is_string($bandSrcset) && $bandSrcset !== '')
             srcset="{{ $bandSrcset }}"
             sizes="100vw"
@endif
             alt=""
             width="1600"
             height="400"
             loading="{{ ! empty($props['_high']) ? 'eager' : 'lazy' }}"@if (! empty($props['_high'])) fetchpriority="high"@else decoding="async" fetchpriority="low"@endif>
        <div class="page-band__overlay" style="opacity:{{ $overlay / 100 }}"></div>
    @endif
    <div class="page-band__content">
@if ($bandCrumbs !== [])
        @include('theme::partials.sf-breadcrumb', ['crumbs' => $bandCrumbs, 'tone' => 'dark', 'class' => 'page-band__crumbs'])
@endif
@if ($bandMark)
        <span class="page-band__mark" aria-hidden="true">@include('theme::partials.icon', ['name' => 'check', 'stroke' => 3])</span>
@endif
        @if (! empty($props['title']))
            <{{ $heading }} class="page-band__title">{{ $props['title'] }}</{{ $heading }}>
        @endif
        @if (! empty($props['subtitle']))
            <p class="page-band__subtitle">{{ $props['subtitle'] }}</p>
        @endif
@if ($bandDay !== '')
        <p class="page-band__dates">{{ __('storefront.effective_date') }} <time datetime="{{ $bandDates['datetime'] ?? '' }}">{{ $bandDay }}</time>@if ($bandUpdated !== '') · {{ __('storefront.updated') }} <time datetime="{{ $bandDates['updatedDatetime'] ?? '' }}">{{ $bandUpdated }}</time>@endif</p>
@endif
    </div>
</section>
