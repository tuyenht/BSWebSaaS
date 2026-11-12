<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Platform\Plans;

use App\Modules\Admin\Support\Plans\PlanAccess;
use App\Modules\Admin\Support\Plans\PlanCatalog;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\BoundedRead;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Gói & tính năng" (design 30), the platform console's plan list: the upgrade invitation switch,
 * the plans in the order customers see them (search, sold / not sold, a page of 10, 20 or 50),
 * each plan's sale switch, ⋯ Nhân bản / Chuyển lên / Chuyển xuống, and below, for platform admins,
 * the website types (read only, #loai-website).
 *
 * Every write goes through PlatformPlans, which checks `platform.billing.manage` and leaves the
 * audit line; each public method checks the permission first too (the livewire/update endpoint
 * skips route middleware) and accepts only plan keys it finds. A change says what it did in a toast
 * with "Hoàn tác" for 10 seconds; what the undo reverses is #[Locked], so the browser cannot rewrite
 * it. The plan editor (Edit) hands its toast and undo over through the session. A new plan or a copy
 * has no "Hoàn tác": plans are never deleted (one no longer wanted stops being sold).
 *
 * The customers on each plan and the websites of each type are counted once (the first render) and
 * again after this page writes, never on a search, a filter or a page turn.
 */
#[Layout('admin::layouts.platform')]
final class Index extends Component
{
    public const array PER_PAGE = [10, 20, 50];

    public const array FILTERS = ['all', 'sold', 'off'];

    /** The longest search kept (a plan name is at most 60 characters). */
    public const int SEARCH_MAX = 60;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $filter = 'all';

    public int $per = 10;

    #[Url(except: 1)]
    public int $page = 1;

    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    /** @var array<string, mixed>|null what "Hoàn tác" reverses */
    #[Locked]
    public ?array $undoAction = null;

    /** @var array<string, int>|null plan key → customers on it; null = count on the next render */
    #[Locked]
    public ?array $customerCounts = null;

    /** @var array<string, int>|null website type key → websites; null = count on the next render */
    #[Locked]
    public ?array $siteCounts = null;

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
    }

    public function mount(): void
    {
        $handed = session('plans.toast');
        if (is_array($handed) && is_string($handed['text'] ?? null)) {
            $this->say($handed['text'], is_array($handed['undo'] ?? null) ? Cast::arr($handed['undo']) : null);
        }
        $this->clean();
    }

    public function updated(string $property): void
    {
        $this->clean();
        if (in_array($property, ['search', 'filter', 'per'], true)) {
            $this->page = 1;
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filter = 'all';
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    /** "Đang bán" on a row: put the plan on sale, or take it off (its customers keep it). */
    public function toggleSold(string $key): void
    {
        $actor = $this->operator();
        $plan = $this->plan($key);
        $was = $plan->is_active;
        $customers = app(PlanCatalog::class)->customersOn($plan);

        if (! $this->guard(fn () => app(PlatformPlans::class)->update($actor, $plan, $plan->name, ! $was))) {
            return;
        }

        $text = match (true) {
            ! $was => __('admin.plans_toast_sold_on', ['name' => $plan->name]),
            $customers > 0 => __('admin.plans_toast_sold_off_kept', ['name' => $plan->name, 'n' => $customers]),
            default => __('admin.plans_toast_sold_off', ['name' => $plan->name]),
        };
        $this->recount();
        $this->say(Cast::string($text), ['kind' => 'sold', 'plan' => $plan->key, 'sold' => $was]);
    }

    /** ⋯ Chuyển lên (-1) / Chuyển xuống (1). */
    public function move(string $key, int $step): void
    {
        $actor = $this->operator();
        $plan = $this->plan($key);
        if (! in_array($step, [-1, 1], true)) {
            return;
        }

        $keys = $this->order();
        $at = array_search($plan->key, $keys, true);
        $to = is_int($at) ? $at + $step : -1;
        if (! is_int($at) || $to < 0 || $to >= count($keys)) {
            return;
        }

        $order = $keys;
        array_splice($order, $at, 1);
        array_splice($order, $to, 0, [$plan->key]);

        if ($this->reorder($actor, $order)) {
            $this->recount();
            $text = __($step < 0 ? 'admin.plans_toast_moved_up' : 'admin.plans_toast_moved_down', ['name' => $plan->name, 'pos' => $to + 1]);
            $this->say(Cast::string($text), ['kind' => 'order', 'keys' => $keys]);
        }
    }

    /** ⋯ Nhân bản: a copy that is not on sale (no "Hoàn tác": plans are never deleted). */
    public function duplicate(string $key): void
    {
        $actor = $this->operator();
        $plan = $this->plan($key);
        $name = mb_substr(Cast::string(__('admin.plans_copy_name', ['name' => $plan->name])), 0, 60);

        $copy = null;
        if (! $this->guard(function () use ($actor, $plan, $name, &$copy): void {
            $copy = app(PlatformPlans::class)->duplicate($actor, $plan, $name);
        }) || ! $copy instanceof Plan) {
            return;
        }

        $this->recount();
        $this->say(Cast::string(__('admin.plans_toast_duplicated', ['name' => $copy->name])), null);
    }

    /** "Lời mời nâng gói" on or off, for every website on the platform. */
    public function toggleInvitation(): void
    {
        $actor = $this->operator();
        $was = app(UpgradeInvitation::class)->isOn();

        if (! $this->guard(fn () => app(PlatformPlans::class)->setUpgradeInvitation($actor, ! $was))) {
            return;
        }

        $text = $was
            ? __('admin.plans_toast_inv_off')
            : __(PlanCatalog::differ(app(PlanCatalog::class)->plans()) ? 'admin.plans_toast_inv_on' : 'admin.plans_toast_inv_on_same');
        $this->say(Cast::string($text), ['kind' => 'invite', 'on' => $was]);
    }

    /** "Hoàn tác": reverse the last change this page made. */
    public function undo(): void
    {
        $actor = $this->operator();
        $undo = $this->undoAction;
        $this->undoAction = null;
        $this->toast = '';
        if ($undo === null) {
            return;
        }

        $plans = app(PlatformPlans::class);
        $plan = isset($undo['plan']) ? Plan::query()->where('key', Cast::string($undo['plan']))->first() : null;

        $kind = $undo['kind'] ?? null;
        $done = $this->guard(function () use ($kind, $plan, $plans, $actor, $undo): void {
            if ($kind === 'invite') {
                $plans->setUpgradeInvitation($actor, (bool) ($undo['on'] ?? false));
            } elseif ($kind === 'order') {
                if (! $this->reorder($actor, Cast::strings($undo['keys'] ?? []), quiet: true)) {
                    throw new PlanChangeRejectedException('not_all_plans');
                }
            } elseif ($plan === null) {
                throw new PlanChangeRejectedException('plan_missing');
            } elseif ($kind === 'sold') {
                $plans->update($actor, $plan, $plan->name, (bool) ($undo['sold'] ?? false));
            } elseif ($kind === 'restore') {
                $this->restore($actor, $plan, $undo);
            }
        }, 'admin.plans_toast_undo_failed');
        if ($done) {
            $this->toast = '';
            $this->recount();
        }
    }

    public function dismissToast(?int $seq = null): void
    {
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
            $this->undoAction = null;
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        $access = app(PlanAccess::class);
        $canPlans = $access->plans($user);
        $canTypes = $access->types($user);
        $this->clean();

        $failed = false;
        $all = [];
        $types = [];
        $invitation = false;
        try {
            [$all, $types, $invitation] = app(BoundedRead::class)->run(function () use ($canPlans, $canTypes): array {
                $catalog = app(PlanCatalog::class);
                if ($canPlans) {
                    $this->customerCounts ??= $catalog->customerCounts();
                }
                if ($canTypes) {
                    $this->siteCounts ??= $catalog->siteCounts();
                }

                return [
                    $canPlans ? $catalog->plans($this->customerCounts) : [],
                    $canTypes ? $catalog->siteTypes($this->siteCounts) : [],
                    $canPlans && app(UpgradeInvitation::class)->isOn(),
                ];
            });
        } catch (QueryException $e) {
            if (! BoundedRead::timedOut($e)) {
                report($e);
            }
            $failed = true;
        }

        $q = VietnameseFold::text(trim($this->search));
        $byName = array_values(array_filter($all, fn (array $p): bool => $q === '' || str_contains(VietnameseFold::text($p['name']), $q)));
        $in = fn (array $p, string $f): bool => $f === 'all' || ($f === 'sold' ? $p['sold'] : ! $p['sold']);
        $counts = [];
        foreach (self::FILTERS as $f) {
            $counts[$f] = count(array_filter($byName, fn (array $p): bool => $in($p, $f)));
        }
        $list = array_values(array_filter($byName, fn (array $p): bool => $in($p, $this->filter)));
        $pages = max(1, (int) ceil(count($list) / $this->per));
        $this->page = min($this->page, $pages);

        $view = view('admin::livewire.platform.plans.index', [
            'canPlans' => $canPlans,
            'canTypes' => $canTypes,
            'group' => $canPlans || $canTypes ? '' : $access->groupName($user),
            'failed' => $failed,
            'invitation' => $invitation,
            'plansDiffer' => PlanCatalog::differ($all),
            'all' => $all,
            'counts' => $counts,
            'rows' => array_slice($list, ($this->page - 1) * $this->per, $this->per),
            'matched' => count($list),
            'pages' => $pages,
            'order' => array_column($all, 'key'),
            'filtering' => trim($this->search) !== '' || $this->filter !== 'all',
            'features' => array_keys(FeatureRegistry::all()),
            'types' => $types,
        ]);
        $view->title(__('admin.platform_plans'));

        return $view;
    }

    /** The signed-in member, when they may change plans; anyone else is refused. */
    private function operator(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(PlanAccess::class)->plans($user), 403);

        return $user;
    }

    private function plan(string $key): Plan
    {
        $plan = strlen($key) <= 64 ? Plan::query()->where('key', $key)->first() : null;
        abort_if($plan === null, 404);

        return $plan;
    }

    /** @return list<string> every plan key, first to last */
    private function order(): array
    {
        return Cast::strings(Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('key')->all());
    }

    /** @param  list<string>  $keys */
    private function reorder(User $actor, array $keys, bool $quiet = false): bool
    {
        $ids = Plan::query()->whereIn('key', $keys)->pluck('id', 'key');
        $order = [];
        foreach ($keys as $key) {
            if ($ids->has($key)) {
                $order[] = Cast::int($ids->get($key));
            }
        }

        try {
            app(PlatformPlans::class)->reorder($actor, $order);

            return true;
        } catch (PlanChangeRejectedException) {
            if (! $quiet) {
                $this->say(Cast::string(__('admin.plans_toast_order_failed')), null, 'error');
            }

            return false;
        }
    }

    /**
     * Put a plan back as it was before the editor saved it: all of it or nothing, and only while the
     * plan is still as that save left it (`after`). A change made since, here or elsewhere, is never
     * overwritten: the undo is refused instead.
     *
     * @param  array<string, mixed>  $undo
     *
     * @throws PlanChangeRejectedException plan_changed
     * @throws FeatureChangeRejectedException
     */
    private function restore(User $actor, Plan $plan, array $undo): void
    {
        $plans = app(PlatformPlans::class);
        $after = Cast::arr($undo['after'] ?? null);

        DB::transaction(function () use ($plans, $actor, $plan, $undo, $after): void {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
            if ($locked === null || $after === []
                || $locked->name !== ($after['name'] ?? null)
                || $locked->is_active !== ($after['sold'] ?? null)
                || ! PlanCatalog::sameGrants(PlanCatalog::grantsOf($locked), Cast::arr($after['features'] ?? []))) {
                throw new PlanChangeRejectedException('plan_changed');
            }

            $plans->update($actor, $locked, Cast::string($undo['name'] ?? $locked->name), (bool) ($undo['sold'] ?? $locked->is_active));
            $plans->setFeatures($actor, $locked, PlanCatalog::grantsFrom(Cast::arr($undo['features'] ?? [])));
        });
    }

    /** Count the customers and websites again on the next render. */
    private function recount(): void
    {
        $this->customerCounts = null;
        $this->siteCounts = null;
    }

    /** Run a write; a refusal of the plan rules becomes an error toast and false. */
    private function guard(callable $write, string $refused = 'admin.plans_toast_refused'): bool
    {
        try {
            $write();

            return true;
        } catch (AuthorizationException) {
            abort(403);
        } catch (PlanChangeRejectedException|FeatureChangeRejectedException) {
            $this->say(Cast::string(__($refused)), null, 'error');

            return false;
        }
    }

    /** @param  array<string, mixed>|null  $undo */
    private function say(string $text, ?array $undo, string $tone = 'success'): void
    {
        $this->toast = $text;
        $this->toastTone = $tone;
        $this->undoAction = $undo;
        $this->toastSeq++;
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all';
        $this->per = in_array($this->per, self::PER_PAGE, true) ? $this->per : 10;
        $this->page = max(1, $this->page);
    }
}
