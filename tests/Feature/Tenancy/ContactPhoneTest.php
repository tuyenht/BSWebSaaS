<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\ContactPhone;
use App\Modules\Tenancy\Support\ContactPhoneRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/*
 * A customer's contact phone (tenants.contact_phone): staff type it however they dial it, it is
 * stored in one international form (+84901234567) so a search by digits and a tel: link work the
 * same for every customer, and staff read it back in the national form ("0901 234 567").
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate tenant_probes, domains, sites, tenants restart identity cascade');
});

it('stores every way a Vietnamese number is typed in one form', function (string $typed, string $stored): void {
    expect(ContactPhone::normalize($typed))->toBe($stored);
})->with([
    'spaces' => ['0901 234 567', '+84901234567'],
    'dots and dashes' => ['090.123-4567', '+84901234567'],
    'country code' => ['+84 90 123 4567', '+84901234567'],
    'bracketed trunk 0' => ['+84 (0)90 123 4567', '+84901234567'],
    'trunk 0 after the code' => ['+84 0901 234 567', '+84901234567'],
    '00 prefix' => ['0084 901234567', '+84901234567'],
    'landline' => ['(028) 3812 3456', '+842838123456'],
    'surrounding spaces' => ['  0901234567 ', '+84901234567'],
    'another country keeps its code' => ['+1 (415) 555-0132', '+14155550132'],
]);

it('refuses what is not a phone number', function (string $typed): void {
    expect(ContactPhone::normalize($typed))->toBeNull();
})->with([
    'empty' => [''],
    'letters' => ['0901 234 abc'],
    'no trunk 0 or country' => ['901234567'],
    'too short for Vietnam' => ['090 123 456'],
    'too long for Vietnam' => ['0901 234 5678 9'],
    'two plus signs' => ['+84+901234567'],
    'plus in the middle' => ['0901+234567'],
    'extension text' => ['0901234567 ext 12'],
    'too long overall' => ['+1234567890123456'],
]);

it('reads a home number in its national form and any other as stored', function (): void {
    expect(ContactPhone::display('+84901234567'))->toBe('0901 234 567')
        ->and(ContactPhone::display('+842838123456'))->toBe('028 3812 3456')
        ->and(ContactPhone::display('+14155550132'))->toBe('+14155550132');
});

it('searches by digits, a leading 0 meaning the home country, from three digits', function (): void {
    expect(ContactPhone::searchDigits('0901'))->toBe('84901')
        ->and(ContactPhone::searchDigits('234 567'))->toBe('234567')
        ->and(ContactPhone::searchDigits('+84 90'))->toBe('8490')
        ->and(ContactPhone::searchDigits('0084 9'))->toBe('849')
        ->and(ContactPhone::searchDigits('09'))->toBeNull()
        ->and(ContactPhone::searchDigits('000'))->toBeNull()
        ->and(ContactPhone::searchDigits('Công ty'))->toBeNull();
});

it('takes the home country from config, so another market changes no code', function (): void {
    config(['customers.phone_country_code' => '66']);

    expect(ContactPhone::normalize('081 234 5678'))->toBe('+66812345678')
        ->and(ContactPhone::display('+66812345678'))->toBe('0812 345 678')
        ->and(ContactPhone::display('+84901234567'))->toBe('+84901234567')
        ->and(ContactPhone::searchDigits('081'))->toBe('6681');
});

it('gives staff a plain message when the number cannot be used, and allows none', function (): void {
    $rules = ['phone' => ['nullable', new ContactPhoneRule]];

    expect(Validator::make(['phone' => '0901 234 567'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['phone' => null], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['phone' => ['0901234567']], $rules)->passes())->toBeFalse();

    $failed = Validator::make(['phone' => '12'], $rules);
    expect($failed->fails())->toBeTrue()
        ->and($failed->errors()->first('phone'))->toBe(__('customers.phone_invalid'))
        ->and(__('customers.phone_invalid'))->not->toBe('customers.phone_invalid');
});

it('saves what staff typed in the stored form, and a blank as none', function (): void {
    $tenant = Tenant::query()->create(['name' => 'An Vinh', 'contact_phone' => '0901 234 567']);

    expect($tenant->fresh()?->contact_phone)->toBe('+84901234567');

    $tenant->update(['contact_phone' => '  ']);
    expect($tenant->fresh()?->contact_phone)->toBeNull();
});

it('lets the database refuse any other shape, whichever code writes', function (string $raw): void {
    expect(fn () => DB::connection('pgsql_admin')->table('tenants')->insert([
        'ulid' => (string) Str::ulid(), 'name' => 'Raw', 'settings' => '{}', 'contact_phone' => $raw,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'tenants_contact_phone_e164_check');
})->with(['national form' => ['0901234567'], 'spaces' => ['+84 901 234 567'], 'too short' => ['+8490']]);

it('refuses a typed number that is not a phone through the model too', function (): void {
    expect(fn () => Tenant::query()->create(['name' => 'Bad', 'contact_phone' => 'call me']))
        ->toThrow(QueryException::class, 'tenants_contact_phone_e164_check');
});
