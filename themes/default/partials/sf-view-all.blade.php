{{-- The "view all" button under a card section: only when both its label and a safe link are set. --}}
@php($sfViewAllUrl = \App\Modules\Shared\Support\SafeLink::href($url ?? null))
@if (($label ?? '') !== '' && $sfViewAllUrl !== null)
<div class="sf-view-all">
    <a class="sf-view-all__link" href="{{ $sfViewAllUrl }}">{{ $label }}</a>
</div>
@endif
