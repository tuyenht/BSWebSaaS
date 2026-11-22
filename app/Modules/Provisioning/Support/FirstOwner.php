<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * The person named as the creator («Người tạo») of content that no member typed in: what
 * provisioning, a content pack or a demo seed writes. It is the website's first owner (the owner
 * with the lowest id), the account that provisioning gave the website. Null when the website has
 * no owner, so the content says «—» rather than naming someone else.
 *
 * Read for the current website, inside its tenant context. Remembered per website for the life of
 * this object, so a pack of a hundred items asks once.
 */
final class FirstOwner
{
    /** @var array<int, int|null> site id → owner user id */
    private array $known = [];

    public function __construct(private readonly CurrentSite $site) {}

    public function id(): ?int
    {
        $siteId = $this->site->id();

        if (! array_key_exists($siteId, $this->known)) {
            $id = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereNull('roles.deleted_at')
                ->where('model_has_roles.site_id', $siteId)
                ->where('model_has_roles.model_type', User::class)
                ->where('roles.site_id', $siteId)
                ->where('roles.name', 'owner')
                ->orderBy('model_has_roles.model_id')
                ->value('model_has_roles.model_id');
            $this->known[$siteId] = $id === null ? null : Cast::int($id);
        }

        return $this->known[$siteId];
    }
}
