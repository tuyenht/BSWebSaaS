<?php

use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/*
 * counters:reconcile recounts every website's running totals from the rows they count and corrects
 * the ones that drifted, logging each correction; a second run finds nothing. Each website is
 * counted inside its own tenant context, so one website's files never reach another's totals.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** Files made straight on the model: no action counts them, as a write path that missed its counter. */
function rcFile(int $site, string $name, int $size, bool $trashed = false): Media
{
    return app(Tenancy::class)->enter($site, function () use ($site, $name, $size, $trashed): Media {
        $media = Media::query()->create([
            'collection' => 'library', 'slug' => $name, 'hash' => hash('sha256', $site.$name),
            'storage_key' => "s{$site}/library/{$name}.png", 'mime' => 'image/png', 'size' => $size,
        ]);
        if ($trashed) {
            $media->delete();
        }

        return $media;
    });
}

/** @return array<string, int> */
function rcTotals(int $site): array
{
    return app(Tenancy::class)->enter($site, fn (): array => app(SiteCounters::class)->read([MediaStorage::BYTES, MediaStorage::TRASH])['values']);
}

it('fills and corrects drifted totals per website, logs each drift, and finds nothing the second time', function (): void {
    rcFile(1, 'a', 1000);
    rcFile(1, 'b', 250, trashed: true);
    rcFile(1, 'c', 5);
    rcFile(2, 'z', 77);
    // site 2 counted too much (a missed purge); site 1 was never counted
    app(Tenancy::class)->enter(2, fn () => app(SiteCounters::class)->add(MediaStorage::BYTES, 9000));

    Log::spy();
    $this->artisan('counters:reconcile', ['--chunk' => 2])->assertSuccessful(); // chunks of 2: three files take two steps

    expect(rcTotals(1))->toBe([MediaStorage::BYTES => 1255, MediaStorage::TRASH => 250])
        ->and(rcTotals(2))->toBe([MediaStorage::BYTES => 77, MediaStorage::TRASH => 0]);
    Log::shouldHaveReceived('warning')->with('Site counter drifted', ['site_id' => 1, 'metric' => MediaStorage::BYTES, 'from' => 0, 'to' => 1255])->once();
    Log::shouldHaveReceived('warning')->with('Site counter drifted', ['site_id' => 2, 'metric' => MediaStorage::BYTES, 'from' => 9000, 'to' => 77])->once();

    $this->artisan('counters:reconcile')->expectsOutputToContain('corrected 0')->assertSuccessful();
});

it('leaves totals that already match untouched', function (): void {
    rcFile(1, 'a', 400);
    app(Tenancy::class)->enter(1, fn () => app(SiteCounters::class)->add(MediaStorage::BYTES, 400));

    Log::spy();
    $this->artisan('counters:reconcile')->expectsOutputToContain('corrected 0')->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
    expect(rcTotals(1))->toBe([MediaStorage::BYTES => 400, MediaStorage::TRASH => 0]);
});

it('runs the recount every night', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'counters:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 20 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->withoutOverlapping)->toBeTrue();
});
