<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\NotificationLink;
use App\Modules\Admin\Support\NotificationRows;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Registry\NotificationKinds;
use App\Modules\Notifications\Support\NotificationFeed;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Workflow\Services\ReviewQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Thông báo" — the reader's notifications on this website, every one of the 90 days they are kept
 * (design c-thong-bao, the bell's "Xem tất cả thông báo"): grouped by the site's day, newest first,
 * one tab per group (Tất cả, Chưa đọc, Duyệt bài, Khách liên hệ, Bảo mật, Hệ thống — ?tab=chua-doc…).
 *
 *  - Only the reader's own rows on this site: user_id here, site_id through the global scope + RLS.
 *  - Pages of eight by keyset ("Tải thêm"): the oldest notice on screen is the cursor (a ulid); the
 *    page draws again everything from the newest down to it. Each tab's count stops at 100 ("99+").
 *  - Opening one marks it read and goes where it points (NotificationLink: ulid addresses only);
 *    marking read, one or all, offers "Hoàn tác" in the shared toast.
 *  - A support person signed in as the member reads the list but never marks anything read for them.
 */
#[Layout('admin::layouts.app')]
final class Notifications extends Component
{
    /** The board shows eight, and eight more on each "Tải thêm". */
    public const int PAGE = 8;

    /** From this many a tab's count reads "99+". */
    public const int CAP = 100;

    /** The most rows one page draws again (eight per "Tải thêm"; a guard, not a limit people meet). */
    private const int MOST = 1000;

    /** The tab's address word => [the group it lists, or null for every group; unread only]. */
    private const array TABS = [
        'tat-ca' => [null, false],
        'chua-doc' => [null, true],
        'duyet-bai' => ['workflow', false],
        'khach-lien-he' => ['lead', false],
        'bao-mat' => ['security', false],
        'he-thong' => ['system', false],
    ];

    /** What the member needs (any one) to still do what a notice of the kind asks. */
    private const array NEEDS = [
        'lead.new' => ['forms.view'],
        'workflow.pending_review' => ['entries.review', 'entries.approve'],
        'workflow.assigned' => ['entries.review', 'entries.approve'],
        'workflow.returned' => ['entries.edit.own', 'entries.edit.any'],
        'workflow.published' => ['entries.view'],
        'system.domain_problem' => ['domains.manage'],
        'security.domain_taken_over' => ['domains.manage'],
        'system.storage_near_limit' => ['media.view'],
    ];

    #[Url(as: 'tab', except: 'tat-ca')]
    #[Locked]
    public string $tab = 'tat-ca';

    /** The oldest notice on screen once "Tải thêm" was pressed (null: the first page). */
    #[Locked]
    public ?string $until = null;

    public string $toast = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public bool $toastUndo = false;

    /** What "Hoàn tác" puts back: one notice (its ulid), or the ones "Đánh dấu đã đọc tất cả" marked (their read time). */
    #[Locked]
    public ?string $undoUlid = null;

    #[Locked]
    public ?string $undoStamp = null;

    public function mount(): void
    {
        $this->reader();
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'tat-ca';
        }
    }

    /** A tab: its first page. */
    public function show(string $tab): void
    {
        $this->reader();
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $this->tab = $tab;
        $this->until = null;
    }

    /** "Tải thêm": eight more, older ones. */
    public function more(): void
    {
        $user = $this->reader();
        $feed = app(NotificationFeed::class);
        [$kinds, $unread] = $this->filter();

        $last = $this->until ?? $feed->page($user, $kinds, $unread, self::PAGE)->last()?->ulid;
        if ($last === null) {
            return;
        }
        $this->until = $feed->page($user, $kinds, $unread, self::PAGE, after: $last)->last()->ulid ?? $last;
    }

    /** "Đã đọc" on one notice. */
    public function read(string $ulid): void
    {
        $user = $this->reader();
        abort_unless(Str::isUlid($ulid), 422);

        if ($this->supporting()) {
            $this->say(__('admin.bell.read_all_support'), tone: 'info');

            return;
        }
        if (app(NotificationFeed::class)->markRead($user, $ulid) === 1) {
            $this->undoUlid = $ulid;
            $this->undoStamp = null;
            $this->say(__('admin.ntf.read_done'), undo: true);
        } elseif (! $this->mine($user, $ulid)) {
            $this->say(__('admin.bell.gone'), tone: 'info');
        }
    }

    public function readAll(): void
    {
        $user = $this->reader();

        if ($this->supporting()) {
            $this->say(__('admin.bell.read_all_support'), tone: 'info');

            return;
        }

        // read_at keeps whole seconds (timestampTz, precision 0): "Hoàn tác" puts back the rows that
        // carry this second, i.e. these ones plus any the reader marked in the same second.
        $stamp = now()->startOfSecond()->format('Y-m-d H:i:sP');
        $count = app(NotificationFeed::class)->markAllRead($user, $stamp);
        if ($count === 0) {
            $this->say(__('admin.ntf.none_unread'), tone: 'info');

            return;
        }
        $this->undoUlid = null;
        $this->undoStamp = $stamp;
        $this->say(__('admin.ntf.read_all_done', ['count' => $count]), undo: true);
    }

    /** "Hoàn tác" in the toast: the notices just marked read are unread again. */
    public function undo(): void
    {
        $user = $this->reader();
        if ($this->supporting()) {
            return;
        }

        $feed = app(NotificationFeed::class);
        if ($this->undoUlid !== null) {
            $feed->markUnread($user, $this->undoUlid);
        } elseif ($this->undoStamp !== null) {
            $feed->restoreRead($user, $this->undoStamp);
        }
        $this->undoUlid = null;
        $this->undoStamp = null;
        $this->say(__('admin.ntf.undone'));
    }

    /**
     * One notice opened: marked read (unless a support person is looking), then followed. One that
     * is gone meanwhile (cleaned up; never another person's, which answers the same) is not an error
     * page: the list redraws without it and the toast says so.
     */
    public function open(string $ulid): void
    {
        $user = $this->reader();
        abort_unless(Str::isUlid($ulid), 422);

        $notification = SiteNotification::query()->where('user_id', $user->getKey())->where('ulid', $ulid)
            ->select(['id', 'ulid', 'kind', 'data'])->first();
        if ($notification === null) {
            $this->say(__('admin.bell.gone'), tone: 'info');

            return;
        }

        if (! $this->supporting()) {
            app(NotificationFeed::class)->markRead($user, $ulid);
        }

        $link = NotificationLink::for($notification);
        if ($link !== null) {
            $this->redirect($link['url'], navigate: true);
        }
    }

    public function dismissToast(?int $seq = null): void
    {
        $this->reader();
        if ($seq === null || $seq === $this->toastSeq) {
            $this->toast = '';
            $this->toastUndo = false;
        }
    }

    public function render(): View
    {
        $user = $this->reader();
        $feed = app(NotificationFeed::class);
        [$kinds, $unread] = $this->filter();

        $rows = $this->until === null
            ? $feed->page($user, $kinds, $unread, self::PAGE)
            : $feed->page($user, $kinds, $unread, self::MOST, through: $this->until);
        if ($rows->isEmpty() && $this->until !== null) {
            // The oldest notice on screen was cleaned up meanwhile: start again from the first page.
            $this->until = null;
            $rows = $feed->page($user, $kinds, $unread, self::PAGE);
        }
        $last = $rows->last();
        $more = $last !== null && $feed->page($user, $kinds, $unread, 1, after: $last->ulid)->isNotEmpty();

        $specs = [];
        foreach (self::TABS as $key => [$group, $onlyUnread]) {
            $specs[$key] = ['kinds' => $group !== null ? NotificationKinds::ofGroup($group) : null, 'unread' => $onlyUnread];
        }
        $counts = $feed->countsUpTo($user, self::CAP, $specs);

        $days = NotificationRows::days($rows);
        $can = [];
        foreach ($days as $d => $day) {
            foreach ($day['items'] as $i => $item) {
                $needs = self::NEEDS[$item['kind']] ?? [];
                $denied = $item['url'] !== null && $needs !== []
                    && ! ($can[$item['kind']] ??= collect($needs)->contains(fn (string $p): bool => $user->can($p)));
                $days[$d]['items'][$i] += ['denied' => $denied, 'meta' => __('admin.ntf.group.'.$item['group']).' · '.$item['ago']];
            }
        }

        $supporting = $this->supporting();
        $view = view('admin::livewire.notifications', [
            'platform' => false,
            'tab' => $this->tab,
            'tabs' => $this->tabs($counts),
            'days' => $days,
            'shown' => $rows->count(),
            'total' => $counts[$this->tab],
            'more' => $more,
            'cap' => self::CAP,
            'supporting' => $supporting,
            'unread' => $counts['chua-doc'],
            'empty' => $rows->isEmpty() ? $this->emptyState($user) : null,
        ]);
        $view->title(__('admin.notifications'));

        return $view;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, n: string}>
     */
    private function tabs(array $counts): array
    {
        $tabs = [];
        foreach (array_keys(self::TABS) as $key) {
            $n = $counts[$key] ?? 0;
            $tabs[] = ['key' => $key, 'label' => __('admin.ntf.tab.'.$key), 'n' => $n >= self::CAP ? (self::CAP - 1).'+' : (string) $n];
        }

        return $tabs;
    }

    /**
     * What an empty tab says, and the button that leads on (as the board draws each tab and role).
     *
     * @return array{icon: string, title: string, desc: string, btn: string|null, url: string|null, all: bool}
     */
    private function emptyState(User $user): array
    {
        $state = fn (string $icon, string $key, ?string $btn = null, ?string $url = null, bool $all = false): array => [
            'icon' => $icon, 'title' => __('admin.ntf.empty.'.$key.'.t'), 'desc' => __('admin.ntf.empty.'.$key.'.d'),
            'btn' => $btn, 'url' => $url, 'all' => $all,
        ];
        $toAll = __('admin.ntf.see_all');

        return match ($this->tab) {
            // The board's "Tùy chọn thông báo" lives on the account page, not built yet: no button.
            'tat-ca' => $state('ri-notification-off', 'all'),
            'chua-doc' => $state('ri-check-double', 'unread', $toAll, all: true),
            'duyet-bai' => match (true) {
                app(ReviewQueue::class)->activeWorkflows()->isEmpty() => $state('ri-send-plane', 'wf_off', $toAll, all: true),
                Gate::allows('review', Entry::class) => $state('ri-git-pull-request', 'wf_review', __('admin.ntf.empty.wf_review.b'), route('admin.entries.review')),
                default => $state('ri-git-pull-request', 'wf_mine', $toAll, all: true),
            },
            'khach-lien-he' => $user->can('forms.view') && in_array('contacts', app(Entitlement::class)->entitledModuleKeys(), true)
                ? $state('ri-mail', 'lead', __('admin.ntf.empty.lead.b'), route('admin.forms.index'))
                : $state('ri-lock', 'lead_no', $toAll, all: true),
            'bao-mat' => $state('ri-shield-check', 'security', __('admin.ntf.empty.security.b'), route('admin.settings.security')),
            default => $user->can('domains.manage')
                ? $state('ri-checkbox-circle', 'system', __('admin.ntf.empty.system.b'), route('admin.settings.domains'))
                : $state('ri-checkbox-circle', 'system_no', $toAll, all: true),
        };
    }

    /** @return array{0: list<string>|null, 1: bool} */
    private function filter(): array
    {
        [$group, $unread] = self::TABS[$this->tab] ?? self::TABS['tat-ca'];

        return [$group !== null ? NotificationKinds::ofGroup($group) : null, $unread];
    }

    private function mine(User $user, string $ulid): bool
    {
        return SiteNotification::query()->where('user_id', $user->getKey())->where('ulid', $ulid)->exists();
    }

    private function say(string $message, bool $undo = false, string $tone = 'success'): void
    {
        $this->toast = $message;
        $this->toastUndo = $undo;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    /**
     * The signed-in member of this site — the Livewire endpoint skips the admin's member check, so
     * every request re-checks it here (as the bell does).
     */
    private function reader(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->roles->isNotEmpty() || app(PlatformAccess::class)->isSuperAdmin($user)), 403);

        return $user;
    }

    private function supporting(): bool
    {
        return app(SupportSession::class)->current() !== null;
    }
}
