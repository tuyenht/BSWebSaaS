<?php

namespace App\Modules\Admin\Livewire\Platform\Support;

use App\Modules\Admin\Support\SupportSessions;
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\BoundedRead;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Đăng nhập hỗ trợ" in the platform console (design board 20): every time staff signed in as a
 * website's member, read out of the audit trail by SupportSessions, with "Kết thúc phiên ngay" for
 * a session still open. Needs platform.sites.impersonate; any other operator sees the no-right
 * card and nothing is read. Every request (Livewire updates included) re-checks in boot() that the
 * member is an operator, and every action checks the right again before it does anything.
 *
 * Group, search, staff, period and the page cursor live in the URL; the page size and the shown
 * columns are the member's own (users.preferences.lists."platform.support"). Sessions are named by
 * their code (a ulid) in the page and in every call, never by a row id. Ending goes through
 * SupportSignIn::end, which allows one's own session, or anyone's to the platform super-admin.
 */
#[Layout('admin::layouts.platform')]
final class Index extends Component
{
    public const string PREFS = 'platform.support';

    /** The optional columns, in the screen's order (also what a phone card may show). */
    public const array COLS = ['staff', 'reason', 'time'];

    /** @var list<int> */
    public const array PER_PAGE = [10, 20, 50];

    private const int SEARCH_MAX = 100;

    private const int CHOSEN_MAX = 50;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: SupportSessions::TAB_ALL)]
    public string $tab = SupportSessions::TAB_ALL;

    /** @var list<string> staff ulids */
    #[Url(except: [])]
    public array $staff = [];

    /** '' (any time) or one of SupportSessions::PERIODS, as text */
    #[Url(except: '')]
    public string $days = '';

    #[Url(except: '')]
    public string $after = '';

    #[Url(except: '')]
    public string $before = '';

    public int $per = 20;

    /** @var list<string> the optional columns this member shows */
    public array $cols = self::COLS;

    /** "Kết thúc phiên ngay?" is open (for $ending). */
    public bool $endOpen = false;

    /** The code of the session the question is about, and the names it says. */
    #[Locked]
    public string $ending = '';

    #[Locked]
    public string $endingStaff = '';

    #[Locked]
    public string $endingSite = '';

    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(PlatformAccess::class)->isOperator($user), 403);
    }

    public function mount(): void
    {
        $view = Cast::arr(Cast::arr(Cast::arr($this->member()->preferences)['lists'] ?? null)[self::PREFS] ?? null);
        $this->per = in_array($view['per'] ?? null, self::PER_PAGE, true) ? Cast::int($view['per']) : 20;
        $this->cols = isset($view['cols']) ? self::known(Cast::arr($view['cols']), self::COLS) : self::COLS;
        $this->clean();
    }

    public function updated(string $property): void
    {
        $this->authorized();
        $name = Str::before($property, '.');
        $this->clean();
        if (in_array($name, ['search', 'tab', 'staff', 'days', 'per'], true)) {
            $this->firstPage();
        }
        if ($name === 'per') {
            UserPreferences::set($this->member(), ['lists', self::PREFS, 'per'], $this->per);
        }
        if ($name === 'cols') {
            UserPreferences::set($this->member(), ['lists', self::PREFS, 'cols'], $this->cols);
        }
    }

    public function older(string $session): void
    {
        $this->authorized();
        $this->after = self::ulidOr($session);
        $this->before = '';
    }

    public function newer(string $session): void
    {
        $this->authorized();
        $this->before = self::ulidOr($session);
        $this->after = '';
    }

    /** Bỏ lọc: the search, the staff, the period and the group go; the page size stays. */
    public function clearFilters(): void
    {
        $this->authorized();
        $this->search = $this->days = '';
        $this->tab = SupportSessions::TAB_ALL;
        $this->staff = [];
        $this->firstPage();
    }

    public function clearSearch(): void
    {
        $this->authorized();
        $this->search = '';
        $this->firstPage();
    }

    public function clearDays(): void
    {
        $this->authorized();
        $this->days = '';
        $this->firstPage();
    }

    public function removeStaff(string $ulid): void
    {
        $this->authorized();
        $this->staff = array_values(array_diff($this->staff, [$ulid]));
        $this->firstPage();
    }

    /** "N phiên đang mở · Xem" (below 900): the open group. */
    public function showOpen(): void
    {
        $this->authorized();
        $this->tab = SupportSessions::TAB_OPEN;
        $this->firstPage();
    }

    /**
     * ⋮ › "Kết thúc phiên ngay…": asks first. A session already over, unknown, or someone else's
     * (for staff who are not the platform super-admin) says so instead of asking.
     */
    public function askEnd(string $session): void
    {
        $user = $this->authorized();
        $this->endOpen = false;
        $this->ending = '';
        $row = Str::isUlid($session) ? app(SupportSessions::class)->row($user, $session) : null;

        if ($row === null) {
            $this->notify(__('admin.support.session_unknown'), 'error');

            return;
        }
        if ($row['status'] !== 'open') {
            $this->notify(__('admin.support.ended'), 'info');

            return;
        }
        if (! $row['can_end']) {
            $this->notify(__('admin.support.end_not_yours'), 'error');

            return;
        }

        $this->ending = $row['session'];
        $this->endingStaff = $row['staff'];
        $this->endingSite = $row['site_name'] ?? '—';
        $this->endOpen = true;
    }

    public function cancelEnd(): void
    {
        $this->authorized();
        $this->endOpen = false;
        $this->ending = '';
    }

    /** "Kết thúc phiên": the member on the site is signed out at their next request. */
    public function endNow(): void
    {
        $user = $this->authorized();
        $code = $this->ending;
        $staff = $this->endingStaff;
        $this->endOpen = false;
        $this->ending = '';
        if ($code === '') {
            return;
        }

        try {
            $ended = app(SupportSignIn::class)->end($user, $code);
        } catch (AuthorizationException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        } catch (ValidationException $e) {
            $this->notify(Cast::string($e->validator->errors()->first()), 'error');

            return;
        }

        $ended
            ? $this->notify(__('admin.support.list.ended_toast', ['staff' => $staff]))
            : $this->notify(__('admin.support.ended'), 'info');
    }

    public function dismissToast(?int $seq = null): void
    {
        $this->authorized();
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
        }
    }

    public function render(): View
    {
        $this->clean();
        $user = $this->member();
        $platform = app(PlatformAccess::class);
        $super = $platform->isSuperAdmin($user);
        $may = fn (string $permission): bool => $super || $platform->can($user, $permission);
        $allowed = $may('platform.sites.impersonate');
        $failed = false;
        $data = ['rows' => [], 'older' => null, 'newer' => null, 'counts' => ['all' => 0, 'open' => 0, 'ended' => 0], 'staffChoices' => []];

        if ($allowed) {
            $sessions = app(SupportSessions::class);
            $days = $this->days === '' ? null : (int) $this->days;
            try {
                $data = app(BoundedRead::class)->run(fn (): array => [
                    ...$sessions->page($user, $this->tab, $this->staff, $days, $this->search, $this->after, $this->before, $this->per),
                    'counts' => $sessions->counts($user, $this->staff, $days, $this->search),
                    'staffChoices' => $sessions->staffChoices($user, $days, $this->search, $this->staff),
                ]);
            } catch (QueryException $e) {
                if (! BoundedRead::timedOut($e)) {
                    report($e);
                }
                $failed = true;
            }
        }

        $view = view('admin::livewire.platform.support.index', $data + [
            'allowed' => $allowed,
            'failed' => $failed,
            'mayViewSites' => $may('platform.sites.view'),
            'mayViewAudit' => $may('platform.audit.view'),
            'siteTime' => app(SiteTime::class),
        ]);
        $view->title(__('admin.platform_support'));

        return $view;
    }

    /** The member, who must hold the right to sign in as website members. */
    private function authorized(): User
    {
        $user = $this->member();
        $platform = app(PlatformAccess::class);
        abort_unless($platform->isSuperAdmin($user) || $platform->can($user, 'platform.sites.impersonate'), 403);

        return $user;
    }

    private function member(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function notify(string $message, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->tab = in_array($this->tab, SupportSessions::TABS, true) ? $this->tab : SupportSessions::TAB_ALL;
        $this->staff = array_slice(array_values(array_unique(array_filter(Cast::strings($this->staff), fn (string $v): bool => Str::isUlid($v)))), 0, self::CHOSEN_MAX);
        $this->days = in_array((int) $this->days, SupportSessions::PERIODS, true) ? (string) (int) $this->days : '';
        $this->per = in_array($this->per, self::PER_PAGE, true) ? $this->per : 20;
        $this->cols = self::known($this->cols, self::COLS);
        $this->after = self::ulidOr($this->after);
        $this->before = self::ulidOr($this->before);
    }

    private function firstPage(): void
    {
        $this->after = $this->before = '';
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
        return Str::isUlid($value) ? $value : '';
    }
}
