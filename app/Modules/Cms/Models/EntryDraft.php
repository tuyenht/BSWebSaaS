<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one pending change to a live entry (design.md §8): content saved by someone whose edit must
 * not reach visitors until it is approved (workflow on) or applied by someone with the publish
 * right (workflow off). Deleted once applied or discarded. The storefront never reads it.
 *
 * A change need not be under a workflow: workflow_state is null both at a workflow's first stage
 * and when the entry's section has no active workflow. Never addressed from outside by its own
 * id; the entry's ulid is the address.
 *
 * `content` mirrors the revision snapshot field names:
 * {v, shared: {template_override, cover_media_id, meta, primary_category_id},
 *  locales: {<locale>: {title, slug, body, excerpt, seo_title, seo_description, og_image_id}}}.
 *
 * site_id is not mass-assignable: BelongsToSite stamps it from the tenant context.
 *
 * @property int $id
 * @property int $site_id
 * @property int $entry_id
 * @property string|null $workflow_state
 * @property int|null $assigned_to
 * @property array{v?: int, shared?: array<string, mixed>, locales: array<string, array<string, mixed>>} $content
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EntryDraft extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $fillable = ['entry_id', 'workflow_state', 'assigned_to', 'content', 'created_by', 'updated_by'];

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * Who the change waits on; null when unassigned or the user was removed.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'assigned_to' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
