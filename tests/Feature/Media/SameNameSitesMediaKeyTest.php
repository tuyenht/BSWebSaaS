<?php

use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Two websites with the same name used to file their pictures in the same folder (the slug of the
 * name), so the same file uploaded by both got the same storage key: emptying one website's trash
 * deleted the other website's picture. Keys now start with each website's own permanent code.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
});

it('gives two websites with the same name different keys, so one\'s trash never deletes the other\'s file', function (): void {
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['name' => 'A']);   // same name as site 1
    $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);

    $upload = fn (int $site): Media => app(Tenancy::class)->enter($site, fn (): Media => app(UploadMedia::class)->handle(
        UploadedFile::fake()->createWithContent('logo.png', $bytes),
    ));
    $first = $upload(1);
    $second = $upload(2);

    expect($first->storage_key)->toStartWith(siteCode(1).'/')
        ->and($second->storage_key)->toStartWith(siteCode(2).'/');

    app(Tenancy::class)->enter(1, fn () => Media::query()->findOrFail($first->id)->forceDelete());

    Storage::disk('r2')->assertExists($second->storage_key);
});
