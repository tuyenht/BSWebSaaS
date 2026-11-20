<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Settings;

use App\Modules\Admin\Support\Redirects\RedirectBook;
use App\Modules\Admin\Support\Redirects\RedirectDirectory;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\UserPreferences;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Chuyển hướng» (design board c-chuyen-huong): the website's redirects — the ones the system adds
 * when a published address changes and the ones a person adds — in service, with a warning, or in
 * the trash; added and edited in a dialog, moved to the trash with «Hoàn tác», restored, deleted for
 * good from the trash, switched between «Chuyển hẳn» (301) and «Chuyển tạm» (302).
 *
 * Needs `settings.manage`, like the other site settings. The route has no gate of its own (as
 * «Tên miền»): a member without the right gets the board's no-permission card and nothing is read.
 * Livewire's update endpoint passes no route middleware, so every public method checks the right
 * again before it reads or changes anything.
 *
 * Rows are named by their key (ulid) in the page and in every call, never by the row id; the page
 * cursor is an encrypted note (RedirectDirectory). Group, filters, search, sort and the cursor live
 * in the URL; the page size is the member's own (users.preferences.lists."settings.redirects").
 * Only an address on this website is accepted as the new address: a redirect to another website is
 * not offered.
 */
#[Layout('admin::layouts.app')]
final class Redirects extends Component
{
    public const string PREFS = 'settings.redirects';

    /** @var list<int> */
    public const array PER_PAGE = [10, 20, 50];

    /** How many rows may be ticked at once (across pages). */
    private const int PICK_MAX = 200;

    #[Url(except: RedirectDirectory::TAB_LIVE)]
    public string $tab = RedirectDirectory::TAB_LIVE;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> */
    #[Url(as: 'lang', except: [])]
    public array $locales = [];

    /** @var list<string> */
    #[Url(as: 'src', except: [])]
    public array $sources = [];

    /** @var list<string> 301 / 302, as text */
    #[Url(as: 'kind', except: [])]
    public array $codes = [];

    #[Url(except: RedirectDirectory::SORT_NEW)]
    public string $sort = RedirectDirectory::SORT_NEW;

    #[Url(as: 'p', except: '')]
    public string $cursor = '';

    public int $per = 20;

    /** @var list<string> the ticked rows' keys */
    public array $picked = [];

    // ── The add / edit dialog ───────────────────────────────────────────────

    public bool $edOpen = false;

    /** The row being edited; null while adding. */
    #[Locked]
    public ?string $edUlid = null;

    /** What the system made the row for («bài “X”»), shown on an automatic row; '' otherwise. */
    #[Locked]
    public string $edAutoFor = '';

    #[Locked]
    public bool $edAuto = false;

    public string $edLocale = '';

    public string $edFrom = '';

    public string $edTo = '';

    public int $edCode = 301;

    // ── «Xóa vĩnh viễn» ─────────────────────────────────────────────────────

    public bool $purgeOpen = false;

    #[Locked]
    public string $purging = '';

    #[Locked]
    public string $purgingPath = '';

    // ── The message at the bottom ───────────────────────────────────────────

    #[Locked]
    public string $toast = '';

    #[Locked]
    public string $toastTone = 'success';

    #[Locked]
    public int $toastSeq = 0;

    /** @var list<string> the rows the last move to the trash took, for «Hoàn tác» */
    #[Locked]
    public array $undoKeys = [];

    /** The site's public host, read once (a Livewire update may not carry it). */
    #[Locked]
    public string $host = '';

    public function mount(): void
    {
        $this->host = SeoFields::publicHost();
        if (! $this->allowed()) {
            return;
        }
        $view = Cast::arr(Cast::arr(Cast::arr($this->member()->preferences)['lists'] ?? null)[self::PREFS] ?? null);
        $this->per = in_array($view['per'] ?? null, self::PER_PAGE, true) ? Cast::int($view['per']) : 20;
        $this->clean();
    }

    public function updated(string $property): void
    {
        $this->authorized();
        $name = Str::before($property, '.');
        $this->clean();
        if (in_array($name, ['search', 'tab', 'locales', 'sources', 'codes', 'per'], true)) {
            $this->cursor = '';
        }
        if ($name === 'tab') {
            $this->picked = [];
        }
        if ($name === 'per') {
            UserPreferences::set($this->member(), ['lists', self::PREFS, 'per'], $this->per);
        }
        // a field of the dialog is checked as it is left (wire:model.blur)
        if (in_array($name, ['edFrom', 'edTo', 'edLocale'], true) && $this->edOpen) {
            $this->checkFields($name === 'edTo' ? ['to'] : ($name === 'edFrom' ? ['from'] : ['from', 'to']));
        }
    }

    // ── Paging, sorting, filters ────────────────────────────────────────────

    public function older(string $cursor): void
    {
        $this->authorized();
        $this->cursor = mb_substr($cursor, 0, 2000);
    }

    public function newer(string $cursor): void
    {
        $this->authorized();
        $this->cursor = mb_substr($cursor, 0, 2000);
    }

    public function setSort(string $key): void
    {
        $this->authorized();
        $this->sort = in_array($key, RedirectDirectory::SORTS, true) ? $key : RedirectDirectory::SORT_NEW;
        $this->cursor = '';
    }

    /** «Bỏ lọc»: the search and the filters go; the group, the order and the page size stay. */
    public function clearFilters(): void
    {
        $this->authorized();
        $this->search = '';
        $this->locales = $this->sources = $this->codes = [];
        $this->cursor = '';
    }

    public function clearSearch(): void
    {
        $this->authorized();
        $this->search = '';
        $this->cursor = '';
    }

    /** One condition off (a chip's ✕), or with $value "*" the whole section («Bỏ chọn» in its panel). */
    public function removeFilter(string $kind, string $value): void
    {
        $this->authorized();
        match ($kind) {
            'lang' => $this->locales = self::without($this->locales, $value),
            'src' => $this->sources = self::without($this->sources, $value),
            'kind' => $this->codes = self::without($this->codes, $value),
            default => null,
        };
        $this->cursor = '';
    }

    // ── Selection ───────────────────────────────────────────────────────────

    /** @param  list<string>  $ulids */
    public function togglePick(array $ulids): void
    {
        $this->authorized();
        $ulids = self::keys($ulids);
        $allIn = $ulids !== [] && array_diff($ulids, $this->picked) === [];
        $next = $allIn ? array_diff($this->picked, $ulids) : [...$this->picked, ...$ulids];
        $this->picked = array_slice(array_values(array_unique($next)), 0, self::PICK_MAX);
    }

    public function clearPicked(): void
    {
        $this->authorized();
        $this->picked = [];
    }

    // ── Add / edit ──────────────────────────────────────────────────────────

    public function openAdd(): void
    {
        $this->authorized();
        $this->resetErrorBag();
        $this->fill(['edUlid' => null, 'edAuto' => false, 'edAutoFor' => '', 'edFrom' => '', 'edTo' => '', 'edCode' => 301,
            'edLocale' => $this->defaultLocale(), 'edOpen' => true]);
    }

    public function openEdit(string $ulid): void
    {
        $this->authorized();
        $this->resetErrorBag();
        $row = $this->book()->find($ulid, false);
        if ($row === null) {
            $this->notify(__('admin.rd.gone'), 'error');

            return;
        }
        $this->fill([
            'edUlid' => $row->ulid, 'edLocale' => $row->locale, 'edFrom' => $row->from_path, 'edTo' => $row->to_path,
            'edCode' => $row->status_code, 'edAuto' => $row->source === Redirect::SOURCE_AUTO,
            'edAutoFor' => $row->source === Redirect::SOURCE_AUTO ? $this->autoFor($row) : '', 'edOpen' => true,
        ]);
    }

    public function closeEditor(): void
    {
        $this->authorized();
        $this->edOpen = false;
        $this->edUlid = null;
        $this->resetErrorBag();
    }

    /** The dialog's «Kiểu chuyển» cards pass the kind as text ("301" / "302"). */
    public function setEdCode(string $code): void
    {
        $this->authorized();
        $this->edCode = in_array((int) $code, Redirect::STATUS_CODES, true) ? (int) $code : 301;
    }

    public function save(): void
    {
        $this->authorized();
        $this->clean();
        if (! $this->checkFields(['from', 'to'])) {
            $this->dispatch('rd-first-error');

            return;
        }

        $adding = $this->edUlid === null;
        $row = $this->book()->save($this->edUlid, $this->edLocale, $this->edFrom, $this->edTo, $this->edCode, $this->hosts());
        if ($row === null) {
            // gone meanwhile, or its old address was taken meanwhile: say which
            if (! $this->checkFields(['from', 'to'])) {
                return;
            }
            $this->notify(__('admin.rd.gone'), 'error');

            return;
        }

        $this->edOpen = false;
        $this->edUlid = null;
        $this->resetErrorBag();
        $this->notify($adding
            ? __('admin.rd.toast_added', ['from' => $row->from_path, 'to' => $row->to_path])
            : __('admin.rd.toast_saved', ['from' => $row->from_path]));
    }

    // ── Trash, restore, kind ────────────────────────────────────────────────

    public function trashOne(string $ulid): void
    {
        $this->authorized();
        $this->moveToTrash([$ulid]);
    }

    public function trashPicked(): void
    {
        $this->authorized();
        $this->moveToTrash($this->picked);
    }

    /** «Hoàn tác» on the message: the rows the last move took come back. */
    public function undoTrash(): void
    {
        $this->authorized();
        $keys = $this->undoKeys;
        $this->undoKeys = [];
        if ($keys === []) {
            return;
        }
        $result = $this->book()->restore($keys);
        $this->notify($result['blocked'] > 0 ? __('admin.rd.restore_blocked', ['n' => $this->num($result['blocked'])]) : __('admin.rd.toast_undone'),
            $result['blocked'] > 0 ? 'error' : 'success');
    }

    public function restoreOne(string $ulid): void
    {
        $this->authorized();
        $this->bringBack([$ulid]);
    }

    public function restorePicked(): void
    {
        $this->authorized();
        $this->bringBack($this->picked);
    }

    /** ⋮ › «Đổi sang chuyển tạm / chuyển hẳn» on one row. */
    public function switchCode(string $ulid): void
    {
        $this->authorized();
        $row = $this->book()->find($ulid, false);
        if ($row === null) {
            $this->notify(__('admin.rd.gone'), 'error');

            return;
        }
        $this->book()->setCode([$ulid], $row->status_code === 301 ? 302 : 301);
        $this->notify(__('admin.rd.toast_kind', ['from' => $row->from_path]));
    }

    /** «Chuyển hẳn (N)»: the ticked rows that are «Chuyển tạm» become «Chuyển hẳn». */
    public function makePickedPermanent(): void
    {
        $this->authorized();
        $n = $this->book()->setCode($this->picked, 301);
        if ($n === 0) {
            $this->notify(__('admin.rd.bulk_none_temp'), 'info');

            return;
        }
        $this->notify(__('admin.rd.toast_kind_n', ['n' => $this->num($n)]));
    }

    public function askPurge(string $ulid): void
    {
        $this->authorized();
        $row = $this->book()->find($ulid, true);
        if ($row === null) {
            $this->notify(__('admin.rd.gone'), 'error');

            return;
        }
        $this->purging = (string) $row->ulid;
        $this->purgingPath = $row->from_path;
        $this->purgeOpen = true;
    }

    public function cancelPurge(): void
    {
        $this->authorized();
        $this->purgeOpen = false;
        $this->purging = $this->purgingPath = '';
    }

    public function purgeNow(): void
    {
        $this->authorized();
        $key = $this->purging;
        $this->purgeOpen = false;
        $this->purging = $this->purgingPath = '';
        if ($key === '') {
            return;
        }
        if (! $this->book()->purge($key)) {
            $this->notify(__('admin.rd.gone'), 'error');

            return;
        }
        $this->picked = array_values(array_diff($this->picked, [$key]));
        $this->notify(__('admin.rd.toast_purged'));
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
        $title = __('admin.rd.title');
        if (! $this->allowed()) {
            $view = view('admin::livewire.settings.redirects', ['allowed' => false, 'role' => RoleLabel::topOf($this->member()) ?? '']);
            $view->title($title);

            return $view;
        }

        $this->clean();
        $directory = app(RedirectDirectory::class);
        $filters = $this->filters();
        $failed = false;
        $data = ['rows' => [], 'older' => null, 'newer' => null, 'page' => 1,
            'counts' => ['tabs' => [], 'locales' => [], 'sources' => [], 'codes' => []], 'matched' => 0, 'picks' => []];
        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable
            $data = DB::transaction(function () use ($directory, $filters): array {
                $counts = $directory->counts($this->tab);
                // with no filter and no search the group's own count is the match count
                $narrowed = $this->search !== '' || $filters['locales'] !== [] || $filters['sources'] !== [] || $filters['codes'] !== [];

                return [
                    ...$directory->page($this->tab, $filters, $this->search, $this->sort, $this->cursor, $this->per),
                    'counts' => $counts,
                    'matched' => $narrowed ? $directory->matchCount($this->tab, $filters, $this->search) : ($counts['tabs'][$this->tab] ?? 0),
                    'picks' => $this->picksNow(),
                ];
            });
        } catch (QueryException $e) {
            report($e);
            $failed = true;
        }

        $siteLocale = app(SiteLocale::class);
        $view = view('admin::livewire.settings.redirects', $data + [
            'allowed' => true,
            'failed' => $failed,
            'multi' => count($siteLocale->enabled()) > 1,
            'siteLocales' => $siteLocale->enabled(),
            'defaultLocale' => $siteLocale->default(),
            'siteTime' => app(SiteTime::class),
            'paths' => app(PathBuilder::class),
            'pageWarn' => $this->edOpen && $this->pageWarning(),
        ]);
        $view->title($title);

        return $view;
    }

    /** A language's name for the screen ("Tiếng Anh"), or its code when it has none. */
    public static function localeName(string $locale): string
    {
        return Lang::has('entries.language_'.$locale) ? (string) __('entries.language_'.$locale) : strtoupper($locale);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Check the dialog's fields; the errors land in the error bag under edFrom / edTo.
     *
     * @param  list<'from'|'to'>  $fields
     *
     * @phpstan-impure
     */
    private function checkFields(array $fields): bool
    {
        $problems = $this->book()->problems($this->edUlid, $this->edLocale, $this->edFrom, $this->edTo, $this->hosts());
        foreach (['from' => 'edFrom', 'to' => 'edTo'] as $field => $key) {
            if (! in_array($field, $fields, true)) {
                continue;
            }
            $this->resetErrorBag($key);
            if (isset($problems[$field])) {
                $this->addError($key, $problems[$field]);
            }
        }

        return $problems === [];
    }

    /** Whether the old address typed is a live address of the site (the redirect would not run). */
    private function pageWarning(): bool
    {
        $from = $this->book()->oldPath($this->edFrom, $this->edLocale, $this->hosts());

        return $from !== null && $this->book()->isLiveAddress($this->edLocale, $from);
    }

    /** @param  list<string>  $ulids */
    private function moveToTrash(array $ulids): void
    {
        $moved = $this->book()->trash(self::keys($ulids));
        if ($moved === []) {
            $this->notify(__('admin.rd.gone'), 'error');

            return;
        }
        $this->picked = array_values(array_diff($this->picked, $moved));
        $this->undoKeys = $moved;
        $first = count($moved) === 1 ? $this->book()->find($moved[0], true) : null;
        $this->notify($first !== null
            ? __('admin.rd.toast_trashed', ['from' => $first->from_path])
            : __('admin.rd.toast_trashed_n', ['n' => $this->num(count($moved))]), undo: true);
    }

    /** @param  list<string>  $ulids */
    private function bringBack(array $ulids): void
    {
        $keys = self::keys($ulids);
        $result = $this->book()->restore($keys);
        $this->picked = array_values(array_diff($this->picked, $keys));
        if ($result['restored'] === 0 && $result['blocked'] === 0) {
            $this->notify(__('admin.rd.gone'), 'error');

            return;
        }
        if ($result['blocked'] > 0) {
            $this->notify(__('admin.rd.restore_blocked', ['n' => $this->num($result['blocked'])]), 'error');

            return;
        }
        $this->notify(__('admin.rd.toast_restored', ['n' => $this->num($result['restored'])]));
    }

    /**
     * The ticked rows as the bar needs them: how many, and how many are «Chuyển tạm».
     *
     * @return array{n: int, temp: int}
     */
    private function picksNow(): array
    {
        if ($this->picked === []) {
            return ['n' => 0, 'temp' => 0];
        }
        $rows = $this->book()->many($this->picked, $this->tab === RedirectDirectory::TAB_TRASH);

        return ['n' => $rows->count(), 'temp' => $rows->where('status_code', 302)->count()];
    }

    /** What an automatic row was made for, as the dialog's note names it. */
    private function autoFor(Redirect $row): string
    {
        $found = app(RedirectDirectory::class)->describe($row);
        $kind = Cast::string($found['target_kind'] ?? null);
        $name = Cast::string($found['target_name'] ?? null);

        return $kind === '' || $name === ''
            ? (string) __('admin.rd.auto_for_content')
            : (string) __('admin.rd.auto_for_'.$kind, ['name' => $name]);
    }

    /** @return array{locales: list<string>, sources: list<string>, codes: list<int>} */
    private function filters(): array
    {
        return ['locales' => $this->locales, 'sources' => $this->sources, 'codes' => array_map('intval', $this->codes)];
    }

    /** @return list<string> the site's own host names, for an address pasted in full */
    private function hosts(): array
    {
        return array_values(array_unique(array_filter([$this->host, request()->getHost()])));
    }

    private function defaultLocale(): string
    {
        return app(SiteLocale::class)->default();
    }

    private function book(): RedirectBook
    {
        return app(RedirectBook::class);
    }

    private function num(int $n): string
    {
        return (string) Number::format($n, locale: app()->getLocale());
    }

    private function notify(string $message, string $tone = 'success', bool $undo = false): void
    {
        if (! $undo) {
            $this->undoKeys = [];
        }
        $this->toast = $message;
        $this->toastTone = $tone;
        $this->toastSeq++;
    }

    private function allowed(): bool
    {
        return Gate::allows('settings.manage');
    }

    private function authorized(): void
    {
        abort_unless($this->allowed(), 403);
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
        $enabled = app(SiteLocale::class)->enabled();
        $this->tab = in_array($this->tab, RedirectDirectory::TABS, true) ? $this->tab : RedirectDirectory::TAB_LIVE;
        $this->search = mb_substr($this->search, 0, RedirectDirectory::SEARCH_MAX);
        $this->locales = array_values(array_intersect($enabled, Cast::strings($this->locales)));
        $this->sources = array_values(array_intersect([Redirect::SOURCE_AUTO, Redirect::SOURCE_MANUAL], Cast::strings($this->sources)));
        $this->codes = array_values(array_intersect(['301', '302'], Cast::strings($this->codes)));
        $this->sort = in_array($this->sort, RedirectDirectory::SORTS, true) ? $this->sort : RedirectDirectory::SORT_NEW;
        $this->per = in_array($this->per, self::PER_PAGE, true) ? $this->per : 20;
        $this->picked = array_slice(self::keys($this->picked), 0, self::PICK_MAX);
        $this->edLocale = in_array($this->edLocale, $enabled, true) ? $this->edLocale : $this->defaultLocale();
        $this->edCode = in_array($this->edCode, Redirect::STATUS_CODES, true) ? $this->edCode : 301;
        $this->edFrom = mb_substr($this->edFrom, 0, 2048);
        $this->edTo = mb_substr($this->edTo, 0, 2048);
    }

    /**
     * @param  list<string>  $values
     * @return list<string> the values without $value ("*": none left)
     */
    private static function without(array $values, string $value): array
    {
        return $value === '*' ? [] : array_values(array_filter($values, static fn (string $v): bool => $v !== $value));
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function keys(array $values): array
    {
        return array_values(array_unique(array_map('strtoupper', array_filter(Cast::strings($values), fn (string $u): bool => Str::isUlid($u)))));
    }
}
