<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Services\SiteCounters;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * «Dung lượng đã dùng» on the media library (design board 12): the stored total against the plan's
 * ceiling, read in one statement with the page. ok under 80 %, warn from 80 %, full at the ceiling —
 * full only while the storage switch refuses uploads; with it off a website above its plan sees the
 * warn card with its real figures.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    config(['media.storage_enforce' => false]);
});

/** Site 1: a ceiling of $limitMb (its own override) and $usedMb stored, one file listed. */
function lsSetUp(int $limitMb, float $usedMb, float $trashMb = 0): void
{
    app(Tenancy::class)->enter(1, function () use ($limitMb, $usedMb, $trashMb): void {
        SiteFeature::query()->create(['feature_key' => MediaStorage::FEATURE, 'value' => $limitMb]);
        app(SiteCounters::class)->add(MediaStorage::BYTES, (int) round($usedMb * 1048576));
        app(SiteCounters::class)->add(MediaStorage::TRASH, (int) round($trashMb * 1048576));
        Media::query()->create(['collection' => 'library', 'slug' => 'anh', 'hash' => str_repeat('a', 64),
            'storage_key' => 's1/library/anh.png', 'original_filename' => 'anh.png', 'mime' => 'image/png', 'size' => 1024]);
    });
}

function lsMember(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['media.view', 'media.upload', 'media.delete.any']));

    return $user->fresh();
}

it('shows the level the board draws at 79, 80 and 100 per cent', function (float $usedMb, bool $enforce, string $level, string $status): void {
    config(['media.storage_enforce' => $enforce]);
    lsSetUp(100, $usedMb, 12.5);

    $html = $this->actingAs(lsMember())->get('http://a.test/admin/media')->assertOk()->getContent();

    expect($html)->toContain('data-lv="'.$level.'"', e($status), 'aria-valuenow="'.min(100, (int) round($usedMb)).'"')
        ->and(substr_count($html, 'role="progressbar"'))->toBe($level === 'ok' ? 2 : 1); // ok: card (narrow) + column meter (wide)
    if ($level === 'full') {
        expect($html)->toContain('id="tv-full"', e(__('media.lib.st_full_off', ['trash' => '12,5 MB'])), e(__('media.lib.st_up_tip')));
    } else {
        expect($html)->not->toContain('id="tv-full"')->not->toContain(e(__('media.lib.st_full_title')));
    }
    expect(str_contains($html, e(__('media.lib.st_open_trash'))))->toBe($level === 'warn');
})->with([
    '79 %' => [79.0, false, 'ok', 'Còn 21 MB.'],
    '80 %' => [80.0, false, 'warn', 'Sắp đầy — còn 20 MB.'],
    '100 %, switch on' => [100.0, true, 'full', 'Đã dùng hết 100 MB.'],
    '100 %, switch off' => [100.0, false, 'warn', 'Đã dùng hết 100 MB.'],
    'past the plan, switch off' => [612.0, false, 'warn', 'Đã dùng hết 100 MB.'],
]);

it('writes the figures in megabytes the Vietnamese way', function (): void {
    lsSetUp(500, 312.44);

    $html = $this->actingAs(lsMember())->get('http://a.test/admin/media')->assertOk()->getContent();

    expect($html)->toContain('<b class="font-semibold">312,4 MB</b> / 500 MB', 'aria-valuetext="312,4 MB trên 500 MB"', e('Còn 187,6 MB.'));
});

it('reads the site’s own ceiling over the plan’s', function (): void {
    expect(Library::storageMeter(['used' => 10 * 1048576, 'trash' => 0, 'limit' => 12 * 1048576], false)['level'])->toBe('warn')
        ->and(Library::storageMeter(['used' => 10 * 1048576, 'trash' => 0, 'limit' => 500 * 1048576], false)['level'])->toBe('ok');

    lsSetUp(12, 10); // the plan default is 500 MB; the override says 12
    $this->actingAs(lsMember())->get('http://a.test/admin/media')->assertOk()->assertSee('data-lv="warn"', false)->assertSee('/ 12 MB', false);
});

it('opens the Trash from the card', function (): void {
    lsSetUp(100, 90);
    $member = lsMember();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($member)->test(Library::class)
        ->call('openUpload')->call('openTrash')
        ->assertSet('status', 'trash')->assertSet('uploadOpen', false));
});

it('keeps the library route within one query of its budget with the meter on', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'editor', ['media.view']);
        foreach (range(1, 10) as $i) {
            Media::query()->create(['collection' => 'library', 'slug' => "a{$i}", 'hash' => hash('sha256', "asset-{$i}"),
                'storage_key' => "a/library/asset-{$i}.png", 'original_filename' => "asset-{$i}.png", 'mime' => 'image/png', 'size' => 1024]);
        }
    });

    $html = '';
    $count = countQueries(function () use ($viewer, &$html): void {
        $html = (string) $this->actingAs($viewer)->get('http://a.test/admin/media')->assertOk()->getContent();
    });

    expect($html)->toContain('role="progressbar"')
        // measured 23 before the meter, 24 with it: the meter reads both totals and the ceiling in one statement
        ->and($count)->toBeLessThanOrEqual(24);
});
