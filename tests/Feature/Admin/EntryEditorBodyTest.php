<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Support\BlockKinds;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\ComponentRegistry;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\StorefrontCards;

/*
 * The «Soạn thảo» body of the post and page editor (ADR-19, one body / two editors): a body that is
 * empty or one formatted-text block is written in the rich editor; a body of older plain-text blocks
 * is shown converted (in memory, written by the person's save, a revision of the old body kept
 * first); any other body is a layout, locked in «Soạn thảo» and edited in «Trình dựng». Pictures
 * reach the browser as content-hash addresses and are stored by library id.
 */

/** A draft entry of the current site whose vi body is $blocks. */
function bodyEntry(User $by, array $blocks, string $type = 'page'): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => 'draft', 'author_id' => $by->id, 'meta' => []]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'draft', 'body' => ['blocks' => $blocks]]);

    return $entry;
}

/** @return list<array{type: string, props: array<string, mixed>}> */
function storedBlocks(Entry $entry): array
{
    return $entry->fresh()?->translationFor('vi')?->body['blocks'] ?? [];
}

function plainBlock(array $props): array
{
    return ['type' => 'richtext', 'props' => $props];
}

describe('in the editor', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
        app()->setLocale('vi');
        StorefrontCards::pinMediaConfig();
        $this->editor = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($this->editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'media.view']));
    });

    it('shows a body of plain-text blocks as one document and writes nothing until the save', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $old = [
                plainBlock(['heading' => 'Về chúng tôi', 'paragraphs' => ["Xưởng **cơ khí** <chính xác>\nhai mươi năm", '']]),
                plainBlock(['paragraphs' => ['Đoạn hai']]),
            ];
            $entry = bodyEntry($this->editor, $old);
            $before = $entry->fresh()?->translationFor('vi');

            $c = Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('prose', true)
                ->assertSet('converted', true)
                ->assertSet('blocks', [])
                ->assertSet('bodyHtml', '<h2>Về chúng tôi</h2><p>Xưởng <strong>cơ khí</strong> &lt;chính xác&gt;<br>hai mươi năm</p><p>Đoạn hai</p>')
                ->assertSeeHtml('data-rich-editor="full"')
                // the conversion is not an unsaved change of the person's
                ->assertViewHas('changes', 0);

            // Opening wrote nothing: same body, same time, no revision.
            $after = $entry->fresh()?->translationFor('vi');
            expect($after?->body)->toBe(['blocks' => $old])
                ->and($after?->updated_at?->toIso8601String())->toBe($before?->updated_at?->toIso8601String())
                ->and(EntryRevision::query()->where('entry_id', $entry->id)->count())->toBe(0);

            $c->call('save')->assertHasNoErrors();

            expect(storedBlocks($entry))->toBe([[
                'type' => 'richtext_html',
                // as the sanitizer writes it (a line break closes itself)
                'props' => ['html' => '<h2>Về chúng tôi</h2><p>Xưởng <strong>cơ khí</strong> &lt;chính xác&gt;<br />hai mươi năm</p><p>Đoạn hai</p>'],
            ]]);
            // The old body was kept as a revision before the first save wrote over it.
            $revision = EntryRevision::query()->where('entry_id', $entry->id)->sole();
            expect($revision->kind)->toBe(RevisionKind::Baseline)
                ->and($revision->snapshot['body'])->toBe(['blocks' => $old]);

            // A second save keeps no second copy of the old body.
            $c->assertSet('converted', false)->set('bodyHtml', '<p>Sửa lại</p>')->call('save')->assertHasNoErrors();
            expect(EntryRevision::query()->where('entry_id', $entry->id)->count())->toBe(1)
                ->and(storedBlocks($entry)[0]['props']['html'])->toBe('<p>Sửa lại</p>');
        });
    });

    it('opens a post of plain-text blocks in the one «Soạn thảo» tab', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [plainBlock(['paragraphs' => ['Tin mới']])], 'post');

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('prose', true)
                ->assertSet('bodyHtml', '<p>Tin mới</p>')
                ->assertSeeHtml('data-rich-editor="full"')
                ->assertSee('Soạn thảo')
                ->assertDontSee('Trình dựng')
                ->assertDontSeeHtml('wire:click="addBlock(');
        });
    });

    it('keeps the look of plain-text blocks that share one, and treats differing looks as a layout', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $look = ['bg' => 'tint', 'spacing_top' => 'l'];
            $same = bodyEntry($this->editor, [plainBlock(['paragraphs' => ['Một']] + $look), plainBlock(['paragraphs' => ['Hai']] + $look)]);
            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $same->ulid])
                ->assertSet('prose', true)
                ->call('save')
                ->assertHasNoErrors();
            $stored = storedBlocks($same);
            expect($stored)->toHaveCount(1)
                ->and($stored[0]['props'])->toMatchArray($look + ['html' => '<p>Một</p><p>Hai</p>']);

            $mixed = [plainBlock(['paragraphs' => ['Một'], 'bg' => 'tint']), plainBlock(['paragraphs' => ['Hai']])];
            $differs = bodyEntry($this->editor, $mixed);
            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $differs->ulid])
                ->assertSet('prose', false)
                ->assertSet('converted', false);
        });
    });

    it('locks a page laid out in other blocks and keeps its blocks as they are', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $layout = [
                ['type' => 'hero', 'props' => ['heading' => 'Chào mừng']],
                plainBlock(['paragraphs' => ['Đoạn']]),
            ];
            $entry = bodyEntry($this->editor, $layout);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('prose', false)
                ->assertSet('bodyHtml', '')
                ->assertSee('Trang này gồm 2 khối')
                ->assertSee('Xem bố cục trang')
                ->assertDontSeeHtml('data-rich-editor')
                ->assertSee('Trình dựng')
                ->set('title', 'Giới thiệu (sửa)')
                ->call('save')
                ->assertHasNoErrors();

            expect(array_column(storedBlocks($entry), 'type'))->toBe(['hero', 'richtext'])
                ->and(EntryRevision::query()->where('entry_id', $entry->id)->count())->toBe(0);
        });
    });

    it('shows both tabs on a page, «Soạn thảo» first, and the written body as one row in «Trình dựng»', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => '<h2>Về xưởng</h2><p>Gia công chính xác</p>']]]);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSeeHtmlInOrder(['aria-pressed="true"', 'Soạn thảo', 'aria-pressed="false"', 'Trình dựng'])
                ->assertSeeHtml('data-rich-editor="full"')
                ->assertSee('Bố cục trang · 1 khối')
                ->assertSee('Các khối nội dung của bản Tiếng Việt — Soạn thảo và Trình dựng cùng sửa một nội dung.')
                ->assertSee('Văn bản có định dạng')
                ->assertSee('Về xưởng Gia công chính xác')
                ->assertSee('Thêm khối');
        });
    });

    it('names every block of a layout in Vietnamese, never by its type key', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [
                ['type' => 'hero', 'props' => ['heading' => 'Chào mừng']],
                plainBlock(['paragraphs' => ['Đoạn']]),
                ['type' => 'cta', 'props' => []],
            ]);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSee('Trang này gồm 3 khối')
                ->assertSee('Xem bố cục trang')
                ->assertSee('Bố cục trang · 3 khối')
                ->assertSeeInOrder(['Hero / Banner', 'Chào mừng', 'Văn bản (đơn giản)', 'Kêu gọi (CTA)'])
                ->assertSee('Sửa nội dung: Hero / Banner')
                ->assertSee('Xóa khối: Kêu gọi (CTA)')
                ->assertDontSeeHtml('<strong>hero</strong>')
                ->assertDontSee('+ hero');
        });

        // Every kind a body can hold has a name of its own (none falls back to «Khối»).
        foreach (app(ComponentRegistry::class)->types() as $type) {
            expect(BlockKinds::of($type)['label'])->not->toBe(__('entries.block.other'), $type);
        }
        expect(BlockKinds::of('khong_co')['label'])->toBe('Khối');
    });

    it('opens one block content form at a time, following its block when blocks move', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [
                ['type' => 'hero', 'props' => ['heading' => 'Một']],
                ['type' => 'cta', 'props' => []],
            ]);

            $c = Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('openBlock', null)
                ->assertSeeHtml('aria-controls="block-form-1" aria-expanded="false"')
                ->set('openBlock', 1)
                ->assertSeeHtml('aria-controls="block-form-1" aria-expanded="true"')
                ->assertSeeHtml('aria-controls="block-form-0" aria-expanded="false"')
                ->call('moveUp', 1)
                ->assertSet('openBlock', 0)
                ->assertSet('blocks.0.type', 'cta')
                ->call('addBlock', 'faq')
                ->assertSet('openBlock', 2)
                ->call('removeBlock', 0)
                ->assertSet('openBlock', 1)
                ->call('removeBlock', 1)
                ->assertSet('openBlock', null);

            expect(array_column($c->get('blocks'), 'type'))->toBe(['hero']);
        });
    });

    it('offers every kind a body may hold by its name in «Thêm khối»', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, []);
            $c = Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSee('Chọn loại khối muốn thêm')
                ->assertSee('Bản này chưa có nội dung. Soạn ở tab Soạn thảo hoặc thêm khối bên dưới.');
            foreach (app(ComponentRegistry::class)->listedTypes() as $type) {
                $c->assertSeeHtml("wire:click=\"addBlock('{$type}')\"")->assertSee(BlockKinds::of($type)['label']);
            }
        });
    });

    it('turns a written body into the first block of a layout when a block is added', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => '<p>Mở đầu</p>']]]);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('prose', true)
                ->call('addBlock', 'cta')
                ->assertSet('prose', false)
                ->call('save')
                ->assertHasNoErrors();

            expect(array_column(storedBlocks($entry), 'type'))->toBe(['richtext_html', 'cta'])
                ->and(storedBlocks($entry)[0]['props']['html'])->toBe('<p>Mở đầu</p>');
        });
    });

    it('stores a written body through the sanitizer', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, []);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->set('bodyHtml', '<p onclick="steal()">Xin chào<script>alert(1)</script> <a href="javascript:alert(1)">bấm</a> <a href="https://baoson.net" onmouseover="x()">trang</a></p>')
                ->call('save')
                ->assertHasNoErrors();

            $html = storedBlocks($entry)[0]['props']['html'];
            expect($html)->toContain('Xin chào')
                ->toContain('href="https://baoson.net"')
                ->not->toContain('<script')
                ->not->toContain('alert(1)')
                ->not->toContain('onclick')
                ->not->toContain('onmouseover')
                ->not->toContain('javascript:');
        });
    });

    it('stores an empty body as no block at all', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => '<p>Cũ</p>']]]);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->set('bodyHtml', '<p><br></p>')
                ->call('save')
                ->assertHasNoErrors();

            expect(storedBlocks($entry))->toBe([]);
        });
    });

    it('names a library picture by id when stored and by its address in the browser', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $media = StorefrontCards::media('may-tien-cnc');
            $url = app(EntryProseBody::class)->editorUrl($media->storage_key);
            expect($url)->toStartWith('https://files.test/')->toContain($media->storage_key);
            $entry = bodyEntry($this->editor, []);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->set('bodyHtml', '<p>Máy</p><img src="'.e($url).'" alt="Máy tiện" data-size="half" data-align="center" width="600">')
                ->call('save')
                ->assertHasNoErrors()
                // the stored form never stays in the browser's copy of the form
                ->assertSet('blocks', []);

            $html = storedBlocks($entry)[0]['props']['html'];
            expect($html)->toContain('data-media-id="'.$media->id.'"')
                ->toContain('alt="Máy tiện"')
                ->not->toContain('src=')
                ->not->toContain('files.test');

            // Reopened: the address again, the id nowhere in the page.
            $reopened = Livewire::actingAs($this->editor->fresh())->test(Editor::class, ['ulid' => $entry->ulid]);
            expect($reopened->get('bodyHtml'))->toContain('src="'.e($url).'"')->not->toContain('data-media-id');
            $reopened->assertDontSeeHtml('data-media-id');
        });
    });

    it('refuses to save a picture that is not one of this website\'s library pictures', function (string $where): void {
        $other = app(Tenancy::class)->enter(2, fn () => StorefrontCards::media('anh-site-khac'));

        app(Tenancy::class)->enter(1, function () use ($where, $other): void {
            $src = $where === 'another website'
                ? app(EntryProseBody::class)->editorUrl($other->storage_key)
                : 'https://example.com/anh.png';
            $entry = bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => '<p>Cũ</p>']]]);

            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->set('bodyHtml', '<p>Mới</p><img src="'.e($src).'" alt="">')
                ->call('save')
                ->assertHasErrors('bodyHtml')
                ->assertSee('Có 1 ảnh không nằm trong thư viện ảnh của website');

            expect(storedBlocks($entry)[0]['props']['html'])->toBe('<p>Cũ</p>');
        });
    })->with(['another website', 'a pasted link']);

    it('keeps picture ids out of the browser when a written body becomes a layout, and back on save', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $media = StorefrontCards::media('khoi-anh');
            $stored = '<p>Mở đầu</p><img data-media-id="'.$media->id.'" alt="Xưởng > máy">';
            $entry = bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => $stored]]]);

            $c = Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->call('addBlock', 'cta')
                ->assertSet('prose', false)
                ->assertDontSeeHtml('data-media-id')
                // the document is edited as several lines, never squeezed into one
                ->assertSeeHtml('wire:model="blocks.0.props.html"');
            expect($c->get('blocks.0.props.html'))->toContain($media->storage_key)->not->toContain('data-media-id');

            $c->call('save')->assertHasNoErrors()->assertDontSeeHtml('data-media-id');
            $html = storedBlocks($entry)[0]['props']['html'];
            expect($html)->toContain('data-media-id="'.$media->id.'"')
                ->and($html)->toContain('alt="Xưởng &gt; máy"')
                ->and($html)->not->toContain($media->storage_key);

            // Reopened, the layout's formatted-text block shows the address again.
            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('prose', false)
                ->assertDontSeeHtml('data-media-id');
        });
    });

    it('refuses to save a layout whose formatted text holds a picture from elsewhere', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [['type' => 'hero', 'props' => ['heading' => 'Chào']]]);
            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Chào']], ['type' => 'richtext_html', 'props' => ['html' => '<p>x</p><img src="https://example.com/a.jpg" alt="">']]])
                ->call('save')
                ->assertHasErrors('blocks');
            expect(array_column(storedBlocks($entry), 'type'))->toBe(['hero']);
        });
    });

    it('draws rows by position even when a block list arrives with other keys', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $entry = bodyEntry($this->editor, [['type' => 'hero', 'props' => ['heading' => 'Chào']]]);
            Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->set('blocks', ['1||alert(1)' => ['type' => 'hero', 'props' => ['heading' => 'Chào']]])
                ->assertSet('blocks', [['type' => 'hero', 'props' => ['heading' => 'Chào']]])
                ->assertDontSeeHtml('alert(1)');
        });
    });

    it('counts a written body as the body the approval check looks for', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $missing = fn (Editor $editor): array => (fn (): array => $this->missingPublicFields())->call($editor);
            $written = Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => '<p>Có chữ</p>']]])->ulid]);
            expect($missing($written->instance()))->not->toContain(__('admin.wfb.missing_body'));

            $written->set('bodyHtml', '<p><br></p>');
            expect($missing($written->instance()))->toContain(__('admin.wfb.missing_body'));
            $written->assertSee('Bố cục trang · 0 khối');
        });
    });

    it('lets no member without the right to edit open or save the body', function (): void {
        $viewer = User::factory()->create();
        $ulid = app(Tenancy::class)->enter(1, function () use ($viewer): string {
            grantRole($viewer, 'viewer', ['entries.view']);

            return bodyEntry($this->editor, [plainBlock(['paragraphs' => ['Đoạn']])])->ulid;
        });

        $this->actingAs($viewer)->get("http://a.test/admin/content/{$ulid}/edit")->assertForbidden();

        app(Tenancy::class)->enter(1, function () use ($ulid): void {
            $c = Livewire::actingAs($this->editor->fresh())
                ->test(Editor::class, ['ulid' => $ulid])
                ->set('bodyHtml', '<p>Ghi đè</p>');

            // The right to edit is taken away while the editor is open.
            Role::findByName('editor')->syncPermissions(['entries.view']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $c->call('save')->assertForbidden();

            $entry = Entry::query()->where('ulid', $ulid)->sole();
            expect(storedBlocks($entry))->toBe([plainBlock(['paragraphs' => ['Đoạn']])])
                ->and(EntryRevision::query()->where('entry_id', $entry->id)->count())->toBe(0);
        });
    });

    it('opens a written body with the same number of queries whatever the number of pictures', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $html = fn (array $media): string => implode('', array_map(fn ($m): string => '<p>Ảnh</p><img data-media-id="'.$m->id.'" alt="">', $media));
            $one = bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => $html([StorefrontCards::media('mot')])]]]);
            $five = bodyEntry($this->editor, [['type' => 'richtext_html', 'props' => ['html' => $html(array_map(fn (int $i) => StorefrontCards::media("nam-{$i}"), range(1, 5)))]]]);
            $user = $this->editor->fresh();

            Livewire::actingAs($user)->test(Editor::class, ['ulid' => $one->ulid]); // warm the per-process caches
            $countOne = countQueries(fn () => Livewire::actingAs($user)->test(Editor::class, ['ulid' => $one->ulid]));
            $countFive = countQueries(fn () => Livewire::actingAs($user)->test(Editor::class, ['ulid' => $five->ulid]));

            expect($countFive)->toBe($countOne);
        });
    });
});

describe('on the storefront', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->siteId = StorefrontCards::site();
        app()->setLocale('vi');
    });

    it('shows the saved body, a library picture printed from the library', function (): void {
        $editor = User::factory()->create();
        app(Tenancy::class)->enter($this->siteId, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'media.view']);
            $media = StorefrontCards::media('xuong-co-khi');
            $entry = StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [plainBlock(['paragraphs' => ['Nội dung cũ']])]);

            Livewire::actingAs($editor->fresh())
                ->test(Editor::class, ['ulid' => $entry->ulid])
                ->assertSet('converted', true)
                ->set('bodyHtml', '<h2>Năng lực</h2><p>Nội dung <strong>mới</strong></p><img src="'.e(app(EntryProseBody::class)->editorUrl($media->storage_key)).'" alt="Xưởng">')
                ->call('save')
                ->assertHasNoErrors();
        });
        app(TeamContext::class)->forget();

        $page = $this->get('http://mau.platform.test/gioi-thieu')->assertOk()->getContent();
        expect($page)->toContain('Năng lực')
            ->toContain('Nội dung <strong>mới</strong>')
            ->toContain('alt="Xưởng"')
            ->toContain('https://files.test/')
            ->not->toContain('Nội dung cũ')
            ->not->toContain('data-media-id');
    });
});
