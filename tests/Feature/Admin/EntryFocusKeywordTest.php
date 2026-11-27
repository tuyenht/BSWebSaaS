<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Support\Entries\PendingChangeDiff;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\StorefrontCards;

/*
 * «Từ khóa muốn lên top» in the «Hiện thế nào trên Google» card of a post or page (board 07): the
 * words this language of the page should be found by (entry_translations.focus_keyword), kept in
 * each version of the content and never sent to the website.
 */

/** A member who edits every post and page, on site 1. */
function focusKeywordEditor(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'focus-keyword-editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
    });

    return $user->fresh();
}

/** A draft page in Vietnamese holding $keyword. */
function focusKeywordPage(?string $keyword = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'Gia công', 'slug' => 'gia-cong', 'status' => 'draft', 'body' => ['blocks' => []], 'focus_keyword' => $keyword]);

    return $page;
}

function focusKeywordOf(Entry $entry, string $locale = 'vi'): ?string
{
    return EntryTranslation::query()->where('entry_id', $entry->id)->where('locale', $locale)->value('focus_keyword');
}

describe('the editor', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    it('draws the field in the Google card, stores it on one line and shows it again', function (): void {
        $user = focusKeywordEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = focusKeywordPage();

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertSeeHtml('id="entry-seo-keyword"')
                ->assertSeeHtml('wire:model="focusKeyword"')
                ->assertSee(__('entries.ed.keyword_label'))
                ->assertSee(__('entries.ed.keyword_note', ['doc' => __('entries.ed.doc_page')]))
                ->assertSeeHtml('placeholder="'.e(__('entries.ed.keyword_ph')).'"')
                ->set('focusKeyword', "  gia công\n CNC   theo yêu cầu ")
                ->call('save')
                ->assertHasNoErrors();

            expect(focusKeywordOf($page))->toBe('gia công CNC theo yêu cầu');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->assertSet('focusKeyword', 'gia công CNC theo yêu cầu')
                ->set('focusKeyword', '   ')
                ->call('save')
                ->assertHasNoErrors();

            expect(focusKeywordOf($page))->toBeNull();
        });
    });

    it('keeps each language\'s own words', function (): void {
        $user = focusKeywordEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = focusKeywordPage('gia công CNC');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid, 'locale' => 'en'])
                ->assertSet('focusKeyword', '')
                ->set('title', 'Machining')
                ->set('slug', 'machining')
                ->set('focusKeyword', 'CNC machining')
                ->call('save')
                ->assertHasNoErrors();

            expect(focusKeywordOf($page))->toBe('gia công CNC')
                ->and(focusKeywordOf($page, 'en'))->toBe('CNC machining');
        });
    });

    it('refuses words that are too long or hold markup, and stores nothing', function (string $typed, string $message): void {
        $user = focusKeywordEditor();

        app(Tenancy::class)->enter(1, function () use ($user, $typed, $message): void {
            $page = focusKeywordPage('gia công CNC');

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('focusKeyword', $typed)
                ->call('save')
                ->assertHasErrors('focusKeyword')
                ->assertSee(__($message, ['max' => 120]));

            expect(focusKeywordOf($page))->toBe('gia công CNC');
        });
    })->with([
        'longer than 120 characters' => [str_repeat('a', 121), 'entries.ed.keyword_too_long'],
        'markup' => ['gia công <b>CNC</b>', 'entries.ed.keyword_markup'],
    ]);
});

describe('versions and copies', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    it('keeps the words in each version, restores them, and leaves them when the version is older than they are', function (): void {
        $user = focusKeywordEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = focusKeywordPage();
            $record = function (array $changes) use ($page, $user): EntryRevision {
                $translation = $page->translationFor('vi');
                $translation->update($changes);
                app(RecordEntryRevision::class)->forLocale($page->fresh(), $translation->fresh(), RevisionKind::Edit, $user);

                return EntryRevision::query()->where('entry_id', $page->id)->orderByDesc('id')->firstOrFail();
            };
            $v1 = $record(['title' => 'Bản một']);
            $v2 = $record(['title' => 'Bản hai', 'focus_keyword' => 'gia công CNC']);

            expect($v1->snapshot)->toHaveKey('focus_keyword', null)
                ->and($v2->snapshot)->toHaveKey('focus_keyword', 'gia công CNC');

            app(RestoreEntryRevision::class)->handle($v1, $user);
            expect(focusKeywordOf($page))->toBeNull();

            app(RestoreEntryRevision::class)->handle($v2->fresh(), $user);
            expect(focusKeywordOf($page))->toBe('gia công CNC');

            // A version saved before the words existed has no such key: restoring it keeps them.
            $old = $v1->snapshot;
            unset($old['focus_keyword']);
            DB::table('entry_revisions')->where('id', $v1->id)->update(['snapshot' => json_encode($old)]);
            app(RestoreEntryRevision::class)->handle($v1->fresh(), $user);
            expect($page->fresh()->translationFor('vi')->title)->toBe('Bản một')
                ->and(focusKeywordOf($page))->toBe('gia công CNC');

            // A version holding words the column cannot take is refused whole.
            DB::table('entry_revisions')->where('id', $v1->id)->update(['snapshot' => json_encode(['focus_keyword' => str_repeat('a', 121)] + $old)]);
            expect(fn () => app(RestoreEntryRevision::class)->handle($v1->fresh(), $user))->toThrow(RevisionRestoreRejectedException::class)
                ->and(focusKeywordOf($page))->toBe('gia công CNC');
        });
    });

    it('shows the words in the version history beside today\'s', function (): void {
        $user = focusKeywordEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = focusKeywordPage('gia công cũ');
            app(RecordEntryRevision::class)->forLocale($page->fresh(), $page->translationFor('vi'), RevisionKind::Edit, $user);
            $version = EntryRevision::query()->where('entry_id', $page->id)->orderByDesc('id')->firstOrFail();
            $page->translationFor('vi')->update(['focus_keyword' => 'gia công mới']);

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid])
                ->call('loadRevisions')
                ->call('viewRevision', $version->ulid)
                ->assertSee(__('entries.ed.keyword_label'))
                ->assertSee('gia công cũ');
        });
    });

    it('copies the words with a duplicated page', function (): void {
        $user = focusKeywordEditor();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            $page = focusKeywordPage('gia công CNC');

            $copy = app(DuplicateEntry::class)->handle($page, $user);

            expect(focusKeywordOf($copy))->toBe('gia công CNC');
        });
    });
});

describe('«So sánh» of a pending change', function (): void {
    /** The keyword row of the diff between a live page holding $live and a change holding $text, or null. */
    function focusKeywordDiffRow(?string $live, array $text): ?array
    {
        $entry = (new Entry)->forceFill(['type' => 'page', 'template_override' => null, 'cover_media_id' => null, 'meta' => []]);
        $seen = (new EntryTranslation)->forceFill(['locale' => 'vi', 'title' => 'Gia công', 'slug' => 'gia-cong', 'status' => 'published', 'body' => ['blocks' => []], 'focus_keyword' => $live]);
        $change = (new EntryDraft)->forceFill(['content' => ['locales' => ['vi' => $text + ['title' => 'Gia công', 'slug' => 'gia-cong', 'body' => ['blocks' => []]]], 'shared' => []]]);
        $change->setRelation('entry', $entry);

        $diff = app(PendingChangeDiff::class)->between($entry, $change, collect(['vi' => $seen]), null, fn (array $ids): array => [], ['vi'], PendingChangeDiff::coverRefs([$change]));
        $row = collect($diff['rows'])->firstWhere('label', __('entries.chg.f_focus_keyword'));

        return $row === null ? null : ['now' => $row['now'], 'seen' => $row['seen']];
    }

    it('shows a changed keyword, and none for a change saved before keywords existed', function (): void {
        expect(focusKeywordDiffRow('gia công cũ', ['focus_keyword' => 'gia công mới']))->toBe(['now' => 'gia công mới', 'seen' => 'gia công cũ'])
            ->and(focusKeywordDiffRow('gia công cũ', ['focus_keyword' => 'gia công cũ']))->toBeNull()
            ->and(focusKeywordDiffRow(null, ['focus_keyword' => 'gia công mới']))->toBe(['now' => 'gia công mới', 'seen' => __('entries.chg.diff_empty')])
            // a change without the key leaves the keyword as it is when applied: nothing differs
            ->and(focusKeywordDiffRow('gia công cũ', []))->toBeNull();
    });
});

describe('the website', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
    });

    it('never shows the words: not in the page, its head or its structured data', function (): void {
        $site = StorefrontCards::site();
        app(Tenancy::class)->enter($site, function (): void {
            $post = StorefrontCards::post(StorefrontCards::news(), 'Ống thép mới về', 'ong-thep-moi', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
            EntryTranslation::query()->where('entry_id', $post->id)->update(['focus_keyword' => 'từ khóa bí mật xyz']);
        });

        $html = (string) test()->get('http://mau.platform.test/tin-tuc/ong-thep-moi')->assertOk()->getContent();

        expect($html)->toContain('Ống thép mới về')
            ->not->toContain('từ khóa bí mật xyz')
            ->not->toContain('focus_keyword')
            ->not->toContain('name="keywords"');
    });
});

describe('the migration', function (): void {
    function focusKeywordMigration(): Migration
    {
        return require database_path('migrations/2102_01_01_000000_add_focus_keyword_to_entry_translations.php');
    }

    /** @return list<object{data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}> */
    function focusKeywordColumn(): array
    {
        /** @var list<object{data_type: string, character_maximum_length: int|null, is_nullable: string, column_default: string|null}> */
        return DB::connection('pgsql_admin')->select(
            "select data_type, character_maximum_length, is_nullable, column_default from information_schema.columns
             where table_schema = current_schema() and table_name = 'entry_translations' and column_name = 'focus_keyword'"
        );
    }

    function focusKeywordAsMigrator(Closure $step): mixed
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_admin');
        try {
            return $step();
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    it('adds a nullable varchar(120) with no default that the app may write, and runs twice', function (): void {
        $column = focusKeywordColumn();
        expect(array_map(fn (object $c): array => [$c->data_type, $c->character_maximum_length, $c->is_nullable, $c->column_default], $column))
            ->toBe([['character varying', 120, 'YES', null]]);

        $granted = DB::connection('pgsql_admin')->selectOne("select has_column_privilege('app_user', 'entry_translations', 'focus_keyword', 'UPDATE') as ok");
        expect($granted?->ok)->toBeTrue();

        focusKeywordAsMigrator(fn () => focusKeywordMigration()->up());
        expect(focusKeywordColumn())->toEqual($column);
    });

    it('refuses to roll back while a page holds words, and drops nothing', function (): void {
        StorefrontCards::reset();
        $site = StorefrontCards::site();
        app(Tenancy::class)->enter($site, function (): void {
            $post = StorefrontCards::post(StorefrontCards::news(), 'Bài', 'bai');
            EntryTranslation::query()->where('entry_id', $post->id)->update(['focus_keyword' => 'gia công CNC']);
        });

        expect(fn () => focusKeywordAsMigrator(fn () => focusKeywordMigration()->down()))
            ->toThrow(RuntimeException::class, 'Refused: 1 row(s) of entry_translations.focus_keyword still hold words an editor typed.')
            ->and(focusKeywordColumn())->toHaveCount(1);
    });

    it('drops the column when no page holds words, and up() puts it back', function (): void {
        StorefrontCards::reset();
        $migration = focusKeywordMigration();

        try {
            focusKeywordAsMigrator(function () use ($migration): void {
                $migration->down();
                $migration->down();
            });
            $dropped = focusKeywordColumn();
        } finally {
            // Whatever failed above, the schema is left as it was found.
            focusKeywordAsMigrator(fn () => $migration->up());
        }

        expect($dropped)->toBe([])
            ->and(focusKeywordColumn())->toHaveCount(1);
    });
});
