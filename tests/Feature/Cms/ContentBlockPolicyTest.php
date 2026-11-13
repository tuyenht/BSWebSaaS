<?php

use App\Modules\Cms\Actions\ContentBlocks\CreateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DeleteContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\DuplicateContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\PublishContentBlock;
use App\Modules\Cms\Actions\ContentBlocks\SaveContentBlockBody;
use App\Modules\Cms\Actions\ContentBlocks\UpdateContentBlock;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * Who may see and change the block library (ContentBlockPolicy), role by role. Changing a block
 * (`blocks.manage`) is for the owner, the website admin and the editor: one change shows on every
 * page that uses it. Seeing the list is also for whoever may place a block: the menu screen
 * (`menus.manage`) and anyone who writes pages (an author or contributor may place a block in their
 * own page, never change it). A viewer neither sees nor changes. The rights are per website, and
 * every action refuses someone without them, however it is reached.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** @return array<string, bool> ability → allowed, for $user on site 1 about $block */
function blockAbilities(User $user, ContentBlock $block): array
{
    return app(Tenancy::class)->enter(1, fn (): array => [
        'viewAny' => $user->can('viewAny', ContentBlock::class),
        'view' => $user->can('view', $block),
        'create' => $user->can('create', ContentBlock::class),
        'update' => $user->can('update', $block),
        'publish' => $user->can('publish', $block),
        'duplicate' => $user->can('duplicate', $block),
        'delete' => $user->can('delete', $block),
    ]);
}

it('lets each role see and change the library as its template says', function (string $template, bool $sees, bool $changes): void {
    $user = blockLibraryUser(1, $template);
    $block = app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->create(['key' => 'footer', 'name' => 'Chân trang']));

    expect(blockAbilities($user, $block))->toBe([
        'viewAny' => $sees, 'view' => $sees,
        'create' => $changes, 'update' => $changes, 'publish' => $changes, 'duplicate' => $changes, 'delete' => $changes,
    ]);
})->with([
    'owner' => ['owner', true, true],
    'website admin' => ['admin', true, true],
    'editor' => ['editor', true, true],
    'author (writes pages)' => ['author', true, false],
    'contributor (writes pages)' => ['contributor', true, false],
    'viewer' => ['viewer', false, false],
]);

it('lets the menu screen and page writers list the blocks without changing them', function (array $permissions): void {
    $user = blockLibraryUser(1, 'custom-placer', $permissions);
    $block = app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->create(['key' => 'footer', 'name' => 'Chân trang']));

    expect(blockAbilities($user, $block))->toBe([
        'viewAny' => true, 'view' => true,
        'create' => false, 'update' => false, 'publish' => false, 'duplicate' => false, 'delete' => false,
    ]);
})->with([
    'menus only' => [['menus.manage']],
    'creates pages' => [['entries.view', 'entries.create']],
    'edits own pages' => [['entries.view', 'entries.edit.own']],
    'edits any page' => [['entries.view', 'entries.edit.any']],
]);

it('gives nothing to someone with no right on the website, nor carries a right to another website', function (): void {
    $nobody = blockLibraryUser(1, 'custom-nothing', ['entries.view', 'media.view']);
    $editorOfB = blockLibraryUser(2, 'editor');
    $block = app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->create(['key' => 'footer', 'name' => 'Chân trang']));

    expect(array_filter(blockAbilities($nobody, $block)))->toBe([])
        ->and(array_filter(blockAbilities($editorOfB, $block)))->toBe([]);
});

it('refuses every block action to someone who may only place blocks', function (string $template, ?array $permissions): void {
    $editor = blockLibraryUser(1, 'editor');
    $user = blockLibraryUser(1, $template, $permissions);
    $block = app(Tenancy::class)->enter(1, fn () => app(CreateContentBlock::class)->handle($editor, 'Chân trang'));

    app(Tenancy::class)->enter(1, function () use ($user, $block): void {
        $version = ContentBlockVersion::current($block);
        $calls = [
            'create' => fn () => app(CreateContentBlock::class)->handle($user, 'Khác'),
            'update' => fn () => app(UpdateContentBlock::class)->handle($user, $block, 'Đổi', 'inline', $version),
            'save content' => fn () => app(SaveContentBlockBody::class)->handle($user, $block, 'vi', [['type' => 'cta', 'props' => []]], $version),
            'publish' => fn () => app(PublishContentBlock::class)->handle($user, $block),
            'duplicate' => fn () => app(DuplicateContentBlock::class)->handle($user, $block),
            'delete' => fn () => app(DeleteContentBlock::class)->handle($user, $block),
        ];
        foreach ($calls as $name => $call) {
            expect($call)->toThrow(AuthorizationException::class, null, "[{$name}] went through");
        }

        expect(ContentBlock::query()->sole()->only(['name', 'status']))->toBe(['name' => 'Chân trang', 'status' => 'draft']);
    });
})->with([
    'author' => ['author', null],
    'contributor' => ['contributor', null],
    'menus only' => ['custom-menus', ['menus.manage']],
    'viewer' => ['viewer', null],
]);

it('lets an editor run every block action', function (): void {
    $editor = blockLibraryUser(1, 'editor');

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $block = app(CreateContentBlock::class)->handle($editor, 'Chân trang');
        $version = app(UpdateContentBlock::class)->handle($editor, $block, 'Chân trang chính', 'footer', ContentBlockVersion::current($block));
        app(SaveContentBlockBody::class)->handle($editor, $block, 'vi', [['type' => 'cta', 'props' => []]], $version);
        app(PublishContentBlock::class)->handle($editor, $block);
        $copy = app(DuplicateContentBlock::class)->handle($editor, $block);
        app(DeleteContentBlock::class)->handle($editor, $copy);

        expect(ContentBlock::query()->sole()->only(['name', 'region', 'status']))->toBe(['name' => 'Chân trang chính', 'region' => 'footer', 'status' => 'published']);
    });
});

/** Limit $user on site 1 to one new category, as the members screen would. */
function blockLimitedTo(User $user): void
{
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $category = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức']));
        MemberCategoryScope::query()->create(['user_id' => $user->id, 'taxonomy_id' => $category->id]);
    });
}

it('lets a member limited to some categories see and place blocks but never change one', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    blockLimitedTo($editor);
    $block = app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->create(['key' => 'footer', 'name' => 'Chân trang']));

    // one change to a block shows on every page and menu using it, also outside the member's limits
    expect(blockAbilities($editor, $block))->toBe([
        'viewAny' => true, 'view' => true,
        'create' => false, 'update' => false, 'publish' => false, 'duplicate' => false, 'delete' => false,
    ]);
    app(Tenancy::class)->enter(1, fn () => expect(fn () => app(CreateContentBlock::class)->handle($editor, 'Chân trang phụ', 'footer'))
        ->toThrow(AuthorizationException::class));
});

it('keeps a website admin with leftover limit rows free to change blocks (admins are never limited)', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    DB::connection('pgsql_admin')->table('roles')->where('site_id', 1)->where('name', 'admin')
        ->update(['level' => PermissionRegistry::tenantRoleTemplates('corporate')['admin']['level']]);
    blockLimitedTo($admin);
    $block = app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->create(['key' => 'footer', 'name' => 'Chân trang']));

    expect(blockAbilities($admin, $block)['update'])->toBeTrue()
        ->and(blockAbilities($admin, $block)['delete'])->toBeTrue();
});
