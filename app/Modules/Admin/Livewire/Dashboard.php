<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Errors\SupportChannels;
use App\Modules\Admin\Support\Dashboard\DashboardReader;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Tổng quan" — the website admin's first screen after signing in. Each card shows only what the
 * member's permissions and the site's modules allow (the same checks the screens it links to make),
 * reads a few rows at most (DashboardReader), and fails on its own: a card whose read breaks shows
 * "Thử lại" while the others stay right.
 *
 * Read-only apart from one choice the member keeps: putting away the "Bắt đầu" list on this
 * website (users.preferences, this member only), which "Hiện lại danh sách Bắt đầu" brings back.
 */
#[Layout('admin::layouts.app')]
final class Dashboard extends Component
{
    /** The cards that read the database, each retried on its own. */
    private const array CARDS = ['todo', 'start', 'review', 'mine', 'recent'];

    public string $toastMsg = '';

    public int $toastSeq = 0;

    public bool $toastUndo = false;

    /** "Thử lại" on a card: the next render reads it again (every card is read on each render). */
    public function retry(string $card): void
    {
        abort_unless(in_array($card, self::CARDS, true), 404);
    }

    /** "Ẩn danh sách này": the member puts the first-steps list away on this website. */
    public function hideStart(): void
    {
        abort_unless(Gate::allows('settings.manage'), 403);
        $user = $this->actor();
        UserPreferences::set($user, $this->startPath($this->siteUlid()), true);
        UserPreferences::reload($user);
        $this->say(__('dash.start_hidden_toast'), true);
    }

    /** "Hiện lại danh sách Bắt đầu" (or "Hoàn tác" right after hiding it). */
    public function showStart(): void
    {
        abort_unless(Gate::allows('settings.manage'), 403);
        $user = $this->actor();
        UserPreferences::forget($user, $this->startPath($this->siteUlid()));
        UserPreferences::reload($user);
        $this->toastMsg = '';
        $this->toastUndo = false;
    }

    public function closeToast(int $seq): void
    {
        if ($seq === $this->toastSeq) {
            $this->toastMsg = '';
            $this->toastUndo = false;
        }
    }

    public function render(): View
    {
        $user = $this->actor();
        $reader = app(DashboardReader::class);
        $modules = app(Entitlement::class)->entitledModuleKeys();
        $site = Site::query()->whereKey(app(CurrentSite::class)->id())->first(['id', 'ulid', 'name']);

        $canDomains = $user->can('domains.manage');
        $canLeads = $user->can('forms.view') && in_array('contacts', $modules, true);
        $isReviewer = Gate::allows('review', Entry::class);
        $isWriter = $user->can('entries.edit.own') && ! $user->can('entries.edit.any');
        $editor = $user->can('entries.create') || $user->can('entries.edit.own') || $user->can('entries.edit.any');
        $workflowOn = ($isReviewer || $isWriter) && $this->read(fn (): bool => $reader->workflowOn())['data'] === true;

        // 1 · Việc cần làm hôm nay — the parts the member may act on.
        $todo = $this->read(function () use ($reader, $canDomains, $canLeads): array {
            return [
                'leads' => $canLeads ? $reader->unreadLeads() : null,
                'groups' => $canDomains ? $reader->domainGroups() : null,
            ];
        });
        $groups = $todo['data']['groups'] ?? null;

        // 2 · Bắt đầu — only for whoever runs the website's settings.
        $start = $user->can('settings.manage')
            ? $this->read(fn (): array => $reader->startSteps($user, $canDomains ? ($groups ?? $reader->domainGroups()) : null))
            : null;

        // 3 · Duyệt bài / Bài của tôi / Nội dung gần đây
        $review = $isReviewer && $workflowOn ? $this->read(fn (): array => $reader->review($user)) : null;
        $mine = $isWriter ? $this->read(fn (): array => $reader->mine($user, $workflowOn)) : null;
        $recent = $user->can('entries.view') ? $this->read(fn (): array => $reader->recent($user, $editor)) : null;

        $view = view('admin::livewire.dashboard', [
            'siteName' => $site instanceof Site ? $site->name : '',
            'todo' => $todo,
            'domains' => $groups !== null ? $reader->domainProblems($groups) : [],
            'start' => $start,
            'startHidden' => $site instanceof Site && $this->startHidden($user, $site->ulid),
            'review' => $review,
            'mine' => $mine,
            'workflowOn' => $workflowOn,
            'recent' => $recent,
            'editor' => $editor,
            'canCreate' => $user->can('entries.create'),
            'quick' => $this->quickActions($user, $modules),
            'help' => SupportChannels::all(),
            'postsSection' => $this->postsSection(),
            'me' => $user,
            'cap' => DashboardReader::CAP,
        ]);
        $view->title(__('admin.dashboard'));

        return $view;
    }

    /**
     * One card's read inside a savepoint of the request's tenancy transaction: a failed read is
     * reported and leaves the transaction usable for the rest of the page.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return array{ok: bool, data: T|null}
     */
    private function read(Closure $read): array
    {
        try {
            return ['ok' => true, 'data' => DB::transaction($read)];
        } catch (QueryException $e) {
            report($e);

            return ['ok' => false, 'data' => null];
        }
    }

    /**
     * "Làm nhanh": only the shortcuts the member can use on this website.
     *
     * @param  list<string>  $modules
     * @return list<array{label: string, icon: string, url: string}>
     */
    private function quickActions(User $user, array $modules): array
    {
        $on = fn (string $module): bool => in_array($module, $modules, true);
        $quick = [];
        if ($user->can('entries.create')) {
            $quick[] = ['label' => __('dash.quick_page'), 'icon' => 'ri-file-add', 'url' => route('admin.entries.create', ['section' => 'pages'])];
        }
        if ($user->can('catalog.manage') && $on('catalog')) {
            $quick[] = ['label' => __('dash.quick_product'), 'icon' => 'ri-box-3', 'url' => route('admin.catalog.create')];
        }
        if ($user->can('media.upload') && $on('media')) {
            $quick[] = ['label' => __('dash.quick_upload'), 'icon' => 'ri-upload-2', 'url' => route('admin.media.index')];
        }
        if ($user->can('users.invite') && $user->can('users.manage') && $on('users')) {
            $quick[] = ['label' => __('dash.quick_invite'), 'icon' => 'ri-user-add', 'url' => route('admin.users.index')];
        }
        if ($user->can('menus.manage') && $on('menus')) {
            $quick[] = ['label' => __('dash.quick_menus'), 'icon' => 'ri-menu-2', 'url' => route('admin.menus.index')];
        }

        return $quick;
    }

    /** The first section that holds posts — where "Mở danh sách bài" lands. */
    private function postsSection(): ?string
    {
        $key = ContentSection::query()->where('entry_type', 'post')->orderBy('sort_order')->orderBy('id')->value('key');

        return is_string($key) ? $key : null;
    }

    private function startHidden(User $user, string $siteUlid): bool
    {
        $at = Cast::arr($user->preferences);
        foreach ($this->startPath($siteUlid) as $key) {
            $at = is_array($at) ? ($at[$key] ?? null) : null;
        }

        return $at === true;
    }

    /**
     * Where the choice is kept: per website (a member of several websites puts each list away on
     * its own), under the website's code.
     *
     * @return non-empty-list<string>
     */
    private function startPath(string $siteUlid): array
    {
        return ['dashboard', 'start_hidden', $siteUlid];
    }

    private function siteUlid(): string
    {
        return Cast::string(Site::query()->whereKey(app(CurrentSite::class)->id())->value('ulid'));
    }

    private function say(string $message, bool $undo = false): void
    {
        $this->toastMsg = $message;
        $this->toastUndo = $undo;
        $this->toastSeq++;
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
