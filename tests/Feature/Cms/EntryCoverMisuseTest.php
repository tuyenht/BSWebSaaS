<?php

declare(strict_types=1);

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryCover;
use Illuminate\Support\Facades\Log;

function coverMisuseEntry(array $attributes): Entry
{
    $entry = new Entry;
    $entry->setRawAttributes($attributes);

    return $entry;
}

it('throws in testing when a query reads the cover key without the cover id', function (): void {
    EntryCover::value(coverMisuseEntry([EntryCover::KEY => 'abc123/x.jpg', 'meta' => '{"image_url":"abc123/old.jpg"}']));
})->throws(LogicException::class, 'must also select entries.cover_media_id');

it('throws in testing when an entry names its cover by id with no subselect or batch', function (): void {
    EntryCover::value(coverMisuseEntry(['cover_media_id' => 7, 'meta' => '{"image_url":"abc123/old.jpg"}']));
})->throws(LogicException::class, 'needs the EntryCover::KEY subselect');

it('reports the mistake and draws no cover in production instead of failing the page', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Log::spy();

    try {
        expect(EntryCover::value(coverMisuseEntry([EntryCover::KEY => 'abc123/x.jpg', 'meta' => '{"image_url":"abc123/old.jpg"}'])))->toBe('')
            ->and(EntryCover::value(coverMisuseEntry(['cover_media_id' => 7, 'meta' => '{"image_url":"abc123/old.jpg"}'])))->toBe('');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    Log::shouldHaveReceived('error')->twice();
});
