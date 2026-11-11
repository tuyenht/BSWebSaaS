<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\StyleProps;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The "Trình bày" panel of each block in the editor: every choice is one of the listed look props
 * (anything else is ignored), a colour set paints the band, a background photo turns the photo band
 * on and off, spacing is joined unless shown apart, a typed anchor becomes a valid one, a new block
 * carries no look props until one is chosen, and the look saves through the same validation.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * @param  list<array<string, mixed>>  $blocks
 * @param  list<string>  $permissions
 */
function lookEditor(array $blocks, callable $then, array $permissions = ['entries.view', 'entries.create', 'entries.edit.any']): void
{
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor, $blocks, $then, $permissions): void {
        grantRole($editor, 'editor', $permissions);
        $then(Livewire::actingAs($editor->fresh())->test(Editor::class)->set('blocks', $blocks));
    });
}

it('shows the look panel under each block and keeps the look props out of the block fields', function (): void {
    lookEditor([['type' => 'richtext', 'props' => ['heading' => 'Một']], ['type' => 'page_header', 'props' => ['title' => 'Hai']]], function (Testable $test): void {
        $test->assertSee(__('entries.look_heading'))
            ->assertSee(__('entries.look_scheme_brand'))
            ->assertSee(__('entries.look_motion_first'))
            // The second block keeps the automatic choice and says what it draws for its kind.
            ->assertSee(__('entries.look_motion_auto'))
            ->assertSee(__('entries.look_motion_auto_hint', ['effect' => __('entries.look_summary_motion_none')]))
            ->assertSee(__('entries.look_spacing_unset'))
            ->assertDontSeeHtml('wire:model="blocks.0.props.width"')
            ->assertDontSeeHtml('wire:model="blocks.0.props.scheme"')
            ->assertSeeHtml("openBlockImagePicker('0.props.bg_image')")
            // A photo block keeps its own photo and overlay: no second photo on the panel.
            ->assertDontSeeHtml("openBlockImagePicker('1.props.bg_image')")
            ->assertSeeHtml('wire:model="blocks.1.props.overlay"');
    });
});

it('keeps only listed choices and paints the band when a colour set is picked', function (): void {
    lookEditor([['type' => 'richtext', 'props' => ['heading' => 'Một']]], function (Testable $test): void {
        $test->call('setLook', 0, 'scheme', 'dark')
            ->assertSet('blocks.0.props.scheme', 'dark')
            ->assertSet('blocks.0.props.bg', 'scheme')
            ->call('setLook', 0, 'width', 'narrow')
            ->call('setLook', 0, 'motion', 'auto')
            ->assertSet('blocks.0.props.motion', 'auto')
            ->call('setLook', 0, 'motion', 'rise')
            ->call('setLook', 0, 'scheme', 'neon')
            ->call('setLook', 0, 'width', 'huge')
            ->call('setLook', 0, 'heading', 'x')
            ->call('setLook', 7, 'width', 'full')
            ->call('setLook', 0, 'spacing', '9px')
            ->assertSet('blocks.0.props', ['heading' => 'Một', 'scheme' => 'dark', 'bg' => 'scheme', 'width' => 'narrow', 'motion' => 'rise'])
            ->assertCount('blocks', 1);
    });
});

it('marks the set a block paints today, older backgrounds and hero bands included', function (): void {
    // A hero or cta with no band set paints the dark colour (storefront.css), so that is the one marked.
    expect(StyleProps::schemeOf([], 'hero'))->toBe('dark')
        ->and(StyleProps::schemeOf([], 'cta'))->toBe('dark')
        ->and(StyleProps::schemeOf([], 'richtext'))->toBe('light')
        ->and(StyleProps::schemeOf(['bg' => 'tint'], 'features'))->toBe('soft')
        ->and(StyleProps::schemeOf(['bg' => 'dark'], 'features'))->toBe('dark')
        ->and(StyleProps::schemeOf(['bg' => 'scheme', 'scheme' => 'soft'], 'hero'))->toBe('soft')
        ->and(StyleProps::schemeOf(['bg' => 'scheme', 'scheme' => 'x'], 'hero'))->toBe('light');
});

it('warns when two strong bands of the same set sit side by side', function (): void {
    lookEditor([
        ['type' => 'richtext', 'props' => ['bg' => 'scheme', 'scheme' => 'dark']],
        ['type' => 'richtext', 'props' => ['bg' => 'scheme', 'scheme' => 'dark']],
    ], function (Testable $test): void {
        $test->assertSee(__('entries.look_scheme_clash', ['scheme' => __('entries.look_scheme_dark')]))
            ->call('setLook', 1, 'scheme', 'light')
            ->assertDontSee(__('entries.look_scheme_clash', ['scheme' => __('entries.look_scheme_dark')]));
    });
});

it('joins the top and bottom spacing unless shown apart', function (): void {
    lookEditor([['type' => 'richtext', 'props' => []]], function (Testable $test): void {
        $test->call('setLook', 0, 'spacing', 'l')
            ->assertSet('blocks.0.props.spacing_top', 'l')
            ->assertSet('blocks.0.props.spacing_bottom', 'l')
            ->call('toggleLookSplit', 0)
            ->assertSeeHtml("setLook(0, 'spacing_bottom', 'xl')")
            ->call('setLook', 0, 'spacing_bottom', 'xl')
            ->assertSet('blocks.0.props.spacing_top', 'l')
            ->assertSet('blocks.0.props.spacing_bottom', 'xl')
            ->call('toggleLookSplit', 0)
            ->assertSet('blocks.0.props.spacing_bottom', 'l')
            ->assertDontSeeHtml("setLook(0, 'spacing_bottom', 'xl')");
    });
});

it('turns the photo band on when a background photo is picked and back to the set when it is removed', function (): void {
    lookEditor([['type' => 'richtext', 'props' => ['heading' => 'Một', 'bg' => 'scheme', 'scheme' => 'soft']]], function (Testable $test): void {
        $photo = Media::query()->create([
            'collection' => 'library', 'slug' => 'anh', 'hash' => str_repeat('a', 64),
            'storage_key' => 'a/library/x-aaaaaaaaaaaa.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
        ]);

        $test->call('openBlockImagePicker', '0.props.bg_image')
            ->call('selectBlockImage', $photo->id)
            ->assertSet('blocks.0.props.bg', 'image')
            ->assertSet('blocks.0.props.bg_image', $photo->id)
            ->assertSee(__('entries.look_image_on_note'))
            ->call('setLook', 0, 'overlay', '75')
            ->call('setLook', 0, 'scheme', 'dark')
            ->assertSet('blocks.0.props.bg', 'image')
            ->call('removeLookImage', 0)
            ->assertSet('blocks.0.props', ['heading' => 'Một', 'bg' => 'scheme', 'scheme' => 'dark']);
    }, ['entries.view', 'entries.create', 'entries.edit.any', 'media.view']);
});

it('makes a typed anchor a valid one and clears a blank one', function (): void {
    lookEditor([['type' => 'richtext', 'props' => []]], function (Testable $test): void {
        $test->set('blocks.0.props.anchor_id', 'Liên hệ Đà Nẵng!')
            ->assertSet('blocks.0.props.anchor_id', 'lien-he-da-nang')
            ->set('blocks.0.props.anchor_id', str_repeat('ab-', 40))
            ->assertSet('blocks.0.props.anchor_id', fn (string $anchor): bool => preg_match(StyleProps::ANCHOR_PATTERN, $anchor) === 1)
            ->set('blocks.0.props.anchor_id', '  ')
            ->assertSet('blocks.0.props', []);
    });
});

it('keeps an anchor with no Latin letters as typed and says how to fix it', function (): void {
    lookEditor([['type' => 'richtext', 'props' => ['anchor_id' => 'cu']]], function (Testable $test): void {
        $test->set('blocks.0.props.anchor_id', 'お問い合わせ')
            ->assertSet('blocks.0.props.anchor_id', 'お問い合わせ')
            ->assertHasErrors('blocks.0.props.anchor_id')
            ->assertSee(__('entries.look_anchor_invalid'))
            ->set('blocks.0.props.anchor_id', 'lien he')
            ->assertSet('blocks.0.props.anchor_id', 'lien-he')
            ->assertHasNoErrors('blocks.0.props.anchor_id');
    });
});

it('numbers an anchor another block or the page layout already uses', function (): void {
    lookEditor([['type' => 'richtext', 'props' => ['anchor_id' => 'lien-he']], ['type' => 'richtext', 'props' => []], ['type' => 'richtext', 'props' => []]], function (Testable $test): void {
        $test->set('blocks.1.props.anchor_id', 'Liên hệ')
            ->assertSet('blocks.1.props.anchor_id', 'lien-he-2')
            ->set('blocks.2.props.anchor_id', 'Liên hệ')
            ->assertSet('blocks.2.props.anchor_id', 'lien-he-3')
            // The skip link's target: a menu link to it must reach the block, not the top of the page.
            ->set('blocks.2.props.anchor_id', 'Nội dung')
            ->assertSet('blocks.2.props.anchor_id', 'noi-dung-2')
            // Retyping a block's own anchor keeps it.
            ->set('blocks.0.props.anchor_id', 'lien-he')
            ->assertSet('blocks.0.props.anchor_id', 'lien-he');
    });
});

it('adds a block with no look props and saves a chosen look through the page validation', function (): void {
    lookEditor([], function (Testable $test): void {
        $test->set('title', 'Giới thiệu')
            ->set('slug', 'gioi-thieu')
            ->call('addBlock', 'richtext')
            ->assertSet('blocks.0.props', fn (array $props): bool => array_intersect(array_keys($props), StyleProps::RESERVED) === [])
            ->set('blocks.0.props.heading', 'Một')
            ->call('setLook', 0, 'scheme', 'brand')
            ->call('setLook', 0, 'motion', 'fade')
            ->set('blocks.0.props.anchor_id', 'gioi-thieu')
            ->call('save')
            ->assertHasNoErrors();

        $props = Entry::query()->where('type', 'page')->firstOrFail()->translationFor('vi')->body['blocks'][0]['props'];
        expect($props)->toMatchArray(['scheme' => 'brand', 'bg' => 'scheme', 'motion' => 'fade', 'anchor_id' => 'gioi-thieu']);
    });
});

it('leaves a block\'s own prop of the same name alone', function (): void {
    // page_header's own `overlay` is how dark its photo is (a number), not the look panel's choice.
    lookEditor([['type' => 'page_header', 'props' => ['title' => 'Một', 'overlay' => 40]]], function (Testable $test): void {
        $test->call('setLook', 0, 'overlay', '75')
            ->assertSet('blocks.0.props.overlay', 40)
            // Its photo and darkening are its own: the panel's photo actions leave them alone.
            ->call('removeLookImage', 0)
            ->assertSet('blocks.0.props.overlay', 40)
            ->call('setLook', 0, 'width', 'narrow')
            ->assertSet('blocks.0.props.width', 'narrow');
    });
});

it('never turns the panel photo band on for a block with a photo of its own', function (): void {
    lookEditor([['type' => 'page_header', 'props' => ['title' => 'Một', 'overlay' => 60]]], function (Testable $test): void {
        $photo = Media::query()->create([
            'collection' => 'library', 'slug' => 'anh-dau-trang', 'hash' => str_repeat('b', 64),
            'storage_key' => 'a/library/y-bbbbbbbbbbbb.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
        ]);

        // A crafted call: the panel shows no photo button on this block.
        $test->call('openBlockImagePicker', '0.props.bg_image')
            ->call('selectBlockImage', $photo->id)
            ->assertSet('blocks.0.props.bg', null)
            ->assertSet('blocks.0.props.overlay', 60);
    }, ['entries.view', 'entries.create', 'entries.edit.any', 'media.view']);
});
