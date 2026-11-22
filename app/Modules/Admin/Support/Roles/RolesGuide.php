<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Roles;

use App\Modules\Admin\Support\Guide\Guide;
use App\Modules\Admin\Support\Guide\GuideText as W;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;

/**
 * The guide of «Nhóm quyền»: creating a role, the quick filters, opening a role, its members, the
 * plain-words summary and the row menu. Steps point at the screen's data-guide="…" marks; a mark
 * the screen did not draw is skipped in the browser. Text: guide.roles.*. Only members with
 * roles.manage reach the screen, so the role box always says they may create and edit roles.
 */
final class RolesGuide
{
    /**
     * @param  string  $plan  '' custom roles on · 'off' not turned on for the website · 'up' not in the plan
     * @param  string|null  $example  the first role on the list, named in «Mở một nhóm»
     */
    public static function for(User $user, string $plan, ?string $example): Guide
    {
        $note = self::t('note').($plan !== '' ? ' '.self::t($plan === 'up' ? 'note_plan_up' : 'note_plan_off') : '');

        $top = RoleLabel::topOf($user);
        $guide = new Guide(
            'roles',
            Cast::string(__('admin.roles.title')),
            'roles|'.($plan === '' ? 'on' : $plan),
            // platform staff hold no role here: no role box
            $top === null ? null : ['name' => $top, 'may' => self::t('may'), 'note' => $note],
            self::t('purpose'),
        );

        $chips = W::labels([Cast::string(__('admin.roles.k_all')), Cast::string(__('admin.roles.k_sys')), Cast::string(__('admin.roles.k_cust'))], 'or');

        $guide->part('main', self::t('part_main'), [
            Guide::step('add', [Guide::on('add', Cast::string(__('admin.roles.create')), self::t('create', ['create' => W::label(Cast::string(__('admin.roles.create')))]))]),
            Guide::step('chips', [Guide::on('chips', self::t('chips_title'), self::t('chips', ['chips' => $chips]))]),
            $example === null ? null : Guide::step('open', [Guide::on('name', self::t('open_title'), self::t('open', ['role' => W::quote($example)]))]),
            $example === null ? null : Guide::step('members', [Guide::on('members', self::t('members_title'), self::t('members'))]),
            // on the list the summary opens from each row's «Xem đủ»: the step points at the first one and names it
            $example === null ? null : Guide::step('explain', [Guide::on('explain', Cast::string(__('admin.members.x_invite_item')), self::t('explain', ['explain' => W::target()]))]),
            Guide::step('more', [Guide::on('row-more', self::t('more_title'), self::t('more'))]),
        ]);

        return $guide;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function t(string $key, array $replace = []): string
    {
        return Cast::string(__('guide.roles.'.$key, $replace));
    }
}
