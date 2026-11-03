<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Platform\Audit;

use App\Modules\Admin\Support\AuditMonthPurgeRefused;
use App\Modules\Admin\Support\PurgeAuditMonth;
use App\Modules\Audit\Support\AuditMonths;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Dọn nhật ký" (design 29): the platform super-admin deletes one whole old month of the activity
 * log, in two steps — choose the month, then confirm it with its exact row count and their password.
 * The audit screen shows the opener only to the super-admin and dispatches `open-audit-cleanup`;
 * this component refuses everyone else on every request (boot) and again in every action.
 *
 * The month list comes from AuditMonths::months() (the planner's estimates, one query); the exact
 * count is read only for the one month chosen. Months after the running one (created ahead, empty)
 * are not shown. Whether a month may go, the password, the attempt limit and the month floor are
 * all decided by PurgeAuditMonth: this dialog only shows its refusals where the design puts them —
 * a password refusal under the field, a month refusal as the alert above the buttons.
 *
 * The password never becomes a public property (those travel back to the browser in every
 * response): it is passed to purge() as an argument and forgotten.
 */
final class CleanupDialog extends Component
{
    public bool $open = false;

    /** 'pick' (choose a month) or 'confirm'. */
    #[Locked]
    public string $step = 'pick';

    /**
     * Every month of the log up to the running one, oldest first, as read when the dialog opened;
     * null = not read yet.
     *
     * @var list<array{month: string, rows_estimate: int}>|null
     */
    #[Locked]
    public ?array $months = null;

    /** The oldest month kept from removal by hand ('YYYY-MM'): older ones may be chosen. */
    #[Locked]
    public string $floor = '';

    #[Locked]
    public bool $loadFailed = false;

    /** The chosen month ('YYYY-MM', '' = none); checked against the list on every use. */
    public string $month = '';

    /** "Tiếp tục" was pressed with no month chosen. */
    #[Locked]
    public bool $pickError = false;

    /** The chosen month's exact row count; null while it is being counted. */
    #[Locked]
    public ?int $exact = null;

    #[Locked]
    public bool $countFailed = false;

    #[Locked]
    public string $passwordError = '';

    #[Locked]
    public string $monthError = '';

    /** The month itself was refused (too recent, or gone): only another month can go on. */
    #[Locked]
    public bool $monthRefused = false;

    /** The success message shown after the dialog closes ('' = none). */
    #[Locked]
    public string $toast = '';

    public function boot(): void
    {
        $this->authorizeCleanup();
    }

    public function openDialog(): void
    {
        $this->authorizeCleanup();
        $this->reset('step', 'months', 'floor', 'loadFailed', 'month', 'pickError', 'exact', 'countFailed', 'passwordError', 'monthError', 'monthRefused', 'toast');
        $this->open = true;
    }

    /** Reads the months (the dialog shows its loading rows until this returns). */
    public function loadMonths(): void
    {
        $this->authorizeCleanup();
        if (! $this->open) {
            return;
        }

        $this->loadFailed = false;
        try {
            $audit = app(AuditMonths::class);
            $running = $audit->runningMonth();
            $this->floor = $audit->manualFloor();
            // the months made ahead of the running one hold nothing yet: never offered, never "kept"
            $this->months = array_values(array_filter($audit->months(), fn (array $m): bool => $m['month'] <= $running));
        } catch (QueryException $e) {
            report($e);
            $this->months = null;
            $this->loadFailed = true;
        }
    }

    public function updatedMonth(): void
    {
        $this->authorizeCleanup();
        $this->month = $this->removable($this->month) ? $this->month : '';
        $this->pickError = false;
    }

    /** "Tiếp tục": on to the confirmation, whose exact count is read next (countRows). */
    public function next(): void
    {
        $this->authorizeCleanup();
        if ($this->step !== 'pick' || $this->months === null) {
            return;
        }
        if (! $this->removable($this->month)) {
            $this->month = '';
            $this->pickError = true;

            return;
        }

        $this->step = 'confirm';
        $this->exact = null;
        $this->countFailed = false;
        $this->passwordError = $this->monthError = '';
        $this->monthRefused = false;
    }

    /** The exact number of rows of the chosen month, for the confirmation that names it. */
    public function countRows(): void
    {
        $this->authorizeCleanup();
        if ($this->step !== 'confirm' || ! $this->removable($this->month)) {
            return;
        }

        $this->countFailed = false;
        try {
            $this->exact = app(AuditMonths::class)->rows($this->month);
        } catch (QueryException $e) {
            report($e);
            $this->countFailed = true;
        }
    }

    /** "Thử lại" after the months or the count could not be read: shows the loading rows, which read again. */
    public function retry(): void
    {
        $this->authorizeCleanup();
        if ($this->step === 'pick') {
            $this->loadFailed = false;
            $this->months = null;
        } else {
            $this->countFailed = false;
            $this->exact = null;
        }
    }

    /** "Chọn tháng khác": back to the list, keeping the month unless it is gone. */
    public function back(): void
    {
        $this->authorizeCleanup();
        $this->step = 'pick';
        $this->month = $this->removable($this->month) ? $this->month : '';
        $this->exact = null;
        $this->countFailed = $this->pickError = $this->monthRefused = false;
        $this->passwordError = $this->monthError = '';
    }

    public function purge(string $password = ''): void
    {
        $actor = $this->authorizeCleanup();
        if ($this->step !== 'confirm' || $this->exact === null || $this->monthRefused) {
            return;
        }
        if (! $this->removable($this->month)) {
            // what the list no longer offers, sent straight from the browser
            $this->refuseMonth(Cast::string(__('admin.audit.purge.invalid_month')), true);

            return;
        }

        $this->passwordError = $this->monthError = '';
        if ($password === '') {
            $this->passwordError = Cast::string(__('admin.audit.purge.password_required'));

            return;
        }

        try {
            $rows = app(PurgeAuditMonth::class)->handle($actor, $this->month, $password);
        } catch (AuthorizationException) {
            abort(403);
        } catch (AuditMonthPurgeRefused $e) {
            if ($e->reason === 'missing') {
                // gone already (deleted elsewhere): it leaves the list
                $this->months = array_values(array_filter($this->months ?? [], fn (array $m): bool => $m['month'] !== $this->month));
            }
            // busy / unrecorded: the same month may work on a retry, so no "another month" offer
            $this->refuseMonth($e->validator->errors()->first('month'), in_array($e->reason, ['missing', 'kept', 'invalid'], true));

            return;
        } catch (ValidationException $e) {
            $errors = $e->validator->errors();
            if ($errors->has('password')) {
                $this->passwordError = $errors->first('password');

                return;
            }
            $this->refuseMonth($errors->has('month') ? $errors->first('month') : Cast::string(__('admin.audit.purge.invalid_month')), true);

            return;
        }

        $this->toast = Cast::string(__('admin.audit.purge.done', [
            'month' => self::label($this->month),
            'n' => Number::format($rows, locale: app()->getLocale()),
        ]));
        $this->open = false;
        $this->reset('step', 'months', 'floor', 'month', 'exact', 'passwordError', 'monthError', 'monthRefused');
        $this->dispatch('audit-month-deleted');
    }

    public function close(): void
    {
        $this->authorizeCleanup();
        $this->open = false;
    }

    public function dismissToast(): void
    {
        $this->toast = '';
    }

    public function render(): View
    {
        $all = $this->months ?? [];
        $removable = array_values(array_filter($all, fn (array $m): bool => $m['month'] < $this->floor));
        $kept = array_values(array_filter($all, fn (array $m): bool => $m['month'] >= $this->floor));
        $oldest = $all[0]['month'] ?? null;

        return view('admin::livewire.platform.audit.cleanup-dialog', [
            'removable' => $removable,
            'kept' => $kept,
            'keptRows' => array_sum(array_column($kept, 'rows_estimate')),
            'oldest' => $oldest,
            // the oldest month can go once it is older than the running month and the 12 before it
            'oldestFrom' => $oldest === null ? null : self::shift($oldest, AuditMonths::MANUAL_FLOOR_MONTHS + 1),
        ]);
    }

    /** 'YYYY-MM' as the screen writes a month: 'MM/YYYY'. */
    public static function label(string $month): string
    {
        return AuditMonths::isMonth($month) ? substr($month, 5, 2).'/'.substr($month, 0, 4) : $month;
    }

    private static function shift(string $month, int $months): string
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');

        return $first === null ? $month : $first->addMonthsNoOverflow($months)->format('Y-m');
    }

    /** Is $month one of the months the list offers? (PurgeAuditMonth decides again.) */
    private function removable(string $month): bool
    {
        return $month !== '' && $month < $this->floor && in_array($month, array_column($this->months ?? [], 'month'), true);
    }

    private function refuseMonth(string $message, bool $chooseAnother): void
    {
        $this->monthError = $message;
        $this->monthRefused = $chooseAnother;
    }

    private function authorizeCleanup(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(PlatformAccess::class)->isSuperAdmin($user), 403);

        return $user;
    }
}
