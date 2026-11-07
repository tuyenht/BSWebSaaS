<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\JobDeadline;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Theme\Rendering\TemplateCatalog;

/**
 * What the job layout of a post needs from the core: the deadline state and the `JobPosting`
 * structured data for the page head (printed like a product's). Both exist only for a post whose
 * layout belongs to the job set; any other post, and any theme without one, gets nothing.
 */
final readonly class JobPosting
{
    public function __construct(private JobDeadline $deadline, private SiteTime $time) {}

    /**
     * @param  string|null  $postTemplate  the layout the post renders on (its own, else its category's)
     * @return array{state: string, ld: array<string, mixed>}|null null when the post is not a job post
     */
    public function forPost(Entry $post, EntryTranslation $translation, ?string $postTemplate, string $url, ?string $organization): ?array
    {
        if (! TemplateCatalog::isJob($postTemplate)) {
            return null;
        }

        $deadline = $this->deadline->deadlineOf($post);
        $description = trim((string) ($translation->excerpt ?? ''));

        return [
            'state' => $this->deadline->state($deadline, $this->time->now()),
            'ld' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'JobPosting',
                'title' => $translation->title,
                'description' => $description !== '' ? $description : $translation->title,
                'datePosted' => $post->published_at?->toIso8601String(),
                'validThrough' => $deadline?->endOfDay()->toIso8601String(),
                'url' => $url,
                'hiringOrganization' => $organization !== null && $organization !== ''
                    ? ['@type' => 'Organization', 'name' => $organization]
                    : null,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }
}
