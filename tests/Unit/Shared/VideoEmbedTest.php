<?php

use App\Modules\Shared\Support\VideoEmbed;

/*
 * VideoEmbed::html — every common youtube/vimeo URL shape becomes the ONE canonical
 * responsive embed (.video-embed wrapper, youtube-nocookie, lazy, autoplay⇒muted);
 * anything else returns null so the dialog can show a friendly error.
 */

it('embeds every common youtube url shape via the nocookie domain', function (string $url): void {
    $html = VideoEmbed::html($url);

    expect($html)->not->toBeNull()
        ->and($html)->toContain('class="video-embed"')
        ->and($html)->toContain('https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0')
        ->and($html)->toContain('loading="lazy"')
        ->and($html)->toContain('allowfullscreen');
})->with([
    'https://www.youtube.com/watch?v=SxwkhMy4Ywo',
    'https://youtu.be/SxwkhMy4Ywo',
    'https://www.youtube.com/shorts/SxwkhMy4Ywo',
    'https://www.youtube.com/embed/SxwkhMy4Ywo',
    'https://m.youtube.com/watch?v=SxwkhMy4Ywo&t=42s',
]);

it('pairs autoplay with mute — browsers block audible autoplay', function (): void {
    // & is entity-encoded inside the src attribute (e()) — correct HTML.
    expect(VideoEmbed::html('https://www.youtube.com/watch?v=SxwkhMy4Ywo', autoplay: true))
        ->toContain('autoplay=1&amp;mute=1');

    expect(VideoEmbed::html('https://vimeo.com/123456789', autoplay: true))
        ->toContain('autoplay=1&amp;muted=1');
});

it('embeds vimeo urls through the player domain', function (): void {
    expect(VideoEmbed::html('https://vimeo.com/123456789'))
        ->toContain('https://player.vimeo.com/video/123456789');

    expect(VideoEmbed::html('https://player.vimeo.com/video/123456789'))
        ->toContain('https://player.vimeo.com/video/123456789');
});

it('rejects anything that is not a recognizable youtube/vimeo url', function (string $url): void {
    expect(VideoEmbed::html($url))->toBeNull();
})->with([
    'https://evil.example.com/embed/xxx',
    'https://www.youtube.com/',            // no video id
    'javascript:alert(1)',
    'not-a-url',
    'https://vimeo.com/about',             // non-numeric path
]);
