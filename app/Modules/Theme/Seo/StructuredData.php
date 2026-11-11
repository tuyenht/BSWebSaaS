<?php

declare(strict_types=1);

namespace App\Modules\Theme\Seo;

use App\Modules\Tenancy\Support\BusinessHours;
use App\Modules\Theme\Chrome\StorefrontChrome;

/**
 * A page's structured data as ONE schema.org `@graph` (the page head prints it in a single
 * `application/ld+json` script). Pure: it reads only what the page already holds, so it adds no query.
 *
 * Stable ids: `{origin}/#organization`, `{origin}/#website`, `{page url}#webpage`, `{page url}#breadcrumb`.
 * The full Organization (logo, address, contact point, social profiles, branches, opening hours) and the
 * WebSite sit on the home page only; every other page points to them by `@id`. Each page has a WebPage node
 * (CollectionPage for a list, ItemPage for a product) that is part of the website and names its breadcrumb.
 * The Article, Product, JobPosting and FAQPage nodes the controllers build keep every field they have, with
 * the business named by its Organization `@id` instead of a copy. A 404 and the admin's preview have none.
 */
final class StructuredData
{
    private const int MAX_LIST_ITEMS = 50;

    /**
     * @param  array<string, mixed>  $page  the head's view data: brand, branchPlaces, canonical, lang, title,
     *                                      description, ldPage, posts, cards, websiteLd, productLd, jobLd,
     *                                      articleLd, faqLd, breadcrumb, notFound, preview
     * @return array<string, mixed>|null
     */
    public static function build(array $page): ?array
    {
        if (! empty($page['notFound']) || ! empty($page['preview'])) {
            return null;
        }
        $brand = is_array($page['brand'] ?? null) ? $page['brand'] : [];
        $name = self::text($brand['name'] ?? null);
        $origin = rtrim(url('/'), '/');
        $orgId = $name !== '' ? $origin.'/#organization' : null;
        $siteId = $name !== '' ? $origin.'/#website' : null;
        $home = ! empty($page['websiteLd']) && is_array($page['websiteLd']);
        $url = self::text($page['canonical'] ?? null);
        // The home page's address ends with a slash, as the organization's does, so the ids agree.
        if ($url !== '' && rtrim($url, '/') === $origin) {
            $url = $origin.'/';
        }
        $webPageId = $url !== '' ? $url.'#webpage' : null;

        $graph = [];
        if ($orgId !== null && $home) {
            $graph[] = self::organization($brand, $orgId, $origin, is_array($page['branchPlaces'] ?? null) ? $page['branchPlaces'] : []);
        }
        if ($siteId !== null && $home) {
            $graph[] = ['@type' => 'WebSite', '@id' => $siteId] + self::strip($page['websiteLd']) + ($orgId !== null ? ['publisher' => ['@id' => $orgId]] : []);
        }

        $crumbs = self::breadcrumb($page['breadcrumb'] ?? null, $url);
        if ($webPageId !== null) {
            $graph[] = self::webPage($page, $webPageId, $url, $siteId, $crumbs !== null && is_string($crumbs['@id']) ? $crumbs['@id'] : null);
        }
        if ($crumbs !== null) {
            $graph[] = $crumbs;
        }
        foreach (['articleLd', 'productLd', 'jobLd', 'faqLd'] as $key) {
            if (! empty($page[$key]) && is_array($page[$key])) {
                $graph[] = self::withOrganization(self::strip($page[$key]), $orgId, $name, self::text($brand['logo'] ?? null));
            }
        }

        return $graph === [] ? null : ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * The graph as the script's text; `<` and `>` are escaped so it is safe inline. Null when there is none.
     *
     * @param  array<string, mixed>  $page
     */
    public static function json(array $page): ?string
    {
        $graph = self::build($page);

        if ($graph === null) {
            return null;
        }
        $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : null;
    }

    /**
     * @param  array<array-key, mixed>  $brand
     * @param  array<array-key, mixed>  $branches
     * @return array<string, mixed>
     */
    private static function organization(array $brand, string $id, string $origin, array $branches): array
    {
        $phone = self::text($brand['phone'] ?? null) ?: (self::text($brand['hotline'] ?? null) ?: null);
        $email = self::text($brand['email'] ?? null) ?: null;
        // address_line (the split key) wins; the combined address string keeps older sites populated.
        $street = self::text($brand['address_line'] ?? null) ?: (self::text($brand['address'] ?? null) ?: null);
        $locality = self::text($brand['ward'] ?? null) ?: null;
        $region = self::text($brand['province'] ?? null) ?: null;
        $address = $street !== null || $locality !== null || $region !== null
            ? self::filled(['@type' => 'PostalAddress', 'streetAddress' => $street, 'addressLocality' => $locality, 'addressRegion' => $region, 'addressCountry' => 'VN'])
            : null;

        // The site's own social profiles: only web links; Zalo given as a phone number or an id becomes its zalo.me link.
        $sameAs = [];
        foreach (is_array($brand['social_links'] ?? null) ? $brand['social_links'] : [] as $network => $link) {
            $link = $network === 'zalo' ? StorefrontChrome::zalo($link) : $link;
            if (is_string($link) && preg_match('~\Ahttps?://~i', $link) === 1) {
                $sameAs[] = $link;
            }
        }
        // Opening hours make the business a LocalBusiness (the type that carries them).
        $hours = BusinessHours::from($brand['business_hours'] ?? null)?->openingHoursSpecification() ?? [];
        $contact = $phone !== null || $email !== null
            ? self::filled(['@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => $phone, 'email' => $email])
            : null;

        return self::filled([
            '@type' => $hours !== [] ? 'LocalBusiness' : 'Organization',
            '@id' => $id,
            'name' => self::text($brand['name'] ?? null),
            'legalName' => self::text($brand['legal_name'] ?? null),
            'url' => $origin.'/',
            'logo' => self::text($brand['logo'] ?? null),
            'telephone' => $phone,
            'email' => $email,
            'taxID' => self::text($brand['tax_id'] ?? null),
            'address' => $address,
            'contactPoint' => $contact,
            'sameAs' => $sameAs,
            'location' => array_values($branches),
            'openingHoursSpecification' => $hours,
        ]);
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private static function webPage(array $page, string $id, string $url, ?string $siteId, ?string $breadcrumbId): array
    {
        $type = in_array($page['ldPage'] ?? null, ['CollectionPage', 'ItemPage'], true) ? $page['ldPage'] : 'WebPage';
        $node = self::filled([
            '@type' => $type,
            '@id' => $id,
            'url' => $url,
            'name' => self::text($page['title'] ?? null),
            'description' => self::text($page['description'] ?? null),
            'inLanguage' => self::text($page['lang'] ?? null),
            'isPartOf' => $siteId !== null ? ['@id' => $siteId] : null,
            'breadcrumb' => $breadcrumbId !== null ? ['@id' => $breadcrumbId] : null,
        ]);
        if ($type === 'CollectionPage') {
            $items = self::listItems($page);
            if ($items !== []) {
                $node['mainEntity'] = ['@type' => 'ItemList', 'itemListElement' => $items];
            }
        }

        return $node;
    }

    /**
     * The members the list page shows (its first window), as ListItems.
     *
     * @param  array<string, mixed>  $page
     * @return list<array<string, mixed>>
     */
    private static function listItems(array $page): array
    {
        $rows = is_array($page['cards'] ?? null) && $page['cards'] !== [] ? $page['cards'] : (is_array($page['posts'] ?? null) ? $page['posts'] : []);
        $items = [];
        foreach ($rows as $row) {
            $title = is_array($row) ? self::text($row['title'] ?? null) : '';
            $href = is_array($row) ? self::text($row['url'] ?? null) : '';
            if ($title === '' || $href === '') {
                continue;
            }
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $title, 'url' => url($href)];
            if (count($items) >= self::MAX_LIST_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null null for a trail of fewer than two levels
     */
    private static function breadcrumb(mixed $crumbs, string $url): ?array
    {
        if (! is_array($crumbs) || count($crumbs) < 2) {
            return null;
        }
        $items = [];
        foreach (array_values($crumbs) as $i => $crumb) {
            $crumb = is_array($crumb) ? $crumb : [];
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'] ?? '', 'item' => $crumb['url'] ?? ''];
        }

        return ['@type' => 'BreadcrumbList', '@id' => ($url !== '' ? $url : rtrim(url()->current(), '/')).'#breadcrumb', 'itemListElement' => $items];
    }

    /**
     * A node the controllers built, with the business it names replaced by the Organization's `@id`.
     * Without a business name there is no Organization to point to, so the node stays as built.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function withOrganization(array $node, ?string $orgId, string $name, string $logo): array
    {
        if ($orgId === null) {
            return $node;
        }
        // A typed, named reference: a reader of this page alone still learns who the business is.
        $ref = ['@type' => 'Organization', '@id' => $orgId, 'name' => $name];
        $type = $node['@type'] ?? null;
        if ($type === 'Article') {
            $node['author'] = $ref;
            $node['publisher'] = $logo !== '' ? $ref + ['logo' => ['@type' => 'ImageObject', 'url' => $logo]] : $ref;
        } elseif ($type === 'JobPosting') {
            $node['hiringOrganization'] = $ref;
        }

        return $node;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<string, mixed>
     */
    private static function strip(array $node): array
    {
        unset($node['@context']);

        /** @var array<string, mixed> $node */
        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function filled(array $node): array
    {
        return array_filter($node, fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
