<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Cms\Validation\ExpiryTime;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sets, moves or clears a post's or page's end time («Gỡ khỏi website lúc», expires_at): the one road
 * the editor (and any other screen) uses, so the rules hold however it is reached.
 *
 *   - It needs the right to change the entry's status (EntryPolicy::publish), like «Gỡ khỏi website»
 *     itself: an end time is a take-down set in advance. Taking content off needs no approval, so an
 *     approval workflow does not hold it as a pending change; extending it keeps only what was
 *     already approved online.
 *   - The time must still be to come and after a scheduled publish time (ExpiryTime); the database's
 *     CHECK says the same. The Home page takes none: «/» would answer 404 at that time.
 *   - Judged on the locked row; the change writes its audit row (field, from, to, who) through the
 *     model, in the same transaction (DateChangeAudit).
 *
 * An entry already taken down at its end time comes back only through the usual publish, after its
 * end time was moved or cleared here (the sweep would take it down again within a minute otherwise).
 */
final readonly class ChangeEntryExpiry
{
    public function __construct(private SiteTime $time) {}

    /**
     * @throws AuthorizationException the member may not change this entry's status
     * @throws HomePageLockedException an end time on the website's Home page
     * @throws ValidationException expiresAt: the time is past, or not after the scheduled publish time
     * @throws ModelNotFoundException the entry left meanwhile (sent to the Trash or purged)
     */
    public function handle(Entry $entry, ?DateTimeInterface $expiresAt, User $actor): void
    {
        DB::transaction(function () use ($entry, $expiresAt, $actor): void {
            $locked = Entry::query()->whereKey($entry->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Entry::class, [$entry->id]);
            $entry->setRawAttributes($locked->getAttributes(), true);

            if (! Gate::forUser($actor)->allows('publish', $entry)) {
                throw new AuthorizationException(__('entries.err_status_forbidden'));
            }

            $at = $expiresAt !== null ? CarbonImmutable::instance($expiresAt)->utc() : null; // stored in UTC: a write prints the time without its zone
            if ($at !== null && HomePages::is($entry)) {
                throw new HomePageLockedException('entries.home_expiry_why');
            }

            // only a publish time still to come (a schedule) bounds the end; a past one the CHECK covers
            $publishAt = $entry->published_at !== null && $entry->published_at > now() ? $entry->published_at : null;
            $error = ExpiryTime::error($at, $publishAt, $this->time->zone());
            if ($error !== null) {
                throw ValidationException::withMessages(['expiresAt' => $error]);
            }

            $entry->update(['expires_at' => $at]);
        });
    }
}
