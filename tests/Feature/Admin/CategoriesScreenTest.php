<?php

use App\Modules\Admin\Livewire\Categories\Index as CategoriesIndex;
use App\Modules\Admin\Support\Categories\CategoryDeletePreview;
use App\Modules\Admin\Support\Categories\CategoryMoveTargets;
use App\Modules\Admin\Support\Categories\CategoryScreen;
use App\Modules\Admin\Support\Categories\CategorySections;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The Chuyên mục screen's endpoints: every one checks the person may manage categories, validates
 * its arguments, names categories and pictures by key only (another site's are never found), stays
 * inside the section it was opened for, and answers {ok: true, …} or {ok: false, reason, message}.
 * Loading the screen costs the same number of queries for 10 categories as for 200.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->editor = cxUser(['taxonomies.manage', 'entries.view']);
    $this->root = cxIn(1, function (): Taxonomy {
        $root = cxCreate('Tin tức');
        ContentSection::query()->create(['key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $root->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);

        return $root;
    });
});

function cxIn(int $site, Closure $callback): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

/** A member of site 1 holding exactly $permissions. Call outside any site context. */
function cxUser(array $permissions): User
{
    $user = User::factory()->create();
    cxIn(1, fn () => grantRole($user, 'cs-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

function cxCreate(string $name, ?Taxonomy $parent = null, string $vocabulary = 'post_cat', array $extra = []): Taxonomy
{
    return app(CreateTaxonomy::class)->handle($vocabulary, $parent, new TaxonomyData(...['names' => ['vi' => $name], ...$extra]));
}

/** Calls one endpoint of the screen as $user in site 1 and returns what it answered. */
function cxCall(User $user, string $method, mixed ...$arguments): array
{
    $out = null;
    cxIn(1, function () use ($user, $method, $arguments, &$out): void {
        Livewire::actingAs($user->fresh())->test(CategoriesIndex::class)
            ->call($method, ...$arguments)
            ->assertReturned(function (mixed $returned) use (&$out): bool {
                $out = $returned;

                return true;
            });
    });

    return is_array($out) ? $out : [];
}

function cxPicture(string $key): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => 'anh-'.md5($key), 'hash' => hash('sha256', $key), 'storage_key' => $key,
        'disk' => 'r2', 'original_filename' => 'anh.jpg', 'mime' => 'image/jpeg', 'size' => 1024,
    ]);
}

/** @param array<string, mixed> $answer */
function cxRows(array $answer): array
{
    $rows = [];
    foreach ($answer['rows'] ?? [] as $row) {
        $rows[$row['node']['name']] = $row['node'] + ['level' => $row['level']];
    }

    return $rows;
}

it('loads the section\'s tree by key: names, the "Thuộc" path, the item count and which rows are locked', function (): void {
    [$company, $events] = cxIn(1, function (): array {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocaleContext::class)->forget();
        $company = cxCreate('Tin công ty', $this->root, extra: ['names' => ['vi' => 'Tin công ty', 'en' => 'Company news']]);

        return [$company, cxCreate('Sự kiện', $company)];
    });

    $answer = cxCall($this->editor, 'load', 'tin-tuc');
    $rows = cxRows($answer);

    expect($answer['ok'])->toBeTrue()
        ->and($answer['section'])->toBe(['key' => 'tin-tuc', 'label' => 'Tin tức', 'vocabulary' => 'post_cat'])
        ->and(array_keys($rows))->toBe(['Tin tức', 'Tin công ty', 'Sự kiện'])
        ->and($rows['Tin tức']['locked'])->toBeTrue()
        ->and($rows['Tin tức']['parent'])->toBeNull()
        ->and($rows['Tin công ty']['key'])->toBe($company->ulid)
        ->and($rows['Tin công ty']['names'])->toBe(['en' => 'Company news', 'vi' => 'Tin công ty'])
        ->and($rows['Tin công ty']['of'])->toBe('Tin tức')
        ->and($rows['Sự kiện'])->toMatchArray(['key' => $events->ulid, 'parent' => $company->ulid, 'of' => 'Tin công ty', 'trail' => ['Tin tức', 'Tin công ty'], 'items' => 0, 'locked' => false, 'editable' => true, 'movable' => true, 'level' => 3]);

    // No database id leaves the server: every reference is a ulid.
    $json = json_encode($answer, JSON_THROW_ON_ERROR);
    foreach ([$this->root->id, $company->id, $events->id] as $id) {
        expect($json)->not->toMatch('/"(id|parent_id|taxonomy_id|cover_media_id)":/')
            ->and($json)->not->toContain('"parent":'.$id);
    }
});

it('maps the screen\'s sections onto category trees without any new column', function (): void {
    cxIn(1, function (): void {
        $legacy = cxCreate('Sản phẩm');
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $legacy->id, 'sort_order' => 2]);
    });

    cxIn(1, function (): void {
        $sections = app(CategorySections::class);
        expect($sections->find('tin-tuc'))->toMatchArray(['vocabulary' => 'post_cat', 'root' => $this->root->id, 'prefix' => 'entries'])
            ->and($sections->find('products'))->toBeNull()      // the old products section: products have their own tree
            ->and($sections->find('catalog'))->toBeNull()       // the site has no catalog
            ->and($sections->find('khong-co'))->toBeNull();
    });

    cxIn(1, fn () => SiteModule::create(['module_key' => 'catalog', 'enabled' => true]));
    app()->forgetScopedInstances(); // a new request: the site's modules are read again
    cxIn(1, fn () => expect(app(CategorySections::class)->find('catalog'))->toMatchArray(['vocabulary' => 'product_cat', 'root' => null, 'prefix' => 'catalog']));

    expect(cxCall($this->editor, 'load', 'khong-co'))->toMatchArray(['ok' => false, 'reason' => 'section_invalid'])
        ->and(cxCall($this->editor, 'load', 42))->toMatchArray(['ok' => false, 'reason' => 'section_invalid']);
});

it('creates a category under the section\'s root and saves the whole drawer, Vietnamese edges intact', function (): void {
    cxIn(1, fn () => cxPicture('s/library/anh-dao-tao.jpg'));

    $created = cxCall($this->editor, 'save', 'tin-tuc', null, null, [
        'names' => ['vi' => 'Đào tạo nghề ữ'],
        'descriptions' => ['vi' => 'Ấn phẩm và khoá học ở'],
        'seoTitles' => ['vi' => "Đào tạo\nnghề ạ"],         // the screen's one-line rule folds the break
        'seoDescriptions' => ['vi' => 'Ưu đãi ở'],
        'cover' => 's/library/anh-dao-tao.jpg',
    ]);
    expect($created['ok'])->toBeTrue()->and($created['version'])->toBeString();

    $node = cxRows(cxCall($this->editor, 'load', 'tin-tuc'))['Đào tạo nghề ữ'];
    expect($node)->toMatchArray([
        'key' => $created['key'], 'parent' => $this->root->ulid,
        'descriptions' => ['vi' => 'Ấn phẩm và khoá học ở'], 'seoTitles' => ['vi' => 'Đào tạo nghề ạ'], 'seoDescriptions' => ['vi' => 'Ưu đãi ở'],
    ])->and($node['cover']['key'])->toBe('s/library/anh-dao-tao.jpg');

    $updated = cxCall($this->editor, 'save', 'tin-tuc', $created['key'], null, ['names' => ['vi' => 'Đào tạo ữ'], 'cover' => '']);
    $node = cxRows(cxCall($this->editor, 'load', 'tin-tuc'))['Đào tạo ữ'];
    expect($updated['ok'])->toBeTrue()->and($node['cover'])->toBeNull();
});

it('refuses arguments of the wrong shape as input_invalid', function (): void {
    $key = $this->root->ulid;

    foreach ([
        ['save', 'tin-tuc', null, null, ['names' => ['vi' => 'Tên'], 'extra' => 1]],
        ['save', 'tin-tuc', null, null, ['names' => ['vi' => 7]]],
        ['save', 'tin-tuc', null, null, 'not an array'],
        ['save', 'tin-tuc', 'not-a-key', null, ['names' => ['vi' => 'Tên']]],
        ['move', 'tin-tuc', $key, null, -1],
        ['move', 'tin-tuc', $key, null, '0'],
        ['move', 'tin-tuc', 15, null, 0],
        ['undoMove', 'tin-tuc', ['key' => $key]],
        ['deletePreview', 'tin-tuc', $key, 'everything'],
        ['search', 'tin-tuc', ['tin']],
    ] as $call) {
        $method = array_shift($call);
        expect(cxCall($this->editor, $method, ...$call))->toMatchArray(['ok' => false, 'reason' => 'input_invalid']);
    }
});

it('never finds another site\'s category or picture, nor a category of another section', function (): void {
    $foreign = cxIn(2, function (): Taxonomy {
        cxPicture('s/library/anh-site-b.jpg');

        return cxCreate('Tin của B');
    });
    [$mine, $elsewhere] = cxIn(1, function (): array {
        $other = cxCreate('Dự án');
        ContentSection::query()->create(['key' => 'du-an', 'label' => 'Dự án', 'entry_type' => 'post', 'taxonomy_id' => $other->id, 'permission_prefix' => 'entries', 'sort_order' => 3]);

        return [cxCreate('Tin công ty', $this->root), cxCreate('Công trình', $other)];
    });

    expect(cxCall($this->editor, 'save', 'tin-tuc', $foreign->ulid, null, ['names' => ['vi' => 'Chiếm']]))->toMatchArray(['ok' => false, 'reason' => 'taxonomy_invalid'])
        ->and(cxCall($this->editor, 'save', 'tin-tuc', null, $foreign->ulid, ['names' => ['vi' => 'Con']]))->toMatchArray(['ok' => false, 'reason' => 'parent_invalid'])
        ->and(cxCall($this->editor, 'move', 'tin-tuc', $mine->ulid, $foreign->ulid, 0))->toMatchArray(['ok' => false, 'reason' => 'parent_invalid'])
        ->and(cxCall($this->editor, 'merge', 'tin-tuc', $mine->ulid, $foreign->ulid))->toMatchArray(['ok' => false])
        ->and(cxCall($this->editor, 'deletePreview', 'tin-tuc', $foreign->ulid))->toMatchArray(['ok' => false, 'reason' => 'taxonomy_invalid'])
        ->and(cxCall($this->editor, 'delete', 'tin-tuc', $elsewhere->ulid))->toMatchArray(['ok' => false, 'reason' => 'taxonomy_invalid'])
        ->and(cxCall($this->editor, 'save', 'tin-tuc', $mine->ulid, null, ['cover' => 's/library/anh-site-b.jpg']))->toMatchArray(['ok' => false, 'reason' => 'cover_invalid']);

    cxIn(2, fn () => expect(Taxonomy::query()->whereKey($foreign->id)->exists())->toBeTrue());
});

it('moves a category and undoes the move while the tree is unchanged, refusing a stale undo', function (): void {
    [$a, $b, $moved] = cxIn(1, function (): array {
        $a = cxCreate('Ẩm thực', $this->root);
        $b = cxCreate('Bóng đá', $this->root);
        cxCreate('Đầu', $a);

        return [$a, $b, cxCreate('Giữa ở', $a)];
    });

    $move = cxCall($this->editor, 'move', 'tin-tuc', $moved->ulid, $b->ulid, 0);
    expect($move['ok'])->toBeTrue()
        ->and($move['undo'])->toBe(['key' => $moved->ulid, 'parent' => $a->ulid, 'position' => 1, 'version' => $move['version']]);
    cxIn(1, fn () => expect($moved->fresh()?->parent_id)->toBe($b->id));

    expect(cxCall($this->editor, 'undoMove', 'tin-tuc', $move['undo'])['ok'])->toBeTrue();
    cxIn(1, fn () => expect($moved->fresh()?->parent_id)->toBe($a->id));

    $again = cxCall($this->editor, 'move', 'tin-tuc', $moved->ulid, $b->ulid, 0);
    cxCall($this->editor, 'save', 'tin-tuc', null, $b->ulid, ['names' => ['vi' => 'Thêm sau']]);
    expect(cxCall($this->editor, 'undoMove', 'tin-tuc', $again['undo']))->toMatchArray(['ok' => false, 'reason' => 'move_stale']);
    cxIn(1, fn () => expect($moved->fresh()?->parent_id)->toBe($b->id));
});

it('lists move targets with a reason for each one refused: depth, own branch and the section root', function (): void {
    [$deep, $moving] = cxIn(1, function (): array {
        $level2 = cxCreate('Cấp hai', $this->root);
        $deep = cxCreate('Cấp ba', $level2);
        $moving = cxCreate('Có con', $this->root);
        cxCreate('Con của nó', $moving);

        return [$deep, $moving];
    });

    $answer = cxCall($this->editor, 'moveTargets', 'tin-tuc', $moving->ulid);
    $options = [];
    foreach ($answer['groups'] as $group) {
        foreach ($group['options'] as $option) {
            $options[$option['name']] = $option;
        }
    }

    expect($answer['ok'])->toBeTrue()
        ->and($options)->not->toHaveKey('Có con')->and($options)->not->toHaveKey('Con của nó')   // its own branch is never offered
        ->and($options['Cấp ba'])->toMatchArray(['key' => $deep->ulid, 'ok' => false, 'reason' => 'depth_exceeded'])
        ->and($options['Cấp hai'])->toMatchArray(['ok' => false, 'reason' => 'depth_exceeded'])
        ->and($options['Tin tức'])->toMatchArray(['ok' => false, 'current' => true, 'reason' => null])   // where it already is
        ->and($options['Cấp ba']['message'])->toContain((string) 3);

    expect(cxCall($this->editor, 'moveTargets', 'tin-tuc', $this->root->ulid))->toMatchArray(['ok' => false, 'reason' => 'section_bound'])
        ->and(cxCall($this->editor, 'move', 'tin-tuc', $this->root->ulid, $deep->ulid, 0))->toMatchArray(['ok' => false, 'reason' => 'section_bound'])
        ->and(cxCall($this->editor, 'move', 'tin-tuc', $moving->ulid, $deep->ulid, 0))->toMatchArray(['ok' => false, 'reason' => 'depth_exceeded'])
        ->and(cxCall($this->editor, 'move', 'tin-tuc', $moving->ulid, null, 0))->toMatchArray(['ok' => false, 'reason' => 'parent_invalid']);
});

it('previews a delete with its counts, then deletes and merges', function (): void {
    [$source, $target] = cxIn(1, function (): array {
        $source = cxCreate('Tin cũ', $this->root);
        cxCreate('Con một', $source);
        $target = cxCreate('Tin mới', $this->root);
        foreach (range(1, 2) as $n) {
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
            $post->translations()->create(['locale' => 'vi', 'title' => "Bài {$n}", 'slug' => "bai-{$n}", 'status' => 'draft', 'body' => ['blocks' => []]]);
            $post->assignCategories([$source->id]);
        }

        return [$source, $target];
    });

    $preview = cxCall($this->editor, 'deletePreview', 'tin-tuc', $source->ulid);
    expect($preview)->toMatchArray(['ok' => true, 'children' => 1, 'items' => 2, 'locked' => false, 'allowed' => true]);

    $refused = cxCall($this->editor, 'delete', 'tin-tuc', $source->ulid);   // neither what happens to the sub-categories nor to the posts was chosen
    expect($refused['ok'])->toBeFalse()->and($refused['reason'])->toBeIn(['has_children', 'has_items']);

    $merged = cxCall($this->editor, 'merge', 'tin-tuc', $source->ulid, $target->ulid);
    expect($merged)->toMatchArray(['ok' => true, 'categories' => 1, 'items' => 2]);
    cxIn(1, fn () => expect(Taxonomy::query()->whereKey($source->id)->exists())->toBeFalse()
        ->and($target->fresh()?->items_count)->toBe(2));

    expect(cxCall($this->editor, 'deletePreview', 'tin-tuc', $this->root->ulid))->toMatchArray(['ok' => true, 'locked' => true]);
});

it('finds categories by name whatever the accents', function (): void {
    $company = cxIn(1, fn () => cxCreate('Tin công ty', $this->root));
    cxIn(1, fn () => cxCreate('Tuyển dụng', $this->root));

    expect(cxCall($this->editor, 'search', 'tin-tuc', 'cong TY'))->toBe(['ok' => true, 'keys' => [$company->ulid]])
        ->and(cxCall($this->editor, 'search', 'tin-tuc', '   '))->toBe(['ok' => true, 'keys' => []]);
});

it('keeps a limited member inside their categories', function (): void {
    $member = cxUser(['taxonomies.manage', 'entries.view']);
    [$mine, $other] = cxIn(1, function () use ($member): array {
        $mine = cxCreate('Của tôi', $this->root);
        $other = cxCreate('Của người khác', $this->root);
        MemberCategoryScope::query()->create(['user_id' => $member->id, 'taxonomy_id' => $mine->id]);
        app(CategoryScopes::class)->forget();

        return [$mine, $other];
    });

    $rows = cxRows(cxCall($member, 'load', 'tin-tuc'));
    expect($rows['Của tôi'])->toMatchArray(['editable' => true, 'movable' => false])
        ->and($rows['Của người khác'])->toMatchArray(['editable' => false, 'movable' => false])
        ->and(cxCall($member, 'save', 'tin-tuc', $other->ulid, null, ['names' => ['vi' => 'Đổi']]))->toMatchArray(['ok' => false, 'reason' => 'outside_category_limits'])
        ->and(cxCall($member, 'move', 'tin-tuc', $mine->ulid, $other->ulid, 0))->toMatchArray(['ok' => false, 'reason' => 'outside_category_limits'])
        ->and(cxCall($member, 'delete', 'tin-tuc', $other->ulid))->toMatchArray(['ok' => false, 'reason' => 'outside_category_limits'])
        ->and(cxCall($member, 'save', 'tin-tuc', $mine->ulid, null, ['names' => ['vi' => 'Của tôi ạ']]))->toMatchArray(['ok' => true]);
});

it('refuses the screen and every endpoint to someone who may not manage categories', function (): void {
    $writer = cxUser(['entries.view', 'entries.create']);

    $this->actingAs($this->editor->fresh())->get('http://a.test/admin/taxonomies?section=tin-tuc')->assertOk()->assertSee(__('categories.empty_title'));
    $this->actingAs($writer->fresh())->get('http://a.test/admin/taxonomies?section=tin-tuc')->assertForbidden();

    cxIn(1, function () use ($writer): void {
        Livewire::actingAs($writer->fresh())->test(CategoriesIndex::class)->assertForbidden();
    });
});

it('loads the screen, previews a delete and lists move targets in the same number of queries for 10 categories as for 200', function (): void {
    $build = function (Taxonomy $root, string $label, int $count): void {
        $parents = [$root];
        for ($n = 1; $n <= $count; $n++) {
            $parent = $parents[($n - 1) % count($parents)];
            $category = cxCreate("{$label} {$n} ở", $parent, extra: ['cover' => 's/library/anh.jpg', 'names' => ['vi' => "{$label} {$n} ở", 'en' => "{$label} item {$n}"]]);
            if ($parent === $root && count($parents) < 20) {
                $parents[] = $category;   // levels 2 and 3
            }
        }
    };

    cxIn(1, function () use ($build): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocaleContext::class)->forget();
        cxPicture('s/library/anh.jpg');
        $small = cxCreate('Nhỏ');
        $large = cxCreate('Lớn');
        ContentSection::query()->create(['key' => 'nho', 'label' => 'Nhỏ', 'entry_type' => 'post', 'taxonomy_id' => $small->id, 'permission_prefix' => 'entries', 'sort_order' => 4]);
        ContentSection::query()->create(['key' => 'lon', 'label' => 'Lớn', 'entry_type' => 'post', 'taxonomy_id' => $large->id, 'permission_prefix' => 'entries', 'sort_order' => 5]);
        $build($small, 'Nhỏ', 10);
        $build($large, 'Lớn', 200);
    });

    $counts = cxIn(1, function (): array {
        $viewer = $this->editor->fresh();
        $screen = app(CategoryScreen::class);
        $sections = app(CategorySections::class);
        $small = $sections->find('nho');
        $large = $sections->find('lon');
        $screen->load($small, $viewer); // warm the per-request memos (sections, permissions)
        $rows = count($screen->load($large, $viewer)['rows']);

        $first = fn (array $section): Taxonomy => Taxonomy::query()->where('parent_id', $section['root'])->orderBy('position')->firstOrFail();
        [$smallFirst, $largeFirst] = [$first($small), $first($large)];
        $preview = app(CategoryDeletePreview::class);
        $targets = app(CategoryMoveTargets::class);
        $preview->for($small, $smallFirst, $viewer, CategoryChildrenOnDelete::Lift);
        $targets->for($small, $smallFirst, $viewer);

        return [
            'rows' => $rows,
            'load' => [countQueries(fn () => $screen->load($small, $viewer)), countQueries(fn () => $screen->load($large, $viewer))],
            'preview' => [countQueries(fn () => $preview->for($small, $smallFirst, $viewer, CategoryChildrenOnDelete::Lift)), countQueries(fn () => $preview->for($large, $largeFirst, $viewer, CategoryChildrenOnDelete::Lift))],
            'targets' => [countQueries(fn () => $targets->for($small, $smallFirst, $viewer)), countQueries(fn () => $targets->for($large, $largeFirst, $viewer))],
        ];
    });

    expect($counts['rows'])->toBe(201)
        ->and($counts['load'][1])->toBe($counts['load'][0])->toBeLessThanOrEqual(8)
        ->and($counts['preview'][1])->toBe($counts['preview'][0])
        ->and($counts['targets'][1])->toBe($counts['targets'][0]);
});
