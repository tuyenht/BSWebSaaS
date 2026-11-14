<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One category an invitation limits its new member to on this site; the category's descendants come
 * with it. No row for an invitation = every category. Written only by InviteMemberWithCategories
 * (synced with the invitation) and DeleteTaxonomy (moving rows onto a merge target); read by
 * CategoryScopes and copied to the member's own limits on accept (CopyInvitationCategoryScopes). The rows
 * go with their invitation (composite foreign key). RLS isolates by site.
 *
 * site_id is not mass-assignable: BelongsToSite stamps it from the tenant context.
 *
 * @property int $id
 * @property int $site_id
 * @property int $invitation_id
 * @property int $taxonomy_id
 * @property CarbonImmutable|null $created_at
 */
class InvitationCategoryScope extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    #[\Override]
    protected $fillable = ['invitation_id', 'taxonomy_id'];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'invitation_id' => 'integer',
            'taxonomy_id' => 'integer',
        ];
    }
}
