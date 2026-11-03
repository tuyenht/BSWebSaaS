<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Actions\ApplyEntryDraft;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * Formatted text is sanitized at the data layer (ADR-19): the casts on entry_translations.body and
 * entry_drafts.content run every html prop through RichTextSanitizer::cms on every write, so each
 * road that saves a body stores clean HTML without calling the sanitizer itself. Each test below
 * writes an attack through one road and reads the raw column back. Where a road copies content
 * that is already stored (approval, restore, duplicate), the stored copy is first made dirty
 * behind the model's back — the case of data written before the cast existed.
 */

const BODY_ATTACK = '<h2>Câu chuyện</h2><p onclick="steal()">Đoạn <a href="javascript:alert(1)">liên kết</a></p>'
    .'<script>alert(1)</script><figure><img data-media-id="5" src="https://evil.test/x.png" onerror="alert(1)" alt="Ảnh"></figure>';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** @return array{blocks: list<array<string, mixed>>} */
function attackBody(string $html = BODY_ATTACK): array
{
    return ['blocks' => [
        ['type' => 'hero', 'props' => ['heading' => '<b>kept as text</b>']],
        ['type' => 'richtext_html', 'props' => ['html' => $html]],
    ]];
}

function cleanAttack(): string
{
    return app(RichTextSanitizer::class)->cms(BODY_ATTACK);
}

/** The formatted text as the database holds it, read past the model. */
function storedHtml(string $table, int $entryId, string $path, ?string $locale = 'vi'): mixed
{
    $query = DB::table($table)->where('entry_id', $entryId);
    if ($table === 'entry_translations') {
        $query->where('locale', $locale);
    }

    return data_get(json_decode((string) $query->value($table === 'entry_drafts' ? 'content' : 'body'), true), $path);
}

function expectStoredClean(mixed $html): void
{
    expect($html)->toBe(cleanAttack())
        ->and(strtolower((string) $html))->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('javascript:')
        ->not->toContain('evil.test')
        ->and((string) $html)->toContain('<img data-media-id="5" alt="Ảnh" />');
}

/** Overwrite a stored JSON column with $value as is, past the model and its cast (inside the tenant transaction). */
function forceStored(string $table, int $id, string $column, array $value): void
{
    expect(DB::table($table)->where('id', $id)->update([$column => json_encode($value)]))->toBe(1);
}

function bodyEditor(): User
{
    $user = User::factory()->create();
    grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);

    return $user->fresh();
}

function livePage(User $author, array $body = ['blocks' => []]): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now(), 'author_id' => $author->id, 'meta' => []]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => $body]);

    return $entry->fresh();
}

describe('the model cast', function (): void {
    it('cleans every way of setting a translation body', function (Closure $write): void {
        app(Tenancy::class)->enter(1, function () use ($write): void {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $write($entry);

            expectStoredClean(storedHtml('entry_translations', $entry->id, 'blocks.1.props.html'));
            // only html props are touched: a text prop keeps its characters (it is escaped at print)
            expect(storedHtml('entry_translations', $entry->id, 'blocks.0.props.heading'))->toBe('<b>kept as text</b>');
        });
    })->with([
        'relation create' => [fn (Entry $entry) => $entry->translations()->create(['locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => attackBody()])],
        'update' => [function (Entry $entry): void {
            $entry->translations()->create(['locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => ['blocks' => []]])->update(['body' => attackBody()]);
        }],
        'fill and save' => [function (Entry $entry): void {
            $entry->translations()->firstOrNew(['locale' => 'vi'])->fill(['title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => attackBody()])->save();
        }],
        'updateOrCreate' => [fn (Entry $entry) => $entry->translations()->updateOrCreate(['locale' => 'vi'], ['title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => attackBody()])],
        'attribute assignment' => [function (Entry $entry): void {
            $translation = new EntryTranslation(['entry_id' => $entry->id, 'locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft']);
            $translation->body = attackBody();
            $translation->save();
        }],
        'raw JSON string' => [function (Entry $entry): void {
            $translation = new EntryTranslation(['entry_id' => $entry->id, 'locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft']);
            $translation->forceFill(['body' => json_encode(attackBody())])->save();
        }],
        'JSON path key' => [function (Entry $entry): void {
            $translation = $entry->translations()->create(['locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => ['blocks' => []]]);
            $translation->update(['body->blocks' => attackBody()['blocks']]);
        }],
    ]);

    it('cleans every language of a pending change', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = livePage(bodyEditor());
            EntryDraft::query()->create(['entry_id' => $entry->id, 'content' => [
                'v' => RecordEntryRevision::FORMAT,
                'locales' => ['vi' => ['title' => 'A', 'body' => attackBody()], 'en' => ['title' => 'B', 'body' => attackBody()]],
            ]]);

            expectStoredClean(storedHtml('entry_drafts', $entry->id, 'locales.vi.body.blocks.1.props.html'));
            expectStoredClean(storedHtml('entry_drafts', $entry->id, 'locales.en.body.blocks.1.props.html'));
        });
    });

    it('cleans a pending change written through a JSON path key', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = livePage(bodyEditor());
            $change = EntryDraft::query()->create(['entry_id' => $entry->id, 'content' => ['v' => RecordEntryRevision::FORMAT, 'locales' => ['en' => ['title' => 'B']]]]);
            // content is fillable but a JSON-path key is not, so mass assignment would drop it: force it
            $change->forceFill(['content->locales' => ['vi' => ['title' => 'A', 'body' => attackBody()]]])->save();

            expectStoredClean(storedHtml('entry_drafts', $entry->id, 'locales.vi.body.blocks.1.props.html'));
        });
    });

    it('leaves a block of an unknown type untouched, and reads back a plain array', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $body = ['blocks' => [['type' => 'not_a_block', 'props' => ['html' => '<script>1</script>']], ['type' => 'richtext_html', 'props' => ['html' => null]]]];
            $translation = $entry->translations()->create(['locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => $body]);

            expect($translation->fresh()->body)->toBe($body);
        });
    });

    it('does not mark an unchanged body dirty', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = livePage(bodyEditor(), attackBody());
            $translation = $entry->translationFor('vi');
            $translation->body = $translation->body;

            expect($translation->isDirty('body'))->toBeFalse();
        });
    });
});

describe('every road that saves a body', function (): void {
    it('the content editor', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            Livewire::actingAs(bodyEditor())
                ->test(Editor::class)
                ->set('title', 'Giới thiệu')
                ->set('slug', 'gioi-thieu')
                ->set('blocks', attackBody()['blocks'])
                ->call('save')
                ->assertHasNoErrors();

            expectStoredClean(storedHtml('entry_translations', Entry::query()->value('id'), 'blocks.1.props.html'));
        });
    });

    it('a pending change saved on live content', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $editor = bodyEditor();
            $entry = livePage($editor);

            app(SaveEntryDraft::class)->handle($entry, $editor, 'vi', ['body' => attackBody()]);

            expectStoredClean(storedHtml('entry_drafts', $entry->id, 'locales.vi.body.blocks.1.props.html'));
        });
    });

    it('a pending change put on the website', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $editor = bodyEditor();
            $entry = livePage($editor);
            $change = app(SaveEntryDraft::class)->handle($entry, $editor, 'vi', ['title' => 'Bản mới']);
            $content = $change->content;
            $content['locales']['vi']['body'] = attackBody();
            forceStored('entry_drafts', $change->id, 'content', $content);

            app(ApplyEntryDraft::class)->handle($entry->fresh(), $editor, $change->fresh()->version());

            expectStoredClean(storedHtml('entry_translations', $entry->id, 'blocks.1.props.html'));
        });
    });

    it('a restored revision', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $editor = bodyEditor();
            $entry = livePage($editor);
            app(RecordEntryRevision::class)->forLocale($entry, $entry->translationFor('vi'), RevisionKind::Edit, $editor);
            $revision = EntryRevision::query()->where('entry_id', $entry->id)->latest('id')->firstOrFail();
            forceStored('entry_revisions', $revision->id, 'snapshot', ['body' => attackBody()] + $revision->snapshot);

            app(RestoreEntryRevision::class)->handle($revision->fresh(), $editor);

            expectStoredClean(storedHtml('entry_translations', $entry->id, 'blocks.1.props.html'));
        });
    });

    it('a duplicated entry', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $editor = bodyEditor();
            $entry = livePage($editor);
            forceStored('entry_translations', $entry->translationFor('vi')->id, 'body', attackBody());

            $copy = app(DuplicateEntry::class)->handle($entry->fresh(), $editor);

            expectStoredClean(storedHtml('entry_translations', $copy->id, 'blocks.1.props.html'));
        });
    });

    it('a content pack, which then loads again as unchanged', function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $folder = ContentPacks::use();
        $siteId = ContentPacks::site();

        try {
            ContentPacks::item($folder, 'posts.json', 'post:thiet-ke-mau', function (array $item): array {
                $item['body'] = ['vi' => attackBody()['blocks']];

                return $item;
            });

            [$exit, $output] = ContentPacks::load();
            expect($exit)->toBe(0, $output);

            ContentPacks::in($siteId, function (): void {
                $entryId = (int) EntryTranslation::query()->where('slug', 'thiet-ke-mau')->value('entry_id');
                expectStoredClean(storedHtml('entry_translations', $entryId, 'blocks.1.props.html'));
            });

            // the plan compares the pack's body as the database will hold it, so a pack whose html the
            // sanitizer rewrites does not show as changed on every run
            [, $again] = ContentPacks::run();
            expect(ContentPacks::verdict($again, 'post:thiet-ke-mau'))->toBe('unchanged');
        } finally {
            ContentPacks::cleanup();
        }
    });
});
