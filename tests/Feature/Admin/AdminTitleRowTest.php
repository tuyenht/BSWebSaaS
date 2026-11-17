<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Dom\HTMLDocument;

/*
 * A list screen's title row (x-bs-admin::title-row) and its main button's short word, as the admin
 * shell draws its header: the name, then ONE group at the end of the row with the (?) guide first and
 * the main button right after it, on every width. Below 768px the button shows only «+ Thêm» (or «Mời»
 * for members) while its name stays the full label. The posts and pages lists lead the row with the
 * shell's trail «⌂ › section › Danh sách» and follow it with one line, a short one on phones.
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

it('puts the (?) and the main button in one group at the end of the row, the (?) first and printed once', function (): void {
    $row = (string) $this->blade(
        '<x-bs-admin::title-row title="Tin &amp; tức" title-id="t1"><x-slot:help><button data-q>?</button></x-slot:help><a data-add>+</a></x-bs-admin::title-row>'
    );
    $dom = HTMLDocument::createFromString('<!doctype html><body>'.$row.'</body>', LIBXML_NOERROR);
    $group = $dom->querySelector('[data-title-act]');
    $kids = $group === null ? [] : iterator_to_array($group->children);

    expect($row)->toContain('id="t1" tabindex="-1"')
        ->and(substr_count($row, 'data-q'))->toBe(1)
        ->and($dom->querySelector('h1')?->closest('[data-title-act]'))->toBeNull()
        ->and($dom->querySelector('h1')?->nextElementSibling)->toBe($group)
        ->and(array_map(fn ($el): bool => $el->hasAttribute('data-title-help'), $kids))->toBe([true, false])
        ->and($kids[1]->hasAttribute('data-add'))->toBeTrue()
        // the group stands at the end of the row, 12px between its two from 768 and 8px below, where its box
        // passes through so the main button can take a line of its own only when the name would get under 6em
        ->and((string) $group?->getAttribute('class'))->toContain('ms-auto')->toContain('gap-3')->toContain('max-md:contents')
        ->and((string) $dom->querySelector('h1')?->getAttribute('class'))->toContain('max-md:flex-[1000_1_6em]')
        ->and((string) $dom->querySelector('h1')?->parentElement?->getAttribute('class'))->toContain('flex-wrap')->toContain('max-md:gap-x-2');

    // no help: no empty (?) box; no id: the heading takes no focus
    $bare = (string) $this->blade('<x-bs-admin::title-row title="Sản phẩm"><a data-add>+</a></x-bs-admin::title-row>');
    expect(HTMLDocument::createFromString('<!doctype html><body>'.$bare.'</body>', LIBXML_NOERROR)->querySelector('[data-title-help]'))->toBeNull()
        ->and($bare)->not->toContain('tabindex');
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

it('shows the posts and pages lists «+ Thêm» beside the guide', function (): void {
    app(Tenancy::class)->enter(1, fn () => ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]));
    $owner = trOwner();

    $this->actingAs($owner)->get('http://a.test/admin/content?section=posts')->assertOk()
        ->assertSeeInOrder(['id="entries-title"', 'data-title-help', 'data-guide-open', 'data-guide="add"', trWords(__('entries.add_post'), __('admin.list.add_short'))], false);

    $this->actingAs($owner)->get('http://a.test/admin/content')->assertOk()
        ->assertSeeInOrder(['id="entries-title"', 'data-title-help', 'data-guide-open', 'data-guide="add"', trWords(__('entries.add_page'), __('admin.list.add_short'))], false)
        ->assertDontSee('entries-help', false);
});

it('draws the posts list header as the shell does, in every admin language', function (string $locale): void {
    app(Tenancy::class)->enter(1, fn () => ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]));
    $owner = trOwner();
    $t = fn (string $key, array $with = []): string => (string) trans($key, $with, $locale);

    foreach ([['posts', 'post'], [null, 'page']] as [$section, $type]) {
        $url = 'http://a.test/admin/content'.($section ? '?section='.$section : '');
        $html = (string) $this->actingAs($owner)->withSession(['locale' => $locale])->get($url)->assertOk()->getContent();
        $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $head = $dom->querySelector('.entries-index > header');
        $label = trim((string) $dom->getElementById('entries-title')?->textContent);
        $trail = $head?->querySelector('nav[aria-label="'.$t('admin.breadcrumb').'"]');
        $group = $head?->querySelector('[data-title-act]');
        $lower = mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);

        // ⌂ › the section (a link back to its list) › Danh sách
        expect($trail?->getAttribute('data-guide'))->toBe('crumb', $locale.' '.$type)
            ->and($trail?->querySelector('a[aria-label="'.$t('admin.dashboard').'"]'))->not->toBeNull()
            ->and(trim((string) $trail?->querySelector('a.underline')?->textContent))->toBe($label)
            ->and($trail?->querySelector('a.underline')?->getAttribute('href'))->toBe(route('admin.entries.index', $section ? ['section' => $section] : []))
            ->and(trim((string) $trail?->querySelector('[aria-current="page"]')?->textContent))->toBe($t('admin.list'))
            // the (?) «Hướng dẫn» first, then the add button, in one group at the end of the title row
            ->and($group?->firstElementChild?->hasAttribute('data-title-help'))->toBeTrue()
            ->and(trim((string) $group?->firstElementChild?->querySelector('[data-guide-open]')?->textContent))->toBe($t('guide.ui.open'))
            ->and($group?->lastElementChild?->getAttribute('data-guide'))->toBe('add')
            ->and(trim((string) $group?->lastElementChild?->textContent))->toContain($t('entries.add_'.$type))->toContain($t('admin.list.add_short'))
            // one line on what the list holds, and its short phone wording
            ->and(trim((string) $head?->querySelector('[data-desc="full"]')?->textContent))->toBe($t('entries.desc_'.$type, ['label' => $lower]))
            ->and(trim((string) $head?->querySelector('[data-desc="short"]')?->textContent))->toBe($t('entries.mdesc_'.$type));
    }
})->with(['vi', 'en', 'ja', 'zh']);

it('shows the members list «Mời» with «Mời thành viên» kept as the button\'s name', function (): void {
    $this->actingAs(trOwner())->get('http://a.test/admin/users')->assertOk()
        ->assertSeeInOrder(['<h1', __('admin.members.title'), 'id="members-invite"', trWords(__('admin.members.invite'), __('admin.members.invite_short'))], false);
});
