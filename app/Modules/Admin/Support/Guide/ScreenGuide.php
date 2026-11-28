<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Guide;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

/**
 * The «Hướng dẫn» of the screens whose guide is the design's plain one (the board's guide.js step sets):
 * the member's role box (what their permissions let them do on the screen), the screen's purpose, one
 * part «Cách làm ở màn này» whose steps point at the screen's data-guide="…" marks, and the screen's
 * common questions. A step whose mark the screen did not draw (no permission, an empty list, hidden at
 * this width) is skipped in the browser, so the steps listed are always the member's own.
 *
 * Every word is in lang/*.json under guide.<screen>.*: purpose, none (the role box with no permission
 * of the list), p<i> (what permission i allows), <step>_t and <step> (a step's title and text, where
 * [[@]] is the label the marked control carries), q<i> / a<i> (a question and its answer). A Settings
 * section reads guide.settings.* for the parts every section shares and guide.settings_<section>.* for
 * its own steps and questions; its purpose is the section's own description (admin.set.desc.*).
 */
final class ScreenGuide
{
    /**
     * perms: [ability (several joined by "|": any of them), the key of what it allows]; steps: id =>
     * the data-guide mark(s) it points at, the first one shown wins; faq: how many questions.
     *
     * @var array<string, array{perms: list<array{0: string, 1: string}>, steps: array<string, string|list<string>>, faq: int}>
     */
    private const SCREENS = [
        'dashboard' => [
            'perms' => [['entries.create', 'p1'], ['entries.publish', 'p2'], ['entries.approve', 'p3'], ['users.invite', 'p4']],
            'steps' => ['todo' => 'dash-todo', 'start' => 'dash-start', 'review' => 'dash-review', 'mine' => 'dash-mine', 'quick' => 'dash-quick', 'help' => 'dash-help'],
            'faq' => 2,
        ],
        'customers' => [
            'perms' => [['platform.billing.manage', 'p1']],
            'steps' => ['search' => 'search', 'filter' => 'filter-button', 'open' => 'row-open', 'more' => 'row-more'],
            'faq' => 2,
        ],
        'sites' => [
            'perms' => [['platform.sites.manage', 'p1'], ['platform.sites.impersonate', 'p2']],
            'steps' => ['search' => 'search', 'status' => 'filter', 'open' => 'row-open'],
            'faq' => 1,
        ],
        'products' => [
            'perms' => [['catalog.manage', 'p1']],
            'steps' => ['add' => 'add', 'tabs' => 'tabs', 'filter' => 'filter-button', 'open' => 'row-open', 'more' => 'row-more', 'search' => 'search'],
            'faq' => 2,
        ],
        'cats' => [
            'perms' => [['taxonomies.manage', 'p1']],
            'steps' => ['add' => 'add', 'chips' => 'chips', 'edit' => 'row-edit', 'child' => 'row-child', 'more' => 'row-more', 'search' => 'search'],
            'faq' => 1,
        ],
        'menus' => [
            'perms' => [['menus.manage', 'p1']],
            'steps' => ['add' => 'add-items', 'drag' => 'rows', 'more' => 'row-more', 'save' => ['save', 'save-b'], 'site' => 'site'],
            'faq' => 3,
        ],
        'media' => [
            'perms' => [['media.upload', 'p1'], ['media.delete.any|media.delete.own', 'p2'], ['media.purge', 'p3']],
            'steps' => ['upload' => 'add', 'search' => 'search', 'filter' => 'filter-button', 'sort' => 'sort', 'open' => 'row-open'],
            'faq' => 2,
        ],
        'forms' => [
            'perms' => [['forms.manage', 'p1']],
            'steps' => ['add' => 'add', 'tabs' => 'tabs', 'search' => 'search', 'open' => 'row-open', 'switch' => 'row-switch', 'more' => 'row-more'],
            'faq' => 2,
        ],
        'leads' => [
            'perms' => [['forms.view', 'p1'], ['forms.manage', 'p2']],
            'steps' => ['export' => 'more', 'tabs' => 'tabs', 'search' => 'search', 'call' => 'call'],
            'faq' => 2,
        ],
        'workflow' => [
            'perms' => [['platform.workflows.manage', 'p1']],
            'steps' => ['switch' => 'row-switch', 'pick' => 'row-pick', 'reload' => 'reload'],
            'faq' => 2,
        ],
        'members' => [
            'perms' => [['users.invite', 'p1'], ['users.manage', 'p2']],
            'steps' => ['add' => 'add', 'tabs' => 'tabs', 'search' => 'search', 'roles' => 'filter-button', 'edit' => 'row-edit', 'more' => 'row-more'],
            'faq' => 4,
        ],
        'audit' => [
            'perms' => [],
            'steps' => ['search' => 'search', 'filter' => 'filter-button', 'support' => 'support'],
            'faq' => 1,
        ],
        'plans' => [
            'perms' => [['platform.billing.manage', 'p1']],
            'steps' => ['add' => 'add', 'sold' => 'row-switch', 'more' => 'row-more'],
            'faq' => 2,
        ],
    ];

    /**
     * A Settings section's own steps and questions beside the shared ones; the shared steps come first
     * (the (i) note, the section menu's search), the save bar's last.
     *
     * @var array<string, array{steps: array<string, string|list<string>>, faq: int}>
     */
    private const SECTIONS = [
        'domains' => ['steps' => ['add' => 'add', 'more' => 'row-more'], 'faq' => 2],
        'redirects' => ['steps' => ['add' => 'add', 'search' => 'search', 'more' => 'row-more'], 'faq' => 1],
    ];

    /** @return list<string> the screens with a plain guide */
    public static function screens(): array
    {
        return array_keys(self::SCREENS);
    }

    /** @return list<string> the Settings sections with steps of their own */
    public static function sections(): array
    {
        return array_keys(self::SECTIONS);
    }

    /**
     * What a screen prints for its guide part on its first draw: the guide of the member signed in, or
     * null on the redraws that follow (the part keeps what it printed through them, wire:ignore) and for
     * nobody signed in. `only` names the steps the screen draws this time, when it leaves whole parts
     * out for a member (the dashboard's cards), so the guide never names a part the member cannot see.
     *
     * @param  list<string>|null  $only
     * @return array<string, mixed>|null
     */
    public static function print(string $screen, string $title, ?array $only = null): ?array
    {
        $user = self::firstDrawUser();

        return $user === null ? null : self::for($screen, $user, $title, $only)->toArray($user, self::siteId());
    }

    /**
     * The same for a Settings section; `saves` says the section has the save bar.
     *
     * @return array<string, mixed>|null
     */
    public static function printSection(string $section, string $title, bool $saves): ?array
    {
        $user = self::firstDrawUser();

        return $user === null ? null : self::section($section, $user, $title, $saves)->toArray($user, self::siteId());
    }

    /**
     * @param  list<string>|null  $only
     */
    public static function for(string $screen, User $user, string $title, ?array $only = null): Guide
    {
        $def = self::SCREENS[$screen] ?? ['perms' => [], 'steps' => [], 'faq' => 0];
        $t = fn (string $key): string => self::t($screen, $key);
        $role = self::role($user, $def['perms'], $t);
        $steps = $only === null ? $def['steps'] : array_intersect_key($def['steps'], array_flip($only));

        $guide = new Guide($screen, $title, $screen.'|'.($role['name'] ?? '-'), $role, $t('purpose'));
        $guide->part('main', Cast::string(__('guide.screen.part_main')), self::steps($steps, $t));

        return $guide->faq(self::questions($def['faq'], $t));
    }

    public static function section(string $section, User $user, string $title, bool $saves): Guide
    {
        $own = self::SECTIONS[$section] ?? ['steps' => [], 'faq' => 0];
        $shared = fn (string $key): string => self::t('settings', $key);
        $mine = fn (string $key): string => self::t('settings_'.$section, $key);
        $role = self::role($user, [['settings.manage', 'p1']], $shared);
        $purpose = Lang::has('admin.set.desc.'.$section) ? Cast::string(__('admin.set.desc.'.$section))
            : (Lang::has('admin.set.sd.'.$section) ? Cast::string(__('admin.set.sd.'.$section)) : $shared('purpose'));

        $guide = new Guide('settings.'.$section, $title, 'settings.'.$section.'|'.($role['name'] ?? '-'), $role, $purpose);
        $guide->part('main', Cast::string(__('guide.screen.part_main')), array_merge(
            self::steps(['info' => 'set-info', 'nav' => 'set-search'], $shared),
            self::steps($own['steps'], $mine),
            $saves ? self::steps(['save' => 'save'], $shared) : [],
        ));

        return $guide->faq(array_merge($saves ? self::questions(1, $shared) : [], self::questions($own['faq'], $mine)));
    }

    /**
     * The role box: the member's highest role and what the screen's permissions let them do, joined
     * (the first letter lowered, as it follows "nhóm quyền X:"); a member holding none of them reads
     * the screen's "none" words. No role box for someone with no role here (platform staff on a website).
     *
     * @param  list<array{0: string, 1: string}>  $perms
     * @param  \Closure(string): string  $t
     * @return array{name: string, may: string, note: string}|null
     */
    private static function role(User $user, array $perms, \Closure $t): ?array
    {
        $name = RoleLabel::topOf($user);
        if ($name === null) {
            return null;
        }
        $may = [];
        foreach ($perms as [$abilities, $key]) {
            foreach (explode('|', $abilities) as $ability) {
                if ($user->can($ability)) {
                    $may[] = $t($key);
                    break;
                }
            }
        }

        return [
            'name' => $name,
            'may' => GuideText::lower($may === [] ? $t('none') : implode(Cast::string(__('guide.list.sep')), $may)),
            'note' => Cast::string(__('guide.screen.note')),
        ];
    }

    /**
     * @param  array<string, string|list<string>>  $steps
     * @param  \Closure(string): string  $t
     * @return list<array<string, mixed>>
     */
    private static function steps(array $steps, \Closure $t): array
    {
        $out = [];
        foreach ($steps as $id => $targets) {
            $out[] = Guide::step($id, array_map(fn (string $target): array => Guide::on($target, $t($id.'_t'), $t($id)), (array) $targets));
        }

        return $out;
    }

    /**
     * @param  \Closure(string): string  $t
     * @return list<array{0: string, 1: string}>
     */
    private static function questions(int $n, \Closure $t): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [$t('q'.$i), $t('a'.$i)];
        }

        return $out;
    }

    private static function t(string $screen, string $key): string
    {
        return Cast::string(__('guide.'.$screen.'.'.$key));
    }

    private static function firstDrawUser(): ?User
    {
        if (Livewire::isLivewireRequest()) {
            return null;
        }
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private static function siteId(): int
    {
        $site = app(CurrentSite::class);

        return $site->hasId() ? $site->id() : 0;
    }
}
