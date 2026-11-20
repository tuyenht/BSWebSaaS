<?php

declare(strict_types=1);

namespace App\Modules\Forms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * One form block of a page (or post) in one language: which pages show which form, so «Xem trang
 * có biểu mẫu» is an indexed lookup, never a scan of page bodies. Tenant table (site_id + RLS
 * FORCE). Written only by the page save (Cms EntryFormPlacements, in the save's transaction) and
 * its reconcile command (`forms:placements`); a form or a page deleted for good takes its rows.
 *
 * @property int $id
 * @property int $site_id
 * @property int $form_id
 * @property int $entry_id
 * @property string $locale
 */
final class FormPlacement extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];
}
