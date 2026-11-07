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
     * The structured data is printed only with the hiring organization's name and a workplace address:
     * a posting missing either would be half-valid, so the page then carries none (ld is null).
     *
     * @param  string|null  $postTemplate  the layout the post renders on (its own, else its category's)
     * @param  array<array-key, mixed>|null  $brand  the site's business identity (name, address_line, address, ward, province)
     * @return array{state: string, ld: array<string, mixed>|null}|null null when the post is not a job post
     */
    public function forPost(Entry $post, EntryTranslation $translation, ?string $postTemplate, string $url, ?array $brand): ?array
    {
        if (! TemplateCatalog::isJob($postTemplate)) {
            return null;
        }

        $deadline = $this->deadline->deadlineOf($post);
        $state = $this->deadline->state($deadline, $this->time->now());
        $organization = $this->text($brand['name'] ?? null);
        $address = $this->address($brand ?? []);

        if ($organization === '' || $address === null) {
            return ['state' => $state, 'ld' => null];
        }

        $description = trim((string) ($translation->excerpt ?? ''));
        if ($description === '') {
            $description = $this->firstParagraph($translation->body);
        }

        return [
            'state' => $state,
            'ld' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'JobPosting',
                'title' => $translation->title,
                'description' => $description !== '' ? $description : null,
                'datePosted' => $post->published_at?->toIso8601String(),
                'validThrough' => $deadline?->endOfDay()->toIso8601String(),
                'url' => $url,
                'hiringOrganization' => ['@type' => 'Organization', 'name' => $organization],
                'jobLocation' => ['@type' => 'Place', 'address' => $address],
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * The workplace as a PostalAddress: the street line (else the whole address), ward and province,
     * or null when the site states no address.
     *
     * @param  array<array-key, mixed>  $brand
     * @return array<string, string>|null
     */
    private function address(array $brand): ?array
    {
        $street = $this->text($brand['address_line'] ?? null);
        $street = $street !== '' ? $street : $this->text($brand['address'] ?? null);
        if ($street === '') {
            return null;
        }

        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $street,
            'addressLocality' => $this->text($brand['ward'] ?? null),
            'addressRegion' => $this->text($brand['province'] ?? null),
            'addressCountry' => 'VN',
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * The first non-empty paragraph of the body as plain text of at most 300 characters: a
     * formatted body (one `html` block) gives its first <p>, a text block its first paragraph.
     */
    private function firstParagraph(mixed $body): string
    {
        $blocks = is_array($body) ? ($body['blocks'] ?? []) : [];
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (! is_array($block) || ! is_array($block['props'] ?? null)) {
                continue;
            }
            $candidates = match ($block['type'] ?? null) {
                'richtext' => is_array($block['props']['paragraphs'] ?? null) ? $block['props']['paragraphs'] : [],
                'html' => preg_match_all('~<p\b[^>]*>(.*?)</p>~is', $this->text($block['props']['html'] ?? null), $found) > 0 ? $found[1] : [],
                default => [],
            };
            foreach ($candidates as $candidate) {
                $plain = str($this->text($candidate))->stripTags()->squish()->limit(300)->toString();
                if ($plain !== '') {
                    return $plain;
                }
            }
        }

        return '';
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
