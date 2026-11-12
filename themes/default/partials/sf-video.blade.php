{{--
    Click-to-load video (the video block, and videos stored in rich text through VideoFacades).
    Nothing is requested from the video host before the visitor presses play: the box shows the
    site's own poster (or a neutral placeholder — never the host's thumbnail), and the player's
    address waits in data-src. sf-video.js, loaded only on a page with a video, turns the link into
    a button and, on the press, swaps the box for the player. Without the script the link opens the
    video's own page.
    $video: VideoEmbed::player() · $title: the player frame's name · $poster/$srcset: optional ·
    $high: the poster is the page's one first-screen image (the video block leads the page).
--}}
<div class="sf-video{{ empty($poster) ? ' sf-video--blank' : '' }}" data-sf-video data-src="{{ $video['src'] }}" data-title="{{ $title }}">
@if (! empty($poster))
    <img class="sf-video__poster" src="{{ $poster }}"@if (! empty($srcset)) srcset="{{ $srcset }}" sizes="(min-width: 60rem) 56rem, 100vw"@endif alt="" width="1600" height="900"@if (! empty($high)) loading="eager" fetchpriority="high"@else loading="lazy" decoding="async" fetchpriority="low"@endif>
@endif
    <a class="sf-video__play" href="{{ $video['watch'] }}">
        <span class="sf-video__icon" aria-hidden="true">@include('theme::partials.sf-icon', ['name' => 'play', 'size' => 32])</span>
        <span class="sf-video__notice">{{ __('storefront.video_play_'.$video['provider']) }}</span>
        <span class="sr-only">: {{ $title }}</span>
    </a>
</div>
