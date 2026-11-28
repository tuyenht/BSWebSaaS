<?php

use App\Modules\Admin\Support\Mail\MailDnsProviders;
use App\Modules\Admin\Support\Mail\MailDnsRecords;
use Tests\Fixtures\FakeDns;

/*
 * Reading a sending domain's SPF, DKIM and DMARC records: what each service asks for (its own
 * documentation, MailDnsProviders), what counts as found, and "could not check" rather than a guess
 * where the service names nothing fixed. Public DNS is the fake: nothing leaves the test.
 */

function mdrCheck(FakeDns $dns, string $domain, string $provider): array
{
    return (new MailDnsRecords($dns))->check($domain, $provider);
}

it('passes SPF when the record at the domain includes the service', function (string $record): void {
    $dns = new FakeDns;
    $dns->txt['congty.vn'] = ['google-site-verification=abc', $record];

    expect(mdrCheck($dns, 'congty.vn', 'mailgun')['spf'])->toBe(['code' => 'ok', 'host' => 'congty.vn']);
})->with([
    'plain' => ['v=spf1 include:mailgun.org ~all'],
    'with others and a qualifier' => ['v=spf1 ip4:203.0.113.5 +include:_spf.google.com include:MAILGUN.ORG. -all'],
    'through redirect' => ['V=SPF1 redirect=mailgun.org'],
]);

it('says the SPF record is missing, or does not allow the service, or is doubled', function (array $records, string $code): void {
    $dns = new FakeDns;
    $dns->txt['congty.vn'] = $records;

    expect(mdrCheck($dns, 'congty.vn', 'mailgun')['spf'])->toBe(['code' => $code, 'host' => 'congty.vn']);
})->with([
    'no record' => [['google-site-verification=abc'], 'missing'],
    'a look-alike first term' => [['v=spf10 include:mailgun.org ~all'], 'missing'],
    'another service only' => [['v=spf1 include:_spf.google.com ~all'], 'not_allowed'],
    'a longer name that ends the same' => [['v=spf1 include:notmailgun.org ~all'], 'not_allowed'],
    'two records' => [['v=spf1 include:mailgun.org ~all', 'v=spf1 include:_spf.google.com ~all'], 'multiple'],
]);

it('looks for the SPF record where the service puts it', function (): void {
    $dns = new FakeDns;
    $dns->txt['send.congty.vn'] = ['v=spf1 include:amazonses.com ~all'];

    expect(mdrCheck($dns, 'congty.vn', 'resend')['spf'])->toBe(['code' => 'ok', 'host' => 'send.congty.vn']);

    $dns->txt = ['congty.vn' => ['v=spf1 include:amazonses.com ~all']];
    expect(mdrCheck($dns, 'congty.vn', 'resend')['spf'])->toBe(['code' => 'missing', 'host' => 'send.congty.vn']);
});

it('passes SPF for a service that proves it itself when the record is there, and cannot check it otherwise', function (): void {
    $dns = new FakeDns;
    expect(mdrCheck($dns, 'congty.vn', 'sendgrid')['spf'])->toBe(['code' => 'unchecked', 'why' => 'provider_managed', 'host' => 'congty.vn'])
        ->and(mdrCheck($dns, 'congty.vn', 'brevo')['spf'])->toBe(['code' => 'unchecked', 'why' => 'provider_managed']);

    $dns->txt['congty.vn'] = ['v=spf1 include:sendgrid.net ~all'];
    $dns->txt['pm-bounces.congty.vn'] = ['v=spf1 a mx ip4:50.31.156.96/27 -all'];
    expect(mdrCheck($dns, 'congty.vn', 'sendgrid')['spf']['code'])->toBe('ok')
        ->and(mdrCheck($dns, 'congty.vn', 'postmark')['spf'])->toBe(['code' => 'ok', 'host' => 'pm-bounces.congty.vn']);
});

it('passes DKIM when one of the service selectors publishes a key', function (string $provider, string $host): void {
    $dns = new FakeDns;
    $dns->txt[$host] = ['v=DKIM1; k=rsa; p=MIIBIjANBgkqh'];

    expect(mdrCheck($dns, 'congty.vn', $provider)['dkim'])->toBe(['code' => 'ok', 'host' => $host]);
})->with([
    'SendGrid s2' => ['sendgrid', 's2._domainkey.congty.vn'],
    'Brevo' => ['brevo', 'brevo1._domainkey.congty.vn'],
    'Resend' => ['resend', 'resend._domainkey.congty.vn'],
    'Mailtrap' => ['mailtrap', 'rwmt1._domainkey.congty.vn'],
    'Google Workspace' => ['google', 'google._domainkey.congty.vn'],
    'Microsoft 365' => ['microsoft', 'selector1._domainkey.congty.vn'],
]);

it('says the DKIM key is missing, a revoked or unrelated record included', function (array $records): void {
    $dns = new FakeDns;
    $dns->txt['s1._domainkey.congty.vn'] = $records;

    expect(mdrCheck($dns, 'congty.vn', 'sendgrid')['dkim'])->toBe(['code' => 'missing', 'host' => 's1._domainkey.congty.vn']);
})->with([
    'nothing' => [[]],
    'revoked key' => [['v=DKIM1; p=']],
    'no key tag' => [['v=DKIM1; k=rsa']],
]);

it('cannot check DKIM when the service names its key per domain, or the server is unknown', function (string $provider, string $why): void {
    $dns = new FakeDns;
    $dns->txt['s1._domainkey.congty.vn'] = ['v=DKIM1; p=MIIB'];

    expect(mdrCheck($dns, 'congty.vn', $provider)['dkim'])->toBe(['code' => 'unchecked', 'why' => $why]);
})->with([
    'Mailgun' => ['mailgun', 'unknown_selector'],
    'Postmark' => ['postmark', 'unknown_selector'],
    'Amazon SES' => ['ses', 'unknown_selector'],
    'another mail server' => ['smtp', 'unknown_server'],
]);

it('passes DMARC at the domain or a parent, and says when it is missing or doubled', function (): void {
    $dns = new FakeDns;
    expect(mdrCheck($dns, 'mg.congty.vn', 'mailgun')['dmarc'])->toBe(['code' => 'missing', 'host' => '_dmarc.mg.congty.vn']);

    $dns->txt['_dmarc.congty.vn'] = ['v=DMARC1; p=none; rua=mailto:dmarc@congty.vn'];
    expect(mdrCheck($dns, 'mg.congty.vn', 'mailgun')['dmarc'])->toBe(['code' => 'ok', 'host' => '_dmarc.congty.vn'])
        ->and(mdrCheck($dns, 'congty.vn', 'mailgun')['dmarc'])->toBe(['code' => 'ok', 'host' => '_dmarc.congty.vn']);

    $dns->txt['_dmarc.congty.vn'] = ['v=DMARC1; p=none', 'v=DMARC1; p=reject'];
    expect(mdrCheck($dns, 'congty.vn', 'mailgun')['dmarc'])->toBe(['code' => 'multiple', 'host' => '_dmarc.congty.vn']);

    $dns->txt['_dmarc.congty.vn'] = ['v=DMARC2; p=none'];
    expect(mdrCheck($dns, 'congty.vn', 'mailgun')['dmarc']['code'])->toBe('missing');
});

it('takes a subdomain\'s DMARC policy from its registered domain, however deep, and never from a public suffix', function (): void {
    $dns = new FakeDns;
    $dns->txt['_dmarc.congty.vn'] = ['v=DMARC1; p=none'];
    $dns->txt['_dmarc.com.vn'] = ['v=DMARC1; p=none'];

    expect(mdrCheck($dns, 'a.b.c.congty.vn', 'mailgun')['dmarc'])->toBe(['code' => 'ok', 'host' => '_dmarc.congty.vn'])
        ->and(mdrCheck($dns, 'mail.congty.com.vn', 'mailgun')['dmarc'])->toBe(['code' => 'missing', 'host' => '_dmarc.mail.congty.com.vn'])
        ->and(mdrCheck($dns, 'congty.com.vn', 'mailgun')['dmarc']['code'])->toBe('missing')
        ->and(MailDnsRecords::organisational('mail.congty.com.vn'))->toBe('congty.com.vn')
        ->and(MailDnsRecords::organisational('x.y.congty.vn'))->toBe('congty.vn')
        ->and(MailDnsRecords::organisational('congty.vn'))->toBe('congty.vn')
        ->and(MailDnsRecords::organisational('mail.congty.id.vn'))->toBe('congty.id.vn')
        ->and(MailDnsRecords::organisational('mail.congty.co.za'))->toBe('congty.co.za')
        ->and(MailDnsRecords::organisational('mail.congty.com'))->toBe('congty.com')
        // a known limit, kept on the safe side: a ccTLD with no second level keeps one label too many
        ->and(MailDnsRecords::organisational('mail.info.de'))->toBe('mail.info.de');
});

it('looks nothing up for a public mailbox: its provider publishes the records', function (): void {
    $dns = new FakeDns;
    $row = ['code' => 'unchecked', 'why' => 'public_mailbox'];

    expect(mdrCheck($dns, 'gmail.com', 'google'))->toBe(['spf' => $row, 'dkim' => $row, 'dmarc' => $row])
        ->and($dns->lookups)->toBe(0);
});

it('takes only a domain name as the domain to look up, and names the service of a known server', function (): void {
    expect(MailDnsProviders::domain(' CongTy.VN '))->toBe('congty.vn')
        ->and(MailDnsProviders::domain('congty.vn.'))->toBe('')
        ->and(MailDnsProviders::domain('203.0.113.5'))->toBe('')
        ->and(MailDnsProviders::domain('-congty.vn'))->toBe('')
        ->and(MailDnsProviders::domain('cong_ty.vn'))->toBe('')
        ->and(MailDnsProviders::domain("congty.vn\n_dmarc.evil.vn"))->toBe('')
        ->and(MailDnsProviders::domain(str_repeat('a', 64).'.vn'))->toBe('')
        ->and(MailDnsProviders::of('smtp', 'SMTP.gmail.com'))->toBe('google')
        ->and(MailDnsProviders::of('smtp', 'smtp.office365.com'))->toBe('microsoft')
        ->and(MailDnsProviders::of('smtp', 'mail.congty.vn'))->toBe('smtp')
        ->and(MailDnsProviders::of('nope'))->toBe('smtp')
        ->and(MailDnsProviders::domainOf(['relay' => 'smtp', 'smtp' => ['username' => 'ban@CongTy.vn']]))->toBe('congty.vn')
        ->and(MailDnsProviders::domainOf(['relay' => 'smtp', 'smtp' => ['username' => 'login'], 'from_address' => 'no-reply@congty.vn']))->toBe('congty.vn')
        ->and(MailDnsProviders::domainOf(['relay' => 'mailgun', 'send_domain' => 'mg.congty.vn']))->toBe('mg.congty.vn');
});
