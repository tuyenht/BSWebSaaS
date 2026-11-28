{{-- One video of the product's picture strip: its thumbnail, the «Video» chip and its name. Needs $video, $j (its place among the videos) and $editShared. --}}
@php
    $vName = $video['title'] !== '' ? $video['title'] : __('catalog.ed.video_unnamed');
    $vAria = $video['title'] !== '' ? __('catalog.ed.video_aria', ['i' => $j + 1, 'title' => $video['title']]) : __('catalog.ed.video_aria_plain', ['i' => $j + 1]);
@endphp
<div wire:key="video-{{ $video['yt'] }}" class="ped-im" role="listitem" x-bind:data-sel="selV === {{ $j }} ? '1' : '0'">
    <button type="button" class="ped-imb" data-vid-i="{{ $j }}" title="{{ $vName }}" aria-label="{{ $vAria }}"
            @if ($editShared) aria-pressed="false" x-bind:aria-pressed="(selV === {{ $j }}).toString()" x-on:click="pickV({{ $j }})" @endif
            x-on:keydown="key($event)">
        <span class="ped-imf ped-imv">
            <img src="https://i.ytimg.com/vi/{{ $video['yt'] }}/hqdefault.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-full object-cover">
        </span>
    </button>
    <x-bs-admin::badge color="info" soft class="ped-main self-start"><x-bs-admin::icon name="ri-play-circle" class="size-3.5" />{{ __('catalog.ed.video_chip') }}</x-bs-admin::badge>
    <span class="ped-imn" title="{{ $vName }}">{{ $vName }}</span>
</div>
