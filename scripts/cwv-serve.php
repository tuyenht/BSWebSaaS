<?php

declare(strict_types=1);

// CI only (the cwv job): the router for PHP's built-in server, answering with the compression
// production's Caddy applies (`encode zstd gzip`, docker/caddy/Caddyfile), so Lighthouse measures
// the bytes a visitor downloads. Chrome asks a plain-http host for gzip, so this answers gzip.
// Run from the project root: php -S 0.0.0.0:8099 -t public scripts/cwv-serve.php

$public = dirname(__DIR__).'/public';
$path = urldecode((string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/'));
$gzip = str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip');

// Caddy's encoder skips bodies under 512 bytes and compresses text types and icons; woff2 and
// photos are compressed already, so they pass through as they are.
$minimum = 512;
$types = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'json' => 'application/json',
    'svg' => 'image/svg+xml',
    'txt' => 'text/plain; charset=utf-8',
    'xml' => 'application/xml',
    'ico' => 'image/x-icon',
];

if ($path !== '/' && ! str_contains($path, '..') && is_file($public.$path)) {
    $type = $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    if ($type === null || ! $gzip || filesize($public.$path) < $minimum) {
        return false; // the built-in server sends the file as it is
    }
    header('Content-Type: '.$type);
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    echo gzencode((string) file_get_contents($public.$path), 6);

    return true;
}

// A page is compressed when it accepts gzip, has a body of 512 bytes or more, carries no
// Content-Length of its own and is not a 204 or 304 (which must have no body).
ob_start(static function (string $body) use ($gzip, $minimum): string {
    $sized = preg_grep('/^content-(length|encoding):/i', headers_list()) !== [];
    if (! $gzip || $sized || strlen($body) < $minimum || in_array(http_response_code(), [204, 304], true)) {
        return $body;
    }
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding', false);

    return (string) gzencode($body, 6);
});
require $public.'/index.php';
