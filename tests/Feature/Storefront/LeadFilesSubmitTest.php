<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Components\FormComponent;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * A visitor sends files with a lead («Tệp đính kèm»), from the browser's own file box: the lead is
 * always kept first, then each file on its own — kept on the private disk, or refused with a row
 * (wrong or disguised kind, too large, over the field's number) — and a file that cannot be
 * written is left out. Neither ever costs the visitor their lead. A spam lead keeps no file. The
 * thank-you card says how many did not come through; the lead email only counts them.
 */

beforeEach(function (): void {
    seedTenancy();
    Notification::fake();
    Cache::flush(); // the per-IP rate limiter
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings, audit_logs restart identity cascade');
    config(['services.turnstile.secret_key' => '', 'services.turnstile.site_key' => '', 'forms.file_kinds' => ['cad', 'cv', 'img']]);
    Storage::fake('leads');
});

/** The quote form of site 1: name, phone, and a drawings field taking up to $max files of $kind. */
function lfsForm(int $max = 2, string $kind = 'cad', bool $required = false, string $key = 'quote'): Form
{
    return app(Tenancy::class)->enter(1, function () use ($max, $kind, $required, $key): Form {
        $form = Form::query()->create(['key' => $key, 'name' => ['vi' => 'Báo giá'], 'status' => 'active', 'notify_emails' => ['sales@anvinh.test']]);
        $form->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['vi' => 'Điện thoại'], 'is_required' => true, 'sort_order' => 2],
            ['key' => 'drawing', 'type' => 'file', 'label' => ['vi' => 'Bản vẽ kèm theo'], 'is_required' => $required, 'sort_order' => 3,
                'options' => ['accept' => $kind], 'validation' => ['max_files' => $max]],
        ]);

        return $form;
    });
}

function lfsStep(string $name = 'banh-rang.step', int $pad = 64): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "ISO-10303-21;\nHEADER;\n".str_repeat('x', $pad));
}

function lfsPdf(string $name = 'ban-ve.pdf', int $pad = 64): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $pad));
}

/** @param  array<string, mixed>  $extra */
function lfsSend(array $files, array $extra = [], string $key = 'quote'): TestResponse
{
    return test()->from('http://a.test/bao-gia')->post('http://a.test/forms/'.$key, ['name' => 'Bình', 'phone' => '0900000000', 'drawing' => $files, ...$extra]);
}

function lfsLead(): ?FormSubmission
{
    return app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->first());
}

/** @return list<array{status: string, original_name: string, field_key: string}> */
function lfsRows(): array
{
    return app(Tenancy::class)->enter(1, fn (): array => FormSubmissionFile::query()->orderBy('id')->get(['status', 'original_name', 'field_key', 'storage_key'])
        ->map(fn (FormSubmissionFile $f): array => ['status' => $f->status, 'original_name' => $f->original_name, 'field_key' => $f->field_key])->all());
}

it('keeps each file sent with a lead, apart from its answers, and the email only counts them', function (): void {
    $form = lfsForm(max: 2);

    lfsSend([lfsStep(), lfsPdf()])->assertSessionHasNoErrors()->assertRedirect()
        ->assertSessionMissing('form_files_missed');

    $lead = lfsLead();
    expect($lead)->not->toBeNull()
        ->and($lead->data)->toBe(['name' => 'Bình', 'phone' => '0900000000']) // no file in the answers
        ->and(lfsRows())->toBe([
            ['status' => 'stored', 'original_name' => 'banh-rang.step', 'field_key' => 'drawing'],
            ['status' => 'stored', 'original_name' => 'ban-ve.pdf', 'field_key' => 'drawing'],
        ])
        ->and(Storage::disk('leads')->allFiles())->toHaveCount(2);

    Notification::assertSentOnDemand(NewLeadNotification::class, function (NewLeadNotification $mail) use ($lead): bool {
        app()->setLocale('vi');
        $message = $mail->toMail(new AnonymousNotifiable);
        $text = implode("\n", array_map(strval(...), [...$message->introLines, ...$message->outroLines]));

        return str_contains($text, '2 tệp đính kèm') && $message->attachments === [] && $message->rawAttachments === []
            && ! str_contains($text, 'ban-ve.pdf') && $lead !== null;
    });
});

it('refuses the files past the field\'s number with rows, keeps the lead, and says how many did not come', function (): void {
    lfsForm(max: 2);

    lfsSend([lfsStep('a.step'), lfsStep('b.step'), lfsPdf('c.pdf')])->assertSessionHasNoErrors()
        ->assertSessionHas('form_files_missed', 1);

    expect(lfsLead())->not->toBeNull()
        ->and(array_column(lfsRows(), 'status'))->toBe(['stored', 'stored', 'rejected'])
        ->and(lfsRows()[2]['original_name'])->toBe('c.pdf')
        ->and(Storage::disk('leads')->allFiles())->toHaveCount(2);
    // the email counts the files kept, not the one refused
    Notification::assertSentOnDemand(NewLeadNotification::class, function (NewLeadNotification $mail): bool {
        app()->setLocale('vi');
        $message = $mail->toMail(new AnonymousNotifiable);
        $lines = implode("\n", array_map(strval(...), [...$message->introLines, ...$message->outroLines]));

        return str_contains($lines, '2 tệp đính kèm') && ! str_contains($lines, '3 tệp');
    });
});

it('refuses a file over the size limit, a forged one and one of the wrong kind, and still keeps the lead', function (): void {
    config(['forms.file_max_mb' => 1]);
    lfsForm(max: 4);

    lfsSend([
        lfsStep('lon.step', 1024 * 1024 + 10),        // over 1 MB
        UploadedFile::fake()->createWithContent('gia.step', 'MZ not a drawing'), // the name says STEP, the bytes do not
        lfsPdf('anh.png'),                             // not a kind this field takes
        lfsPdf('dung.pdf'),
    ])->assertSessionHasNoErrors()->assertSessionHas('form_files_missed', 3);

    expect(lfsLead())->not->toBeNull()
        ->and(array_column(lfsRows(), 'status'))->toBe(['rejected', 'rejected', 'rejected', 'stored'])
        ->and(Storage::disk('leads')->allFiles())->toHaveCount(1);
});

it('keeps the lead when a file cannot be written at all, and leaves that file out', function (): void {
    lfsForm();
    config(['forms.files_disk' => 'no-such-disk']);

    lfsSend([lfsPdf()])->assertSessionHasNoErrors()->assertSessionHas('form_files_missed', 1);

    expect(lfsLead())->not->toBeNull()->and(lfsRows())->toBe([]);
});

it('keeps no file of a spam lead, not even a refused one', function (): void {
    lfsForm(max: 1);

    lfsSend([lfsStep(), lfsPdf(), lfsPdf('x.exe')], ['company_website' => 'https://spam.test'])->assertSessionHasNoErrors()
        ->assertSessionMissing('form_files_missed');

    expect(lfsLead()?->is_spam)->toBeTrue()
        ->and(lfsRows())->toBe([])
        ->and(Storage::disk('leads')->allFiles())->toBe([]);
    Notification::assertNothingSent();
});

it('asks for a file when the field needs one, and keeps nothing', function (): void {
    lfsForm(required: true);

    lfsSend([])->assertSessionHasErrors(['drawing' => 'Vui lòng chọn tệp để gửi kèm.']);

    expect(lfsLead())->toBeNull();
});

it('takes a lone file sent without the list brackets', function (): void {
    lfsForm();

    test()->post('http://a.test/forms/quote', ['name' => 'Bình', 'phone' => '0900000000', 'drawing' => lfsPdf()])->assertSessionHasNoErrors();

    expect(array_column(lfsRows(), 'status'))->toBe(['stored']);
});

it('takes no typed value in a file field', function (): void {
    lfsForm();

    lfsSend(['not-a-file'])->assertSessionHasErrors('drawing');

    expect(lfsLead())->toBeNull()->and(lfsRows())->toBe([]);
});

it('takes a lead with no file in an optional file field', function (): void {
    lfsForm();

    test()->post('http://a.test/forms/quote', ['name' => 'Bình', 'phone' => '0900000000'])->assertSessionHasNoErrors();

    expect(lfsLead()?->data)->toBe(['name' => 'Bình', 'phone' => '0900000000'])->and(lfsRows())->toBe([]);
});

it('brings a send over the body limit back to its form with a plain message, in the site\'s language', function (): void {
    lfsForm();
    $limit = (int) ini_get('post_max_size') * 1024 * 1024;

    $response = test()->from('http://a.test/bao-gia')->call('POST', 'http://a.test/forms/quote', [], [], [], ['CONTENT_LENGTH' => (string) ($limit + 1)]);

    $response->assertRedirect('http://a.test/bao-gia')
        ->assertSessionHasErrors(['form' => __('forms.err_files_total', ['mb' => intdiv($limit, 1024 * 1024)], 'vi')])
        ->assertSessionHasInput('lead_form', 'quote');
    expect(lfsLead())->toBeNull();
});

it('keeps Laravel\'s body limit for every other POST', function (): void {
    $limit = (int) ini_get('post_max_size') * 1024 * 1024;

    test()->call('POST', 'http://a.test/admin/login', [], [], [], ['CONTENT_LENGTH' => (string) ($limit + 1)])->assertStatus(413);
});

it('neither shows nor collects a file field of a kind the platform holds back', function (): void {
    config(['forms.file_kinds' => ['cad', 'img']]);
    lfsForm(kind: 'cv');

    $fields = app(Tenancy::class)->enter(1, fn () => app(FormComponent::class)->data(['form_key' => 'quote']))['fields'];
    lfsSend([lfsPdf()])->assertSessionHasNoErrors();

    expect(array_column($fields, 'key'))->toBe(['name', 'phone'])
        ->and(lfsLead())->not->toBeNull()
        ->and(lfsRows())->toBe([]);
});

it('draws the browser\'s file box with the kind, the number and the size, on a multipart form', function (): void {
    lfsForm(max: 3);
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Báo giá', 'slug' => 'bao-gia', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'form', 'props' => ['form_key' => 'quote']]]]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/bao-gia', 'entry_id' => $entry->id]);
    });

    $html = $this->get('http://a.test/bao-gia')->assertOk()->getContent();

    expect($html)->toContain('enctype="multipart/form-data"')
        ->toContain('type="file" name="drawing[]" accept=".step,.stp,.iges,.igs,.dwg,.dxf,.pdf"')
        ->toMatch('/name="drawing\[\]"[^>]*\smultiple\b/')
        ->toContain('STEP, IGES, DWG, DXF, PDF · tối đa 3 tệp, mỗi tệp 20 MB')
        ->toMatch('/aria-describedby="[^"]*drawing--files/');
});

it('tells the visitor on the thank-you card how many files did not come through', function (): void {
    lfsForm();
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Báo giá', 'slug' => 'bao-gia', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'form', 'props' => ['form_key' => 'quote']]]]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/bao-gia', 'entry_id' => $entry->id]);
    });

    $html = $this->withCookie((string) config('session.cookie'), 'visitor')
        ->withSession(['form_success' => 'Cảm ơn', 'form_success_key' => 'quote', 'form_files_missed' => 2])
        ->get('http://a.test/bao-gia')->getContent();

    expect($html)->toContain('2 tệp chưa nhận được');
});
