<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One category a member may write in on this site; the category's descendants come with it. No row
 * for a member = every category. Read through CategoryScopes (Cms\Support) and
 * written only by SetMemberCategoryScopes, by DeleteTaxonomy (moving rows onto a merge target) and
 * when the member's last role on the site goes. RLS isolates by site.
 *
 * site_id is not mass-assignable: BelongsToSite stamps it from the tenant context.
 *
 * @property int $id
 * @property int $site_id
 * @property int $user_id
 * @property int $taxonomy_id
 * @property CarbonImmutable|null $created_at
 */
class MemberCategoryScope extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    #[\Override]
    protected $fillable = ['user_id', 'taxonomy_id'];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'taxonomy_id' => 'integer',
        ];
    }
}
