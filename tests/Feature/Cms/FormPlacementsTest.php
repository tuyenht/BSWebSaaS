<?php

use App\Modules\Admin\Livewire\Forms\FormEditor;
use App\Modules\Admin\Livewire\Forms\FormList;
use App\Modules\Cms\Jobs\PlaceNewForm;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\FormPagePaths;
use App\Modules\Cms\Support\EntryFormPlacements;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormPlacement;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * form_placements: which pages show which form, kept by the page save (EntryTranslation's model
 * events, in the save's transaction) and checked again by `forms:placements`, so «Xem trang có biểu
 * mẫu» on the «Biểu mẫu» list and «Đang có trên trang» in the editor never scan page bodies. A
 * website table: RLS FORCE fails closed, and another site's pages are never named.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function placedForm(int $site, string $key, string $name): Form
{
    return app(Tenancy::class)->enter($site, fn (): Form => Form::query()->create(['key' => $key, 'name' => ['vi' => $name], 'status' => 'active']));
}

/** @param  list<string|null>  $keys  one «Biểu mẫu» block per key (null: a block with no key, the contact form) */
function formBlocks(array $keys): array
{
    return ['blocks' => [
        ['type' => 'hero', 'props' => ['title' => 'Chào mừng']],
        ...array_map(fn (?string $key): array => ['type' => 'form', 'props' => $key === null ? [] : ['form_key' => $key]], $keys),
    ]];
}

/** A page of $site with a translation in $locale showing $keys, and its route. */
function pageWithForms(int $site, string $slug, array $keys, string $status = 'published', string $locale = 'vi'): Entry
{
    return app(Tenancy::class)->enter($site, function () use ($slug, $keys, $status, $locale): Entry {
        $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'meta' => []]);
        $entry->translations()->create(['locale' => $locale, 'title' => 'Trang '.$slug, 'slug' => $slug, 'status' => $status, 'body' => formBlocks($keys)]);
        SiteRoute::query()->create(['locale' => $locale, 'path' => '/'.$slug, 'entry_id' => $entry->id]);

        return $entry;
    });
}

/** @return list<string> "entry:locale:form key" rows of form_placements (outside a site: read past RLS) */
function placements(): array
{
    // inside a site's open transaction its own writes are seen only on its connection (RLS: that site's rows)
    $db = DB::transactionLevel() > 0 ? DB::connection() : DB::connection('pgsql_admin');

    return $db->table('form_placements as p')->join('forms as f', 'f.id', '=', 'p.form_id')
        ->orderBy('p.entry_id')->orderBy('p.locale')->orderBy('f.key')
        ->get(['p.entry_id', 'p.locale', 'f.key', 'p.site_id', 'f.site_id as form_site'])
        ->map(function (object $row): string {
            expect($row->site_id)->toBe($row->form_site);   // a row never joins two sites

            return $row->entry_id.':'.$row->locale.':'.$row->key;
        })->all();
}

it('keeps the rows with every save of a page language: added, changed, moved and removed', function (): void {
    $contact = placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $page = pageWithForms(1, 'lien-he', [null, 'bao-gia', 'bao-gia', 'chua-co']);   // twice the same form, a key no form has

    expect(placements())->toBe(["{$page->id}:vi:bao-gia", "{$page->id}:vi:contact"]);

    app(Tenancy::class)->enter(1, function () use ($page): void {
        $vi = EntryTranslation::query()->where('entry_id', $page->id)->where('locale', 'vi')->firstOrFail();
        $vi->update(['body' => formBlocks(['bao-gia'])]);
        expect(placements())->toBe(["{$page->id}:vi:bao-gia"]);

        // a save loaded without the body, that changes only the title: nothing moves
        EntryTranslation::query()->whereKey($vi->id)->firstOrFail(['id', 'site_id', 'entry_id', 'locale', 'title'])->update(['title' => 'Liên hệ với chúng tôi']);
        expect(placements())->toBe(["{$page->id}:vi:bao-gia"]);

        // the row moved to another language: its rows go with it
        $vi->refresh()->update(['locale' => 'en']);
        expect(placements())->toBe(["{$page->id}:en:bao-gia"]);

        $page->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he-2', 'status' => 'published', 'body' => formBlocks([null])]);
        expect(placements())->toBe(["{$page->id}:en:bao-gia", "{$page->id}:vi:contact"]);

        $vi->refresh()->delete();
        expect(placements())->toBe(["{$page->id}:vi:contact"]);
    });

    // a form deleted for good, or a page purged, takes its rows (ON DELETE CASCADE)
    $other = pageWithForms(1, 'gioi-thieu', [null]);
    DB::connection('pgsql_admin')->table('entries')->where('id', $page->id)->delete();
    expect(placements())->toBe(["{$other->id}:vi:contact"]);
    DB::connection('pgsql_admin')->table('forms')->where('id', $contact->id)->delete();
    expect(placements())->toBe([]);
});

it('fails closed: no rows without a site, and none written for another site', function (): void {
    placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    $page = pageWithForms(1, 'lien-he', [null]);
    expect(placements())->toHaveCount(1);

    // the app role with no tenant context reads nothing (RLS FORCE, the policy's NULL site)
    $seen = DB::transaction(fn (): int => (int) DB::selectOne('select count(*) as n from form_placements')->n);
    expect($seen)->toBe(0);

    // site B's context reaches none of site A's rows, and cannot write one for site A
    app(Tenancy::class)->enter(2, function () use ($page): void {
        expect(FormPlacement::query()->count())->toBe(0)
            ->and(DB::table('form_placements')->count())->toBe(0);
        expect(fn () => DB::transaction(fn () => DB::table('form_placements')->insert([
            'site_id' => 1, 'form_id' => DB::connection('pgsql_admin')->table('forms')->value('id'), 'entry_id' => $page->id, 'locale' => 'vi',
            'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class);
    });
    expect(placements())->toHaveCount(1);
});

it('names a page only a visitor can open, and never another site\'s', function (): void {
    $contact = placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $consult = placedForm(1, 'tu-van', 'Đăng ký tư vấn');
    pageWithForms(1, 'nhap', ['bao-gia'], status: 'draft');
    $trashed = pageWithForms(1, 'thung-rac', ['tu-van']);
    app(Tenancy::class)->enter(1, fn () => Entry::query()->whereKey($trashed->id)->firstOrFail()->delete());
    pageWithForms(1, 'contact-us', [null], locale: 'en');
    pageWithForms(1, 'lien-he', [null]);
    $contactB = placedForm(2, Form::SYSTEM_KEY, 'Liên hệ B');
    pageWithForms(2, 'lien-he-b', [null]);

    $paths = app(Tenancy::class)->enter(1, fn (): array => app(FormPagePaths::class)->of([$contact->id, $quote->id, $consult->id, $contactB->id], 'vi'));
    // the default language's page first; a draft, a page in the Trash and site B's page are not named
    expect($paths)->toBe([$contact->id => '/lien-he']);

    $inB = app(Tenancy::class)->enter(2, fn (): array => app(FormPagePaths::class)->of([$contact->id, $contactB->id], 'vi'));
    expect($inB)->toBe([$contactB->id => '/lien-he-b']);
});

it('names no page for a switched-off form, nor a page whose language is still a draft', function (): void {
    $contact = placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    pageWithForms(1, 'lien-he', [null, 'bao-gia']);
    $draftLanguage = pageWithForms(1, 'contact-us', [null], locale: 'en');
    app(Tenancy::class)->enter(1, fn () => EntryTranslation::query()->where('entry_id', $draftLanguage->id)->firstOrFail()->update(['status' => 'draft']));
    $paths = fn (): array => app(Tenancy::class)->enter(1, fn (): array => app(FormPagePaths::class)->of([$contact->id, $quote->id], 'en'));

    // the entry is published but its English language is a draft: the Vietnamese page is named
    expect($paths())->toBe([$contact->id => '/lien-he', $quote->id => '/lien-he']);

    // a switched-off form shows nothing on its page (FormComponent renders active forms only)
    app(Tenancy::class)->enter(1, fn () => $quote->update(['status' => 'disabled']));
    expect($paths())->toBe([$contact->id => '/lien-he']);
});

it('gives a form made after pages name its key its rows at once, and heals a missed row with the next save', function (): void {
    $contactPage = pageWithForms(1, 'lien-he', [null]);
    $quotePage = pageWithForms(1, 'bao-gia', ['bao-gia']);
    pageWithForms(2, 'lien-he-b', [null]);
    expect(placements())->toBe([]);   // no form of those keys yet

    // blocks whose key is null or not a string also show the contact form (keysIn, as an empty one); a page
    // naming the same form twice gets one row
    $oddKeys = app(Tenancy::class)->enter(1, function (): Entry {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'meta' => []]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Hỗ trợ', 'slug' => 'ho-tro', 'status' => 'published', 'body' => ['blocks' => [
            ['type' => 'form', 'props' => ['form_key' => null]], ['type' => 'form', 'props' => ['form_key' => 7]],
            ['type' => 'form', 'props' => ['form_key' => ['bao-gia']]], ['type' => ['form'], 'props' => ['form_key' => 'bao-gia']],
        ]]]);

        return $entry;
    });
    $quoted = pageWithForms(1, 'bao-gia-dac-biet', ["bao-gia \"đặc biệt\" 'x'"]);

    // the contact form made after the pages: site A's pages get their rows, site B's page none
    placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    expect(placements())->toBe(["{$contactPage->id}:vi:contact", "{$oddKeys->id}:vi:contact"]);
    $special = placedForm(1, "bao-gia \"đặc biệt\" 'x'", 'Báo giá đặc biệt');
    expect(placements())->toContain("{$quoted->id}:vi:{$special->key}");
    DB::connection('pgsql_admin')->table('forms')->where('id', $special->id)->delete();

    // a form deleted for good and made again under the same key
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    DB::connection('pgsql_admin')->table('forms')->where('id', $quote->id)->delete();
    expect(placements())->toBe(["{$contactPage->id}:vi:contact", "{$oddKeys->id}:vi:contact"]);
    placedForm(1, 'bao-gia', 'Báo giá');
    expect(placements())->toBe(["{$contactPage->id}:vi:contact", "{$quotePage->id}:vi:bao-gia", "{$oddKeys->id}:vi:contact"]);

    app(Tenancy::class)->enter(1, fn () => $oddKeys->translations()->firstOrFail()->delete());

    // a row lost: the next save of the body heals it even when its forms did not change, and also
    // from a model loaded without the body
    DB::connection('pgsql_admin')->table('form_placements')->delete();
    app(Tenancy::class)->enter(1, function () use ($contactPage, $quotePage): void {
        $vi = EntryTranslation::query()->where('entry_id', $quotePage->id)->firstOrFail();
        $body = formBlocks(['bao-gia']);
        $body['blocks'][0]['props']['title'] = 'Báo giá trong ngày';
        $vi->update(['body' => $body]);
        EntryTranslation::query()->where('entry_id', $contactPage->id)->firstOrFail(['id', 'site_id', 'entry_id', 'locale'])
            ->update(['body' => formBlocks([null])]);
    });
    expect(placements())->toBe(["{$contactPage->id}:vi:contact", "{$quotePage->id}:vi:bao-gia"]);
});

it('offers «Xem trang có biểu mẫu» where a page shows the form, «Đặt vào trang» elsewhere, on a flat query budget', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    $contact = placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    pageWithForms(1, 'lien-he', [null]);

    $html = $this->actingAs($owner)->get('http://a.test/admin/form-builder')->assertOk()->getContent();
    expect($html)->toContain(__('form_builder.view_page'))
        ->toContain('href="/lien-he" role="menuitem"')
        ->toContain(__('form_builder.place_on_page'))
        // «Đặt vào trang» opens the editor, as the board does: one link more to the quote form's editor than to the contact form's
        ->and(substr_count($html, '/admin/form-builder/'.$quote->ulid.'/edit"'))
        ->toBe(substr_count($html, '/admin/form-builder/'.$contact->ulid.'/edit"') + 1);

    $count = function () use ($owner): int {
        return app(Tenancy::class)->enter(1, function () use ($owner): int {
            $component = Livewire::actingAs($owner)->test(FormList::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $component->call('$refresh');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        });
    };
    $one = $count();
    foreach (['a', 'b', 'c', 'd'] as $i => $k) {
        placedForm(1, 'mau-'.$k, 'Mẫu '.$k);
        pageWithForms(1, 'trang-'.$k, ['mau-'.$k, null]);
    }
    expect($count())->toBe($one)->toBeLessThanOrEqual(12);
    unset($contact);
});

it('says in the form\'s editor how to put it on a page, and where it already is', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    $contact = placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    pageWithForms(1, 'lien-he', [null]);

    app(Tenancy::class)->enter(1, function () use ($owner, $contact, $quote): void {
        Livewire::actingAs($owner)->test(FormEditor::class, ['ulid' => $contact->ulid])
            ->assertSet('pagePath', '/lien-he')
            ->assertSee(__('form_builder.place_title'))
            ->assertSee(__('form_builder.place_text', ['name' => 'Liên hệ']))
            ->assertSee(__('form_builder.place_on'))
            ->assertSeeHtml('href="/lien-he" target="_blank"')
            ->assertSeeHtml('/admin/content?section=pages');
        Livewire::actingAs($owner)->test(FormEditor::class, ['ulid' => $quote->ulid])
            ->assertSet('pagePath', null)
            ->assertDontSee(__('form_builder.place_on'));
    });

    // a member who may not open the pages list gets no button to it
    $formsOnly = blockLibraryUser(1, 'forms-only', ['forms.view', 'forms.manage']);
    expect(app(Tenancy::class)->enter(1, fn (): bool => $formsOnly->can('entries.view')))->toBeFalse();
    app(Tenancy::class)->enter(1, function () use ($formsOnly, $quote): void {
        Livewire::actingAs($formsOnly)->test(FormEditor::class, ['ulid' => $quote->ulid])
            ->assertSee(__('form_builder.place_text', ['name' => 'Yêu cầu báo giá']))
            ->assertDontSeeHtml('/admin/content?section=pages');
    });
});

it('reconciles every page language with `forms:placements`, and a second run corrects nothing', function (): void {
    placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $kept = pageWithForms(1, 'lien-he', [null]);
    $lost = pageWithForms(1, 'bao-gia', ['bao-gia']);
    $stray = pageWithForms(1, 'gioi-thieu', []);
    placedForm(2, Form::SYSTEM_KEY, 'Liên hệ B');
    $pageB = pageWithForms(2, 'lien-he-b', [null]);
    $right = placements();

    // drift: a row lost, a row of a form the page does not show, and a row of a language that is gone
    $admin = DB::connection('pgsql_admin');
    $admin->table('form_placements')->where('entry_id', $lost->id)->delete();
    $admin->table('form_placements')->insert([
        ['site_id' => 1, 'form_id' => $quote->id, 'entry_id' => $stray->id, 'locale' => 'vi', 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 1, 'form_id' => $quote->id, 'entry_id' => $kept->id, 'locale' => 'ja', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('forms:placements')->expectsOutput('Checked 4 page language(s) on 2 site(s); corrected 3.')->assertSuccessful();
    expect(placements())->toBe($right);
    $this->artisan('forms:placements')->expectsOutput('Checked 4 page language(s) on 2 site(s); corrected 0.')->assertSuccessful();
    $this->artisan('forms:placements', ['--site' => 2, '--chunk' => 1])->expectsOutput('Checked 1 page language(s) on 1 site(s); corrected 0.')->assertSuccessful();
    unset($pageB);
});

it('refuses a row that pairs a site with another site\'s form, even past the app', function (): void {
    $formA = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $formB = placedForm(2, 'bao-gia', 'Báo giá B');
    $pageB = pageWithForms(2, 'trang-b', []);
    $row = fn (int $formId): array => ['site_id' => 2, 'form_id' => $formId, 'entry_id' => $pageB->id, 'locale' => 'vi', 'created_at' => now(), 'updated_at' => now()];

    // in site B's context, with site B's page: site A's form is refused by the foreign key itself
    app(Tenancy::class)->enter(2, function () use ($row, $formA, $formB): void {
        expect(fn () => DB::transaction(fn () => DB::table('form_placements')->insert($row($formA->id))))->toThrow(QueryException::class, 'form_placements_site_id_form_id_foreign');
        DB::table('form_placements')->insert($row($formB->id));   // its own form goes in
    });
    // and so is a superuser's write, which RLS does not stop
    expect(fn () => DB::connection('pgsql_admin')->transaction(fn () => DB::connection('pgsql_admin')->table('form_placements')->insert($row($formA->id))))
        ->toThrow(QueryException::class, 'form_placements_site_id_form_id_foreign');

    expect(placements())->toBe(["{$pageB->id}:vi:bao-gia"])
        ->and((int) DB::connection('pgsql_admin')->table('form_placements')->value('form_id'))->toBe($formB->id);
});

it('names no page in a language visitors cannot open yet', function (): void {
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    DB::connection('pgsql_admin')->table('site_locales')->insert(
        ['site_id' => 1, 'locale' => 'ja', 'is_default' => false, 'enabled' => true, 'is_public' => false, 'created_at' => now(), 'updated_at' => now()],
    );
    pageWithForms(1, 'bao-gia-ja', ['bao-gia'], locale: 'ja');   // published, its route there, but ja is not public
    $paths = function () use ($quote): array {
        app()->forgetInstance(SiteLocale::class);
        app()->forgetScopedInstances();

        return app(Tenancy::class)->enter(1, fn (): array => app(FormPagePaths::class)->of([$quote->id], 'ja'));
    };

    expect(placements())->toHaveCount(1)->and($paths())->toBe([]);

    // once ja is public the page is named, with its language prefix
    DB::connection('pgsql_admin')->table('site_locales')->where('site_id', 1)->where('locale', 'ja')->update(['is_public' => true]);
    expect($paths())->toBe([$quote->id => '/ja/bao-gia-ja']);
});

it('saves the page when its form is deleted just before its rows are written, writing no row for it', function (): void {
    $contact = placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $page = pageWithForms(1, 'lien-he', [null]);

    // the quote form is deleted for good right after the save has read which forms its blocks name
    $armed = true;
    DB::listen(function ($query) use (&$armed, $quote): void {
        if ($armed && str_starts_with($query->sql, 'select "id" from "forms"')) {
            $armed = false;
            DB::connection('pgsql_admin')->table('forms')->where('id', $quote->id)->delete();
        }
    });
    app(Tenancy::class)->enter(1, function () use ($page): void {
        EntryTranslation::query()->where('entry_id', $page->id)->firstOrFail()->update(['body' => formBlocks([null, 'bao-gia'])]);
    });

    expect($armed)->toBeFalse()
        ->and(placements())->toBe(["{$page->id}:vi:contact"])
        ->and(app(Tenancy::class)->enter(1, fn () => EntryTranslation::query()->where('entry_id', $page->id)->value('body')))->toBe(formBlocks([null, 'bao-gia']));

    // the write itself, handed a form that is gone: no row, and it says nothing changed
    expect(app(Tenancy::class)->enter(1, fn (): bool => EntryFormPlacements::write(1, $page->id, 'vi', [$contact->id, $quote->id], [$contact->id])))->toBeFalse();
});

it('places a new form once however often its job runs, and never for a site that is not the form\'s', function (): void {
    $page = pageWithForms(1, 'bao-gia', ['bao-gia']);
    $pageB = pageWithForms(2, 'bao-gia-b', ['bao-gia']);
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');   // its job ran once already (sync queue)
    expect(placements())->toBe(["{$page->id}:vi:bao-gia"]);

    PlaceNewForm::dispatchSync(1, $quote->id);
    PlaceNewForm::dispatchSync(1, $quote->id);
    expect(placements())->toBe(["{$page->id}:vi:bao-gia"]);

    // a job naming site B with site A's form finds no form under site B's context: site B's page gets no row
    PlaceNewForm::dispatchSync(2, $quote->id);
    expect(placements())->toBe(["{$page->id}:vi:bao-gia"]);
    unset($pageB);
});

it('brings a page\'s link back when it is restored from the Trash', function (): void {
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $page = pageWithForms(1, 'bao-gia', ['bao-gia']);
    $paths = fn (): array => app(Tenancy::class)->enter(1, fn (): array => app(FormPagePaths::class)->of([$quote->id], 'vi'));
    expect($paths())->toBe([$quote->id => '/bao-gia']);

    app(Tenancy::class)->enter(1, fn () => Entry::query()->whereKey($page->id)->firstOrFail()->delete());
    expect($paths())->toBe([])->and(placements())->toBe(["{$page->id}:vi:bao-gia"]);   // the row stays in the Trash

    app(Tenancy::class)->enter(1, fn () => Entry::withTrashed()->whereKey($page->id)->firstOrFail()->restore());
    expect($paths())->toBe([$quote->id => '/bao-gia']);
});

it('exits non-zero when a site fails, still checking the others, and refuses a --site that is no site', function (): void {
    placedForm(1, Form::SYSTEM_KEY, 'Liên hệ');
    placedForm(2, Form::SYSTEM_KEY, 'Liên hệ B');
    pageWithForms(1, 'lien-he', [null]);
    pageWithForms(2, 'lien-he-b', [null]);
    DB::connection('pgsql_admin')->table('form_placements')->delete();

    // site 1's page languages cannot be read
    DB::listen(function ($query): void {
        if (str_starts_with($query->sql, 'select "id", "site_id", "entry_id", "locale", "body" from "entry_translations"')
            && DB::scalar("select current_setting('app.current_site_id', true)") === '1') {
            throw new RuntimeException('site 1 is broken');
        }
    });
    $this->artisan('forms:placements')
        ->expectsOutput('Site 1: site 1 is broken')
        ->expectsOutput('Checked 1 page language(s) on 2 site(s); corrected 1.')
        ->expectsOutput('Failed on 1 site(s); see the log.')
        ->assertFailed();
    expect(placements())->toHaveCount(1);   // site 2 was still checked

    foreach (['abc', '0', '-1', '999999', '1x'] as $bad) {
        $this->artisan('forms:placements', ['--site' => $bad])->expectsOutput("No site with the id {$bad}.")->assertExitCode(2);
    }
});

it('checks the placements every night, never two at once', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'forms:placements'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 21 * * *')
        ->and($event->timezone)->toBe('UTC')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('drops the table and the forms key it aims at on the way down, safely twice, and makes both again on the way up', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2082_01_01_000000_create_form_placements_table.php');
    /** @var Migration $formsKey */
    $formsKey = require database_path('migrations/2081_01_01_000200_add_site_id_id_unique_to_forms.php');
    $admin = DB::connection('pgsql_admin');
    $exists = fn (): bool => $admin->selectOne("select to_regclass('public.form_placements') is not null as e")->e;
    $keyed = fn (): bool => $admin->selectOne("select count(*) = 1 as k from pg_constraint where conrelid = 'forms'::regclass and conname = 'forms_site_id_id_unique' and contype = 'u'")->k
        && $admin->selectOne("select to_regclass('public.forms_site_id_id_unique') is not null as i")->i;

    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $migration->down();
        $dropped = ! $exists();
        $formsKey->down();
        $formsKey->down();
        $unkeyed = ! $keyed() && $admin->selectOne("select to_regclass('public.forms_site_id_id_unique') is null as n")->n;
        $formsKey->up();
        $formsKey->up();   // a second run finds both and adds nothing
        $rekeyed = $keyed();
        DB::transaction(fn () => $migration->up());
        $forced = DB::selectOne("select relrowsecurity and relforcerowsecurity as f from pg_class where relname = 'form_placements'")->f;
    } finally {
        if (! $keyed()) {
            $formsKey->up();
        }
        if (! $exists()) {
            DB::transaction(fn () => $migration->up());
        }
        DB::setDefaultConnection($original);
    }

    expect($dropped)->toBeTrue()->and($unkeyed)->toBeTrue()->and($rekeyed)->toBeTrue()
        ->and($forced)->toBeTrue()->and($exists())->toBeTrue()->and($keyed())->toBeTrue();
})->skip(getenv('CI') !== 'true', 'drops and creates a table: runs on CI\'s own database only');

it('gives a form being deleted in batches no row anywhere: the save, the nightly check and a new form\'s job drop or skip it', function (): void {
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $page = pageWithForms(1, 'bao-gia', ['bao-gia']);
    expect(placements())->toBe(["{$page->id}:vi:bao-gia"]);
    $admin = DB::connection('pgsql_admin');
    $admin->table('forms')->where('id', $quote->id)->update(['status' => Form::DELETING]);
    $stray = fn () => $admin->table('form_placements')->insert(['site_id' => 1, 'form_id' => $quote->id, 'entry_id' => $page->id, 'locale' => 'vi', 'created_at' => now(), 'updated_at' => now()]);

    // the list names no page for it while its row is still there
    expect(app(Tenancy::class)->enter(1, fn (): array => app(FormPagePaths::class)->of([$quote->id], 'vi')))->toBe([]);

    // the next save of the page drops its row
    app(Tenancy::class)->enter(1, function () use ($page): void {
        EntryTranslation::query()->where('entry_id', $page->id)->firstOrFail()->update(['body' => formBlocks(['bao-gia', 'bao-gia'])]);
    });
    expect(placements())->toBe([]);

    // the nightly check drops one written past the app
    $stray();
    $this->artisan('forms:placements')->assertSuccessful();
    expect(placements())->toBe([]);

    // a new form's job, and the write handed its id, add none (the write is the one past the scope:
    // its INSERT … SELECT reads forms itself)
    PlaceNewForm::dispatchSync(1, $quote->id);
    expect(placements())->toBe([])
        ->and(app(Tenancy::class)->enter(1, fn (): bool => EntryFormPlacements::write(1, $page->id, 'vi', [$quote->id], [])))->toBeFalse()
        ->and(placements())->toBe([]);
});

it('gives a new form\'s job no rows when the form is switched to deleting after the job read it', function (): void {
    $page = pageWithForms(1, 'bao-gia', ['bao-gia']);
    $pageEn = pageWithForms(1, 'quote', ['bao-gia'], locale: 'en');
    $quote = placedForm(1, 'bao-gia', 'Yêu cầu báo giá');
    expect(placements())->toBe(["{$page->id}:vi:bao-gia", "{$pageEn->id}:en:bao-gia"]);   // both languages, through the array binding
    $admin = DB::connection('pgsql_admin');
    $admin->table('form_placements')->delete();

    $armed = true;
    DB::listen(function ($query) use (&$armed, $admin, $quote): void {
        // the job has read the form and the pages naming it; the delete in batches commits now
        if ($armed && str_starts_with($query->sql, 'select "entry_id", "locale" from "entry_translations"')) {
            $armed = false;
            $admin->table('forms')->where('id', $quote->id)->update(['status' => Form::DELETING]);
        }
    });
    PlaceNewForm::dispatchSync(1, $quote->id);

    expect($armed)->toBeFalse()->and(placements())->toBe([]);
});
