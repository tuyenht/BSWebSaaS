<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Admin\Support\Guide\Guide;
use App\Modules\Admin\Support\Guide\GuideText as W;
use App\Modules\Cms\Support\EntryTrash;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Builder;

/**
 * The guide of an entry list — «Quản lý bài viết» (a post section) or the Pages list — in the
 * design's parts: adding a record and getting it onto the website, what each row offers, finding
 * records, and working on many at once. Each step is the member's own: who may publish, who sends
 * for review, whether the section runs an approval workflow and how many steps it has, what they may
 * edit or delete — all from their permissions and the section's workflow (pages never run one), with
 * the button names the list itself draws. Steps for things they cannot do are left out; the elements
 * each step points at are marked data-guide="…" in the list's view. Both lists read the same lines
 * (guide.posts.*), the record's noun filled in; a page list's own role words are guide.pages.*.
 *
 * The design's part on the page tree (its child-page button, dragging rows, moving up and down, the
 * flat-list note) is not part of the guide: the Pages list draws none of those yet.
 */
final class EntryListGuide
{
    /**
     * @param  array{type: string, title: string, scoped: bool, roleNote: array{role: string, governed: bool, text: string, summary: string}|null,
     *     multilingual: bool, locales: int, sorts: list<string>, cols: list<string>, filters: list<array{key: string, label: string}>}  $screen
     */
    public static function for(User $user, array $screen, ?Workflow $workflow): Guide
    {
        $page = $screen['type'] === 'page';
        $workflow = $page ? null : $workflow;
        $note = $page ? self::pageNote($user, $screen['title']) : ($screen['roleNote'] ?? PostRoleNote::with($user, $workflow, $screen['title'], $screen['scoped']));
        $can = fn (string $permission): bool => $user->can($permission);
        $editScope = $can('entries.edit.any') ? 'any' : ($can('entries.edit.own') ? 'own' : 'none');
        $delScope = $can('entries.delete.any') ? 'any' : ($can('entries.delete.own') ? 'own' : 'none');
        $canCreate = $can('entries.create');
        $canPublish = $can('entries.publish');
        $unit = Cast::string(__('entries.noun_'.($page ? 'page' : 'post')));
        $t = fn (string $key, array $replace = []): string => self::t($key, $replace, $unit);
        $st = fn (string $status): string => Cast::string(__('entries.status_'.$status));

        $guide = new Guide(
            $page ? 'pages' : 'posts',
            $screen['title'],
            $note['role'].'|'.($workflow instanceof Workflow ? $workflow->key : 'direct'),
            ['name' => $note['role'], 'may' => W::lower($note['summary']), 'note' => $note['text']],
            $t('purpose', [
                'type' => W::lower($screen['title']),
                'create' => $canCreate ? $t('purpose_create') : '',
                'goal' => $t($editScope === 'none' ? 'goal_read' : 'goal_work'),
            ]),
        );

        $mine = array_values($user->roles->pluck('name')->all());
        // Who publishes and who sends for review, read in one query the first time a step needs it.
        $held = null;
        $holding = function (string $permission) use (&$held, $mine): array {
            $held ??= self::rolesHolding(['entries.publish', 'entries.submit'], $mine);

            return $held[$permission] ?? [];
        };

        // Part 1 · adding a post and getting it onto the website
        $add = Cast::string(__('entries.add_'.($page ? 'page' : 'post')));
        $addText = null;
        if ($canCreate) {
            $A = ['add' => W::label($add)];
            if ($workflow instanceof Workflow) {
                $steps = self::reviewSteps($workflow, $user);
                $addText = match (true) {
                    $steps === null => $t('add_write', $A),
                    $steps === 1 => $t('add_flow_one', $A),
                    default => $t('add_flow', $A + ['n' => $steps]),
                };
            } elseif ($canPublish) {
                $addText = $t('add_publish', $A);
            } elseif ($can('entries.submit')) {
                $addText = $t('add_submit', $A + ['who' => self::who($holding('entries.publish'), $unit)]);
            } else {
                $addText = $t('add_write', $A);
            }
        }
        // Who sends posts for review without publishing them: a publisher reviews their posts (an
        // approval workflow reviews in its own inbox, not on this list).
        $senders = $workflow instanceof Workflow || ! $canPublish ? [] : array_values(array_diff($holding('entries.submit'), $holding('entries.publish')));
        $pending = W::label($st('pending'));
        $reviewCall = ['roles' => W::join($senders), 'pending' => $pending, 'publish' => W::label($st('published')),
            'schedule' => W::label($st('scheduled')), 'draft' => W::label($st('draft')),
            'filter' => W::label(Cast::string(__('admin.list.filter'))), 'status' => W::label(Cast::string(__('entries.status_label')))];

        $guide->part('main', $t($canCreate ? 'part_main_create' : 'part_main_view'), [
            $page ? null : Guide::step('intro', [Guide::on('role', $t('intro_title'), $t('intro', ['role' => W::quote($note['role']), 'may' => W::lower($note['summary']), 'note' => $note['text']]))], intro: true),
            $addText === null ? null : Guide::step('add', [Guide::on('add', $add, $addText)]),
            Guide::step('tabs', [
                Guide::on('tabs', $t('tabs_title'), $t('tabs', ['draft' => W::label($st('draft')), 'published' => W::label($st('published'))])),
                Guide::on('filter-button', $t('tabs_phone_title'), $t('tabs_phone', ['draft' => $st('draft'), 'trash' => Cast::string(__('entries.tab_trash')),
                    'filter' => Cast::string(__('admin.list.filter')), 'status' => Cast::string(__('entries.status_label'))])),
            ]),
            $senders === [] ? null : Guide::step('review', [
                Guide::on('tab-pending', $t('review_title'), $t('review_tab', $reviewCall)),
                Guide::on('filter-button', $t('review_title'), $t('review_sheet', $reviewCall)),
            ]),
            Guide::step('open', [Guide::on($editScope === 'none' ? 'row-view' : 'row-edit', $t('open_title'), $t('open_'.$editScope, [
                'edit' => W::label(Cast::string(__('entries.edit_action'))), 'view' => W::label(Cast::string(__('admin.view'))), 'preview' => W::label(Cast::string(__('entries.action_preview'))),
            ]))]),
            Guide::step('status', [Guide::on('row-status', $t('status_title'), $canPublish
                ? $t('status_change', ['label' => W::target(), 'items' => W::words([$st('published'), $st('draft'), $st('private'), $st('scheduled')], 'or')])
                : $t('status_fixed'))]),
        ]);

        // Part 2 · on each row
        $mayEdit = $editScope !== 'none';
        $more = array_values(array_filter([
            $mayEdit ? Cast::string(__('entries.quick_edit_title')) : null,
            $canCreate ? Cast::string(__('entries.action_duplicate')) : null,
        ]));
        $edit = W::label(Cast::string(__('entries.edit_action')));
        $guide->part('row', $t('part_row'), [
            Guide::step('title', array_values(array_filter([
                $mayEdit ? Guide::on('row-title-qe', $t('title_qe_title'), $t('title_qe')) : null,
                Guide::on('row-title', $t('title_title'), $t($editScope === 'any' ? 'title_edit' : 'title_view')),
            ]))),
            Guide::step('path', array_values(array_filter([
                $mayEdit ? Guide::on('row-path-edit', $t('path_title'), $t('path_edit')) : null,
                $page ? Guide::on('row-path-home', $t('path_title'), $t('path_home')) : null,
                Guide::on('row-path', $t('path_title'), $t('path_plain')),
            ]))),
            $screen['multilingual'] ? Guide::step('langs', [Guide::on('row-langs', $t('langs_title'),
                $screen['locales'] > 4 ? $t('langs_sum') : $t($mayEdit ? 'langs_edit' : 'langs_view', ['edit' => $edit]))]) : null,
            Guide::step('views', [Guide::on('row-views', $t('views_title'), $t('views'))]),
            $more === [] && $delScope === 'none' ? null : Guide::step('more', [Guide::on('row-more', $t('more_title'),
                ($more === [] ? $t('more') : $t('more_items', ['items' => W::words($more)])).' '.$t('del_'.$delScope))]),
        ]);

        // Part 4 · finding records (part 3, the page tree, is left out: see the class note)
        $phrase = function (array $f) use ($t): string {
            $label = Cast::string($f['label']);

            return match ($f['key']) {
                'cat' => $t('f_cat', ['name' => W::label($label)]),
                'tpl' => $t('f_tpl', ['name' => W::label($label)]),
                'trans' => $t('f_trans', ['name' => W::label($label)]),
                'creator' => $t('f_creator', ['name' => W::label($label)]),
                default => $t('f_other', ['name' => W::label($label), 'what' => W::lower($label)]),
            };
        };
        $filterLabel = Cast::string(__('admin.list.filter'));
        $guide->part('find', $t('part_find'), [
            $delScope === 'none' ? null : Guide::step('trash', [Guide::on('tab-trash', Cast::string(__('entries.tab_trash')),
                $t('trash', ['trash' => W::label(Cast::string(__('entries.tab_trash'))), 'restore' => W::label(Cast::string(__('entries.action_restore'))),
                    'days' => (string) EntryTrash::days()]))]),
            Guide::step('search', [Guide::on('search', $t('search_title'), $t('search'))]),
            $screen['filters'] === [] ? null : Guide::step('filter', [
                Guide::on('filter-button', $filterLabel, $t('filter_one', ['filter' => W::label($filterLabel), 'names' => implode(Cast::string(__('guide.list.sep')), array_map(W::lower(...), array_column($screen['filters'], 'label')))])),
                Guide::on('filters', $filterLabel, implode($t('filter_sep'), array_map($phrase, $screen['filters'])).$t('filter_end')),
            ]),
            Guide::step('chips', [
                Guide::on('filter-line', $t('chips_title'), $t('chips', ['clear' => W::label(Cast::string(__('admin.list.clear_filters')))])),
                Guide::on('filter-sort', $t('chips_title'), $t('chips_sort')),
            ]),
            $screen['sorts'] === [] ? null : Guide::step('sort', [Guide::on('sort', Cast::string(__('admin.list.sort')), $t('sort', [
                'sort' => W::label(Cast::string(__('admin.list.sort'))),
                'opts' => W::words(array_map(fn (string $k): string => Cast::string(__('entries.sort_'.$k)), $screen['sorts']), 'and', 12),
            ]))]),
            Guide::step('cols', [Guide::on('cols', Cast::string(__('admin.list.cols')), $t('cols', [
                'cols' => W::label(Cast::string(__('admin.list.cols'))),
                'opts' => W::words($screen['cols'], 'and', 12),
                'locked' => W::words([Cast::string(__('entries.title_label')), Cast::string(__('entries.col_status')), Cast::string(__('admin.actions'))]),
            ]))]),
        ]);

        // Part 5 · many rows at once, other pages
        $guide->part('bulk', $t('part_bulk'), [
            Guide::step('head', [Guide::on('list-head', $t('head_title'), $t('head', ['cols' => W::labels([
                Cast::string(__('entries.title_label')), Cast::string(__('entries.col_status')), Cast::string(__('entries.col_creator')),
            ], 'or')]))]),
            Guide::step('pick', [Guide::on('row-check', $t('pick_title'), $t('pick'))]),
            Guide::step('page', [
                Guide::on('select-tool', $t('page_title'), $t('page_tool')),
                Guide::on('select-head', $t('page_title'), $t('page_head')),
            ]),
            Guide::step('bar', [Guide::on('bulk-bar', $t('bar_title'), $t('bar', ['clear' => W::label(Cast::string(__('entries.bulk_deselect')))]))]),
            Guide::step('per', [Guide::on('per-page', $t('per_title'), $t('per', ['n' => W::words(['10', '20', '50'], 'or')]))]),
            Guide::step('pager', [Guide::on('pager', $t('pager_title'), $t('pager'))]),
            Guide::step('crumb', [Guide::on('crumb', $t('crumb_title'), $t('crumb'))]),
        ]);

        return $guide;
    }

    /**
     * How many review steps a post goes through after it is sent, when this member may send it (the
     * workflow's first step out of its starting stage into a stage that is not yet published); null
     * when the step is not theirs. The steps walked are the forward ones (a step that asks for a
     * note sends the post back).
     */
    private static function reviewSteps(Workflow $workflow, User $user): ?int
    {
        $states = $workflow->states()->get(['key', 'is_initial', 'is_published'])->keyBy('key');
        $steps = $workflow->transitions()->orderBy('id')->get(['from_state', 'to_state', 'required_permission', 'requires_note']);
        $initial = $states->first(fn (WorkflowState $state): bool => $state->is_initial)?->key;
        $published = fn (string $key): bool => ($states->get($key)?->is_published) === true;
        $send = $steps->first(fn (WorkflowTransition $s): bool => $s->from_state === $initial && ! $published($s->to_state));
        if (! $send instanceof WorkflowTransition || ! $user->can($send->required_permission)) {
            return null;
        }
        $n = 0;
        $at = $send->to_state;
        // Walk forward to the published state; a state already passed ends the walk, so a step that leads back
        // without a note (a withdraw) never counts the same steps twice.
        $passed = [$send->from_state => true, $at => true];
        while (! $published($at)) {
            $forward = $steps->first(fn (WorkflowTransition $s): bool => $s->from_state === $at && ! $s->requires_note && ! isset($passed[$s->to_state]));
            if (! $forward instanceof WorkflowTransition) {
                break;
            }
            $n++;
            $at = $forward->to_state;
            $passed[$at] = true;
        }

        return max(1, $n);
    }

    /**
     * This website's roles, other than the member's own, that hold each of $permissions (the owner
     * holds every one), as people read them: one query for all of them, at most 20 roles.
     *
     * @param  list<string>  $permissions
     * @param  list<mixed>  $mine
     * @return array<string, list<string>>
     */
    private static function rolesHolding(array $permissions, array $mine): array
    {
        $query = Role::query()->where('site_id', app(CurrentSite::class)->id())
            ->where(fn ($q) => $q->where('name', 'owner')->orWhereHas('permissions', fn ($p) => $p->whereIn('name', $permissions)))
            ->whereNotIn('name', $mine)
            ->select(['id', 'name', 'is_system', 'level']);
        foreach ($permissions as $i => $permission) {
            $query->withExists(['permissions as holds_'.$i => fn (Builder $p) => $p->where('name', $permission)]);
        }
        $roles = $query->orderByDesc('level')->limit(20)->get();

        $held = [];
        foreach ($permissions as $i => $permission) {
            $held[$permission] = array_values($roles
                ->filter(fn (Role $role): bool => $role->name === 'owner' || Cast::bool($role->getAttribute('holds_'.$i)))
                ->map(fn (Role $role): string => RoleLabel::for($role))->all());
        }

        return $held;
    }

    /**
     * What a page list says of the member: their highest role, the straight publishing every page
     * goes through, and what they may do with pages (the post card's words, with pages for posts).
     *
     * @return array{role: string, governed: bool, text: string, summary: string}
     */
    private static function pageNote(User $user, string $title): array
    {
        $note = PostRoleNote::with($user, null, $title, false);
        $may = match (true) {
            $user->can('entries.publish') => __('entries.role_may_publish'),
            $user->can('entries.submit') => __('entries.role_may_submit'),
            default => __('entries.role_view_only'),
        };
        $edit = $user->can('entries.edit.any') ? 'any' : ($user->can('entries.edit.own') ? 'own' : 'none');

        return ['summary' => Cast::string($may).' · '.Cast::string(__('guide.pages.role_edit_'.$edit))] + $note;
    }

    /**
     * A line of a list's guide, the record's noun ("bài viết", "trang") filled in.
     *
     * @param  array<mixed>  $replace
     */
    private static function t(string $key, array $replace, string $unit): string
    {
        $words = ['unit' => $unit];
        foreach ($replace as $name => $value) {
            $words[(string) $name] = is_int($value) ? $value : Cast::string($value);
        }

        return Cast::string(__('guide.posts.'.$key, $words));
    }

    /** @param  list<string>  $roles */
    private static function who(array $roles, string $unit): string
    {
        return $roles === [] ? Cast::string(__('guide.posts.who_none', ['unit' => $unit])) : Cast::string(__('guide.posts.who', ['roles' => W::join($roles, 'or')]));
    }
}
