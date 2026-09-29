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

    /** The fields one language of a change holds (the revision snapshot's per-locale fields). */
    public const array LOCALE_FIELDS = ['title', 'slug', 'body', 'excerpt', 'seo_title', 'seo_description', 'og_image_id'];

    /** The fields every language of a change shares (the snapshot's entry fields + the primary category). */
    public const array SHARED_FIELDS = ['template_override', 'cover_media_id', 'meta', 'primary_category_id'];

    #[\Override]
    protected $fillable = ['entry_id', 'workflow_state', 'assigned_to', 'content', 'created_by', 'updated_by'];

    /**
     * A fingerprint of the content: the screen that shows a change passes it back with a decision,
     * and the decision is refused when it no longer matches, so nobody approves, applies or throws
     * away content they have not seen (a change returned, edited and sent again keeps its stage).
     * It is taken over the content rather than updated_at, which is stored to the second and so
     * cannot tell two saves in the same second apart. Keys are sorted first: jsonb does not keep
     * the order an array was written in.
     */
    public function version(): string
    {
        return hash('sha256', json_encode(self::canonical($this->content), JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

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
