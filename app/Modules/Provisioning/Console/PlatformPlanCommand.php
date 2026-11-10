<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\ChangeCustomerPlan;
use App\Modules\Tenancy\Actions\SavePlan;
use App\Modules\Tenancy\Actions\SetPlanFeatures;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator tool for the plan catalog until the platform console has a screen for it:
 *
 *   platform:plan                                   list the plans: sold or not, customers, what each grants
 *   platform:plan --create="Pro"                    add a plan (its key is printed)
 *   platform:plan pro locales.max 3                 the plan grants this limit (on / off for a switch)
 *   platform:plan pro locales.max --clear           the plan no longer grants it: the default applies
 *   platform:plan pro --retire                      stop selling it; its customers keep it (--sell puts it back)
 *   platform:plan pro --assign-to=av1.baoson.net --reason="Ký hợp đồng"
 *                                                   put the customer who owns that website on the plan
 *   platform:plan --invitation=on                   websites see features outside their plan as locked, with a way
 *                                                   to see the plans (off, the default: hidden or a neutral lock)
 *
 * Every change goes through the same actions as the console screen will, and is audited.
 */
final class PlatformPlanCommand extends Command
{
    #[\Override]
    protected $signature = 'platform:plan
        {plan? : Key of the plan}
        {feature? : Feature key, e.g. locales.max}
        {value? : on / off for a switch, a whole number for a limit}
        {--create= : Add a plan with this name}
        {--clear : The plan no longer grants the feature}
        {--retire : Stop selling the plan; its customers keep it}
        {--sell : Sell the plan again}
        {--assign-to= : Put the customer who owns the website on this host on the plan}
        {--reason= : Why the customer changes plan (required with --assign-to)}
        {--invitation= : on / off — show features outside a plan as locked with a way to see the plans}';

    #[\Override]
    protected $description = 'List the plans; add one, set what it grants, stop selling it, put a customer on it.';

    public function handle(): int
    {
        try {
            return $this->run_();
        } catch (PlanChangeRejectedException|FeatureChangeRejectedException $e) {
            $this->error(match ($e->reason) {
                'name_required' => 'name_required — a plan needs a name.',
                'reason_required' => 'reason_required — add --reason="…" (up to 500 characters): it is the only record of why the customer changed plan.',
                'plan_not_sold' => 'plan_not_sold — this plan is no longer sold; use --sell first, or pick another plan.',
                'unknown_feature' => 'unknown_feature — no feature has that key; run site:feature with a host to list them.',
                default => 'wrong_kind — a switch takes on or off, a limit takes a whole number of 0 or more.',
            });

            return self::FAILURE;
        }
    }

    private function run_(): int
    {
        $invitation = $this->option('invitation');
        if (is_string($invitation)) {
            if (! in_array($invitation, ['on', 'off'], true)) {
                $this->error('--invitation takes on or off.');

                return self::FAILURE;
            }

            $switch = app(UpgradeInvitation::class);
            if ($switch->isOn() !== ($invitation === 'on')) {
                $switch->set($invitation === 'on');
                $this->audit('provisioning.plan.upgrade_invitation_changed', ['on' => $invitation === 'on']);
            }

            return $this->done();
        }

        $name = $this->option('create');
        if (is_string($name)) {
            $plan = app(SavePlan::class)->handle(null, $name, true);
            $this->audit('provisioning.plan.created', ['plan' => $plan->key, 'name' => $plan->name, 'sold' => true]);
            $this->info("Created plan '{$plan->key}'.");

            return self::SUCCESS;
        }

        $key = $this->argument('plan');
        if ($key === null) {
            $this->report();

            return self::SUCCESS;
        }

        $plan = Plan::query()->firstWhere('key', $key);
        if ($plan === null) {
            $this->error("No plan has the key '{$key}'.");

            return self::FAILURE;
        }

        $feature = $this->argument('feature');
        $host = $this->option('assign-to');

        return match (true) {
            $feature !== null => $this->grant($plan, $feature),
            is_string($host) => $this->assign($plan, $host),
            (bool) $this->option('retire'), (bool) $this->option('sell') => $this->sell($plan, (bool) $this->option('sell')),
            default => $this->done(),
        };
    }

    private function grant(Plan $plan, string $feature): int
    {
        $raw = $this->argument('value');
        $clear = (bool) $this->option('clear');

        if (($raw === null) === ! $clear) {
            $this->error('Give a value (on, off or a whole number), or --clear — one of the two.');

            return self::FAILURE;
        }

        /** @var array<string, bool|int> $grants */
        $grants = PlanFeature::query()->where('plan_id', $plan->id)->pluck('value', 'feature_key')->all();

        if ($raw === null) {
            unset($grants[$feature]);
        } else {
            $grants[$feature] = match (true) {
                in_array($raw, ['on', 'true'], true) => true,
                in_array($raw, ['off', 'false'], true) => false,
                ctype_digit($raw) => (int) $raw,
                default => -1,   // not a value of any kind: refused below as wrong_kind
            };
        }

        app(SetPlanFeatures::class)->handle($plan, $grants);
        $this->audit('provisioning.plan.features_changed', ['plan' => $plan->key, 'features' => $grants]);

        return $this->done();
    }

    private function assign(Plan $plan, string $host): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new PlanChangeRejectedException('reason_required');
        }

        $resolved = app(SiteResolver::class)->resolve($host);
        $customer = $resolved === null ? null : Tenant::query()->whereKey(Site::query()->whereKey($resolved->siteId)->value('tenant_id'))->first();
        if ($customer === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        $was = app(ChangeCustomerPlan::class)->handle($customer, $plan, $reason);
        if ($was === $plan->id) {
            return $this->done();
        }

        $this->audit('provisioning.customer.plan_changed', array_filter([
            'customer' => $customer->ulid,
            'from' => $was === null ? null : Plan::query()->whereKey($was)->value('key'),
            'to' => $plan->key,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null));

        return $this->done();
    }

    private function sell(Plan $plan, bool $sold): int
    {
        $plan = app(SavePlan::class)->handle($plan, $plan->name, $sold);
        $this->audit('provisioning.plan.updated', ['plan' => $plan->key, 'name' => $plan->name, 'sold' => $sold]);

        return $this->done();
    }

    private function done(): int
    {
        $this->report();

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(string $action, array $meta): void
    {
        Audit::record($action, ['actor_label' => 'console', 'meta' => $meta]);
    }

    private function report(): void
    {
        $customers = DB::table('tenants')->whereNotNull('plan_id')->whereNull('deleted_at')
            ->selectRaw('plan_id, count(*) as customers')->groupBy('plan_id')->pluck('customers', 'plan_id');
        $grants = PlanFeature::query()->orderBy('feature_key')->get()->groupBy('plan_id');
        $show = fn (mixed $v): string => is_bool($v) ? ($v ? 'on' : 'off') : (is_int($v) ? (string) $v : '—');

        $this->line('Upgrade invitation: '.(app(UpgradeInvitation::class)->isOn() ? 'on' : 'off'));
        $this->table(['Plan', 'Name', 'Sold', 'Customers', 'Grants'], Plan::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Plan $plan): array => [
                $plan->key,
                $plan->name,
                $plan->is_active ? 'yes' : 'no',
                (string) Cast::int($customers->get($plan->id)),
                $grants->get($plan->id)?->map(fn (PlanFeature $row): string => $row->feature_key.'='.$show($row->value))->implode(', ') ?? '',
            ])->all());
    }
}
