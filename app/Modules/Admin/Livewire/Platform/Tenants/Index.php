<?php

namespace App\Modules\Admin\Livewire\Platform\Tenants;

use App\Modules\Admin\Support\Customers\CustomerDirectory;
use App\Modules\Admin\Support\Customers\CustomerPermissions;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Shared\Support\BoundedRead;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The platform's customer list (design 03 "Khách hàng"). `tenants` is platform-wide (D6, no RLS),
 * read with no tenant context (CustomerDirectory). Gated by platform.tenants.view; super_admin passes
 * via isSuperAdmin. Re-checked in boot() because the livewire/update endpoint bypasses route
 * middleware (EnsurePlatformAccess only guards the GET).
 *
 * Status groups, search, the plan filter and the page cursor live in the URL; the shown columns
 * and the page size are the member's own (users.preferences.lists."platform.customers", each key
 * written on its own). "Đổi gói" is the shared ChangePlanDialog on the page, opened from a row's ⋮.
 */
#[Layout('admin::layouts.platform')]
final class Index extends Component
{
    /** Where this list's choices live in the member's preferences. */
    public const string PREFS = 'platform.customers';

    /** The optional columns, in the screen's order (also what a phone card may show). */
    public const array COLS = ['plan', 'sites', 'joined'];

    public const array TABS = ['all', ...CustomerDirectory::STATUSES];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $tab = 'all';

    /** @var list<string> plan keys */
    #[Url(as: 'plan', except: [])]
    public array $plans = [];

    #[Url(except: '')]
    public string $after = '';

    #[Url(except: '')]
    public string $before = '';

    public int $per = 20;

    /** @var list<string> the optional columns this member shows */
    public array $cols = self::COLS;

    public function boot(): void
    {
        abort_unless(app(CustomerPermissions::class)->canView(auth()->user()), 403);
    }

    public function mount(): void
    {
        $view = Cast::arr(Cast::arr(Cast::arr($this->member()->preferences)['lists'] ?? null)[self::PREFS] ?? null);
        $this->per = in_array($view['per'] ?? null, CustomerDirectory::PER_PAGE, true) ? Cast::int($view['per']) : 20;
        $this->cols = isset($view['cols']) ? self::known(Cast::arr($view['cols']), self::COLS) : self::COLS;
        $this->clean();
    }

    public function updated(string $property): void
    {
        $name = Str::before($property, '.');
        $this->clean();
        if (in_array($name, ['search', 'tab', 'plans', 'per'], true)) {
            $this->after = $this->before = '';
        }
        if ($name === 'per') {
            UserPreferences::set($this->member(), ['lists', self::PREFS, 'per'], $this->per);
        }
        if ($name === 'cols') {
            UserPreferences::set($this->member(), ['lists', self::PREFS, 'cols'], $this->cols);
        }
    }

    public function older(string $ulid): void
    {
        $this->after = self::ulidOr($ulid);
        $this->before = '';
    }

    public function newer(string $ulid): void
    {
        $this->before = self::ulidOr($ulid);
        $this->after = '';
    }

    /** Bỏ lọc: the search, the plans and the group go; the page size stays. */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->tab = 'all';
        $this->plans = [];
        $this->after = $this->before = '';
    }

    public function clearPlans(): void
    {
        $this->plans = [];
        $this->after = $this->before = '';
    }

    public function removePlan(string $key): void
    {
        $this->plans = array_values(array_diff($this->plans, [$key]));
        $this->after = $this->before = '';
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->after = $this->before = '';
    }

    /** A customer's plan changed in the dialog: the next render reads it again. */
    #[On('customer-plan-changed')]
    public function planChanged(): void {}

    public function render(): View
    {
        $directory = app(CustomerDirectory::class);
        $permissions = app(CustomerPermissions::class);
        $this->clean();
        $member = $this->member();
        $failed = false;
        $plans = new Collection;

        try {
            $data = app(BoundedRead::class)->run(function () use ($directory, &$plans): array {
                $plans = $directory->plans();
                $chosen = array_values(array_intersect($this->plans, Cast::strings($plans->pluck('key')->all())));
                $status = $this->tab === 'all' ? '' : $this->tab;
                $narrowed = CustomerDirectory::searches($this->search) || $chosen !== [];

                $page = $directory->page($this->search, $status, $chosen, $this->per, $this->after, $this->before);

                return [
                    'rows' => $page['rows'],
                    'older' => $page['older'],
                    'newer' => $page['newer'],
                    'counts' => $page['counts']['groups'],
                    'planCounts' => $page['counts']['plans'],
                    // "N trong M": M is the same group with neither the search nor the plans
                    'baseCounts' => $narrowed ? $directory->counts('', [])['groups'] : null,
                ];
            });
        } catch (QueryException $e) {
            if (! BoundedRead::timedOut($e)) {
                report($e);
            }
            $failed = true;
            $data = ['rows' => new Collection, 'older' => null, 'newer' => null, 'counts' => ['all' => 0, 'active' => 0, 'suspended' => 0], 'planCounts' => [], 'baseCounts' => null];
        }

        $view = view('admin::livewire.platform.tenants.index', $data + [
            'failed' => $failed,
            // a search too short to run: the list stays whole and says how much to type
            'searching' => CustomerDirectory::searches($this->search),
            'searchTooShort' => CustomerDirectory::tooShort($this->search),
            'planList' => $plans,
            'siteTime' => app(SiteTime::class),
            'canChangePlan' => $permissions->canChangePlan($member),
            'roleStrip' => $permissions->roleStrip($member),
            'lockedReason' => $permissions->lockedReason($member),
        ]);
        $view->title(__('admin.platform_tenants'));

        return $view;
    }

    private function member(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'all';
        $this->search = mb_substr($this->search, 0, CustomerDirectory::SEARCH_MAX);
        $this->plans = array_slice(array_values(array_unique(array_filter(Cast::strings($this->plans), fn (string $v): bool => $v !== '' && strlen($v) <= 64))), 0, 50);
        $this->per = in_array($this->per, CustomerDirectory::PER_PAGE, true) ? $this->per : 20;
        $this->cols = self::known($this->cols, self::COLS);
        $this->after = self::ulidOr($this->after);
        $this->before = self::ulidOr($this->before);
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function known(array $values, array $known): array
    {
        return array_values(array_intersect($known, array_filter($values, 'is_string')));
    }

    private static function ulidOr(string $value): string
    {
        return Str::isUlid($value) ? strtoupper($value) : '';
    }
}
