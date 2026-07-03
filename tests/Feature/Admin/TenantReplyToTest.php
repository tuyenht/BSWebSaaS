<?php

use App\Modules\Admin\Listeners\SetTenantReplyTo;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;

/*
 * SetTenantReplyTo stamps the tenant's configured reply-to onto outgoing mail so a recipient's reply
 * reaches the CUSTOMER, not the shared platform inbox — read at send time (stateless, no Octane leak).
 */

beforeEach(function (): void {
    seedTenancy();
});

it('adds the tenant reply_to as the message Reply-To (replies reach the customer)', function (): void {
    $event = new MessageSending(new Email);

    app(Tenancy::class)->enter(1, function () use ($event): void {
        app(SettingsRepository::class)->put('email', 'config', ['reply_to' => 'lienhe@anvinh.com']);
        app(SetTenantReplyTo::class)->handle($event);
    });

    expect($event->message->getReplyTo())->toHaveCount(1)
        ->and($event->message->getReplyTo()[0]->getAddress())->toBe('lienhe@anvinh.com');
});

it('leaves Reply-To empty when the tenant has no reply_to configured', function (): void {
    $event = new MessageSending(new Email);

    app(Tenancy::class)->enter(1, fn () => app(SetTenantReplyTo::class)->handle($event));

    expect($event->message->getReplyTo())->toBe([]);
});

it('never overrides an explicit Reply-To already on the message', function (): void {
    $event = new MessageSending((new Email)->replyTo('explicit@example.com'));

    app(Tenancy::class)->enter(1, function () use ($event): void {
        app(SettingsRepository::class)->put('email', 'config', ['reply_to' => 'lienhe@anvinh.com']);
        app(SetTenantReplyTo::class)->handle($event);
    });

    expect($event->message->getReplyTo()[0]->getAddress())->toBe('explicit@example.com');
});
