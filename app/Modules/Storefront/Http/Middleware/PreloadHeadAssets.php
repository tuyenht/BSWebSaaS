<?php

namespace App\Modules\Storefront\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Early Hints for a storefront page: the `Link` header (which the edge turns into 103 Early Hints)
 * lists what the finished page's <head> loads, read from the HTML itself. The framework's own header
 * middleware reads the list Vite built while the head was drawn, which still holds every block
 * stylesheet when PageRenderer has cut the page down to the ones it uses (BlockStyles::narrow), so it
 * hinted files the page never links and missed the ones it does.
 *
 * A preconnect the head asks for (the media host, when the page leads with a photo) goes first: the
 * edge sends it in the 103 too, so the browser opens that connection while the page itself is still
 * on its way. Every stylesheet the head links follows, in link order, then the font preload; module
 * preloads follow only while the total stays within MAX_HINTS, so a script can never displace a
 * stylesheet.
 * The cap keeps the header small (a CDN refuses an oversized one) and spends the early connection
 * on what blocks rendering first. The middleware keeps no state between requests.
 */
final class PreloadHeadAssets
{
    /** Hints in one header: every stylesheet and the font first, module preloads only up to this many in all. */
    public const int MAX_HINTS = 12;

    /** @param  Closure(Request): SymfonyResponse  $next */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        if ($response instanceof Response && $response->getStatusCode() === 200) {
            $hints = $this->hints((string) $response->getContent());
            if ($hints !== []) {
                $response->headers->set('Link', implode(', ', $hints));
            }
        }

        return $response;
    }

    /**
     * @return list<string> one `<url>; rel="…"; …` entry per hint
     */
    private function hints(string $html): array
    {
        $end = stripos($html, '</head>');
        if ($end === false || ! preg_match_all('~<link\s[^>]*>~i', substr($html, 0, $end), $tags)) {
            return [];
        }

        $connects = $styles = $fonts = $modules = [];
        foreach ($tags[0] as $tag) {
            $attributes = $this->attributes($tag);
            $href = $attributes['href'] ?? '';
            $rel = strtolower($attributes['rel'] ?? '');
            // A URL with a space or a delimiter cannot go in a header's angle brackets.
            if ($href === '' || preg_match('~[\s<>,;]~', $href)) {
                continue;
            }
            if ($rel === 'preconnect' && preg_match('~^https://[^/]+$~i', $href)) {
                $connects[$href] = $this->entry($href, 'preconnect', [], $attributes);
            } elseif ($rel === 'stylesheet') {
                $styles[$href] = $this->entry($href, 'preload', ['as' => 'style'], $attributes);
            } elseif ($rel === 'preload' && ($attributes['as'] ?? '') === 'font') {
                $fonts[$href] = $this->entry($href, 'preload', ['as' => 'font', 'type' => $attributes['type'] ?? null], $attributes);
            } elseif ($rel === 'modulepreload') {
                $modules[$href] = $this->entry($href, 'modulepreload', [], $attributes);
            }
        }

        $hints = [...array_values($connects), ...array_values($styles), ...array_values($fonts)];

        return [...$hints, ...array_slice(array_values($modules), 0, max(0, self::MAX_HINTS - count($hints)))];
    }

    /**
     * @param  array<string, string|null>  $fixed
     * @param  array<string, string>  $attributes
     */
    private function entry(string $href, string $rel, array $fixed, array $attributes): string
    {
        $parts = ['<'.$href.'>', 'rel="'.$rel.'"'];
        foreach ($fixed as $name => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $name.'="'.$value.'"';
            }
        }
        // `crossorigin` must match the tag the page itself uses, or the browser fetches the file twice.
        if (array_key_exists('crossorigin', $attributes)) {
            $parts[] = $attributes['crossorigin'] === '' ? 'crossorigin' : 'crossorigin="'.$attributes['crossorigin'].'"';
        }

        return implode('; ', $parts);
    }

    /** @return array<string, string> the tag's attributes, names in lower case, values decoded */
    private function attributes(string $tag): array
    {
        preg_match_all('~([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', (string) preg_replace('~^<link~i', '', $tag), $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] ??= htmlspecialchars_decode($match[2] ?? $match[3] ?? $match[4] ?? '');
        }

        return $attributes;
    }
}
