<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Support\NotificationLink;
use App\Modules\Admin\Support\NotificationRows;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Support\NotificationFeed;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The bell in the website admin's top bar (design 01 "Thông báo"): the reader's unread count on
 * every page, and their eight newest notifications on this website once the panel opens.
 *
 *  - Every page pays one bounded count (stops at 100: the badge reads "99+"). The list is read only
 *    after the panel first opens (load()), so a page that never opens it reads nothing more.
 *  - Only the reader's own rows on this site: user_id here, site_id through the global scope + RLS.
 *  - Opening one marks it read and goes where it points (NotificationLink: ulid addresses only).
 *  - A support person signed in as the member reads the bell but never marks anything read for them.
 */
final class NotificationBell extends Component
{
    /** The board's panel shows the eight newest; the rest live on the Notifications page. */
    public const int PANEL = 8;

    /** From this many unread on the badge reads "99+". */
    public const int CAP = 100;

    /** The panel has been opened on this page, so its list is drawn. */
    #[Locked]
    public bool $loaded = false;

    public function load(): void
    {
        $this->reader();
        $this->loaded = true;
    }

    /**
     * One notification clicked: marked read (unless a support person is looking), then followed.
     * One that is gone meanwhile (cleaned up while the panel stayed open; never another person's,
     * which answers the same) is not an error page: the panel redraws without it and a toast says so.
     */
    public function open(string $ulid): void
    {
        $user = $this->reader();
        abort_unless(Str::isUlid($ulid), 422);

        $notification = SiteNotification::query()->where('user_id', $user->getKey())->where('ulid', $ulid)
            ->select(['id', 'ulid', 'kind', 'data'])->first();
        if ($notification === null) {
            $this->dispatch('bs-toast', text: __('admin.bell.gone'));

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

    public function readAll(): void
    {
        $user = $this->reader();

        if ($this->supporting()) {
            $this->dispatch('bs-toast', text: __('admin.bell.read_all_support'));

            return;
        }

        $count = app(NotificationFeed::class)->markAllRead($user);
        $this->dispatch('bs-toast', text: __('admin.bell.read_all_done', ['count' => $count]));
    }

    public function render(): View
    {
        $user = $this->reader();
        $feed = app(NotificationFeed::class);
        $unread = $feed->unreadCountUpTo($user, self::CAP);

        return view('admin::livewire.notification-bell', [
            'unread' => $unread,
            'badge' => $unread >= self::CAP ? (self::CAP - 1).'+' : (string) $unread,
            'supporting' => $this->supporting(),
            'days' => $this->loaded ? NotificationRows::days($feed->latest($user, self::PANEL)) : [],
        ]);
    }

    /**
     * The signed-in member of this site — the Livewire endpoint skips the admin's member check, so
     * every request re-checks it here (the page render has it warm from the menu build).
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
