<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;

/**
 * Dynamic content-type registry / admin nav section (page|post + templates). Seeded
 * per-site by ProvisionSite; drives which "kinds" of content a site manages.
 *
 * The categories a post section covers live in content_section_categories (several top-level ones
 * may sit side by side); `taxonomy_id` stays as the first of them (expand-contract). Every save that
 * sets or changes `taxonomy_id` keeps that category among the section's own (booted()), so a writer
 * of the column alone (provisioning, seeders) stays right; a category another section covers is
 * refused with a DomainException, never dropped in silence.
 *
 * @property int $site_id
 * @property string $key
 * @property string $label
 * @property string|null $icon
 * @property int $sort_order
 * @property string|null $entry_type
 * @property int|null $taxonomy_id
 * @property string|null $list_template
 * @property string|null $detail_template
 * @property string|null $permission_prefix
 * @property array<string, mixed> $meta
 */
class ContentSection extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        // A category covered by another section is refused before the row is written: the two tables
        // never disagree on a section's first category (the readers would drop the section, the nav not).
        static::saving(static function (ContentSection $section): void {
            if (! $section->isDirty('taxonomy_id') || $section->taxonomy_id === null) {
                return;
            }
            $owner = ContentSectionCategory::query()->where('taxonomy_id', Cast::int($section->taxonomy_id))->value('content_section_id');
            if ($owner !== null && (! $section->exists || Cast::int($owner) !== $section->id)) {
                throw new \DomainException("The category {$section->taxonomy_id} already belongs to another section; section '{$section->key}' cannot take it as its first category.");
            }
        });

        static::saved(static function (ContentSection $section): void {
            // 'saved' fires before the model takes this save as its original: dirty = what this save wrote
            // (every column on an insert, whose original is empty), the original = the row before it.
            if (! $section->isDirty('taxonomy_id')) {
                return;
            }
            $before = $section->getOriginal('taxonomy_id');
            $now = $section->taxonomy_id === null ? null : Cast::int($section->taxonomy_id);
            if ($before !== null && Cast::int($before) !== $now) {
                ContentSectionCategory::query()->where('content_section_id', $section->id)->where('taxonomy_id', Cast::int($before))->delete();
            }
            // saving() refused a category of another section, so the only row there can be is this one's own.
            if ($now !== null && ! ContentSectionCategory::query()->where('content_section_id', $section->id)->where('taxonomy_id', $now)->exists()) {
                ContentSectionCategory::query()->create([
                    'site_id' => Cast::int($section->site_id), 'content_section_id' => $section->id, 'taxonomy_id' => $now,
                ]);
            }
        });
    }

    /** @return HasMany<ContentSectionCategory, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(ContentSectionCategory::class, 'content_section_id');
    }

    /**
     * The categories the section covers, its first one (`taxonomy_id`) first, the others by id. One query.
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        $ids = array_map(Cast::int(...), $this->categories()->orderBy('taxonomy_id')->pluck('taxonomy_id')->all());
        $first = $this->taxonomy_id === null ? null : Cast::int($this->taxonomy_id);
        if ($first === null || ! in_array($first, $ids, true)) {
            return array_values($ids);
        }

        return [$first, ...array_values(array_filter($ids, static fn (int $id): bool => $id !== $first))];
    }

    /**
     * The section name in the current UI locale. Standard sections (pages, posts, services…) carry a
     * `section.{key}` translation; a tenant's custom section, with no such key, keeps its stored label.
     * This localizes the admin chrome without translating tenant-authored data.
     */
    public function localizedLabel(): string
    {
        $key = 'section.'.$this->key;

        return Lang::has($key) ? __($key) : $this->label;
    }
}
