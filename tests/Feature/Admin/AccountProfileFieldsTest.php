<?php

use App\Modules\Admin\Livewire\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\StorefrontCards;

/*
 * The job title and phone on the member's own profile (Tài khoản của tôi › Hồ sơ): one value for
 * every website the member belongs to. The phone is typed in any usual way and stored in one
 * international form; a Vietnamese number of 10–11 digits (from 0, or +84) or a +country number
 * is accepted, anything else is refused in plain words. A change goes on record without the values.
 * The phone is personal data: never on a storefront, never to a member who does not manage members.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** @param  list<string>  $permissions */
function profileMember(array $permissions = ['entries.view'], array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $permissions));

    return $user->fresh();
}

/** @return Livewire\Features\SupportTesting\Testable */
function profilePage(User $user): mixed
{
    return Livewire::actingAs($user)->test(Account::class);
}

/** @return list<object{meta: string|null}> the member's own profile rows, newest first */
function profileAuditRows(User $user): array
{
    /** @var list<object{meta: string|null}> */
    return DB::connection('pgsql_admin')->table('audit_logs')
        ->where('action', 'identity.profile.updated')->where('actor_id', $user->id)
        ->orderByDesc('id')->get(['meta'])->all();
}

it('stores the phone in one international form and shows it back as the member reads it', function (string $typed, string $stored, string $shown): void {
    $user = profileMember();

    app(Tenancy::class)->enter(1, fn () => profilePage($user)
        ->set('profilePhone', $typed)->assertHasNoErrors()
        ->call('saveProfile')->assertHasNoErrors()
        ->assertSet('profilePhone', $shown)->assertSet('savedPhone', $shown));

    expect($user->fresh()->phone)->toBe($stored);
    app(Tenancy::class)->enter(1, fn () => profilePage($user->fresh())->assertSet('profilePhone', $shown));
})->with([
    'mobile, spaced' => ['0901 234 567', '+84901234567', '0901 234 567'],
    'mobile, dashes' => ['090-123-4567', '+84901234567', '0901 234 567'],
    'mobile, +84' => ['+84 90 123 4567', '+84901234567', '0901 234 567'],
    'mobile, +84 and its 0' => ['+84 0901 234 567', '+84901234567', '0901 234 567'],
    'mobile, 0084' => ['0084901234567', '+84901234567', '0901 234 567'],
    'landline, 11 digits' => ['028 3812 3456', '+842838123456', '028 3812 3456'],
    'landline of a province' => ['0299 123 4567', '+842991234567', '0299 123 4567'],
    'foreign, Singapore' => ['+65 6123 4567', '+6561234567', '+6561234567'],
    'foreign, US' => ['+1 (415) 555-2671', '+14155552671', '+14155552671'],
    'no-break spaces' => ["0901\u{00A0}234\u{00A0}567", '+84901234567', '0901 234 567'],
]);

it('refuses a phone that is not a Vietnamese or a +country number, in plain words, and keeps the old one', function (string $typed): void {
    $user = profileMember(attributes: []);
    $user->forceFill(['phone' => '+84901234567'])->save();

    app(Tenancy::class)->enter(1, fn () => profilePage($user->fresh())
        ->set('profilePhone', $typed)
        ->assertHasErrors(['profilePhone'])
        ->assertSee('Số điện thoại chưa đúng. Nhập số Việt Nam 10–11 số bắt đầu bằng 0')
        ->call('saveProfile')
        ->assertHasErrors(['profilePhone']));

    expect($user->fresh()->phone)->toBe('+84901234567');
})->with([
    'too short' => ['0901 234'],
    'nine digits' => ['090123456'],
    'eleven digits of a mobile' => ['09012345678'],
    'hotline' => ['1900 1234'],
    'no leading 0 or +' => ['901234567'],
    'Vietnamese after +84 that is no number' => ['+84 123 456 789'],
    'country code 0' => ['+0 123 456 789'],
    'too long' => ['+1234567890123456'],
    'letters' => ['gọi tôi nhé'],
    'markup' => ['<b>0901234567</b>'],
    'two plus signs' => ['+84+901234567'],
]);

it('clears the phone and the job title when the member empties them', function (): void {
    $user = profileMember();
    $user->forceFill(['phone' => '+84901234567', 'job_title' => 'Kế toán'])->save();

    app(Tenancy::class)->enter(1, fn () => profilePage($user->fresh())
        ->set('profilePhone', '   ')->set('profileTitle', '  ')
        ->call('saveProfile')->assertHasNoErrors());

    expect($user->fresh()->phone)->toBeNull()->and($user->fresh()->job_title)->toBeNull();
});

it('saves the job title trimmed, at most 80 characters, plain text only', function (): void {
    $user = profileMember();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        profilePage($user)->set('profileTitle', str_repeat('a', 81))
            ->assertHasErrors(['profileTitle'])
            ->assertSee('Chức danh tối đa 80 ký tự — bớt 1 ký tự.')
            ->call('saveProfile')->assertHasErrors(['profileTitle']);

        profilePage($user)->set('profileTitle', '<script>alert(1)</script>')
            ->assertHasErrors(['profileTitle'])
            ->assertSee('Chức danh không dùng được dấu &lt; hoặc &gt;', false)
            ->call('saveProfile')->assertHasErrors(['profileTitle']);

    });
    expect($user->fresh()->job_title)->toBeNull();

    // Pasted whitespace becomes one space and invisible characters go, so nothing shows reversed or hidden.
    app(Tenancy::class)->enter(1, fn () => profilePage($user)
        ->set('profileTitle', "\u{00A0}Kế\ttoán\u{200B}\r\n trưởng\u{202E}\u{0007}\u{00A0}")->assertHasNoErrors()
        ->call('saveProfile')->assertHasNoErrors()
        ->assertSet('savedTitle', 'Kế toán trưởng'));
    expect($user->fresh()->job_title)->toBe('Kế toán trưởng');

    $eighty = str_repeat('ễ', 80);
    app(Tenancy::class)->enter(1, fn () => profilePage($user)
        ->set('profileTitle', '  '.$eighty.'  ')->call('saveProfile')->assertHasNoErrors()
        ->assertSet('savedTitle', $eighty));
    expect($user->fresh()->job_title)->toBe($eighty);

    app(Tenancy::class)->enter(1, fn () => profilePage($user)
        ->set('profileTitle', '  Trưởng phòng kinh doanh ')->call('saveProfile')->assertHasNoErrors());
    expect($user->fresh()->job_title)->toBe('Trưởng phòng kinh doanh');
});

it('saves nothing while any of the three fields is wrong', function (): void {
    $user = profileMember(attributes: ['name' => 'Tên Cũ']);

    app(Tenancy::class)->enter(1, fn () => profilePage($user)
        ->set('profileName', 'Tên Mới')->set('profileTitle', 'Kế toán')->set('profilePhone', '123')
        ->call('saveProfile')
        ->assertHasErrors(['profilePhone'])->assertHasNoErrors(['profileName', 'profileTitle']));

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Tên Cũ')->and($fresh->job_title)->toBeNull()->and($fresh->phone)->toBeNull()
        ->and(profileAuditRows($user))->toBe([]);
});

it('puts a profile change on record with the fields changed, never their values, and nothing when nothing changed', function (): void {
    $user = profileMember(attributes: ['name' => 'Nguyễn Ngọc Anh']);

    app(Tenancy::class)->enter(1, fn () => profilePage($user)
        ->set('profileTitle', 'Kế toán trưởng')->set('profilePhone', '0901 234 567')
        ->call('saveProfile')->assertHasNoErrors());

    $rows = profileAuditRows($user);
    expect($rows)->toHaveCount(1);
    $meta = json_decode((string) $rows[0]->meta, true, flags: JSON_THROW_ON_ERROR);
    expect($meta['fields'])->toEqualCanonicalizing(['job_title', 'phone'])
        ->and((string) $rows[0]->meta)->not->toContain('901234567')->not->toContain('Kế toán');

    // Saving again with nothing changed adds no row.
    app(Tenancy::class)->enter(1, fn () => profilePage($user->fresh())->call('saveProfile')->assertHasNoErrors());
    expect(profileAuditRows($user))->toHaveCount(1);
});

it('puts the board inputs on the profile, with the hint that says who sees the number', function (): void {
    $user = profileMember();
    $user->forceFill(['phone' => '+84901234567', 'job_title' => 'Kế toán'])->save();

    $html = (string) $this->actingAs($user->fresh())->get('http://a.test/admin/account')->assertOk()->getContent();

    expect($html)->toContain('for="ac-title">Chức danh</label>')
        ->toContain('autocomplete="organization-title"')
        ->toContain('for="ac-phone">Số điện thoại</label>')
        ->toContain('type="tel" inputmode="tel" autocomplete="tel"')
        ->toContain('Số này hiện ở mọi website bạn tham gia')
        // the inputs are filled from the component's state (wire:model), in the national form
        ->toContain('0901 234 567')->not->toContain('+84901234567');
});

it('lets the discard button put back the saved title and phone', function (): void {
    $user = profileMember();
    $user->forceFill(['phone' => '+6561234567', 'job_title' => 'Kế toán'])->save();

    app(Tenancy::class)->enter(1, fn () => profilePage($user->fresh())
        ->set('profileTitle', 'Khác')->set('profilePhone', '12')->assertHasErrors(['profilePhone'])
        ->call('resetProfile')
        ->assertHasNoErrors()
        ->assertSet('profileTitle', 'Kế toán')->assertSet('profilePhone', '+6561234567'));
});

it('keeps job title and phone out of mass assignment and the phone out of a serialised user', function (): void {
    $user = new User;
    $user->fill(['name' => 'A', 'job_title' => 'Giám đốc', 'phone' => '+84901234567']);

    expect($user->job_title)->toBeNull()->and($user->phone)->toBeNull();

    $saved = profileMember();
    $saved->forceFill(['phone' => '+84901234567'])->save();
    expect($saved->fresh()->toArray())->not->toHaveKey('phone')
        ->and($saved->fresh()->toJson())->not->toContain('901234567');
});

it('never shows the phone to a member who does not manage members, nor on another member\'s profile', function (): void {
    $owner = profileMember(['entries.view', 'users.manage']);
    $owner->forceFill(['phone' => '+84987650123', 'job_title' => 'Chủ xưởng'])->save();
    $editor = profileMember();

    $forms = ['+84987650123', '84987650123', '0987 650 123', '0987650123'];

    $members = (string) $this->actingAs($editor)->get('http://a.test/admin/users')->assertOk()->getContent();
    $account = (string) $this->actingAs($editor)->get('http://a.test/admin/account')->assertOk()->getContent();
    foreach ($forms as $form) {
        expect($members)->not->toContain($form)->and($account)->not->toContain($form);
    }
});

it('never puts a member\'s phone on the storefront, not even on a post that member wrote', function (): void {
    StorefrontCards::reset();
    $siteId = StorefrontCards::site();
    $owner = User::query()->where('email', 'owner@mau.platform.test')->firstOrFail();
    $owner->forceFill(['phone' => '+84987650123', 'job_title' => 'Chủ xưởng'])->save();
    app(Tenancy::class)->enter($siteId, function () use ($owner): void {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Bài của chủ xưởng', 'bai-cua-chu-xuong');
        $post->forceFill(['author_id' => $owner->id])->save();
    });

    $forms = ['+84987650123', '84987650123', '0987 650 123', '0987650123', 'Chủ xưởng'];
    foreach (['/', '/tin-tuc', '/tin-tuc/bai-cua-chu-xuong', '/lien-he'] as $path) {
        $html = (string) $this->get('http://mau.platform.test'.$path)->assertOk()->getContent();
        foreach ($forms as $form) {
            expect($html)->not->toContain($form);
        }
    }
});
