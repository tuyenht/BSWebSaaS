<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Services\SiteCounters;

/**
 * How much the current website's media library stores, against what its plan allows (design board 12
 * «Dung lượng đã dùng»; board 02 reads the same figure). Two running totals in site_counters:
 *
 *  - `media.bytes`: the master file's size (media.size) of every row the website keeps, its Trash
 *    included (a file in the Trash still takes room until it is deleted for good). A file uploaded
 *    twice is one row, counted once. The image sizes the system makes from a master are not counted.
 *  - `media.trash_bytes`: the part of it sitting in the Trash, so the library can say how much
 *    emptying the Trash frees without counting it live.
 *
 * Changed only by explicit calls in the actions that change the rows, in their transaction, as
 * their last write: a new row (UploadMedia, RegisterMediaUpload, EnsureMediaFromKey), the Trash and
 * back (TrashMedia), deleting for good (PurgeMedia). `counters:reconcile` recounts both nightly
 * (MediaStorageReconciler).
 *
 * The plan's ceiling is the `media.storage_mb` limit (a website's own override wins). Refusing an
 * upload that would pass it is behind the platform switch `media.storage_enforce`, OFF until the
 * plans' numbers are settled: while it is off every upload is counted and none is refused, and a
 * website may hold more than its plan says. Nothing is ever deleted to make room.
 */
final readonly class MediaStorage
{
    public const string BYTES = 'media.bytes';

    public const string TRASH = 'media.trash_bytes';

    /** The plan limit that caps the library, in megabytes. */
    public const string FEATURE = 'media.storage_mb';

    private const int MB = 1048576;

    public function __construct(
        private SiteCounters $counters,
        private Entitlement $entitlement,
    ) {}

    /** Whether an upload past the plan's ceiling is refused (the platform switch; off by default). */
    public static function enforcing(): bool
    {
        return config('media.storage_enforce', false) === true;
    }

    /** Count a row just created, refusing it (storage_full) when enforcing and it does not fit. */
    public function charge(Media $media): void
    {
        $size = max(0, $media->size);
        if (! self::enforcing()) {
            $this->counters->add(self::BYTES, $size);

            return;
        }
        if (! $this->counters->tryAdd(self::BYTES, $size, $this->limitBytes())) {
            throw new MediaRejectedException('storage_full');
        }
    }

    /** Count a row just created that is never refused (a seed or a backfill, not an upload). */
    public function added(Media $media): void
    {
        $this->counters->add(self::BYTES, max(0, $media->size));
    }

    /** Whether $bytes more would still fit, for the early answer before an upload (always true while not enforcing). */
    public function fits(int $bytes): bool
    {
        if (! self::enforcing()) {
            return true;
        }
        $usage = $this->usage();

        return $usage['limit'] >= $usage['used'] + max(0, $bytes);
    }

    /** $bytes went into the Trash. */
    public function trashed(int $bytes): void
    {
        $this->counters->add(self::TRASH, $bytes);
    }

    /** $bytes came back out of the Trash. */
    public function restored(int $bytes): void
    {
        $this->counters->add(self::TRASH, -$bytes);
    }

    /** $bytes were deleted for good, $fromTrash of them out of the Trash. */
    public function purged(int $bytes, int $fromTrash): void
    {
        $this->counters->addMany([self::BYTES => -$bytes, self::TRASH => -$fromTrash]);
    }

    /**
     * Used and Trash bytes and the plan's ceiling in bytes, for the current website, in one statement.
     *
     * @return array{used: int, trash: int, limit: int}
     */
    public function usage(): array
    {
        $read = $this->counters->read([self::BYTES, self::TRASH], $this->entitlement->featureValueExpression(self::FEATURE));
        $limit = $this->entitlement->valueFrom($read['also'], self::FEATURE);

        return [
            'used' => $read['values'][self::BYTES],
            'trash' => $read['values'][self::TRASH],
            'limit' => max(0, Cast::int($limit)) * self::MB,
        ];
    }

    private function limitBytes(): int
    {
        return max(0, Cast::int($this->entitlement->featureValue(self::FEATURE))) * self::MB;
    }
}
