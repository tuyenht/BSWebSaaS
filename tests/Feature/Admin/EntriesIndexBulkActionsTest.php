<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The content list's bulk bar and row buttons act only on the rows they are for: Restore and
 * Permanent delete on the Trash, every other action on live content. A row of the wrong kind,
 * or one this person may not change, is skipped and counted — never acted on — so a selection
 * carried over from another tab (or forged) can never delete live content for good. Each row
 * runs in its own savepoint, a concurrency error abandons the whole run, and one toast reports
 * what was done, skipped and failed.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function bulkRow(?User $author, string $title, string $status = 'published', string $translationStatus = 'published', bool $trashed = false): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'author_id' => $author?->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)), 'status' => $translationStatus, 'body' => ['blocks' => []]]);
    if ($trashed) {
        $entry->delete();
        // Back-dated, so a second trashing (which would restart the 30-day purge clock) shows.
        Entry::withTrashed()->whereKey($entry->id)->update(['deleted_at' => now()->subDays(3)]);
    }

    return $entry;
}

function bulkFind(Entry $entry): ?Entry
{
    return Entry::withTrashed()->whereKey($entry->id)->first();
}

function bulkToast(string $doneKey, int $done, array $rest = []): string
{
    $parts = [__($doneKey, ['count' => $done])];
    foreach ($rest as $key => $count) {
        $parts[] = __($key, ['count' => $count]);
    }

    return implode(' ', $parts);
}

it('keeps live content when a selection made on another tab meets "Xóa vĩnh viễn" in the Trash', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $live = bulkRow($editor, 'Trang đang chạy');

        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$live->ulid])
            ->call('setTab', 'trash')
            ->call('bulkForceDelete');

        $survivor = bulkFind($live);
        expect($survivor)->not->toBeNull()
            ->and($survivor->trashed())->toBeFalse()
            ->and($survivor->status)->toBe('published');

        // The tab change drops a selection the new tab does not show.
        $component->assertSet('selected', []);
    });
});

it('never purges a live row named in a Trash-tab selection, however the selection got there', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $live = bulkRow($editor, 'Trang đang chạy');

        // `tab` set directly, not through setTab(): the selection survives, as a forged one would.
        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$live->ulid])
            ->set('tab', 'trash')
            ->call('bulkForceDelete');

        expect(bulkFind($live)?->trashed())->toBeFalse();
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_destroyed', 0, ['entries.bulk_skipped_trash' => 1]));
    });
});

it('refuses a single-row permanent delete or restore of a row that is not in the Trash', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $live = bulkRow($editor, 'Trang đang chạy');
        $trashed = bulkRow($editor, 'Trang đã xóa', trashed: true);
        $deletedAt = bulkFind($trashed)->deleted_at?->toIso8601String();

        // Not found, exactly like an unknown ulid: a plain "this changed" toast, nothing done.
        $index = Livewire::actingAs($editor->fresh())->test(Index::class);
        $index->call('forceDelete', $live->ulid)->assertDispatched('bs-toast', text: __('entries.row_stale'));
        $index->call('restore', $live->ulid)->assertDispatched('bs-toast', text: __('entries.row_stale'));
        // The reverse: trashing a row already in the Trash would restart its 30-day clock.
        $index->call('trash', $trashed->ulid)->assertDispatched('bs-toast', text: __('entries.row_stale'));

        expect(bulkFind($live)?->trashed())->toBeFalse()
            ->and(bulkFind($trashed)->deleted_at?->toIso8601String())->toBe($deletedAt);
    });
});

it('bulk-trashes only live rows this person may delete', function (): void {
    $author = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $colleague): void {
        grantRole($author, 'author', ['entries.view', 'entries.delete.own']);
        $mine = bulkRow($author, 'Của tôi');
        $mineTrashed = bulkRow($author, 'Của tôi đã xóa', trashed: true);
        $theirs = bulkRow($colleague, 'Của đồng nghiệp');
        $deletedAt = bulkFind($mineTrashed)->deleted_at?->toIso8601String();

        $component = Livewire::actingAs($author->fresh())->test(Index::class)
            ->set('selected', [$mine->ulid, $mineTrashed->ulid, $theirs->ulid])
            ->call('bulkTrash');

        expect(bulkFind($mine)->trashed())->toBeTrue()
            ->and(bulkFind($mineTrashed)->deleted_at?->toIso8601String())->toBe($deletedAt)
            ->and(bulkFind($theirs)->trashed())->toBeFalse();
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_trashed', 1, ['entries.bulk_skipped' => 2]))->assertSet('selected', []);
    });
});

it('bulk-restores only trashed rows this person may restore', function (): void {
    $author = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $colleague): void {
        grantRole($author, 'author', ['entries.view', 'entries.delete.own']);
        $mineTrashed = bulkRow($author, 'Của tôi đã xóa', trashed: true);
        $mineLive = bulkRow($author, 'Của tôi');
        $theirsTrashed = bulkRow($colleague, 'Của đồng nghiệp đã xóa', trashed: true);

        $component = Livewire::actingAs($author->fresh())->test(Index::class)
            ->set('tab', 'trash')
            ->set('selected', [$mineTrashed->ulid, $mineLive->ulid, $theirsTrashed->ulid])
            ->call('bulkRestore');

        expect(bulkFind($mineTrashed)->trashed())->toBeFalse()
            ->and(bulkFind($mineLive)->trashed())->toBeFalse()
            ->and(bulkFind($theirsTrashed)->trashed())->toBeTrue();
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_restored', 1, ['entries.bulk_skipped_trash' => 2]));
    });
});

it('bulk-deletes for good only trashed rows this person may delete', function (): void {
    $author = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $colleague): void {
        grantRole($author, 'author', ['entries.view', 'entries.delete.own']);
        $mineTrashed = bulkRow($author, 'Của tôi đã xóa', trashed: true);
        $mineLive = bulkRow($author, 'Của tôi');
        $theirsTrashed = bulkRow($colleague, 'Của đồng nghiệp đã xóa', trashed: true);

        $component = Livewire::actingAs($author->fresh())->test(Index::class)
            ->set('tab', 'trash')
            ->set('selected', [$mineTrashed->ulid, $mineLive->ulid, $theirsTrashed->ulid])
            ->call('bulkForceDelete');

        expect(bulkFind($mineTrashed))->toBeNull()
            ->and(bulkFind($mineLive)?->trashed())->toBeFalse()
            ->and(bulkFind($theirsTrashed)?->trashed())->toBeTrue();
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_destroyed', 1, ['entries.bulk_skipped_trash' => 2]));
    });
});

it('bulk-drafts only live rows that are not drafts already', function (): void {
    $editor = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor, $colleague): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.publish']);
        $live = bulkRow($editor, 'Đang chạy');
        $draft = bulkRow($editor, 'Bản nháp', 'draft', 'draft');
        $trashed = bulkRow($editor, 'Đã xóa', trashed: true);
        $theirs = bulkRow($colleague, 'Của đồng nghiệp');   // a publisher may take anyone's page offline

        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$live->ulid, $draft->ulid, $trashed->ulid, $theirs->ulid])
            ->call('bulkDraft');

        $trashed = bulkFind($trashed);
        expect(bulkFind($live)->status)->toBe('draft')
            ->and(bulkFind($theirs)->status)->toBe('draft')
            ->and($trashed->status)->toBe('published')
            ->and($trashed->trashed())->toBeTrue()
            // draft → draft is not a change: no audit row claims one.
            ->and(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('meta->entry_ulid', $draft->ulid)->count())->toBe(0);
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_drafted', 2, ['entries.bulk_skipped' => 2]));
    });
});

it('bulk-duplicates only live rows', function (): void {
    $editor = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor, $colleague): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create']);
        $mine = bulkRow($editor, 'Của tôi');
        $trashed = bulkRow($editor, 'Đã xóa', trashed: true);
        $theirs = bulkRow($colleague, 'Của đồng nghiệp');

        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$mine->ulid, $trashed->ulid, $theirs->ulid])
            ->call('bulkDuplicate');

        expect(Entry::query()->where('type', 'page')->count())->toBe(4)   // two originals + two copies
            ->and(Entry::query()->where('author_id', $editor->id)->where('status', 'draft')->count())->toBe(2);
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_duplicated', 2, ['entries.bulk_skipped' => 1]));
    });
});

it('bulk-publishes what is not yet live, and leaves what is live exactly as it was', function (): void {
    $editor = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor, $colleague): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $draft = bulkRow($editor, 'Bản nháp', 'draft', 'draft');
        $live = bulkRow($editor, 'Đã đăng');
        // Live, but its default-language text is still a draft (the default language changed): Đăng has work to do.
        $halfLive = bulkRow($editor, 'Đăng một nửa', 'published', 'draft');
        $trashed = bulkRow($editor, 'Đã xóa', 'draft', 'draft', trashed: true);
        $theirs = bulkRow($colleague, 'Bản nháp đồng nghiệp', 'draft', 'draft');

        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$draft->ulid, $live->ulid, $halfLive->ulid, $trashed->ulid, $theirs->ulid])
            ->call('bulkPublish');

        expect(bulkFind($draft)->status)->toBe('published')
            ->and(bulkFind($theirs)->status)->toBe('published')
            ->and(bulkFind($halfLive)->translationFor('vi')->status)->toBe('published')
            ->and(bulkFind($trashed)->status)->toBe('draft')
            // The live row was never touched: no new version, no audit row.
            ->and(EntryRevision::query()->where('entry_id', $live->id)->count())->toBe(0)
            ->and(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('meta->entry_ulid', $live->ulid)->count())->toBe(0);
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_published', 3, ['entries.bulk_skipped' => 2]));
    });
});

it('bulk-sends only the sender\'s own live drafts', function (): void {
    $author = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $colleague): void {
        grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        $mine = bulkRow($author, 'Bản nháp của tôi', 'draft', 'draft');
        $waiting = bulkRow($author, 'Đang chờ duyệt', 'pending', 'pending');
        $trashed = bulkRow($author, 'Bản nháp đã xóa', 'draft', 'draft', trashed: true);
        $theirs = bulkRow($colleague, 'Bản nháp đồng nghiệp', 'draft', 'draft');

        $component = Livewire::actingAs($author->fresh())->test(Index::class)
            ->set('selected', [$mine->ulid, $waiting->ulid, $trashed->ulid, $theirs->ulid])
            ->call('bulkPublish');

        expect(bulkFind($mine)->status)->toBe('pending')
            ->and(bulkFind($trashed)->status)->toBe('draft')
            ->and(bulkFind($theirs)->status)->toBe('draft')
            ->and(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('meta->entry_ulid', $waiting->ulid)->count())->toBe(0);
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_sent', 1, ['entries.bulk_skipped' => 3]));
    });
});

it('counts a row that fails as failed, keeps the rows around it, and undoes only its own half-work', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $first = bulkRow($editor, 'Một', 'draft', 'draft');
        $broken = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);   // no default-language text to publish
        $last = bulkRow($editor, 'Ba', 'draft', 'draft');
        Exceptions::fake();

        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$first->ulid, $broken->ulid, $last->ulid])
            ->call('bulkPublish');

        Exceptions::assertReported(RuntimeException::class);
        expect(bulkFind($first)->status)->toBe('published')
            ->and(bulkFind($broken)->status)->toBe('draft')
            ->and(bulkFind($last)->status)->toBe('published');
        $component->assertDispatched('bs-toast', text: bulkToast('entries.bulk_published', 2, ['entries.bulk_failed' => 1]));
    });
});

it('abandons the whole run on a deadlock and leaves the request usable', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.publish']);
        $first = bulkRow($editor, 'Một');
        $second = bulkRow($editor, 'Hai');
        $third = bulkRow($editor, 'Ba');

        // A real PostgreSQL deadlock error on the second row: the database aborts the transaction.
        Entry::updating(function (Entry $entry) use ($second): void {
            if ($entry->id === $second->id) {
                DB::statement("do \$\$ begin raise exception 'deadlock detected' using errcode = '40P01'; end \$\$");
            }
        });

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$third->ulid, $first->ulid, $second->ulid])
            ->call('bulkDraft')
            ->assertDispatched('bs-toast', text: __('entries.bulk_conflict'))
            ->assertCount('selected', 3);   // kept, so "try again" is one click

        // The first row's change went with the run, the third was never reached, and the request
        // transaction still answers queries.
        expect(bulkFind($first)->status)->toBe('published')
            ->and(bulkFind($second)->status)->toBe('published')
            ->and(bulkFind($third)->status)->toBe('published');
    });
});

it('refuses a selection over the bulk limit without touching any row', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $live = bulkRow($editor, 'Trang đang chạy');
        $selection = [$live->ulid];
        for ($i = 0; $i < 200; $i++) {
            $selection[] = (string) Str::ulid();
        }

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', $selection)
            ->call('bulkTrash')
            ->assertDispatched('bs-toast', text: __('entries.bulk_too_many', ['max' => 200]));

        expect(bulkFind($live)->trashed())->toBeFalse();
    });
});

it('offers no editor link on a Trash row, since the editor does not open trashed content', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.delete.any']);
        $trashed = bulkRow($editor, 'Bài đã bỏ', trashed: true);
        $live = bulkRow($editor, 'Bài đang chạy');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('setTab', 'trash')
            ->assertSee('Bài đã bỏ')
            ->assertDontSee(route('admin.entries.edit', ['ulid' => $trashed->ulid, 'locale' => 'vi']), false);

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->assertSee(route('admin.entries.edit', ['ulid' => $live->ulid, 'locale' => 'vi']), false);
    });
});
