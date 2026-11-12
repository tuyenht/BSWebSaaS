<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Platform\Plans;

use App\Modules\Admin\Support\Plans\PlanAccess;
use App\Modules\Admin\Support\Plans\PlanCatalog;
use App\Modules\Admin\Support\Plans\PlanFeatureText;
use App\Modules\Admin\Support\PlatformPlans;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Exceptions\PlanChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Thêm gói" / "Sửa gói" (design 30): the plan's name, whether it is on sale, and the whole feature
 * table saved at once (a feature left empty grants nothing, so the system default applies), with
 * "Khách dùng gói này sẽ thấy…" worked out from the table as it is being edited.
 *
 * Saving applies at once to every customer on the plan (Entitlement reads the plan), so the page
 * says how many customers that is before the save; nothing a customer already has is taken away
 * (limits only stop adding more). The change goes through PlatformPlans (`platform.billing.manage`,
 * one audit line for the name/sale and one for the feature table); each public method checks the
 * permission first. The draft lives in public properties and is cleaned on every request: a value
 * is '' (default), 'on' / 'off' for a switch, or up to 9 digits for a limit.
 */
#[Layout('admin::layouts.platform')]
final class Edit extends Component
{
    public const int NAME_MAX = 60;

    #[Locked]
    public ?string $planKey = null;

    public string $name = '';

    public bool $sold = false;

    /** @var array<string, string> field (the feature key, dots as "__", which Livewire would read as nesting) → '' | 'on' | 'off' | digits */
    public array $features = [];

    /** @var array{name: string, sold: bool, features: array<string, string>} the saved state */
    #[Locked]
    public array $base = ['name' => '', 'sold' => false, 'features' => []];

    public bool $nameTouched = false;

    public bool $tried = false;

    /** The refusal shown above the form: name_required · num · unknown_feature · wrong_kind */
    #[Locked]
    public string $error = '';

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
    }

    public function mount(?string $key = null): void
    {
        $this->features = array_fill_keys(array_map(self::field(...), array_keys(FeatureRegistry::all())), '');
        if ($key !== null && app(PlanAccess::class)->plans(auth()->user())) {
            $plan = strlen($key) <= 64 ? Plan::query()->where('key', $key)->first() : null;
            abort_if($plan === null, 404);
            $this->planKey = $plan->key;
            $this->name = $plan->name;
            $this->sold = $plan->is_active;
            foreach (PlanFeature::query()->where('plan_id', $plan->id)->pluck('value', 'feature_key') as $feature => $value) {
                if (array_key_exists(self::field(Cast::string($feature)), $this->features)) {
                    $this->features[self::field(Cast::string($feature))] = is_bool($value) ? ($value ? 'on' : 'off') : (is_int($value) ? (string) $value : '');
                }
            }
        }
        $this->base = ['name' => $this->name, 'sold' => $this->sold, 'features' => $this->features];
    }

    public function updated(string $property): void
    {
        $this->clean();
        if ($property === 'name') {
            $this->nameTouched = true;
        }
    }

    /** A switch feature's choice: '' (default), 'on' or 'off'. */
    public function pick(string $feature, string $value): void
    {
        $this->operator();
        if (FeatureRegistry::has($feature) && ! PlanFeatureText::isLimit($feature) && in_array($value, ['', 'on', 'off'], true)) {
            $this->features[self::field($feature)] = $value;
        }
    }

    public function toggleSold(): void
    {
        $this->operator();
        $this->sold = ! $this->sold;
    }

    /** "Bỏ thay đổi": back to the saved plan. */
    public function resetChanges(): void
    {
        $this->operator();
        $this->name = $this->base['name'];
        $this->sold = $this->base['sold'];
        $this->features = $this->base['features'];
        $this->tried = $this->nameTouched = false;
        $this->error = '';
    }

    /** "Lưu gói" / "Thêm gói": save, then back to the list with what changed and "Hoàn tác". */
    public function save(): void
    {
        if ($this->store()) {
            $this->redirectRoute('platform.plans.index', navigate: true);
        }
    }

    /** The save of the unsaved-changes dialog: the dialog itself goes on where the member was going. */
    public function saveQuietly(): void
    {
        if (! $this->store()) {
            throw new \RuntimeException('The plan was not saved.');
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        $canPlans = app(PlanAccess::class)->plans($user);
        $this->clean();

        $plan = $this->planKey !== null ? Plan::query()->where('key', $this->planKey)->first() : null;
        $view = view('admin::livewire.platform.plans.edit', [
            'canPlans' => $canPlans,
            'group' => $canPlans ? '' : app(PlanAccess::class)->groupName($user),
            'customers' => $plan !== null && $canPlans ? app(PlanCatalog::class)->customersOn($plan) : 0,
            'status' => $this->status($plan),
            'changes' => $this->changes(),
            'numErrors' => $this->numberErrors(),
            'nameError' => ($this->nameTouched || $this->tried) && trim($this->name) === '',
            'effective' => PlanCatalog::effective($this->grants()),
            'invitation' => $canPlans && app(UpgradeInvitation::class)->isOn(),
        ]);
        $view->title($this->planKey === null ? __('admin.plans_add') : __('admin.plans_edit_title', ['name' => $this->base['name']]));

        return $view;
    }

    private function store(): bool
    {
        $actor = $this->operator();
        $this->clean();
        $this->tried = true;
        $this->nameTouched = true;

        if (trim($this->name) === '') {
            $this->error = 'name_required';

            return false;
        }
        if ($this->numberErrors() !== []) {
            $this->error = 'num';

            return false;
        }

        $plans = app(PlatformPlans::class);
        $grants = $this->grants();
        $plan = $this->planKey !== null ? Plan::query()->where('key', $this->planKey)->first() : null;
        if ($this->planKey !== null && $plan === null) {
            abort(404);
        }
        $before = $plan === null ? null : [
            'name' => $plan->name, 'sold' => $plan->is_active,
            'features' => PlanFeature::query()->where('plan_id', $plan->id)->pluck('value', 'feature_key')->all(),
        ];

        try {
            $saved = DB::transaction(function () use ($plans, $actor, $plan, $grants): Plan {
                if ($plan === null) {
                    $plan = $plans->create($actor, $this->name, $this->sold);
                    $plans->setFeatures($actor, $plan, $grants);

                    return $plan;
                }
                if (trim($this->name) !== $plan->name || $this->sold !== $plan->is_active) {
                    $plans->update($actor, $plan, $this->name, $this->sold);
                }
                if ($this->features !== $this->base['features']) {
                    $plans->setFeatures($actor, $plan, $grants);
                }

                return $plan;
            });
        } catch (AuthorizationException) {
            abort(403);
        } catch (PlanChangeRejectedException|FeatureChangeRejectedException $e) {
            $this->error = in_array($e->reason, ['name_required', 'unknown_feature', 'wrong_kind'], true) ? $e->reason : 'wrong_kind';

            return false;
        }

        $this->error = '';
        $this->base = ['name' => $saved->name, 'sold' => $saved->is_active, 'features' => $this->features];
        $this->name = $saved->name;
        $this->planKey = $saved->key;

        if ($before === null) {
            session()->flash('plans.toast', [
                'text' => __($saved->is_active ? 'admin.plans_toast_added_sold' : 'admin.plans_toast_added', ['name' => $saved->name]),
                // a plan just added and not on sale can be taken back; one on sale stays
                'undo' => $saved->is_active ? null : ['kind' => 'discard', 'plan' => $saved->key],
            ]);
        } else {
            $customers = app(PlanCatalog::class)->customersOn($saved);
            session()->flash('plans.toast', [
                'text' => $customers > 0
                    ? __('admin.plans_toast_saved_applied', ['name' => $saved->name, 'n' => $customers])
                    : __('admin.plans_toast_saved', ['name' => $saved->name]),
                'undo' => ['kind' => 'restore', 'plan' => $saved->key] + $before,
            ]);
        }

        return true;
    }

    /** @return array<string, bool|int> the table as PlatformPlans takes it: a feature left empty is not granted */
    private function grants(): array
    {
        $grants = [];
        foreach ($this->draft() as $key => $value) {
            if ($value === '') {
                continue;
            }
            if (PlanFeatureText::isLimit($key)) {
                if (preg_match('/^\d{1,9}$/', $value) === 1) {
                    $grants[$key] = (int) $value;
                }
            } else {
                $grants[$key] = $value === 'on';
            }
        }

        return $grants;
    }

    /** @return array<string, string> feature key → what is wrong with its number */
    private function numberErrors(): array
    {
        $errors = [];
        foreach ($this->draft() as $key => $value) {
            if ($value === '' || ! PlanFeatureText::isLimit($key)) {
                continue;
            }
            $shown = $this->tried || $value !== ($this->base['features'][self::field($key)] ?? '');
            $min = PlanFeatureText::min($key);
            if ($shown && preg_match('/^\d{1,9}$/', $value) !== 1) {
                $errors[$key] = Cast::string(__('admin.plans_err_whole_number'));
            } elseif ($shown && (int) $value < $min) {
                $errors[$key] = Cast::string(__('admin.plans_err_min', ['min' => $min, 'unit' => PlanFeatureText::unit($key)]));
            }
        }

        return $errors;
    }

    private function changes(): int
    {
        $n = (int) (trim($this->name) !== $this->base['name']) + (int) ($this->sold !== $this->base['sold']);
        foreach ($this->features as $field => $value) {
            $n += (int) ($value !== ($this->base['features'][$field] ?? ''));
        }

        return $n;
    }

    /** @return 'ok'|'off'|'new' */
    private function status(?Plan $plan): string
    {
        if ($this->sold) {
            return 'ok';
        }

        return $plan !== null && ((Cast::arr($plan->meta)['sold_once'] ?? false) === true) ? 'off' : 'new';
    }

    private function operator(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(PlanAccess::class)->plans($user), 403);

        return $user;
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->name = mb_substr($this->name, 0, self::NAME_MAX);
        $features = [];
        foreach (array_keys(FeatureRegistry::all()) as $key) {
            $value = Cast::string($this->features[self::field($key)] ?? '');
            $features[self::field($key)] = PlanFeatureText::isLimit($key)
                ? substr((string) preg_replace('/\D/', '', $value), 0, 9)
                : (in_array($value, ['on', 'off'], true) ? $value : '');
        }
        $this->features = $features;
    }

    /** The form field of a feature: its key with "__" for each dot. */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return array<string, string> feature key → the draft value */
    private function draft(): array
    {
        $draft = [];
        foreach (array_keys(FeatureRegistry::all()) as $key) {
            $draft[$key] = $this->features[self::field($key)] ?? '';
        }

        return $draft;
    }
}
