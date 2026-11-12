<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Forms;

use App\Modules\Admin\Support\Leads\LeadListQuery;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Khách liên hệ" (tenant plane): the site's leads on the left, the one being read on the right
 * (below 940px one at a time, with "Quay lại danh sách"). Status groups, search without
 * diacritics, the forms and time filters, ticking rows for a status change, and every change
 * undone from its message.
 *
 * Reads need forms.view, writes forms.manage, both asked on every request (the livewire/update
 * endpoint carries no route middleware); the plan is asked first. Leads are site-scoped by
 * BelongsToSite + RLS and addressed by ulid. A "new" lead turns "read" only when a manager opens
 * it; the lead shown first on a wide screen without being opened stays as it was.
 */
#[Layout('admin::layouts.app')]
final class Inbox extends Component
{
    /** Rows read at first, and on each "Tải thêm". */
    public const int PAGE = 15;

    /** The most rows the list holds at once, and the most rows a tick can hold. */
    public const int MAX_ROWS = 300;

    #[Url(as: 'status', except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> ulids of the forms ticked */
    #[Url(as: 'form', except: [])]
    public array $formF = [];

    #[Url(as: 'when', except: '')]
    public string $when = '';

    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    /** The lead opened (its ulid); '' = none opened (a wide screen shows the first row). */
    #[Url(as: 'lead', except: '')]
    public string $lead = '';

    /** `created_at|ulid` of the last row loaded after "Tải thêm"; null = the first page only. */
    #[Locked]
    public ?string $until = null;

    /** @var list<string> ulids of the ticked rows */
    public array $selected = [];

    public string $toastMsg = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    /** @var array{items: array<string, string>, set: string}|null what "Hoàn tác" puts back: ulid => the status before; `set` is the status the action gave them */
    #[Locked]
    public ?array $undoOp = null;

    public function boot(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->roles->isNotEmpty() || app(PlatformAccess::class)->isSuperAdmin($user)), 403);
    }

    /** `/admin/forms/{ulid}` opens that lead (the old detail address keeps working). */
    public function mount(?string $ulid = null): void
    {
        if (is_string($ulid) && $ulid !== '') {
            $this->lead = $ulid;
        }
        $this->clean();
        if ($this->lead !== '' && $this->entitled() && Gate::allows('forms.view')) {
            $this->markRead($this->lead);
        }
    }

    /** A filter changed from the browser: start the list again from its top, with nothing ticked. */
    public function updated(string $property): void
    {
        if ($property === 'when' && $this->when !== LeadListQuery::RANGE) {
            $this->from = $this->to = '';
        }
        if ($property === 'from' || $property === 'to') {
            $this->when = self::dateOr($this->from) !== '' || self::dateOr($this->to) !== '' ? LeadListQuery::RANGE : '';
        }
        if (in_array(explode('.', $property)[0], ['tab', 'search', 'formF', 'when', 'from', 'to'], true)) {
            $this->clean();
            $this->until = null;
            $this->selected = [];
        }
    }

    public function openLead(string $ulid): void
    {
        $this->guardRead();
        $this->lead = $ulid;
        $this->markRead($ulid);
    }

    public function closeLead(): void
    {
        $this->guardRead();
        $this->lead = '';
    }

    public function loadMore(): void
    {
        $this->guardRead();
        $this->until = $this->listQuery($this->knownForms())->nextCursor($this->tab, $this->until, self::PAGE, self::PAGE);
    }

    public function removeForm(string $ulid): void
    {
        $this->guardRead();
        $this->formF = array_values(array_diff($this->formF, [$ulid]));
        $this->updated('formF');
    }

    public function resetFilterGroup(string $group): void
    {
        $this->guardRead();
        match ($group) {
            'form' => $this->formF = [],
            'when' => $this->when = $this->from = $this->to = '',
            default => null,
        };
        $this->updated('formF');
    }

    public function clearAllFilters(): void
    {
        $this->guardRead();
        $this->tab = 'all';
        $this->search = '';
        $this->formF = [];
        $this->when = $this->from = $this->to = '';
        $this->updated('formF');
    }

    /** The status of the lead being read (the status buttons, "Là thư rác", "Không phải thư rác"). */
    public function setStatus(string $ulid, string $status): void
    {
        $this->guardWrite();
        abort_unless(in_array($status, LeadListQuery::STATUSES, true), 422);
        $lead = FormSubmission::query()->where('ulid', $ulid)->select(['id', 'ulid', 'status'])->first();
        if ($lead === null) {
            $this->say(__('leads.gone'), 'error');

            return;
        }
        if ($lead->status === $status) {
            return;
        }
        $before = $lead->status;
        $lead->forceFill(['status' => $status])->save();
        $message = match (true) {
            $status === 'spam' => __('leads.toast_spam'),
            $before === 'spam' => __('leads.toast_not_spam'),
            default => __('leads.toast_status', ['status' => __('leads.st_'.$status)]),
        };
        $this->say($message, 'success', ['items' => [$ulid => $before], 'set' => $status]);
    }

    /**
     * The check boxes: a row's box and "Chọn tất cả" send the ulids they stand for. All of them
     * already ticked → they are unticked, else they are added. Nothing is trusted until an action
     * looks the leads up again.
     *
     * @param  list<string>  $ulids
     */
    public function toggleSelect(array $ulids): void
    {
        $this->guardWrite();
        $ulids = array_slice(array_values(array_unique(array_filter($ulids, 'is_string'))), 0, self::MAX_ROWS);
        $allIn = $ulids !== [] && array_diff($ulids, $this->selected) === [];
        $next = $allIn ? array_diff($this->selected, $ulids) : [...$this->selected, ...$ulids];
        $this->selected = array_slice(array_values(array_unique($next)), 0, self::MAX_ROWS);
    }

    public function clearSelection(): void
    {
        $this->guardRead();
        $this->selected = [];
    }

    /** "Đã xem (N)": the ticked leads that are new or done. */
    public function bulkRead(): void
    {
        $this->bulk('read', ['new', 'archived'], 'leads.bulk_done_read');
    }

    /** "Đã xử lý (N)": the ticked leads that are new or read. */
    public function bulkDone(): void
    {
        $this->bulk('archived', ['new', 'read'], 'leads.bulk_done_archived');
    }

    /** "Là thư rác (N)": the ticked leads that are not spam yet. */
    public function bulkSpam(): void
    {
        $this->bulk('spam', ['new', 'read', 'archived'], 'leads.bulk_done_spam');
    }

    /** "Hoàn tác": each lead goes back to the status it had, looked up again in this website. */
    public function undoLast(): void
    {
        $op = $this->undoOp;
        $this->undoOp = null;
        $this->toastMsg = '';
        if ($op === null) {
            return;
        }
        $this->guardWrite();
        $byStatus = [];
        foreach (array_slice($op['items'], 0, self::MAX_ROWS, true) as $ulid => $status) {
            if (in_array($status, LeadListQuery::STATUSES, true)) {
                $byStatus[$status][] = (string) $ulid;
            }
        }
        $back = 0;
        foreach ($byStatus as $status => $ulids) {
            // only a lead still in the status the action gave it: one a colleague changed since stays
            $back += FormSubmission::query()->whereIn('ulid', $ulids)->where('status', $op['set'])
                ->update(['status' => $status, 'updated_at' => now()]);
        }
        $this->say($back > 0 ? __('leads.toast_undone') : __('leads.gone'), $back > 0 ? 'success' : 'error');
    }

    /** Close the message the member (or its timer) names; a newer message stays. */
    public function closeToast(int $seq): void
    {
        $this->guardRead();
        if ($seq === $this->toastSeq) {
            $this->toastMsg = '';
            $this->undoOp = null;
        }
    }

    public function render(): View
    {
        // The plan first, then the permission: either card, and no lead is read.
        if (! $this->entitled()) {
            return $this->titled(view('admin::livewire.forms.plan', ['supportMail' => Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address'))]));
        }
        if (! Gate::allows('forms.view')) {
            return $this->titled(view('admin::livewire.forms.noperm', ['role' => RoleLabel::topOf($this->actingUser()), 'viewRoles' => $this->viewRoles()]));
        }

        $this->clean();
        $siteTime = app(SiteTime::class);
        $canManage = Gate::allows('forms.manage');

        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable for the page
            $read = DB::transaction(function () use ($canManage): array {
                $forms = $this->knownForms();
                $list = $this->listQuery($forms);
                $counts = $list->tabCounts();
                $rows = $list->rows($this->tab, $this->until, self::PAGE, self::MAX_ROWS);
                $open = $this->lead !== '' ? ($rows->firstWhere('ulid', $this->lead) ?? $this->findLead($this->lead)) : $rows->first();
                $formIds = array_values($forms->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all());

                return [
                    'forms' => $forms,
                    'counts' => $counts,
                    'rows' => $rows,
                    'open' => $open,
                    'formCounts' => $list->formCounts($this->tab, $formIds, $counts[$this->tab] ?? 0),
                    'whenCounts' => $list->whenCounts($this->tab),
                    'total' => $this->filtering() ? $list->total($this->tab) : null,
                    // the ticked leads still in what is shown, with their status (the bar's counts)
                    'picked' => $canManage && $this->selected !== [] ? $this->pickedStatuses($list) : [],
                    'any' => $counts['all'] + $counts['spam'] > 0 || $this->filtering() || $this->leadsExist(),
                ];
            });
        } catch (QueryException $e) {
            report($e);
            $read = ['forms' => new Collection, 'counts' => array_fill_keys(LeadListQuery::TABS, 0), 'rows' => new Collection, 'open' => null,
                'formCounts' => null, 'whenCounts' => [], 'total' => null, 'picked' => [], 'any' => true, 'failed' => true];
        }

        $this->selected = array_keys($read['picked']);
        /** @var Form|null $openForm */
        $openForm = $read['open'] !== null ? $read['forms']->firstWhere('id', $read['open']->form_id) : null;

        return $this->titled(view('admin::livewire.forms.inbox', [
            'forms' => $read['forms'],
            'counts' => $read['counts'],
            'rows' => $read['rows'],
            'open' => $read['open'],
            'openForm' => $openForm,
            'opened' => $this->lead !== '' && $read['open'] !== null,
            'formCounts' => $read['formCounts'],
            'whenCounts' => $read['whenCounts'],
            'total' => $read['total'],
            'picked' => $read['picked'],
            'empty' => ! $read['any'],
            'failed' => $read['failed'] ?? false,
            'canManage' => $canManage,
            'filterCount' => count($this->formF) + ($this->when !== '' ? 1 : 0),
            'siteTime' => $siteTime,
            'cap' => LeadListQuery::CAP,
        ]));
    }

    /**
     * @param  array<int, string>  $from  the statuses this action changes
     */
    private function bulk(string $to, array $from, string $doneKey): void
    {
        $this->guardWrite();
        $targets = FormSubmission::query()->whereIn('ulid', array_slice($this->selected, 0, self::MAX_ROWS))
            ->whereIn('status', $from)->select(['id', 'ulid', 'status'])->orderBy('id')->lockForUpdate()->get();
        if ($targets->isEmpty()) {
            return;
        }
        $undo = [];
        foreach ($targets as $lead) {
            $undo[$lead->ulid] = $lead->status;
        }
        FormSubmission::query()->whereIn('id', $targets->pluck('id')->all())->update(['status' => $to, 'updated_at' => now()]);
        $this->selected = [];
        $this->say(__($doneKey, ['count' => count($undo)]), 'success', ['items' => $undo, 'set' => $to]);
    }

    /** A manager opening a new lead has read it; anyone else leaves it as it was. */
    private function markRead(string $ulid): void
    {
        if (! Gate::allows('forms.manage')) {
            return;
        }
        FormSubmission::query()->where('ulid', $ulid)->where('status', 'new')->update(['status' => 'read', 'updated_at' => now()]);
    }

    private function findLead(string $ulid): ?FormSubmission
    {
        return FormSubmission::query()->where('ulid', $ulid)
            ->select(['id', 'ulid', 'form_id', 'name', 'email', 'phone', 'source_url', 'ip_address', 'user_agent', 'status', 'is_spam', 'data', 'created_at'])
            ->first();
    }

    /**
     * The site's forms (disabled ones too: their leads stay), each with its fields for the answers.
     *
     * @return Collection<int, Form>
     */
    private function knownForms(): Collection
    {
        $forms = Form::query()->select(['id', 'ulid', 'key', 'name', 'status'])->orderBy('id')->limit(200)->get();
        $fields = FormField::query()->whereIn('form_id', $forms->pluck('id')->all())
            ->select(['id', 'form_id', 'key', 'type', 'label', 'options', 'sort_order'])->orderBy('sort_order')->orderBy('id')->get()->groupBy('form_id');
        foreach ($forms as $form) {
            $form->setRelation('fields', $fields->get($form->id) ?? new Collection);
        }

        return $forms;
    }

    /** @param  Collection<int, Form>  $forms */
    private function listQuery(Collection $forms): LeadListQuery
    {
        $ids = array_values($forms->whereIn('ulid', $this->formF)->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all());

        // forms ticked but none of them this site's (gone, another website's, past the forms read): no rows, never the whole list
        return new LeadListQuery(trim($this->search), $ids, $this->when, self::dateOr($this->from), self::dateOr($this->to), app(SiteTime::class), $this->formF !== [] && $ids === []);
    }

    /**
     * The ticked leads that are still in what the screen shows: ulid => status.
     *
     * @return array<string, string>
     */
    private function pickedStatuses(LeadListQuery $list): array
    {
        $rows = LeadListQuery::inTab($list->base(), $this->tab)->whereIn('ulid', $this->selected)
            ->select(['ulid', 'status'])->limit(self::MAX_ROWS)->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->ulid] = $row->status;
        }

        return $out;
    }

    private function filtering(): bool
    {
        return trim($this->search) !== '' || $this->formF !== [] || $this->when !== '';
    }

    /** Whether the site has any lead at all (asked only when the groups under the filters are all empty). */
    private function leadsExist(): bool
    {
        return FormSubmission::query()->toBase()->limit(1)->exists();
    }

    /**
     * The roles of this site that can read leads, as people read them (the no-permission card).
     *
     * @return list<string>
     */
    private function viewRoles(): array
    {
        $roles = Role::query()->where('site_id', app(CurrentSite::class)->id())
            ->where(fn ($q) => $q->where('name', 'owner')->orWhereHas('permissions', fn ($p) => $p->where('name', 'forms.view')))
            ->select(['id', 'name', 'is_system', 'level'])->orderByDesc('level')->limit(20)->get();

        return array_values($roles->map(fn (Role $role): string => RoleLabel::for($role))->all());
    }

    /** Keep only values the screen knows: a pasted address with anything else falls back to the default. */
    private function clean(): void
    {
        if (! in_array($this->tab, LeadListQuery::TABS, true)) {
            $this->tab = 'all';
        }
        $this->search = mb_substr($this->search, 0, 100);
        $this->formF = array_slice(array_values(array_unique(array_filter($this->formF, fn (mixed $v): bool => preg_match('/^[0-9A-Za-z]{26}$/', Cast::string($v)) === 1))), 0, 50);
        if (! in_array($this->when, [...LeadListQuery::WHEN, LeadListQuery::RANGE], true)) {
            $this->when = '';
        }
        $this->from = self::dateOr($this->from);
        $this->to = self::dateOr($this->to);
        if ($this->when === LeadListQuery::RANGE && $this->from === '' && $this->to === '') {
            $this->when = '';
        }
        if (preg_match('/^[0-9A-Za-z]{26}$/', $this->lead) !== 1) {
            $this->lead = '';
        }
        $this->selected = array_slice(array_values(array_filter($this->selected, 'is_string')), 0, self::MAX_ROWS);
    }

    private static function dateOr(string $value): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    /**
     * @param  array{items: array<string, string>, set: string}|null  $undo
     */
    private function say(string $message, string $tone = 'success', ?array $undo = null): void
    {
        $this->toastMsg = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
        $this->undoOp = $undo;
    }

    private function guardRead(): void
    {
        abort_unless($this->entitled(), 403);
        abort_unless(Gate::allows('forms.view'), 403);
    }

    /** A write from the browser: refused unless the plan has the module and the member manages leads. */
    private function guardWrite(): void
    {
        abort_unless($this->entitled(), 403);
        abort_unless(Gate::allows('forms.manage'), 403);
    }

    /** Whether this site's plan includes "Khách liên hệ" (read once per request). */
    private function entitled(): bool
    {
        return in_array('contacts', app(Entitlement::class)->entitledModuleKeys(), true);
    }

    private function titled(View $view): View
    {
        $view->title(__('leads.title'));

        return $view;
    }

    private function actingUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}
