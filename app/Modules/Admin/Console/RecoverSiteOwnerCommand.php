<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Notifications\OwnerRecoveredNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\RecoverSiteOwner;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Operator tool: give a website whose owners are locked out (a lost mailbox, a person who left) an
 * owner again, until the platform console has a screen for it. Platform staff change no member on
 * the website's own screens; this is the one audited way round.
 *
 *   site:recover-owner av1.baoson.net new-owner@example.com --operator=staff@example.com --reason="Chủ cũ mất hộp thư, xác minh qua hợp đồng"
 *
 * The operator running it names their own platform account (--operator), which the audit log
 * records as the actor. The new owner must already have an account and must not be platform
 * staff. The owners already there keep their role. A written reason is required; the change is
 * audited, and every owner the website had and the new one get a notice by mail, sent only once
 * the change is committed. It asks before changing anything (--yes skips the question). An owner
 * who has no account yet cannot be given this way.
 */
final class RecoverSiteOwnerCommand extends Command
{
    #[\Override]
    protected $signature = 'site:recover-owner
        {host : Primary hostname of the website}
        {email : Account email of the person who becomes an owner}
        {--operator= : Your own platform staff account email (required — recorded as who made the change)}
        {--reason= : Why (required — it is written to the audit log and mailed to the owners)}
        {--yes : Do not ask before changing}';

    #[\Override]
    protected $description = 'Make an account an owner of a website whose owners are locked out (reason required, audited, owners told by mail).';

    public function handle(Tenancy $tenancy): int
    {
        $host = (string) $this->argument('host');
        $email = Str::lower(trim((string) $this->argument('email')));
        $reason = trim((string) $this->option('reason'));
        $operatorEmail = Str::lower(trim((string) $this->option('operator')));

        if ($operatorEmail === '') {
            $this->error('Name your own platform staff account with --operator=<email>: the audit log records who made the change.');

            return self::FAILURE;
        }

        if ($reason === '' || mb_strlen($reason) > RecoverSiteOwner::REASON_MAX) {
            $this->error('Write why with --reason="…" (at most '.RecoverSiteOwner::REASON_MAX.' characters): it goes to the audit log and to the owners.');

            return self::FAILURE;
        }

        $resolved = app(SiteResolver::class)->resolve($host);
        if ($resolved === null) {
            $this->error("No website found for host '{$host}'.");

            return self::FAILURE;
        }

        $operator = User::query()->select(['id', 'ulid', 'name', 'email'])->where('email', $operatorEmail)->first();
        if (! $operator instanceof User || ! app(PlatformAccess::class)->isOperator($operator)) {
            $this->error("'{$operatorEmail}' is not a platform staff account. Run it with your own staff account in --operator.");

            return self::FAILURE;
        }

        $newOwner = User::query()->select(['id', 'ulid', 'name', 'email'])->where('email', $email)->first();
        if (! $newOwner instanceof User) {
            $this->error("No account has the email '{$email}'. The new owner needs an account first.");

            return self::FAILURE;
        }

        // The change commits inside the website's tenant context; the notices are built there (they
        // carry the website) and sent only after it has committed, so a rolled-back change mails nobody.
        /** @var int|list<array{0: User, 1: OwnerRecoveredNotification}> $done */
        $done = $tenancy->enter($resolved->siteId, function () use ($resolved, $operator, $newOwner, $reason): int|array {
            $action = app(RecoverSiteOwner::class);
            $siteName = $this->siteName($resolved->siteId, $resolved->primaryHost);

            try {
                $before = $action->owners();
            } catch (RoleChangeRejectedException $e) {
                return $this->refused($e->reason);
            }

            $this->table(['', ''], [
                ['Website', $siteName.' ('.$resolved->primaryHost.')'],
                ['New owner', $newOwner->email],
                ['Owners now', $before->isEmpty() ? '—' : $before->pluck('email')->implode(', ')],
                ['Reason', $reason],
            ]);

            if (! $this->option('yes') && ! $this->confirm("Make {$newOwner->email} an owner of {$resolved->primaryHost}?", false)) {
                $this->warn('Nothing changed.');

                return self::FAILURE;
            }

            try {
                $owners = $action->handle($operator, $newOwner, $reason);
            } catch (RoleChangeRejectedException $e) {
                return $this->refused($e->reason);
            }

            $at = app(SiteTime::class)->now()->format('d/m/Y H:i');
            $locale = app(SiteLocale::class)->default();
            $notices = [];
            foreach ([...$owners, $newOwner] as $person) {
                $notices[] = [$person, (new OwnerRecoveredNotification($siteName, $newOwner->email, $reason, $at))
                    ->forCurrentSite()
                    ->locale($locale)];
            }

            return $notices;
        });

        if (is_int($done)) {
            return $done;
        }

        // The change and its audit row are committed: a notice that cannot be queued is reported and
        // named, and neither stops the others nor turns the run into a failure.
        $queued = 0;
        $missed = [];
        foreach ($done as [$person, $notice]) {
            try {
                $person->notify($notice);
                $queued++;
            } catch (\Throwable $e) {
                report($e);
                $missed[] = $person->email;
            }
        }

        $this->info("{$newOwner->email} is now an owner of {$resolved->primaryHost}. The change is in the audit log; a notice was queued to {$queued} address(es).");

        foreach ($missed as $email) {
            $this->warn("The notice to {$email} was not queued. Tell them yourself; the change itself is done.");
        }

        return self::SUCCESS;
    }

    private function refused(string $reason): int
    {
        $this->error(match ($reason) {
            'not_operator' => 'not_operator — the --operator account is not platform staff.',
            'operator' => 'operator — that account is platform staff; staff never become a website\'s owner this way.',
            'already_owner' => 'already_owner — that account is already an owner of this website. Nothing changed.',
            'no_owner_role' => 'no_owner_role — this website has no owner role to give (was it provisioned?).',
            default => 'reason_required — write why with --reason="…".',
        });

        return self::FAILURE;
    }

    private function siteName(int $siteId, string $host): string
    {
        $name = Site::query()->whereKey($siteId)->value('name');

        return is_string($name) && $name !== '' ? $name : $host;
    }
}
