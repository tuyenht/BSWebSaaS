<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Platform;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Thông báo" in the platform console (design c-thong-bao, the platform plane; the console bell's
 * "Xem tất cả thông báo"). The console has no notification store yet, so the page draws the same
 * screen as a website's with its two tabs (Tất cả, Chưa đọc) empty: nothing is read from a server.
 */
#[Layout('admin::layouts.platform')]
final class Notifications extends Component
{
    private const array TABS = ['tat-ca', 'chua-doc'];

    #[Url(as: 'tab', except: 'tat-ca')]
    #[Locked]
    public string $tab = 'tat-ca';

    public function mount(): void
    {
        $this->operator();
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'tat-ca';
        }
    }

    public function show(string $tab): void
    {
        $this->operator();
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->tab = $tab;
    }

    public function render(): View
    {
        $this->operator();
        $unreadTab = $this->tab === 'chua-doc';

        $view = view('admin::livewire.notifications', [
            'platform' => true,
            'tab' => $this->tab,
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => __('admin.ntf.tab.'.$key), 'n' => '0'], self::TABS),
            'days' => [],
            'shown' => 0,
            'total' => 0,
            'more' => false,
            'cap' => 100,
            'supporting' => false,
            'unread' => 0,
            'empty' => [
                'icon' => $unreadTab ? 'ri-check-double' : 'ri-notification-off',
                'title' => __($unreadTab ? 'admin.ntf.empty.unread.t' : 'admin.ntf.empty.all.t'),
                'desc' => __($unreadTab ? 'admin.ntf.empty.unread.d' : 'admin.ntf.empty.all.d'),
                'btn' => $unreadTab ? __('admin.ntf.see_all') : null,
                'url' => null,
                'all' => $unreadTab,
            ],
        ]);
        $view->title(__('admin.notifications'));

        return $view;
    }

    /** A platform operator — the Livewire endpoint skips the console's middleware, so each request checks. */
    private function operator(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && app(PlatformAccess::class)->isOperator($user), 403);

        return $user;
    }
}
