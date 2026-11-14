<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;

/**
 * The `Article` structured data of a post for the page head (printed like a product's): what search
 * engines and AI answer engines read for the headline, the dates and who stands behind the text.
 *
 * The dates are the ones the post shows: published is the post's publish time, modified is the last
 * change of its main content in this language (never earlier than published; contentDate()), the
 * date its "Cập nhật" line shows. The site's business is the publisher, and the author too unless
 * the post names its own byline in this language (author_name: a team or department an editor typed,
 * linked to no staff account). Such an author is an Organization that is part of the business
 * (`parentOrganization`). Either way no staff member's name leaves the admin; without a business
 * name the business is left out. A job post carries `JobPosting` instead (JobPosting), never both.
 */
final class ArticleData
{
    /**
     * @param  string|null  $image  the post's own share image (absolute URL), if it has one
     * @param  array<array-key, mixed>|null  $brand  the site's business identity (name, logo)
     * @param  bool  $withByline  false on a look that prints no author line: the business is then the author
     * @return array<string, mixed>
     */
    public static function ld(Entry $post, EntryTranslation $translation, string $url, string $home, string $locale, ?string $image, ?array $brand, bool $withByline = true): array
    {
        $published = $post->published_at;
        $modified = $translation->contentDate($published);

        $description = self::text($translation->seo_description);
        if ($description === '') {
            $description = str((string) $translation->excerpt)->squish()->toString();
        }

        $name = self::text($brand['name'] ?? null);
        $logo = self::text($brand['logo'] ?? null);
        $business = $name !== '' ? ['@type' => 'Organization', 'name' => $name, 'url' => $home] : null;
        $byline = $withByline ? self::text($translation->author_name) : '';
        $author = $byline !== ''
            ? array_filter(['@type' => 'Organization', 'name' => $byline, 'parentOrganization' => $business], fn (mixed $value): bool => $value !== null)
            : $business;

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $translation->title,
            'description' => $description !== '' ? $description : null,
            'image' => $image !== null && $image !== '' ? [$image] : null,
            'datePublished' => $published?->toIso8601String(),
            'dateModified' => $modified?->toIso8601String(),
            'inLanguage' => $locale,
            'mainEntityOfPage' => $url,
            'author' => $author,
            'publisher' => $business !== null && $logo !== ''
                ? $business + ['logo' => ['@type' => 'ImageObject', 'url' => $logo]]
                : $business,
        ], fn (mixed $value): bool => $value !== null);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
