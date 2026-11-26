<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;

/*
 * The cover/OG image of a post or page (design 07 «Ảnh đại diện»). The chosen master's row is
 * stored in entries.cover_media_id (the form holds its ulid, coverUlid; the old meta.image_url key is
 * dropped). «Chọn ảnh» / «Đổi ảnh» open the shared «Chọn ảnh» dialog
 * (WithMediaLibraryPicker) in its single mode: one picture of THIS site's live library, re-resolved
 * by id, never a value from the wire (coverUlid is locked). Picking says «Đã chọn ảnh đại diện»,
 * «Bỏ ảnh» says «Đã gỡ ảnh đại diện» with «Hoàn tác». The cover is every language's, so it changes in
 * the website's default language only. Without media.view the card and picture stay, the buttons go.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Create a media in the CURRENT site context — the caller wraps Tenancy::enter (no nesting). */
function seedPickableMedia(array $attrs = []): Media
{
    return Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => 'anh',
        'hash' => str_repeat('a', 64),
        'storage_key' => 'a/library/anh-aaaaaaaaaaaa.png',
        'disk' => 'r2',
        'mime' => 'image/png',
        'size' => 1024,
    ], $attrs));
}

/** A draft page of site 1 with $key as its cover (the caller is inside Tenancy::enter). */
function coverPickerPage(?string $key = null, ?string $alt = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => $key !== null ? ['image_url' => $key] : []]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'A', 'slug' => 'a', 'status' => 'draft', 'body' => ['blocks' => []], 'cover_alt' => $alt]);

    return $page;
}

it('picks one cover in the shared dialog, says so, and stores it as cover_media_id on save', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();
        $other = seedPickableMedia(['slug' => 'khac', 'hash' => str_repeat('e', 64), 'storage_key' => 'a/library/khac-eeeeeeeeeeee.png']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Trang A')
            ->set('slug', 'trang-a')
            ->call('addBlock', 'richtext')
            ->call('openCoverPicker')
            ->assertSet('showImagePicker', true)
            ->assertSet('imagePickerMode', 'single')
            ->assertSet('pickerType', 'image')
            ->assertSee(__('media.picker_insert_one'))
            ->assertSee(__('media.picker_note_cover'))
            ->call('togglePickerItem', $other->id)
            ->call('togglePickerItem', $media->id)
            ->assertSet('pickerSelected', [$media->id])      // one picture: a second pick replaces the first
            ->call('insertPicked')
            ->assertSet('showImagePicker', false)
            ->assertSet('coverUlid', $media->ulid)
            ->assertSet('coverToast', __('entries.ed.cover_picked'))
            ->assertSet('coverUndo', null)
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::query()->where('type', 'page')->first();
        expect($entry?->cover_media_id)->toBe($media->id)
            ->and(data_get($entry?->meta, 'image_url'))->toBeNull();
    });
});

it('loads an existing cover into the editor on edit', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia(['width' => 1600, 'height' => 900, 'original_filename' => 'xuong-may.png']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => coverPickerPage($media->storage_key)->ulid])
            ->assertSet('coverUlid', $media->ulid)
            ->assertSee(__('entries.ed.cover_img', ['name' => 'xuong-may.png']))
            ->assertSee(__('entries.ed.cover_ratio_ok'))
            ->assertSee(__('entries.ed.cover_where'))
            ->assertSee(__('entries.ed.cover_change'));
    });
});

it('warns when the cover is not landscape 16:9', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia(['width' => 800, 'height' => 800]);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => coverPickerPage($media->storage_key)->ulid])
            ->assertSee(__('entries.ed.cover_ratio_warn'))
            ->assertDontSee(__('entries.ed.cover_ratio_ok'));
    });
});

it('removes the cover with «Hoàn tác» that puts the picture and its words back', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();
        $page = coverPickerPage($media->storage_key, 'Thợ đứng máy');

        $test = Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $page->ulid])
            ->call('clearCover')
            ->assertSet('coverUlid', '')
            ->assertSet('coverAlt', '')
            ->assertSet('coverToast', __('entries.ed.cover_removed'))
            ->assertSet('coverUndo', ['ulid' => $media->ulid, 'alt' => 'Thợ đứng máy'])
            ->assertSeeHtml('wire:click="undoCoverRemoval"')
            ->call('undoCoverRemoval')
            ->assertSet('coverUlid', $media->ulid)
            ->assertSet('coverAlt', 'Thợ đứng máy')
            ->assertSet('coverToast', '')
            ->assertSet('coverUndo', null);

        // without the message there is nothing to undo: a second call changes nothing
        $test->call('clearCover')->call('closeCoverToast', $test->get('coverToastSeq'))
            ->call('undoCoverRemoval')
            ->assertSet('coverUlid', '')
            ->call('save')
            ->assertHasNoErrors();

        $saved = Entry::query()->find($page->id);
        expect(data_get($saved?->meta, 'image_url'))->toBeNull()->and($saved?->cover_media_id)->toBeNull();
    });
});

it('does not put back a cover that left the library before «Hoàn tác», and an old message cannot close the new one', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();
        $test = Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => coverPickerPage($media->storage_key)->ulid])
            ->call('clearCover');
        $seq = $test->get('coverToastSeq');

        $media->delete();   // taken out of the library meanwhile
        $test->call('undoCoverRemoval')->assertSet('coverUlid', '')->assertSet('coverUndo', null);

        $test->call('openCoverPicker')->call('togglePickerItem', seedPickableMedia([
            'slug' => 'moi', 'hash' => str_repeat('e', 64), 'storage_key' => 'a/library/moi-eeeeeeeeeeee.png',
        ])->id)->call('insertPicked')
            ->call('closeCoverToast', $seq)                       // the earlier message's number
            ->assertSet('coverToast', __('entries.ed.cover_picked'));
    });
});

it('lands the pick on the cover after a block picture\'s dialog was closed without one', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();

        Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Chào', 'image_url' => null]]])
            ->call('openBlockImagePicker', '0.props.image_url')
            ->call('closeImagePicker')
            ->call('openCoverPicker')
            ->assertSee(__('media.picker_insert_one'))
            ->call('togglePickerItem', $media->id)
            ->call('insertPicked')
            ->assertSet('coverUlid', $media->ulid)
            ->assertSet('blocks.0.props.image_url', null);
    });
});

it('refuses a cover change in a language other than the website\'s default', function (): void {
    $editor = User::factory()->create();
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $media = seedPickableMedia();
        $other = seedPickableMedia(['slug' => 'khac', 'hash' => str_repeat('e', 64), 'storage_key' => 'a/library/khac-eeeeeeeeeeee.png']);
        $page = coverPickerPage($media->storage_key);
        $page->translations()->create(['locale' => 'en', 'title' => 'A en', 'slug' => 'a-en', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $guard = __('entries.ed.shared_guard', ['lang' => 'Tiếng Việt']);

        $test = Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $page->ulid, 'locale' => 'en'])
            ->assertSee(__('entries.ed.shared_chip'))           // the «Chung» chip on the cover card
            ->call('openCoverPicker')
            ->assertSet('showImagePicker', false)
            ->assertDispatched('bs-toast', fn (string $name, array $params): bool => str_starts_with($params['text'], mb_substr($guard, 0, 30)))
            ->call('clearCover')
            ->assertSet('coverUlid', $media->ulid);

        // a crafted pick (the dialog opened in another mode, then switched): the cover stays
        $test->call('openImagePicker', 'single')
            ->call('togglePickerItem', $other->id)
            ->call('insertPicked')
            ->assertSet('coverUlid', $media->ulid)
            // nor does a copy kept on this device carry another picture into it
            ->call('restoreKept', ['coverUlid' => $other->ulid])
            ->assertSet('coverUlid', $media->ulid);
    });
});

it('never takes a cover from the wire: a typed ulid, a malformed one and a kept copy of another site\'s are refused', function (): void {
    $editor = User::factory()->create();
    $foreign = app(Tenancy::class)->enter(2, fn (): Media => seedPickableMedia([
        'storage_key' => 'b/library/foreign-bbbbbbbbbbbb.png', 'hash' => str_repeat('b', 64),
    ]));

    app(Tenancy::class)->enter(1, function () use ($editor, $foreign): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $mine = seedPickableMedia();

        $test = Livewire::actingAs($editor->fresh())->test(Editor::class);
        expect(fn () => $test->set('coverUlid', $foreign->ulid))->toThrow(CannotUpdateLockedPropertyException::class);

        $test->call('restoreKept', ['coverUlid' => $foreign->ulid])
            ->assertSet('coverUlid', '')
            ->call('restoreKept', ['coverUlid' => 'not-a-ulid'])
            ->assertSet('coverUlid', '')
            ->call('restoreKept', ['coverUlid' => strtolower($mine->ulid)])
            ->assertSet('coverUlid', $mine->ulid);
    });
});

it('refuses another site\'s picture in the dialog (RLS / no injection)', function (): void {
    $editor = User::factory()->create();
    $foreign = app(Tenancy::class)->enter(2, fn (): Media => seedPickableMedia([
        'storage_key' => 'b/library/foreign-bbbbbbbbbbbb.png', 'hash' => str_repeat('b', 64),
    ]));

    app(Tenancy::class)->enter(1, function () use ($editor, $foreign): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('openCoverPicker')
            ->call('togglePickerItem', $foreign->id)   // belongs to site 2 — invisible under RLS
            ->assertSet('pickerSelected', [])
            ->call('pickImage', $foreign->id)
            ->call('insertPicked')
            ->assertSet('coverUlid', '');               // rejected, nothing stored
    });
});

it('does not take a file that is not a picture as the cover', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        $pdf = seedPickableMedia(['storage_key' => 'a/library/ho-so.pdf', 'hash' => str_repeat('f', 64), 'mime' => 'application/pdf']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('openCoverPicker')
            ->call('pickImage', $pdf->id)
            ->assertSet('coverUlid', '');
    });
});

it('shows the cover card and picture without media.view, with no buttons, and forbids the picker', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']); // no media.view
        $media = seedPickableMedia(['original_filename' => 'xuong-may.png']);
        $page = coverPickerPage($media->storage_key);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $page->ulid])
            ->assertSee(__('entries.ed.card_cover'))
            ->assertSee(__('entries.ed.cover_img', ['name' => 'xuong-may.png']))
            ->assertDontSeeHtml('wire:click="openCoverPicker"')
            ->assertDontSeeHtml('wire:click="clearCover"')
            ->call('openCoverPicker')
            ->assertForbidden();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $page->ulid])
            ->call('clearCover')
            ->assertForbidden();

        // an empty card reads «Chưa có ảnh» (design 07 read-only empty box)
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->assertSee(__('entries.ed.cover_none'))
            ->assertDontSeeHtml('wire:click="openCoverPicker"');
    });
});

it('shows only this site library in the picker (RLS)', function (): void {
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(2, fn (): Media => seedPickableMedia([
        'storage_key' => 'b/library/site-b-cover.png', 'hash' => str_repeat('2', 64), 'original_filename' => 'site-b-cover.png',
    ]));

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
        seedPickableMedia(['original_filename' => 'site-a-cover.png']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('openCoverPicker')
            ->assertSee('site-a-cover.png')
            ->assertDontSee('site-b-cover.png');
    });
});

it('opens the picker without the upload tab for a member who may not add files', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']); // no media.upload
        seedPickableMedia();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('openCoverPicker')
            ->assertSee(__('media.picker_subtitle_library'))
            ->assertDontSee(__('media.picker_tab_upload'))
            ->call('setPickerTab', 'upload')
            ->assertSet('pickerTab', 'library');
    });
});

/** A page of site 1 whose cover is the library row $media names, with no old key (the caller is inside Tenancy::enter). */
function coverPickerPageById(Media $media, string $slug = 'theo-ma'): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'cover_media_id' => $media->id, 'meta' => []]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'B', 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page;
}

describe('covers named by their row', function (): void {
    it('shows a cover named only by its row, with the same queries as one named by its old key', function (): void {
        $editor = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
            $media = seedPickableMedia(['width' => 1600, 'height' => 900, 'original_filename' => 'xuong-may.png']);
            $byKey = coverPickerPage($media->storage_key);
            $byId = coverPickerPageById($media);
            $user = $editor->fresh();
            $count = function (Entry $page) use ($user): int {
                DB::flushQueryLog();
                DB::enableQueryLog();
                Livewire::actingAs($user)->test(Editor::class, ['ulid' => $page->ulid]);
                DB::disableQueryLog();

                return count(DB::getQueryLog());
            };
            $count($byKey);   // warm

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $byId->ulid])
                ->assertSet('coverUlid', $media->ulid)
                ->assertSee(__('entries.ed.cover_img', ['name' => 'xuong-may.png']))
                ->assertSee(__('entries.ed.cover_ratio_ok'));
            expect($count($byId))->toBe($count($byKey));
        });
    });

    it('clears a cover named by its row: no id and no old key', function (): void {
        $editor = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
            $media = seedPickableMedia();
            $page = coverPickerPageById($media);
            Entry::query()->whereKey($page->id)->update(['meta' => json_encode(['image_url' => $media->storage_key])]);   // an old key left beside it

            Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid])
                ->call('clearCover')
                ->call('save')
                ->assertHasNoErrors();

            $saved = Entry::query()->find($page->id);
            expect($saved?->cover_media_id)->toBeNull()->and($saved?->meta)->not->toHaveKey('image_url');
        });
    });

    it('puts a cover named by its row back with «Hoàn tác» and saves it by its row', function (): void {
        $editor = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
            $media = seedPickableMedia();
            $page = coverPickerPageById($media);

            Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid])
                ->call('clearCover')
                ->assertSet('coverUndo', ['ulid' => $media->ulid, 'alt' => ''])
                ->call('undoCoverRemoval')
                ->assertSet('coverUlid', $media->ulid)
                ->set('title', 'B mới')
                ->call('save')
                ->assertHasNoErrors();

            expect(Entry::query()->find($page->id)?->cover_media_id)->toBe($media->id);
        });
    });

    it('writes an untouched old key as its row on the next save, and leaves a glyph as it is', function (): void {
        $editor = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
            $media = seedPickableMedia();
            $page = coverPickerPage($media->storage_key, 'Thợ đứng máy');
            $glyph = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => ['image_url' => 'gear']]);
            $glyph->translations()->create(['locale' => 'vi', 'title' => 'G', 'slug' => 'bieu-tuong', 'status' => 'draft', 'body' => ['blocks' => []]]);

            Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid])
                ->set('title', 'A mới')->call('save')->assertHasNoErrors();
            Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $glyph->ulid])
                ->assertSet('coverUlid', '')
                ->set('title', 'G mới')->call('save')->assertHasNoErrors();

            $saved = Entry::query()->find($page->id);
            expect($saved?->cover_media_id)->toBe($media->id)
                ->and($saved?->meta)->not->toHaveKey('image_url')
                ->and($saved?->translationFor('vi')?->cover_alt)->toBe('Thợ đứng máy')   // the same picture: its words stay
                ->and(Entry::query()->find($glyph->id)?->meta)->toBe(['image_url' => 'gear'])
                ->and(Entry::query()->find($glyph->id)?->cover_media_id)->toBeNull();
        });
    });

    it('refuses to save a picked cover that left the library meanwhile, and says so', function (): void {
        $editor = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
            $media = seedPickableMedia();
            $page = coverPickerPage();
            $test = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid])
                ->call('openCoverPicker')->call('togglePickerItem', $media->id)->call('insertPicked')
                ->assertSet('coverUlid', $media->ulid);

            $media->delete();
            $test->call('save')
                ->assertDispatched('bs-toast', text: __('entries.ed.cover_gone'))
                ->assertSet('coverUlid', '');
            expect(Entry::query()->find($page->id)?->cover_media_id)->toBeNull();
        });
    });

    it('takes a form opened before covers were named by their row: its key becomes the row, kept or changed', function (): void {
        $editor = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);
            $media = seedPickableMedia();
            $other = seedPickableMedia(['slug' => 'khac', 'hash' => str_repeat('e', 64), 'storage_key' => 'a/library/khac-eeeeeeeeeeee.png']);
            $page = coverPickerPage($media->storage_key);

            // the form as the editor before this change left it in the browser: the cover as its key, no marks
            $oldTab = function (string $key) use ($editor, $page) {
                $form = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid]);
                $snapshot = $form->snapshot;
                unset($snapshot['checksum'], $snapshot['data']['coverUlid'], $snapshot['data']['coverTouched'], $snapshot['data']['coverById'],
                    $snapshot['data']['saved'][0]['coverUlid']);
                $snapshot['data']['coverImageKey'] = $key;
                $snapshot['data']['saved'][0]['coverImageKey'] = $page->fresh()->meta['image_url'] ?? '';
                $snapshot['checksum'] = Checksum::generate($snapshot);
                $form->snapshot = $snapshot;

                return $form;
            };

            // kept as it was read: saved as its row
            $oldTab($media->storage_key)->set('title', 'A mới')
                ->assertSet('coverUlid', $media->ulid)
                ->assertSet('coverImageKey', '')
                ->call('save')->assertHasNoErrors();
            $saved = Entry::query()->find($page->id);
            expect($saved?->cover_media_id)->toBe($media->id)->and($saved?->meta)->not->toHaveKey('image_url');

            // changed in that form before the change: the picture it picked, as its row
            Entry::query()->whereKey($page->id)->update(['cover_media_id' => null, 'meta' => json_encode(['image_url' => $media->storage_key])]);
            $oldTab($other->storage_key)->set('title', 'A mới hơn')
                ->assertSet('coverUlid', $other->ulid)
                ->call('save')->assertHasNoErrors();
            expect(Entry::query()->find($page->id)?->cover_media_id)->toBe($other->id);
        });
    });
});
