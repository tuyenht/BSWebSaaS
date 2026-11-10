{{--
    A section's centered head: its h2, a short decoration (CSS only, no image request) and an optional
    line of description. Nothing renders when both texts are empty. `headId` (optional) is the h2's id, for
    a section labelled by it. The decoration is the site's heading decoration (HeadingDecor): the
    block's own `heading_decor` choice when the including view has `$props`, else the site's setting;
    with neither set it is the short rule headings always had.
--}}
@if (($heading ?? '') !== '' || ($description ?? '') !== '')
@php($sfDecor = app(\App\Modules\Theme\Theming\HeadingDecor::class)->classes($props['heading_decor'] ?? null))
<div class="sf-section-head">
@if (($heading ?? '') !== '')
    <h2 class="sf-section-head__title"@if (($headId ?? '') !== '') id="{{ $headId }}"@endif>{{ $heading }}</h2>
@endif
@if ($sfDecor === null)
    <span class="sf-section-head__rule" aria-hidden="true"></span>
@elseif ($sfDecor !== '')
    <span class="{{ $sfDecor }}" aria-hidden="true"></span>
@endif
@if (($description ?? '') !== '')
    <p class="sf-section-head__text">{{ $description }}</p>
@endif
</div>
@endif
