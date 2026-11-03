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

it('gives the click-to-play player and the video page of a youtube url, through nocookie', function (string $url): void {
    expect(VideoEmbed::player($url))->toBe([
        'provider' => 'youtube',
        'src' => 'https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&autoplay=1',
        'watch' => 'https://www.youtube.com/watch?v=SxwkhMy4Ywo',
    ]);
})->with([
    'https://www.youtube.com/watch?v=SxwkhMy4Ywo',
    'https://youtu.be/SxwkhMy4Ywo',
    'https://www.youtube.com/embed/SxwkhMy4Ywo?rel=0',
    'https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&autoplay=1&mute=1',
    'https://www.youtube.com/watch?v=SxwkhMy4Ywo&t=abc',   // not whole seconds: ignored
    'https://www.youtube.com/embed/SxwkhMy4Ywo?start=0',
]);

it('keeps the second a youtube video starts at', function (string $url): void {
    expect(VideoEmbed::player($url))->toBe([
        'provider' => 'youtube',
        'src' => 'https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&autoplay=1&start=42',
        'watch' => 'https://www.youtube.com/watch?v=SxwkhMy4Ywo&t=42s',
    ]);
})->with([
    '  https://m.youtube.com/watch?v=SxwkhMy4Ywo&t=42s  ',
    'https://www.youtube.com/embed/SxwkhMy4Ywo?start=42',
]);

it('keeps the private hash an unlisted vimeo video needs, and drops one of the wrong shape', function (): void {
    $player = ['provider' => 'vimeo', 'src' => 'https://player.vimeo.com/video/123456789?autoplay=1&h=abc123def0', 'watch' => 'https://vimeo.com/123456789/abc123def0'];

    expect(VideoEmbed::player('https://vimeo.com/123456789/abc123def0'))->toBe($player)
        ->and(VideoEmbed::player('https://player.vimeo.com/video/123456789?h=abc123def0'))->toBe($player)
        ->and(VideoEmbed::player('https://player.vimeo.com/video/123456789?h="><x'))->toBe([
            'provider' => 'vimeo', 'src' => 'https://player.vimeo.com/video/123456789?autoplay=1', 'watch' => 'https://vimeo.com/123456789',
        ]);
});

it('gives the click-to-play player and the video page of a vimeo url', function (string $url): void {
    expect(VideoEmbed::player($url))->toBe([
        'provider' => 'vimeo',
        'src' => 'https://player.vimeo.com/video/123456789?autoplay=1',
        'watch' => 'https://vimeo.com/123456789',
    ]);
})->with(['https://vimeo.com/123456789', 'https://player.vimeo.com/video/123456789?autoplay=1&muted=1']);

it('gives no player for an address it does not recognise', function (string $url): void {
    expect(VideoEmbed::player($url))->toBeNull();
})->with(['', 'https://evil.example.com/embed/xxx', 'https://www.youtube.com/', 'javascript:alert(1)', '//www.youtube.com/embed/SxwkhMy4Ywo']);
