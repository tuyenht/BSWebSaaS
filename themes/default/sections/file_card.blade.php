{{--
    A document card of a side column (a block slot only): a small badge (PDF by default), the title
    and a line under it, the whole card one link to the page or file the editor names. Nothing is
    drawn without a title or without a link that resolves.
--}}
@php
    $sfLink = \App\Modules\Theme\Components\ButtonLink::of($props, 'url');
    $sfTitle = \App\Modules\Theme\Components\SectionProps::text($props, 'title');
    $sfBadge = \App\Modules\Theme\Components\SectionProps::text($props, 'badge');
    $sfLine = \App\Modules\Theme\Components\SectionProps::text($props, 'text');
@endphp
@if ($sfTitle !== '' && $sfLink !== null)
<a class="slot-file" href="{{ $sfLink['href'] }}"@if ($sfLink['blank']) target="_blank" rel="noopener"@elseif ($sfLink['download']) download=""@endif>
    <span class="slot-file__badge" aria-hidden="true">{{ $sfBadge !== '' ? $sfBadge : 'PDF' }}</span>
    <span class="slot-file__text">
        <span class="slot-file__title">{{ $sfTitle }}@if ($sfLink['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</span>
@if ($sfLine !== '')
        <span class="slot-file__line">{{ $sfLine }}</span>
@endif
    </span>
</a>
@endif
