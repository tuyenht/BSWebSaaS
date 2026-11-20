<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\StorefrontCards;

/*
 * «Mô tả ảnh» under a post's or page's featured image: this language's words for the picture
 * (entry_translations.cover_alt), kept in each version of the content, and what the website's
 * <img alt> says — the post's own words, else the picture's in the library, else the title.
 */

/** A member who edits every post and page and may pick pictures, on site 1. */
function coverAltEditor(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'cover-alt-editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'media.view']);
    });

    return $user->fresh();
}

/** A picture in the current site's library — the caller is inside Tenancy::enter. */
function coverAltMedia(): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => 'xuong', 'hash' => str_repeat('c', 64),
        'storage_key' => 'a/library/xuong-cccccccccccc.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ]);
}

/** A draft page with $key as its cover and $alt as its vi words. */
function coverAltPage(string $key, ?string $alt = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => ['image_url' => $key]]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'Xưởng', 'slug' => 'xuong', 'status' => 'draft', 'body' => ['blocks' => []], 'cover_alt' => $alt]);

    return $page;
}

/** A second picture in the current site's library — the caller is inside Tenancy::enter. */
function coverAltOtherMedia(): Media
{
    return Media::query()->firstOrCreate(['storage_key' => 'a/library/kho-dddddddddddd.png'], [
        'collection' => 'library', 'slug' => 'kho', 'hash' => str_repeat('d', 64), 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ]);
}

/** Give $entry an English version holding $alt as its words for the picture. */
function coverAltEnglish(Entry $entry, string $alt): void
{
    $entry->translations()->create(['locale' => 'en', 'title' => 'Workshop', 'slug' => 'workshop', 'status' => $entry->status === 'published' ? 'published' : 'draft', 'body' => ['blocks' => []], 'cover_alt' => $alt]);
}

function coverAltOfIn(Entry $entry, string $locale): ?string
{
    return EntryTranslation::query()->where('entry_id', $entry->id)->where('locale', $locale)->value('cover_alt');
}

function coverAltOf(Entry $entry): ?string
{
    return EntryTranslation::query()->where('entry_id', $entry->id)->where('locale', 'vi')->value('cover_alt');
}

describe('the editor', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    it('stores the words on one line with the cover, and shows them again', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = coverAltPage(coverAltMedia()->storage_key);

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertSee('id="entry-cover-alt"', false)
                ->set('coverAlt', "  Thợ đứng máy\n phay CNC  ")
                ->call('save')
                ->assertHasNoErrors();

            expect(coverAltOf($page))->toBe('Thợ đứng máy phay CNC');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertSet('coverAlt', 'Thợ đứng máy phay CNC');
        });
    });

    it('drops the words with the picture, and shows no field without one', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = coverAltPage(coverAltMedia()->storage_key, 'Thợ đứng máy');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->call('clearCover')
                ->assertSet('coverAlt', '')
                ->assertDontSee('id="entry-cover-alt"', false)
                ->call('save')
                ->assertHasNoErrors();

            expect(coverAltOf($page))->toBeNull();
        });
    });

    it('drops the words when another picture is picked, and keeps them when the same one is', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $first = coverAltMedia();
            $other = Media::query()->create([
                'collection' => 'library', 'slug' => 'kho', 'hash' => str_repeat('d', 64),
                'storage_key' => 'a/library/kho-dddddddddddd.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
            ]);
            $page = coverAltPage($first->storage_key, 'Thợ đứng máy');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->call('openCoverPicker')->call('togglePickerItem', $first->id)->call('insertPicked')->assertSet('coverAlt', 'Thợ đứng máy')
                ->call('openCoverPicker')->call('togglePickerItem', $other->id)->call('insertPicked')->assertSet('coverAlt', '')
                ->call('save')->assertHasNoErrors();

            expect(coverAltOf($page))->toBeNull();
        });
    });

    it('empties the other languages\' words when the picture shared by every language changes, and only then', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $first = coverAltMedia();
            $page = coverAltPage($first->storage_key, 'Thợ đứng máy');
            coverAltEnglish($page, 'A machinist at work');

            // the same picture: English keeps its words
            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('title', 'Xưởng mới')->call('save')->assertHasNoErrors();
            expect(coverAltOfIn($page, 'en'))->toBe('A machinist at work');

            // another picture picked in Vietnamese: English's words described the old one
            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->call('openCoverPicker')->call('togglePickerItem', coverAltOtherMedia()->id)->call('insertPicked')->set('coverAlt', 'Kho ống thép')
                ->call('save')->assertHasNoErrors();
            expect(coverAltOf($page))->toBe('Kho ống thép')->and(coverAltOfIn($page, 'en'))->toBeNull();

            // the picture taken off: every language's words go
            $page->translationFor('en')->update(['cover_alt' => 'Steel pipes']);
            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->call('clearCover')->call('save')->assertHasNoErrors();
            expect(coverAltOf($page))->toBeNull()->and(coverAltOfIn($page, 'en'))->toBeNull();
        });
    });

    it('puts back the words kept on this device', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = coverAltPage(coverAltMedia()->storage_key, 'Cũ');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->call('restoreKept', ['coverAlt' => 'Thợ đứng máy phay'])
                ->assertSet('coverAlt', 'Thợ đứng máy phay')
                ->call('save')->assertHasNoErrors();

            expect(coverAltOf($page))->toBe('Thợ đứng máy phay');
        });
    });

    it('refuses words longer than the column or holding markup, and keeps the stored ones', function (string $words, string $message): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user, $words, $message): void {
            $page = coverAltPage(coverAltMedia()->storage_key, 'Cũ');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('coverAlt', $words)
                ->call('save')
                ->assertHasErrors(['coverAlt'])
                ->assertSee(__($message, ['max' => 250]));

            expect(coverAltOf($page))->toBe('Cũ');
        });
    })->with([
        'too long' => [str_repeat('a', 251), 'entries.ed.cover_alt_too_long'],
        'markup' => ['Ảnh <b>xưởng</b>', 'entries.ed.cover_alt_markup'],
    ]);

    it('copies the words with a duplicated page', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = coverAltPage(coverAltMedia()->storage_key, 'Thợ đứng máy');
            $copy = app(DuplicateEntry::class)->handle($page, $user);

            expect(coverAltOf($copy))->toBe('Thợ đứng máy');
        });
    });
});

describe('versions', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    it('keeps the words in each version, restores them, and leaves them when the version is older than they are', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = coverAltPage(coverAltMedia()->storage_key);
            $record = function (array $changes) use ($page, $user): EntryRevision {
                $translation = $page->translationFor('vi');
                $translation->update($changes);
                app(RecordEntryRevision::class)->forLocale($page->fresh(), $translation->fresh(), RevisionKind::Edit, $user);

                return EntryRevision::query()->where('entry_id', $page->id)->orderByDesc('id')->firstOrFail();
            };
            $v1 = $record(['title' => 'Bản một']);
            $v2 = $record(['title' => 'Bản hai', 'cover_alt' => 'Thợ đứng máy']);

            expect($v1->snapshot)->toHaveKey('cover_alt', null)
                ->and($v2->snapshot)->toHaveKey('cover_alt', 'Thợ đứng máy');

            app(RestoreEntryRevision::class)->handle($v1, $user);
            expect(coverAltOf($page))->toBeNull();

            app(RestoreEntryRevision::class)->handle($v2->fresh(), $user);
            expect(coverAltOf($page))->toBe('Thợ đứng máy');

            // A version saved before the words existed has no such key: restoring it keeps them while
            // the picture stays the same…
            $old = $v1->snapshot;
            unset($old['cover_alt']);
            DB::table('entry_revisions')->where('id', $v1->id)->update(['snapshot' => json_encode($old)]);
            app(RestoreEntryRevision::class)->handle($v1->fresh(), $user);

            expect($page->fresh()->translationFor('vi')->title)->toBe('Bản một')
                ->and(coverAltOf($page))->toBe('Thợ đứng máy');

            // …and drops them when it brings back another picture: they described this one
            $kept = $page->fresh()->meta;
            $page->update(['meta' => ['image_url' => coverAltOtherMedia()->storage_key]]);
            $page->translationFor('vi')->update(['cover_alt' => 'Kho ống thép']);
            app(RestoreEntryRevision::class)->handle($v1->fresh(), $user);
            expect($page->fresh()->meta['image_url'] ?? null)->toBe($kept['image_url'])
                ->and(coverAltOf($page))->toBeNull();
            $page->translationFor('vi')->update(['cover_alt' => 'Thợ đứng máy']);

            // A version holding words the column cannot take is refused whole.
            DB::table('entry_revisions')->where('id', $v1->id)->update(['snapshot' => json_encode(['cover_alt' => str_repeat('a', 251)] + $old)]);
            expect(fn () => app(RestoreEntryRevision::class)->handle($v1->fresh(), $user))->toThrow(RevisionRestoreRejectedException::class)
                ->and(coverAltOf($page))->toBe('Thợ đứng máy');
        });
    });
});

describe('versions across languages', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    it('empties the other languages\' words when a restored version brings back another picture, and keeps them when it does not', function (): void {
        $user = coverAltEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $first = coverAltMedia();
            $page = coverAltPage($first->storage_key, 'Thợ đứng máy');
            coverAltEnglish($page, 'A machinist at work');
            app(RecordEntryRevision::class)->forLocale($page->fresh(), $page->translationFor('vi'), RevisionKind::Edit, $user);
            $v1 = EntryRevision::query()->where('entry_id', $page->id)->orderByDesc('id')->firstOrFail();

            // the same picture, another shared value changed: a restore leaves English alone
            $page->translationFor('vi')->update(['title' => 'Bản hai']);
            $page->update(['meta' => ['image_url' => $first->storage_key, 'subtitle' => 'Phụ đề']]);
            app(RestoreEntryRevision::class)->handle($v1, $user);
            expect($page->fresh()->meta)->not->toHaveKey('subtitle')
                ->and(coverAltOfIn($page, 'en'))->toBe('A machinist at work');

            // only the cover's library id differs, the picture the pages show is the same: English stays
            $page->forceFill(['cover_media_id' => coverAltOtherMedia()->id])->save();
            app(RestoreEntryRevision::class)->handle($v1->fresh(), $user);
            expect($page->fresh()->cover_media_id)->toBeNull()
                ->and(coverAltOf($page))->toBe('Thợ đứng máy')
                ->and(coverAltOfIn($page, 'en'))->toBe('A machinist at work');

            // the page now shows another picture, English describing it; the version brings the first back
            $page->update(['meta' => ['image_url' => coverAltOtherMedia()->storage_key]]);
            $page->translationFor('en')->update(['cover_alt' => 'Steel pipes']);
            app(RestoreEntryRevision::class)->handle($v1->fresh(), $user);
            expect($page->fresh()->meta['image_url'] ?? null)->toBe($first->storage_key)
                ->and(coverAltOf($page))->toBe('Thợ đứng máy')
                ->and(coverAltOfIn($page, 'en'))->toBeNull();
        });
    });
});

describe('the website', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
    });

    /**
     * The cover's alt on a news post whose picture has $library words in the library and $own words
     * of its own; $link: the cover is a full address instead of a library picture.
     */
    function coverAltOnSite(?string $library, ?string $own, bool $link = false): string
    {
        $site = StorefrontCards::site();
        app(Tenancy::class)->enter($site, function () use ($library, $own, $link): void {
            $photo = StorefrontCards::media('ong-thep');
            $photo->update(['alt' => $library !== null ? ['vi' => $library] : []]);
            $cover = $link ? 'https://images.example.test/ong-thep.jpg' : $photo->storage_key;
            $post = StorefrontCards::post(StorefrontCards::news(), 'Ống thép mới về', 'ong-thep-moi', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), ['image_url' => $cover]);
            EntryTranslation::query()->where('entry_id', $post->id)->update(['cover_alt' => $own]);
        });

        $html = test()->get('http://mau.platform.test/tin-tuc/ong-thep-moi')->assertOk()->getContent();
        preg_match('#<img class="post-cover__img"[^>]* alt="([^"]*)"#', (string) $html, $found);

        return $found[1] ?? '(no cover)';
    }

    it('says the post\'s own words first, then the picture\'s, then the title', function (?string $library, ?string $own, string $alt): void {
        expect(coverAltOnSite($library, $own))->toBe($alt);
    })->with([
        'its own words' => ['Ống thép trong kho', 'Thợ xếp ống thép mới', 'Thợ xếp ống thép mới'],
        'the picture\'s words' => ['Ống thép trong kho', null, 'Ống thép trong kho'],
        'the title' => [null, null, 'Ống thép mới về'],
    ]);

    it('says the post\'s own words, else the title, for a cover at a full address', function (?string $own, string $alt): void {
        expect(coverAltOnSite('Ống thép trong kho', $own, link: true))->toBe($alt);
    })->with([
        'its own words' => ['Thợ xếp ống thép mới', 'Thợ xếp ống thép mới'],
        'the title' => [null, 'Ống thép mới về'],
    ]);

    it('escapes the post\'s own words in the page', function (): void {
        $site = StorefrontCards::site();
        app(Tenancy::class)->enter($site, function (): void {
            $photo = StorefrontCards::media('ong-thep');
            $post = StorefrontCards::post(StorefrontCards::news(), 'Ống thép mới về', 'ong-thep-moi', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), ['image_url' => $photo->storage_key]);
            EntryTranslation::query()->where('entry_id', $post->id)->update(['cover_alt' => 'Ống 9" & <thép> \'mới\'']);
        });

        $html = (string) test()->get('http://mau.platform.test/tin-tuc/ong-thep-moi')->assertOk()->getContent();

        expect($html)->toContain('alt="Ống 9&quot; &amp; &lt;thép&gt; &#039;mới&#039;"')
            ->not->toContain('<thép>')->not->toContain('9" &');
    });
});

describe('the migration', function (): void {
    function coverAltMigration(): Migration
    {
        return require database_path('migrations/2084_01_01_000000_add_cover_alt_to_entry_translations.php');
    }

    /** @return list<object{data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}> */
    function coverAltColumn(): array
    {
        /** @var list<object{data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}> */
        return DB::connection('pgsql_admin')->select(
            "select data_type, character_maximum_length, is_nullable, column_default from information_schema.columns
             where table_schema = current_schema() and table_name = 'entry_translations' and column_name = 'cover_alt'"
        );
    }

    function coverAltAsMigrator(Closure $step): mixed
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        try {
            return $step();
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    it('adds a nullable varchar(250) with no default that the app may write, and runs twice', function (): void {
        $column = coverAltColumn();
        expect(array_map(fn (object $c): array => [$c->data_type, $c->character_maximum_length, $c->is_nullable, $c->column_default], $column))
            ->toBe([['character varying', 250, 'YES', null]]);

        $granted = DB::connection('pgsql_admin')->selectOne("select has_column_privilege('app_user', 'entry_translations', 'cover_alt', 'UPDATE') as ok");
        expect($granted?->ok)->toBeTrue();

        coverAltAsMigrator(fn () => coverAltMigration()->up());
        expect(coverAltColumn())->toEqual($column);
    });

    it('refuses to roll back while a post holds words, and drops nothing', function (): void {
        StorefrontCards::reset();
        $site = StorefrontCards::site();
        app(Tenancy::class)->enter($site, function (): void {
            $post = StorefrontCards::post(StorefrontCards::news(), 'Bài', 'bai');
            EntryTranslation::query()->where('entry_id', $post->id)->update(['cover_alt' => 'Thợ đứng máy']);
        });

        expect(fn () => coverAltAsMigrator(fn () => coverAltMigration()->down()))
            ->toThrow(RuntimeException::class, 'Refused: 1 row(s) of entry_translations.cover_alt still hold text an editor typed.')
            ->and(coverAltColumn())->toHaveCount(1);
    });

    it('drops the column when no post holds words, and up() puts it back', function (): void {
        StorefrontCards::reset();
        $migration = coverAltMigration();

        try {
            coverAltAsMigrator(function () use ($migration): void {
                $migration->down();
                $migration->down();
            });
            $dropped = coverAltColumn();
        } finally {
            // Whatever failed above, the schema is left as it was found.
            coverAltAsMigrator(fn () => $migration->up());
        }

        expect($dropped)->toBe([])->and(coverAltColumn())->toHaveCount(1);
    })->skip(getenv('CI') !== 'true', 'drops and re-adds a column: runs on CI\'s own database only');
});
