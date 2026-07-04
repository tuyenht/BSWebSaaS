<?php

use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Jobs\WithinSite;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/*
 * Batch 2a — tenant auth mail is queued (ShouldQueue) so login/verify/reset never block on SMTP.
 * The worker holds none of the request's state, so each queued mail must (1) re-enter its site's
 * isolation via WithinSite job middleware and (2) re-apply TenantMailer before building the message.
 */

beforeEach(function (): void {
    // Leftover encrypted SMTP config from another test would DecryptException under this key.
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
});

it('queues the OTP notification and binds it to the sending site', function (): void {
    seedTenancy();

    $note = app(Tenancy::class)->enter(1, fn () => (new EmailOtpNotification('123456', 10))->forCurrentSite());

    expect($note)->toBeInstanceOf(ShouldQueue::class)
        ->and($note->tenantSiteId)->toBe(1)
        ->and($note->middleware())->toHaveCount(1)
        ->and($note->middleware()[0])->toBeInstanceOf(WithinSite::class);
});

it('adds no tenant middleware on the platform plane (no resolved site)', function (): void {
    $note = (new EmailOtpNotification('123456', 10))->forCurrentSite(); // dispatched without a site

    expect($note->tenantSiteId)->toBeNull()
        ->and($note->middleware())->toBe([]);
});

it('re-enters the site and re-applies the mailer when the queued OTP is processed', function (): void {
    seedTenancy();
    config(['queue.default' => 'sync', 'mail.default' => 'array']);

    // Record the site that is live at the moment the mailer is (re-)applied inside the worker.
    $probe = new stdClass;
    $probe->site = 'unset';
    app()->instance(TenantMailer::class, new class($probe)
    {
        public function __construct(private object $probe) {}

        public function apply(): bool
        {
            $current = app(CurrentSite::class);
            $this->probe->site = $current->hasId() ? $current->id() : null;

            return true;
        }
    });

    $user = User::factory()->create();

    // Dispatch bound to site 1 but from NO ambient context, then let the sync queue run it through
    // the real job-middleware pipeline. Seeing site 1 at apply() time proves WithinSite restored
    // isolation for the queued notification (i.e. the framework honoured its middleware()).
    expect(app(CurrentSite::class)->hasId())->toBeFalse();

    $note = new EmailOtpNotification('123456', 10);
    $note->tenantSiteId = 1;
    $user->notify($note);

    expect($probe->site)->toBe(1);
});
