<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadAnswers;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A pack's form item (`forms.json`, `form:<key>`): it places the platform's own fields, adds or
 * updates the site's extra fields by key, gives a field its example in the empty field, and names
 * the thank-you page a send opens (stored as the page's ulid). The validator refuses what the
 * storefront could not draw or what would rewrite the platform's fields; the loader keeps staff
 * edits and the ledger like every other item.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
    file_put_contents($this->folder.'/forms.json', json_encode(['items' => [fpContactItem()]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

afterEach(fn () => ContentPacks::cleanup());

/** @return array<string, mixed> the contact form as a pack writes it: a "need" select fourth, a thank-you page */
function fpContactItem(): array
{
    return [
        'key' => 'form:contact',
        'fields' => [
            ['key' => 'name', 'sort_order' => 1],
            ['key' => 'phone', 'sort_order' => 2, 'placeholder' => ['vi' => '0912 345 678']],
            ['key' => 'email', 'sort_order' => 3],
            ['key' => 'need', 'type' => 'select', 'label' => ['vi' => 'Bạn cần tư vấn về'], 'is_required' => false, 'sort_order' => 4,
                'placeholder' => ['vi' => '— Chọn nhu cầu —'],
                'choices' => [
                    ['value' => 'jig', 'label' => ['vi' => 'Thiết kế Jig & Đồ gá']],
                    ['value' => 'khuon-mau', 'label' => ['vi' => 'Khuôn mẫu']],
                ]],
            ['key' => 'message', 'sort_order' => 5],
            ['key' => 'consent', 'sort_order' => 6],
        ],
        'settings' => ['redirect_entry' => 'page:/gioi-thieu'],
    ];
}

/**
 * Change the form item of the copied pack.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $change
 */
function fpChange(string $folder, callable $change): void
{
    ContentPacks::item($folder, 'forms.json', 'form:contact', $change);
}

/**
 * Change one field of the copied pack's form item.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $change
 */
function fpField(string $folder, string $key, callable $change): void
{
    fpChange($folder, function (array $item) use ($key, $change): array {
        foreach ($item['fields'] as $index => $field) {
            if ($field['key'] === $key) {
                $item['fields'][$index] = $change($field);
            }
        }

        return $item;
    });
}

function fpProblems(): string
{
    return implode("\n", app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co'));
}

/** @return array<string, array{type: string, sort_order: int}> the live contact form's fields, in order */
function fpFields(int $siteId): array
{
    return ContentPacks::in($siteId, fn (): array => Form::query()->where('key', 'contact')->firstOrFail()->fields()->get()
        ->mapWithKeys(fn (FormField $field): array => [$field->key => ['type' => $field->type, 'sort_order' => (int) $field->getAttribute('sort_order')]])->all());
}

it('accepts the form item as written', function (): void {
    expect(fpProblems())->toBe('');
});

it('refuses a field the storefront could not draw or a key the platform keeps for itself', function (Closure $change, string $problem): void {
    fpField($this->folder, 'need', $change);

    expect(fpProblems())->toContain($problem);
})->with([
    'an unknown type' => [fn (array $field): array => ['type' => 'file'] + $field, 'type must be one of'],
    'a reserved key' => [fn (array $field): array => ['key' => 'locale'] + $field, 'key must be a field key'],
    'a key with a dash' => [fn (array $field): array => ['key' => 'my-need'] + $field, 'key must be a field key'],
    'a select without choices' => [fn (array $field): array => ['choices' => []] + $field, 'needs a non-empty list of choices'],
    'a choice listed twice' => [fn (array $field): array => ['choices' => [['value' => 'a', 'label' => ['vi' => 'A']], ['value' => 'a', 'label' => ['vi' => 'B']]]] + $field, "value 'a' is listed twice"],
    'a choice without a label' => [fn (array $field): array => ['choices' => [['value' => 'a']]] + $field, 'label is required'],
    'no label' => [fn (array $field): array => array_diff_key($field, ['label' => true]), 'label is required'],
    'choices on a text field' => [fn (array $field): array => ['type' => 'text'] + $field, 'only a choice field has choices'],
    'a setting the loader does not write' => [fn (array $field): array => $field + ['validation' => ['max_length' => 5]], "'validation' is not a field setting"],
]);

it('refuses any change to the consent box but its place, and keeps it last', function (Closure $change, string $problem): void {
    fpField($this->folder, 'consent', $change);

    expect(fpProblems())->toContain($problem);
})->with([
    'its sentence' => [fn (array $field): array => $field + ['label' => ['vi' => 'Đồng ý']], "the consent box is the platform's legal wording"],
    'its required flag' => [fn (array $field): array => $field + ['is_required' => false], "the consent box is the platform's legal wording"],
    'its type' => [fn (array $field): array => $field + ['type' => 'text'], "the consent box is the platform's legal wording"],
    'a placeholder' => [fn (array $field): array => $field + ['placeholder' => ['vi' => 'x']], "the pack may only place it ('placeholder' is not allowed)"],
    'a place of zero' => [fn (array $field): array => ['sort_order' => 0] + $field, 'sort_order must be a whole number'],
]);

it('refuses the consent box above a field it covers', function (): void {
    fpField($this->folder, 'consent', fn (array $field): array => ['sort_order' => 3] + $field);
    fpField($this->folder, 'email', fn (array $field): array => ['sort_order' => 7] + $field);

    expect(fpProblems())->toContain('the consent box comes after every field it covers');
});

it('lets a platform field only be placed and given an example', function (string $attribute, mixed $value): void {
    fpField($this->folder, 'email', fn (array $field): array => $field + [$attribute => $value]);

    expect(fpProblems())->toContain("a platform field keeps its type, label and required flag; the pack may only place it and give its placeholder ('{$attribute}' is not allowed)");
})->with([
    'a new type' => ['type', 'text'],
    'a new label' => ['label', ['vi' => 'Thư điện tử']],
    'a required flag' => ['is_required', true],
]);

it('refuses an order with a gap in what it names: two fields on one place, or a platform field left out', function (): void {
    fpField($this->folder, 'email', fn (array $field): array => ['sort_order' => 2] + $field);
    expect(fpProblems())->toContain("sort_order 2 is also phone's");

    fpChange($this->folder, fn (array $item): array => ['fields' => array_values(array_filter($item['fields'], fn (array $field): bool => $field['key'] !== 'message'))] + $item);
    expect(fpProblems())->toContain('the contact form lists every platform field with its sort_order (message is missing)');
});

it('takes only a page key as the thank-you page', function (mixed $target): void {
    fpChange($this->folder, fn (array $item): array => ['settings' => ['redirect_entry' => $target]] + $item);

    expect(fpProblems())->toContain('redirect_entry must be the key of a page');
})->with([
    'a post' => ['post:ra-mat-website'],
    'a full link' => ['https://evil.example/cam-on'],
    'protocol-relative' => ['//evil.example/cam-on'],
    'a path' => ['/cam-on'],
    'a ulid' => ['01JZ0000000000000000000000'],
    'a strange key' => ['tax:dich-vu'],
]);

it('refuses another form setting', function (): void {
    fpChange($this->folder, fn (array $item): array => ['settings' => ['notify_emails' => ['x@evil.example']]] + $item);

    expect(fpProblems())->toContain("the loader writes only the form setting redirect_entry ('notify_emails' stays the site's own)");
});

it('adds the extra field, places every field, gives the examples and stores the thank-you page by its ulid', function (): void {
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    expect(array_keys(fpFields($this->siteId)))->toBe(['name', 'phone', 'email', 'need', 'message', 'consent'])
        ->and(array_column(fpFields($this->siteId), 'sort_order'))->toBe([1, 2, 3, 4, 5, 6]);

    ContentPacks::in($this->siteId, function (): void {
        $form = Form::query()->where('key', 'contact')->firstOrFail();
        $need = $form->fields()->where('key', 'need')->firstOrFail();
        $page = Entry::query()->whereKey(SiteRoute::query()->where('path', '/gioi-thieu')->value('entry_id'))->firstOrFail();

        expect($need->type)->toBe('select')
            ->and($need->is_required)->toBeFalse()
            ->and($need->label)->toBe(['vi' => 'Bạn cần tư vấn về'])
            ->and($need->getAttribute('options'))->toEqual([
                'choices' => [['value' => 'jig', 'label' => ['vi' => 'Thiết kế Jig & Đồ gá']], ['value' => 'khuon-mau', 'label' => ['vi' => 'Khuôn mẫu']]],
                'placeholder' => ['vi' => '— Chọn nhu cầu —'],
            ])
            // the platform field keeps its type, label and its other languages' examples
            ->and($form->fields()->where('key', 'phone')->firstOrFail()->getAttribute('options')['placeholder'])->toMatchArray(['vi' => '0912 345 678', 'en' => __('forms.placeholder_phone', [], 'en')])
            ->and($form->fields()->where('key', 'phone')->value('type'))->toBe('tel')
            ->and($form->settings['redirect_entry'])->toBe($page->ulid)
            ->and(Ledger::read('demo-co')['items']['form:contact']['ref'])->toBe($form->ulid);
    });
});

it('is idempotent: a second dry run plans the form unchanged and a second apply writes nothing', function (): void {
    ContentPacks::load();

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'form:contact'))->toBe('unchanged');

    $writes = ContentPacks::writes(function () use ($output, &$exit): void {
        [$exit] = ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($output)]);
    });
    expect($exit)->toBe(0)->and($writes)->toBe([]);
});

it('keeps a staff edit of a field it manages, and overwrites it only when forced', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => FormField::query()->where('key', 'need')->update(['label' => json_encode(['vi' => 'Dịch vụ quan tâm'])]));

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'form:contact'))->toBe('skip:edited');

    [$exit, $output] = ContentPacks::load(force: ['form:contact']);
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::in($this->siteId, fn () => FormField::query()->where('key', 'need')->firstOrFail()->label))->toBe(['vi' => 'Bạn cần tư vấn về']);
});

it('refuses to leave a field the pack does not name after the consent box', function (): void {
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => Form::query()->where('key', 'contact')->firstOrFail()->fields()->create([
        'key' => 'company', 'type' => 'text', 'label' => ['vi' => 'Công ty'], 'sort_order' => 9,
    ]));

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(1)
        ->and($output)->toContain('the consent box (6) would no longer come last: company (9) would follow it');
});

it('leaves a field the pack does not name in its place above the consent box, and a staff field added after the load does not count as an edit', function (): void {
    fpField($this->folder, 'consent', fn (array $field): array => ['sort_order' => 10] + $field);
    ContentPacks::load();
    ContentPacks::in($this->siteId, fn () => Form::query()->where('key', 'contact')->firstOrFail()->fields()->create([
        'key' => 'company', 'type' => 'text', 'label' => ['vi' => 'Công ty'], 'sort_order' => 9,
    ]));

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'form:contact'))->toBe('unchanged');

    fpField($this->folder, 'need', fn (array $field): array => ['label' => ['vi' => 'Nhu cầu']] + $field);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(fpFields($this->siteId)['company']['sort_order'])->toBe(9)
        ->and(fpFields($this->siteId)['consent']['sort_order'])->toBe(10);
});

it('refuses a field the pack adds below a consent box it does not place', function (): void {
    fpChange($this->folder, fn (array $item): array => ['key' => 'form:contact', 'fields' => [
        ['key' => 'need', 'type' => 'text', 'label' => ['vi' => 'Nhu cầu'], 'sort_order' => 7],
    ]]);
    ContentPacks::in($this->siteId, fn () => Form::query()->where('key', 'contact')->firstOrFail()->update(['key' => 'bao-gia']));
    ContentPacks::edit($this->folder, 'forms.json', fn (array $data): array => ['items' => [['key' => 'form:bao-gia'] + $data['items'][0]]]);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(1)
        ->and($output)->toContain('the consent box (5) would no longer come last: need (7) would follow it');
});

it('plans a thank-you page that is neither in the pack nor on the site, or a form the site lacks, as a conflict', function (Closure $change, string $conflict): void {
    fpChange($this->folder, $change);

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(1)->and($output)->toContain($conflict);
})->with([
    'a missing page' => [fn (array $item): array => ['settings' => ['redirect_entry' => 'page:/khong-co']] + $item, 'the thank-you page page:/khong-co is neither in the pack nor on the site'],
    'a missing form' => [fn (array $item): array => ['key' => 'form:bao-gia', 'fields' => [['key' => 'need', 'type' => 'text', 'label' => ['vi' => 'Nhu cầu'], 'sort_order' => 1]]] + $item, "the site has no form 'bao-gia'"],
]);

it('plans a platform field the site lost as a conflict instead of inventing it', function (): void {
    ContentPacks::in($this->siteId, fn () => FormField::query()->where('key', 'phone')->delete());

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(1)->and($output)->toContain("the site's form has no platform field phone");
});

it('plans a consent box the site lost as a conflict, and an apply writes nothing', function (): void {
    ContentPacks::in($this->siteId, fn () => FormField::query()->where('key', 'consent')->delete());

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(1)
        ->and($output)->toContain("the site's form has no platform field consent (forms:sync-contact-fields restores it)");

    $writes = ContentPacks::writes(function () use ($output, &$exit): void {
        [$exit] = ContentPacks::run('demo-co', ['--apply' => true, '--plan' => ContentPacks::fingerprint($output)]);
    });
    expect($exit)->toBe(1)
        ->and($writes)->toBe([])
        ->and(fpFields($this->siteId))->not->toHaveKey('consent')->not->toHaveKey('need');
});

it('counts a field staff built differently before the first load as an edit, and takes it over only when forced, keeping its other options', function (): void {
    ContentPacks::in($this->siteId, fn () => Form::query()->where('key', 'contact')->firstOrFail()->fields()->create([
        'key' => 'need', 'type' => 'text', 'label' => ['vi' => 'Nhu cầu'], 'sort_order' => 4, 'options' => ['note' => 'x'],
    ]));

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'form:contact'))->toBe('skip:edited')
        ->and($output)->toContain('the site already has field need built by staff, unlike the pack');

    [$exit, $output] = ContentPacks::load(force: ['form:contact']);
    expect($exit)->toBe(0, $output);
    $need = ContentPacks::in($this->siteId, fn () => FormField::query()->where('key', 'need')->firstOrFail());
    expect($need->type)->toBe('select')->and($need->getAttribute('options')['note'])->toBe('x');
});

it('adopts on the first load a field staff already built as the pack writes it', function (): void {
    $need = collect(fpContactItem()['fields'])->firstWhere('key', 'need');
    ContentPacks::in($this->siteId, fn () => Form::query()->where('key', 'contact')->firstOrFail()->fields()->create([
        'key' => 'need', 'type' => 'select', 'label' => $need['label'], 'is_required' => false, 'sort_order' => 9,
        'options' => ['choices' => $need['choices'], 'placeholder' => $need['placeholder']],
    ]));

    [$exit, $output] = ContentPacks::run();
    expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'form:contact'))->toBe('adopt');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)->and(fpFields($this->siteId)['need']['sort_order'])->toBe(4);
});

it('drops the example of a field that becomes a checkbox', function (): void {
    ContentPacks::load();
    fpField($this->folder, 'need', fn (array $field): array => ['type' => 'checkbox'] + array_diff_key($field, ['choices' => true, 'placeholder' => true]));

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'form:contact'))->toBe('skip:edited'); // the field's state no longer names the example it had

    [$exit, $output] = ContentPacks::load(force: ['form:contact']);
    expect($exit)->toBe(0, $output);
    $need = ContentPacks::in($this->siteId, fn () => FormField::query()->where('key', 'need')->firstOrFail());
    expect($need->type)->toBe('checkbox')->and($need->getAttribute('options'))->toBe([]);
});

it('treats name, phone, email and message as the platform\'s only on the contact form', function (): void {
    ContentPacks::edit($this->folder, 'forms.json', fn (array $data): array => ['items' => [...$data['items'], [
        'key' => 'form:bao-gia',
        'fields' => [
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Người liên hệ'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'consent', 'sort_order' => 2],
        ],
    ]]]);

    expect(fpProblems())->toBe('');
});

it('backs up the form and its fields before it writes', function (): void {
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    preg_match('/Backup: (\S+)/', $output, $m);
    $backup = json_decode((string) gzdecode((string) file_get_contents($m[1])), true);
    $item = collect($backup['items'])->firstWhere('key', 'form:contact');

    expect($item['rows']['form']['key'])->toBe('contact')
        ->and(array_column($item['rows']['fields'], 'key'))->toContain('consent')->not->toContain('need');
});

it('lets a visitor pick a need: the answer is kept by its value, read by its label, and the send opens the thank-you page', function (): void {
    Notification::fake();
    Cache::flush();
    config(['services.turnstile.secret_key' => '']);
    ContentPacks::load();

    $this->post('http://'.ContentPacks::HOST.'/forms/contact', ['name' => 'A', 'phone' => '0912345678', 'message' => 'Cần báo giá', 'consent' => '1', 'need' => 'gia-cong', 'locale' => 'vi'])
        ->assertSessionHasErrors('need');

    $this->post('http://'.ContentPacks::HOST.'/forms/contact', ['name' => 'A', 'phone' => '0912345678', 'message' => 'Cần báo giá', 'consent' => '1', 'need' => 'khuon-mau', 'locale' => 'vi'])
        ->assertStatus(303)->assertRedirect('http://'.ContentPacks::HOST.'/gioi-thieu');

    ContentPacks::in($this->siteId, function (): void {
        $lead = FormSubmission::query()->sole();
        $form = Form::query()->with('fields')->where('key', 'contact')->firstOrFail();

        expect($lead->data['need'])->toBe('khuon-mau')
            ->and(collect(LeadAnswers::for($form, $lead->data, 'vi'))->firstWhere('key', 'need'))->toMatchArray(['label' => 'Bạn cần tư vấn về', 'value' => 'Khuôn mẫu']);
    });
});
