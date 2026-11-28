<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Settings;

use App\Modules\Admin\Support\Permalinks\PermalinkBoard;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Cms\Actions\Permalinks\ChangeSitePermalinks;
use App\Modules\Cms\Actions\Permalinks\ReflowSitePermalinks;
use App\Modules\Cms\Exceptions\PermalinkChangeRejectedException;
use App\Modules\Cms\Jobs\ReflowPermalinks;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * «Đường dẫn» (board «Khung quản trị», section «Đường dẫn»): per content section — pages, each
 * section that files posts, products — the format its addresses follow, with a preview of the
 * addresses that change and a confirmation that says how many redirects the website gets.
 *
 * Saving stages the change (ChangeSitePermalinks): a background run moves the addresses, each with
 * a 301 from the old one, and makes the new format live; until it is done (its settle walk
 * included) the screen says so and the cards show what is coming, read-only. The items the run could
 * not move are listed after it, each with why and what to do, with «Thử lại» and «Đã hiểu».
 * settings.manage on every request and every action; a
 * member without it gets the board's no-permission card. A website whose plan does not let it
 * choose (`permalinks.custom` off) gets the board's locked card; what it stored keeps working.
 */
#[Layout('admin::layouts.app')]
final class Permalinks extends Component
{
    /** @var array<string, array{pattern: string, base: string}> section => the choice being drafted */
    public array $draft = [];

    public bool $confirming = false;

    /** «HH:MM» of the last save in this visit, for «Đã lưu lúc …». */
    public string $savedAt = '';

    public string $toast = '';

    public string $toastTone = 'success';

    public int $toastSeq = 0;

    public function mount(): void
    {
        if (! $this->allowed()) {
            return;
        }
        app(ChangeSitePermalinks::class)->resumeIfStale();
        $this->load();
    }

    /** The save bar's «Lưu»: asks first when addresses that visitors know change. */
    public function save(): void
    {
        $this->authorized();
        $this->dismissToast();
        $this->clean();
        if ($this->problems() !== []) {
            $this->dispatch('pl-first-error');

            return;
        }
        $sections = $this->board()->sections();
        $changes = $this->changes($sections);
        if ($changes === []) {
            return;
        }
        $preview = $this->board()->preview($sections, $changes);
        if (app(Entitlement::class)->siteIsActive() && ($preview['changed'] > 0 || $preview['unknown'] > 0)) {
            $this->confirming = true;

            return;
        }
        $this->commit($changes, 0);
    }

    /** «Đổi và tạo N chuyển hướng» in the confirmation. */
    public function confirmSave(): void
    {
        $this->authorized();
        $this->clean();
        $this->confirming = false;
        $sections = $this->board()->sections();
        $changes = $this->changes($sections);
        if ($changes === [] || $this->problems() !== []) {
            return;
        }
        $this->commit($changes, $this->board()->preview($sections, $changes)['changed']);
    }

    /** «Thử lại» on the list of items the last run could not move. */
    public function retryFailed(): void
    {
        $this->authorized();
        $this->dismissToast();
        if (app(ChangeSitePermalinks::class)->retryFailed()) {
            $this->notify(__('admin.pl.failed_retrying'));
        }
        $this->load();
    }

    /** «Đã hiểu» on that list: the items keep their old address. */
    public function dismissFailed(): void
    {
        $this->authorized();
        app(ChangeSitePermalinks::class)->dismissFailed();
        $this->load();
    }

    public function cancelConfirm(): void
    {
        $this->authorized();
        $this->confirming = false;
    }

    /** The save bar's «Bỏ thay đổi». */
    public function resetChanges(): void
    {
        $this->authorized();
        $this->dismissToast();
        $this->confirming = false;
        $this->load();
    }

    /** The error card's «Thử lại», and the refresh while a change is being applied. */
    public function retry(): void
    {
        $this->authorized();
        $this->load();
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
        $title = __('admin.pl.title');
        if (! $this->allowed()) {
            $member = auth()->user();
            $view = view('admin::livewire.settings.permalinks', [
                'allowed' => false,
                'role' => $member instanceof User ? (RoleLabel::topOf($member) ?? '') : '',
            ]);
            $view->title($title);

            return $view;
        }

        $this->clean();
        $entitlement = app(Entitlement::class);
        $locked = ! $entitlement->featureEnabled(ChangeSitePermalinks::FEATURE);
        $failed = false;
        $sections = [];
        $preview = ['list' => [], 'changed' => 0, 'capped' => false, 'dups' => 0, 'unknown' => 0];
        $run = null;
        $changes = [];
        $problems = [];
        try {
            // a savepoint inside the request's tenancy transaction: a failed read leaves it usable
            [$sections, $changes, $preview, $problems, $run] = DB::transaction(function () use ($locked): array {
                if ($locked) {
                    return [[], [], ['list' => [], 'changed' => 0, 'capped' => false, 'dups' => 0, 'unknown' => 0], [], null];
                }
                $sections = $this->board()->sections();
                $run = app(PermalinkConfig::class)->run(); // read with the sections
                $changes = $this->changes($sections, $run);
                $problems = $this->problems();

                // a prefix still in error previews nothing (the field says what to fix)
                return [$sections, $changes, $this->board()->preview($sections, array_diff_key($changes, $problems)), $problems, $run];
            });
        } catch (QueryException $e) {
            report($e);
            $failed = true;
        }

        $walking = in_array($run['phase'] ?? null, [ReflowPermalinks::MOVE, ReflowPermalinks::SETTLE], true);
        $pending = $walking || array_values(array_filter($sections, static fn (array $s): bool => $s['pending'] !== null)) !== [];
        $problems = $pending ? [] : $problems;
        $settling = $walking && $run !== null && $run['phase'] === ReflowPermalinks::SETTLE ? $run['sections'] : [];
        $cards = [];
        foreach ($sections as $section) {
            $key = $section['key'];
            $choice = $section['pending'] ?? ($pending ? ['pattern' => $section['pattern'], 'base' => $section['base']] : null);
            $draft = $choice !== null ? ['pattern' => $choice['pattern'], 'base' => (string) ($choice['base'] ?? $this->defaultBase($section))] : ($this->draft[$key] ?? ['pattern' => $section['pattern'], 'base' => $this->defaultBase($section)]);
            $options = [];
            foreach (PermalinkBoard::STYLES[$section['type']] as $style) {
                $base = $style === 'base' ? ($draft['pattern'] === 'base' && $draft['base'] !== '' ? $draft['base'] : $this->defaultBase($section)) : null;
                $options[] = ['style' => $style, 'example' => $this->board()->example($key, $section['items'], $style, $base) ?? $this->fallbackExample($style, $base)];
            }
            $cards[] = $section + [
                'draft' => $draft,
                'options' => $options,
                'changed' => isset($changes[$key]),
                'staged' => $section['pending'] !== null || in_array($key, $settling, true),
                'problem' => $problems[$key] ?? null,
                'example_base' => $this->defaultBase($section),
            ];
        }

        $names = array_column($sections, 'name', 'key');
        $upsell = app(UpgradeInvitation::class)->isOn();
        $view = view('admin::livewire.settings.permalinks', [
            'allowed' => true,
            'failed' => $failed,
            'locked' => $locked,
            'upsell' => $upsell,
            'supportMail' => Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address')),
            'pending' => $pending,
            'live' => $entitlement->siteIsActive(),
            'cards' => $cards,
            'names' => $names,
            'changes' => count($changes),
            'invalid' => count($problems),
            'preview' => $preview,
            'failures' => $this->failures($run, $names),
            'host' => $this->host(),
            'num' => fn (int $n, bool $capped = false): string => Number::format($n, locale: app()->getLocale()).($capped ? '+' : ''),
        ]);
        $view->title($title);

        return $view;
    }

    /**
     * Apply the changed sections; $redirects is the preview's estimate for the message.
     *
     * @param  array<string, array{pattern: string, base: string|null}>  $changes
     */
    private function commit(array $changes, int $redirects): void
    {
        try {
            app(ChangeSitePermalinks::class)->handle($changes);
        } catch (PermalinkChangeRejectedException $e) {
            $this->notify(__('admin.pl.refused_'.$e->reason), 'danger');

            return;
        }
        $this->savedAt = app(SiteTime::class)->now()->format('H:i');
        $this->notify($redirects > 0
            ? __('admin.pl.toast_redirects', ['n' => Number::format($redirects, locale: app()->getLocale())])
            : __('admin.pl.toast_saved'));
        $this->load();
    }

    /**
     * The drafted sections whose choice differs from the stored one.
     *
     * @param  list<array{key: string, type: string, pattern: string, base: string|null, pending: array{pattern: string, base: string|null}|null}>  $sections
     * @param  array{phase: string}|null  $run  the run under way, when the caller has read it
     * @return array<string, array{pattern: string, base: string|null}>
     */
    private function changes(array $sections, ?array $run = null): array
    {
        if (in_array(($run ?? app(PermalinkConfig::class)->run())['phase'] ?? null, [ReflowPermalinks::MOVE, ReflowPermalinks::SETTLE], true)) {
            return [];
        }
        $out = [];
        foreach ($sections as $section) {
            if ($section['pending'] !== null) {
                return [];
            }
            $draft = $this->draft[$section['key']] ?? null;
            if ($draft === null) {
                continue;
            }
            $base = $draft['pattern'] === 'base' ? trim($draft['base']) : null;
            if ($draft['pattern'] !== $section['pattern'] || ($base !== null && $base !== $section['base'])) {
                $out[$section['key']] = ['pattern' => $draft['pattern'], 'base' => $base];
            }
        }

        return $out;
    }

    /** @return array<string, array{code: string, other: string|null}> the drafted prefixes in error */
    private function problems(): array
    {
        $wanted = [];
        foreach ($this->draft as $section => $choice) {
            if ($choice['pattern'] === 'base') {
                $wanted[$section] = ['pattern' => 'base', 'base' => trim($choice['base'])];
            }
        }

        return $wanted === [] ? [] : app(ChangeSitePermalinks::class)->problems($wanted);
    }

    private function load(): void
    {
        $this->draft = [];
        try {
            $sections = DB::transaction(fn (): array => $this->board()->sections());
        } catch (QueryException $e) {
            report($e);

            return;
        }
        foreach ($sections as $section) {
            $this->draft[$section['key']] = [
                'pattern' => $section['pattern'],
                'base' => $section['pattern'] === 'base' && $section['base'] !== null ? $section['base'] : $this->defaultBase($section),
            ];
        }
    }

    /** Public properties come from the browser: keep only sections and values the screen knows. */
    private function clean(): void
    {
        $types = app(ChangeSitePermalinks::class)->sections();
        $clean = [];
        /** @var array<mixed> $raw what the browser sent */
        $raw = $this->draft;
        foreach ($raw as $section => $choice) {
            $section = (string) $section;
            $type = $types[$section] ?? null;
            if ($type === null || ! is_array($choice)) {
                continue;
            }
            $pattern = Cast::string($choice['pattern'] ?? '');
            $clean[$section] = [
                'pattern' => in_array($pattern, PermalinkBoard::STYLES[$type] ?? [], true) ? $pattern : 'flat',
                'base' => mb_substr(Cast::string($choice['base'] ?? ''), 0, ChangeSitePermalinks::BASE_MAX + 20),
            ];
        }
        $this->draft = $clean;
    }

    /**
     * The prefix a card offers first: the board's «trang» and «san-pham», a post section's name as a slug.
     *
     * @param  array{key: string, name: string}  $section
     */
    private function defaultBase(array $section): string
    {
        return match ($section['key']) {
            'pages' => 'trang',
            'products' => 'san-pham',
            default => Slugger::make($section['name'], 'vi', ChangeSitePermalinks::BASE_MAX) ?: 'muc',
        };
    }

    /** An option's example when the section has nothing published yet. */
    private function fallbackExample(string $style, ?string $base): string
    {
        $slug = __('admin.pl.ex_slug');

        return match ($style) {
            'base' => '/'.$base.'/'.$slug,
            'nested' => '/'.__('admin.pl.ex_parent').'/'.$slug,
            'category' => '/'.__('admin.pl.ex_category').'/'.$slug,
            'category_path' => '/'.__('admin.pl.ex_category').'/'.__('admin.pl.ex_sub').'/'.$slug,
            default => '/'.$slug,
        };
    }

    /**
     * The items the last run could not move, as the screen lists them: name, the address it keeps,
     * the one it should take (as visitors open them), and what to do.
     *
     * @param  array{phase: string, failures: list<array{section: string, type: string, title: string, locale: string, old: string, new: string, reason: string}>, failed: int}|null  $run
     * @param  array<string, string>  $names  section key => card name
     * @return array{count: int, more: int, rows: list<array{title: string, section: string, old: string, new: string, fix: string}>}|null
     */
    private function failures(?array $run, array $names): ?array
    {
        if ($run === null || $run['phase'] !== PermalinkConfig::RUN_DONE || $run['failed'] === 0) {
            return null;
        }
        $paths = app(PathBuilder::class);
        $rows = [];
        foreach ($run['failures'] as $failure) {
            $rows[] = [
                'title' => $failure['title'] !== '' ? $failure['title'] : __('admin.pl.failed_untitled'),
                'section' => $names[$failure['section']] ?? '',
                'old' => $paths->publicUrl($failure['old'], $failure['locale']),
                'new' => $paths->publicUrl($failure['new'], $failure['locale']),
                'fix' => __('admin.pl.failed_'.(in_array($failure['reason'], [ReflowSitePermalinks::TOO_LONG, ReflowSitePermalinks::TAKEN], true) ? $failure['reason'] : ReflowSitePermalinks::ERROR)),
            ];
        }

        return ['count' => $run['failed'], 'more' => max(0, $run['failed'] - count($rows)), 'rows' => $rows];
    }

    private function host(): string
    {
        return SeoFields::publicHost();
    }

    private function board(): PermalinkBoard
    {
        return app(PermalinkBoard::class);
    }

    private function notify(string $message, string $tone = 'success'): void
    {
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
}
