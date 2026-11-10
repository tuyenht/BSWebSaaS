<?php

declare(strict_types=1);

namespace App\Modules\Cms\Navigation;

use Closure;

/**
 * Where each special link (MenuRules::DYNAMIC) leads on this site, worked out from what the site
 * actually has. A key with nothing behind it gets null, and the website leaves that item out, the
 * same way it leaves out a link to an unpublished page:
 *  - home → '/', group → '#' (a label that goes nowhere);
 *  - call / zalo / mail / map → the hotline (else the phone), the Zalo link, the email and the
 *    address of the site's own details (SiteIdentity), as tel: / https / mailto: / a map search;
 *  - search, sitemap, cart, account, login → a page a module provides. The module that builds the
 *    page registers it here at boot and answers per site (cart only when the site sells, account
 *    and login only when customer sign-in exists). Nothing registers them yet, so today they stay
 *    hidden on the website.
 *
 * Holds boot-time closures only, no request state, so a singleton is Octane-safe.
 */
final class SpecialLinks
{
    /** @var array<string, Closure(): (string|null)> key → the site path of its page, or null when off for this site */
    private array $pages = [];

    /**
     * @param  Closure(): (string|null)  $path  the unprefixed site path, or null when the page is off for the current site
     */
    public function register(string $key, Closure $path): void
    {
        $this->pages[$key] = $path;
    }

    /**
     * @param  array<string, mixed>  $contact  the site's details in the page's language (SiteIdentity::for)
     * @return array{href: string, internal: bool}|null internal = a site path the caller prefixes with the language
     */
    public function resolve(string $key, array $contact): ?array
    {
        if (! array_key_exists($key, MenuRules::DYNAMIC)) {
            return null;
        }
        $fixed = MenuRules::DYNAMIC[$key];
        if ($fixed === '#') {
            return ['href' => '#', 'internal' => false];
        }
        if ($fixed !== null) {
            return ['href' => $fixed, 'internal' => true];
        }

        $href = match ($key) {
            'call' => $this->tel($contact['hotline'] ?? $contact['phone'] ?? null) ?? $this->tel($contact['phone'] ?? null),
            'zalo' => $this->zalo($contact['social_links'] ?? null),
            'mail' => $this->mail($contact['email'] ?? null),
            'map' => $this->map($contact['address'] ?? null),
            default => null,
        };
        if ($href !== null) {
            return ['href' => $href, 'internal' => false];
        }

        $page = isset($this->pages[$key]) ? ($this->pages[$key])() : null;

        return is_string($page) && str_starts_with($page, '/') && ! str_starts_with($page, '//')
            ? ['href' => $page, 'internal' => true]
            : null;
    }

    private function tel(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }
        $digits = preg_replace('/[^+\d]/', '', $phone) ?? '';

        return preg_match('/\A\+?\d{3,20}\z/', $digits) === 1 ? 'tel:'.$digits : null;
    }

    private function zalo(mixed $links): ?string
    {
        $url = is_array($links) && is_string($links['zalo'] ?? null) ? trim($links['zalo']) : '';

        return preg_match('~\Ahttps?://(?:www\.)?zalo\.me/[A-Za-z0-9._/-]{1,64}\z~', $url) === 1 ? $url : null;
    }

    private function mail(mixed $email): ?string
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:'.$email : null;
    }

    private function map(mixed $address): ?string
    {
        $address = is_string($address) ? trim($address) : '';

        return $address === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
    }
}
