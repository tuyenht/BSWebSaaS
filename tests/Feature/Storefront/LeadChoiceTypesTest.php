<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * «Số», «Ngày», «Chọn một» and «Chọn nhiều» on a website: the form block draws each with the
 * browser's own control (no script) in the shared field styles, the server checks every answer by
 * its type before the lead is kept, and a failed send keeps what was picked. A choice is sent by its
 * stable value and read by its label in the visitor's language.
 */

beforeEach(function (): void {
    seedTenancy();
    Notification::fake();
    Cache::flush(); // the per-IP send limit
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    config(['services.turnstile.secret_key' => '', 'services.turnstile.site_key' => '']);
});

const CT_SLOTS = ['choices' => [
    ['value' => 'sang', 'label' => ['vi' => 'Buổi sáng', 'en' => 'Morning']],
    ['value' => 'chieu', 'label' => ['vi' => 'Buổi chiều']],
    ['value' => 'toi', 'label' => ['vi' => 'Buổi tối']],
]];

/**
 * The booking form of site 1 (name, quantity, day, one slot, several services) on /dat-hen.
 *
 * @param  array<string, array<string, mixed>>  $change  attributes to change per field key
 */
function ctBookingPage(int $site = 1, array $change = []): void
{
    app(Tenancy::class)->enter($site, function () use ($change): void {
        $fields = [
            'name' => ['type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true],
            'qty' => ['type' => 'number', 'label' => ['vi' => 'Số lượng'], 'is_required' => true],
            'day' => ['type' => 'date', 'label' => ['vi' => 'Ngày hẹn'], 'is_required' => true],
            'slot' => ['type' => 'radio', 'label' => ['vi' => 'Khung giờ'], 'is_required' => true, 'options' => CT_SLOTS],
            'need' => ['type' => 'checkboxes', 'label' => ['vi' => 'Dịch vụ'], 'is_required' => false, 'options' => CT_SLOTS, 'validation' => ['max_choices' => 2]],
        ];
        $rows = [];
        foreach ($fields as $key => $field) {
            $rows[] = ['key' => $key, 'sort_order' => count($rows) + 1, ...$field, ...($change[$key] ?? [])];
        }
        Form::query()->create(['key' => 'dat-hen', 'name' => ['vi' => 'Đặt hẹn tư vấn'], 'status' => 'active'])->fields()->createMany($rows);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Đặt hẹn', 'slug' => 'dat-hen', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'form', 'props' => ['form_key' => 'dat-hen']]]]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/dat-hen', 'entry_id' => $entry->id]);
    });
}

/** @return array<string, mixed> a booking every field accepts */
function ctGoodBooking(array $change = []): array
{
    return ['name' => 'Khách A', 'qty' => '12.5', 'day' => '2026-12-01', 'slot' => 'chieu', 'need' => ['sang', 'toi'], 'locale' => 'vi', ...$change];
}

function ctSection(string $html): string
{
    $start = strpos($html, '<section class="lead-form');
    expect($start)->not->toBeFalse();

    return substr($html, $start, strpos($html, '</section>', $start) - $start);
}

function ctLead(): ?FormSubmission
{
    return app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->first());
}

it('keeps a booking whose every answer fits its type: the number and day as sent, the picks by their values', function (): void {
    ctBookingPage();

    $this->post('http://a.test/forms/dat-hen', ctGoodBooking())->assertSessionHasNoErrors();

    expect(ctLead()?->data)->toMatchArray(['name' => 'Khách A', 'qty' => '12.5', 'day' => '2026-12-01', 'slot' => 'chieu', 'need' => ['sang', 'toi']]);
});

it('refuses an answer that does not fit its type, saying what is wrong, and keeps no lead', function (array $change, string $key, string $message): void {
    ctBookingPage();

    $this->post('http://a.test/forms/dat-hen', ctGoodBooking($change))->assertSessionHasErrors([$key => $message]);

    expect(ctLead())->toBeNull();
})->with([
    'a number with an exponent' => [['qty' => '1e5'], 'qty', 'Vui lòng nhập một số (tối đa 15 chữ số).'],
    'words as a number' => [['qty' => 'mười'], 'qty', 'Vui lòng nhập một số (tối đa 15 chữ số).'],
    'a month that does not exist' => [['day' => '2026-13-01'], 'day', 'Ngày chưa đúng định dạng.'],
    'a day written another way' => [['day' => '01/12/2026'], 'day', 'Ngày chưa đúng định dạng.'],
    'a slot the form does not offer' => [['slot' => 'dem'], 'slot', 'Lựa chọn không hợp lệ, vui lòng chọn lại.'],
    'two slots for one choice' => [['slot' => ['sang', 'chieu']], 'slot', 'Lựa chọn không hợp lệ, vui lòng chọn lại.'],
    'no slot' => [['slot' => ''], 'slot', 'Vui lòng chọn Khung giờ.'],
    'no day' => [['day' => ''], 'day', 'Vui lòng điền Ngày hẹn.'],
    'more picks than the form allows' => [['need' => ['sang', 'chieu', 'toi']], 'need', 'Bạn chỉ được chọn tối đa 2 mục.'],
    'one pick twice' => [['need' => ['sang', 'sang']], 'need.1', 'Lựa chọn không hợp lệ, vui lòng chọn lại.'],
    'a pick the form does not offer' => [['need' => ['dem']], 'need.0', 'Lựa chọn không hợp lệ, vui lòng chọn lại.'],
    'one word for several picks' => [['need' => 'sang'], 'need', 'Lựa chọn không hợp lệ, vui lòng chọn lại.'],
]);

it('asks for at least one pick only when «Chọn nhiều» is required', function (): void {
    ctBookingPage(change: ['need' => ['is_required' => true]]);

    $this->post('http://a.test/forms/dat-hen', ctGoodBooking(['need' => []]))->assertSessionHasErrors(['need' => 'Vui lòng chọn Dịch vụ.']);
    expect(ctLead())->toBeNull();

    $this->post('http://a.test/forms/dat-hen', ctGoodBooking(['need' => ['chieu']]))->assertSessionHasNoErrors();
    expect(ctLead()?->data['need'])->toBe(['chieu']);
});

it('draws a number and a day with the browser\'s own boxes, each tied to its label', function (): void {
    ctBookingPage();

    $section = ctSection($this->get('http://a.test/dat-hen')->assertOk()->getContent());

    expect($section)
        ->toMatch('/<label for="lf-dat_hen__qty">\s*Số lượng <span class="lead-form__required" aria-hidden="true">\*<\/span>\s*<\/label>\s*<input id="lf-dat_hen__qty" type="text" inputmode="decimal" name="qty"\s+value=""\s+required/')
        ->toMatch('/<label for="lf-dat_hen__day">\s*Ngày hẹn <span class="lead-form__required" aria-hidden="true">\*<\/span>\s*<\/label>\s*<input id="lf-dat_hen__day" type="date" name="day"\s+value=""\s+required/');
});

it('draws one choice as a group of round boxes and several choices as tick boxes, under the field\'s label', function (): void {
    ctBookingPage();

    $section = ctSection($this->get('http://a.test/dat-hen')->assertOk()->getContent());

    expect($section)
        ->toMatch('/<fieldset class="lead-form__field lead-form__field--choices" id="lf-dat_hen__slot">\s*<legend>\s*Khung giờ <span class="lead-form__required" aria-hidden="true">\*<\/span><span class="sr-only"> \(bắt buộc\)<\/span>\s*<\/legend>/')
        ->toMatch('/<label class="lead-form__check" for="lf-dat_hen__slot--o1">\s*<input id="lf-dat_hen__slot--o1" type="radio" name="slot" value="sang"\s+required\s*>\s*<span>Buổi sáng<\/span>\s*<\/label>/')
        ->toMatch('/<label class="lead-form__check" for="lf-dat_hen__slot--o3">\s*<input id="lf-dat_hen__slot--o3" type="radio" name="slot" value="toi"\s+required\s*>\s*<span>Buổi tối<\/span>/')
        ->toMatch('/<fieldset class="lead-form__field lead-form__field--choices" id="lf-dat_hen__need">\s*<legend>\s*Dịch vụ\s*<\/legend>/')
        ->toMatch('/<label class="lead-form__check" for="lf-dat_hen__need--o2">\s*<input id="lf-dat_hen__need--o2" type="checkbox" name="need\[\]" value="chieu"\s*>\s*<span>Buổi chiều<\/span>/')
        // a tick box of a group is never required one by one: the server asks for one pick
        ->not->toMatch('/name="need\[\]"[^>]*required/');
});

it('keeps what was picked after a failed send and points the first bad group at its message', function (): void {
    ctBookingPage();

    $this->from('http://a.test/dat-hen')->post('http://a.test/forms/dat-hen', ctGoodBooking(['name' => '', 'slot' => 'toi', 'need' => ['chieu'], 'qty' => '7', 'day' => '2026-12-24']))
        ->assertRedirect('http://a.test/dat-hen');
    $kept = ctSection($this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/dat-hen')->getContent());

    expect($kept)
        ->toMatch('/name="qty"\s+value="7"/')
        ->toMatch('/name="day"\s+value="2026-12-24"/')
        ->toMatch('/value="toi"\s+checked/')
        ->not->toMatch('/value="sang"\s+checked/')
        ->toMatch('/name="need\[\]" value="chieu"\s+checked/');

    $this->from('http://a.test/dat-hen')->post('http://a.test/forms/dat-hen', ctGoodBooking(['slot' => '']));
    $bad = ctSection($this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/dat-hen')->getContent());

    expect($bad)
        ->toMatch('/<input id="lf-dat_hen__slot--o1" type="radio" name="slot" value="sang"\s+required\s+aria-invalid="true"\s+autofocus\s*>/')
        ->toMatch('/<input id="lf-dat_hen__slot--o2" type="radio" name="slot" value="chieu"\s+required\s+aria-invalid="true"\s*>/')
        ->toContain('<p class="lead-form__field-error" id="lf-dat_hen__slot--error">Vui lòng chọn Khung giờ.</p>')
        ->toContain('href="#lf-dat_hen__slot"')
        // the group's message is tied to the fieldset only: a box carrying it too is announced twice
        ->not->toMatch('/<input[^>]*aria-describedby/');

    // a pick the form does not offer fails on that pick (need.0): the group still shows the message
    $this->from('http://a.test/dat-hen')->post('http://a.test/forms/dat-hen', ctGoodBooking(['need' => ['dem']]));
    $pick = ctSection($this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/dat-hen')->getContent());

    expect($pick)
        ->toMatch('/<input id="lf-dat_hen__need--o1" type="checkbox" name="need\[\]" value="sang"\s+aria-invalid="true"\s+autofocus\s*>/')
        ->toContain('<p class="lead-form__field-error" id="lf-dat_hen__need--error">Lựa chọn không hợp lệ, vui lòng chọn lại.</p>');
});

it('neither shows nor asks for a choice field that has no choices', function (): void {
    ctBookingPage(change: ['slot' => ['options' => []]]);

    $section = ctSection($this->get('http://a.test/dat-hen')->assertOk()->getContent());
    expect($section)->not->toContain('name="slot"');

    $this->post('http://a.test/forms/dat-hen', ctGoodBooking(['slot' => null]))->assertSessionHasNoErrors();
    expect(ctLead()?->data)->not->toHaveKey('slot');
});

it('never takes a choice another website\'s form offers', function (): void {
    ctBookingPage(1);
    ctBookingPage(2, ['slot' => ['options' => ['choices' => [['value' => 'dem', 'label' => ['vi' => 'Ban đêm']]]]]]);

    $this->post('http://a.test/forms/dat-hen', ctGoodBooking(['slot' => 'dem']))->assertSessionHasErrors(['slot']);

    expect(ctLead())->toBeNull();
});

it('names a crafted pick of «Chọn nhiều» by the field\'s label, never by its raw key', function (array $need): void {
    ctBookingPage();

    $this->post('http://a.test/forms/dat-hen', ctGoodBooking(['need' => $need]))->assertSessionHasErrors('need.0');

    // every message the pick gets is plain language: none shows the raw key
    $messages = session('errors')->get('need.0');
    expect($messages)->not->toBeEmpty()
        ->and(implode(' ', $messages))->not->toContain('Need')->not->toContain('need');

    expect(ctLead())->toBeNull();
})->with([
    'an empty pick' => [['']],
    'a list inside a pick' => [[['x']]],
]);

it('keeps a number the browser could not read and says what is wrong, instead of dropping it', function (): void {
    ctBookingPage();
    $this->from('http://a.test/dat-hen')->post('http://a.test/forms/dat-hen', ctGoodBooking(['qty' => '1e']))->assertRedirect('http://a.test/dat-hen');

    $html = ctSection($this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/dat-hen')->getContent());

    expect($html)->toMatch('/<input id="lf-dat_hen__qty" type="text" inputmode="decimal" name="qty"\s+value="1e"/')
        ->toContain('Vui lòng nhập một số (tối đa 15 chữ số).');
    expect(ctLead())->toBeNull();
});

it('tells a screen reader that a group of tick boxes is required, and ties the group to its message', function (): void {
    ctBookingPage(change: ['need' => ['is_required' => true]]);

    $ok = ctSection($this->get('http://a.test/dat-hen')->assertOk()->getContent());
    expect($ok)->toMatch('/Dịch vụ <span class="lead-form__required" aria-hidden="true">\*<\/span><span class="sr-only"> \(bắt buộc\)<\/span>\s*<\/legend>/');

    $this->from('http://a.test/dat-hen')->post('http://a.test/forms/dat-hen', ctGoodBooking(['need' => []]));
    $bad = ctSection($this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/dat-hen')->getContent());
    expect($bad)->toContain('<fieldset class="lead-form__field lead-form__field--choices" id="lf-dat_hen__need" aria-describedby="lf-dat_hen__need--error">');
});

it('reads a number typed with a decimal comma, keeps it with a point, and refuses a mix of the two marks', function (string $typed, ?string $stored): void {
    ctBookingPage();

    $response = $this->post('http://a.test/forms/dat-hen', ctGoodBooking(['qty' => $typed]));

    if ($stored === null) {
        $response->assertSessionHasErrors('qty');
        expect(ctLead())->toBeNull();
    } else {
        $response->assertSessionHasNoErrors();
        expect(ctLead()?->data['qty'])->toBe($stored);
    }
})->with([
    // one lone comma with digits both sides and no point is the decimal mark; nothing else is guessed at
    'a decimal comma' => ['1,5', '1.5'],
    'a decimal point' => ['1.5', '1.5'],
    'a negative' => ['-2', '-2'],
    'a negative decimal comma' => ['-2,25', '-2.25'],
    'a thousands comma with a point' => ['1,234.5', null],
    'two commas' => ['1,234,5', null],
    'an unfinished exponent' => ['1e', null],
    'a comma with no digits after it' => ['1,', null],
]);

it('shows a number back as it was typed when it is refused', function (): void {
    ctBookingPage();
    $this->from('http://a.test/dat-hen')->post('http://a.test/forms/dat-hen', ctGoodBooking(['qty' => '1,234.5']));

    $html = ctSection($this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/dat-hen')->getContent());

    expect($html)->toMatch('/name="qty"\s+value="1,234\.5"/');
});

it('draws the page again, whatever list a crafted post put under a field', function (array $change): void {
    ctBookingPage(change: ['need' => ['is_required' => true]]);

    $this->from('http://a.test/dat-hen')->post('http://a.test/forms/dat-hen', ctGoodBooking($change))->assertRedirect('http://a.test/dat-hen');

    $this->withCookie((string) config('session.cookie'), 'visitor')->get('http://a.test/dat-hen')->assertOk();
})->with([
    'a list inside a pick' => [['need' => [['x']]]],
    'a list for a number' => [['qty' => ['1']]],
    'a list for a day' => [['day' => ['x']]],
    'a nested list for a round group' => [['slot' => [['x']]]],
    'a list for a text' => [['name' => ['x']]],
]);
