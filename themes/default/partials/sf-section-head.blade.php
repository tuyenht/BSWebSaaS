{{--
    A section's head: its h2, a short decoration (CSS only, no image request) and an optional
    line of description, centred by default or at the left edge (the block's `heading_align`, then
    the decoration follows the title unless the site keeps it in the middle). Nothing renders when both texts are empty. `headId` (optional) is the h2's id, for
    a section labelled by it. The decoration is the site's heading decoration (HeadingDecor): the
    block's own `heading_decor` choice when the including view has `$props`, else the site's setting;
    with neither set it is the short rule headings always had.
--}}
@if (($heading ?? '') !== '' || ($description ?? '') !== '')
@php($sfAlign = ($props['heading_align'] ?? null) === 'left' ? 'left' : 'center')
@php($sfDecor = app(\App\Modules\Theme\Theming\HeadingDecor::class)->classes($props['heading_decor'] ?? null, $sfAlign))
<div class="sf-section-head{{ $sfAlign === 'left' ? ' sf-section-head--left' : '' }}">
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
