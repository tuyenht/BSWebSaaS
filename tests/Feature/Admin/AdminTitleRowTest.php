<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;

/*
 * A list screen's title row (x-bs-admin::title-row) and its main button's short word: below 768px the
 * button shows only «+ Thêm» (or «Mời» for members) while its name stays the full label, and the (?)
 * stands right beside it; from 768 the row is unchanged (name · (?) · the full button).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** The words a main button with a short word prints: the full label for screen readers below 768, the short word drawn. */
function trWords(string $label, string $short): string
{
    return '<span class="max-md:sr-only">'.e($label).'</span><span class="md:hidden" aria-hidden="true">'.e($short).'</span>';
}

function trOwner(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS));

    return $user->fresh();
}

it('draws a button\'s short word with the full label kept as its name, and the label alone without one', function (): void {
    $short = (string) $this->blade('<x-bs-admin::button label="Thêm <b>bài</b>" short="Thêm"><svg aria-hidden="true"></svg></x-bs-admin::button>');
    expect($short)->toContain('<svg aria-hidden="true"></svg>'.trWords('Thêm <b>bài</b>', 'Thêm'))
        ->not->toContain('<b>');

    $plain = (string) $this->blade('<x-bs-admin::button label="Lưu"><svg aria-hidden="true"></svg></x-bs-admin::button>');
    expect($plain)->toContain('<svg aria-hidden="true"></svg><span>Lưu</span>')->not->toContain('sr-only');

    // a button without `label` keeps its slot as it was
    expect((string) $this->blade('<x-bs-admin::button>Lưu</x-bs-admin::button>'))->toContain('>Lưu</button>')->not->toContain('<span');
});

it('puts the name, the (?) and the main button in one row, the (?) printed once', function (): void {
    $row = (string) $this->blade(
        '<x-bs-admin::title-row title="Tin &amp; tức" title-id="t1"><x-slot:help><button data-q>?</button></x-slot:help><a data-add>+</a></x-bs-admin::title-row>'
    );

    expect($row)->toContain('id="t1" tabindex="-1"')
        ->and($row)->toContain('max-md:contents')
        ->and(substr_count($row, 'data-q'))->toBe(1)
        ->and(strpos($row, '<h1'))->toBeLessThan(strpos($row, 'data-title-help'))
        ->and(strpos($row, 'data-title-help'))->toBeLessThan(strpos($row, 'data-add'));

    // no help: no empty (?) box; no id: the heading takes no focus
    $bare = (string) $this->blade('<x-bs-admin::title-row title="Sản phẩm"><a data-add>+</a></x-bs-admin::title-row>');
    expect($bare)->not->toContain('data-title-help')->not->toContain('tabindex');
});

it('keeps each short word inside its full label, so the name says what is seen', function (string $locale): void {
    $lines = json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true);
    $pairs = [
        'entries.add_post' => 'admin.list.add_short', 'entries.add_page' => 'admin.list.add_short',
        'catalog.create_new' => 'admin.list.add_short', 'categories.ui_add' => 'admin.list.add_short',
        'admin.plans_add' => 'admin.list.add_short', 'admin.members.invite' => 'admin.members.invite_short',
    ];

    foreach ($pairs as $full => $short) {
        expect(mb_strtolower($lines[$full]))->toContain(mb_strtolower($lines[$short]));
    }
})->with(['vi', 'en', 'ja', 'zh']);

it('shows the posts list «+ Thêm» beside the guide and the pages list beside its old help', function (): void {
    app(Tenancy::class)->enter(1, fn () => ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]));
    $owner = trOwner();

    $this->actingAs($owner)->get('http://a.test/admin/content?section=posts')->assertOk()
        ->assertSeeInOrder(['id="entries-title"', 'data-title-help', 'data-guide-open', 'data-guide="add"', trWords(__('entries.add_post'), __('admin.list.add_short'))], false);

    $this->actingAs($owner)->get('http://a.test/admin/content')->assertOk()
        ->assertSeeInOrder(['id="entries-title"', 'data-title-help', 'entries-help', trWords(__('entries.add_page'), __('admin.list.add_short'))], false);
});

it('shows the members list «Mời» with «Mời thành viên» kept as the button\'s name', function (): void {
    $this->actingAs(trOwner())->get('http://a.test/admin/users')->assertOk()
        ->assertSeeInOrder(['<h1', __('admin.members.title'), 'id="members-invite"', trWords(__('admin.members.invite'), __('admin.members.invite_short'))], false);
});
