{{--
    A section's centered head: its h2, a short CSS rule (no image request) and an optional line of
    description. Nothing renders when both texts are empty.
--}}
@if (($heading ?? '') !== '' || ($description ?? '') !== '')
<div class="sf-section-head">
@if (($heading ?? '') !== '')
    <h2 class="sf-section-head__title">{{ $heading }}</h2>
@endif
    <span class="sf-section-head__rule" aria-hidden="true"></span>
@if (($description ?? '') !== '')
    <p class="sf-section-head__text">{{ $description }}</p>
@endif
</div>
@endif
