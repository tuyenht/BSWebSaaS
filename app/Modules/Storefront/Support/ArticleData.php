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
 * write of its text in this language (never earlier than published). The site's business is both the
 * author and the publisher, so no staff member's name leaves the admin; without a business name the
 * two are left out. A job post carries `JobPosting` instead (JobPosting), never both.
 */
final class ArticleData
{
    /**
     * @param  string|null  $image  the post's own share image (absolute URL), if it has one
     * @param  array<array-key, mixed>|null  $brand  the site's business identity (name, logo)
     * @return array<string, mixed>
     */
    public static function ld(Entry $post, EntryTranslation $translation, string $url, string $home, string $locale, ?string $image, ?array $brand): array
    {
        $published = $post->published_at;
        $modified = $translation->updated_at;
        if ($published !== null && ($modified === null || $modified->lessThan($published))) {
            $modified = $published;
        }

        $description = self::text($translation->seo_description);
        if ($description === '') {
            $description = str((string) $translation->excerpt)->squish()->toString();
        }

        $name = self::text($brand['name'] ?? null);
        $logo = self::text($brand['logo'] ?? null);
        $business = $name !== '' ? ['@type' => 'Organization', 'name' => $name, 'url' => $home] : null;

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
            'author' => $business,
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
