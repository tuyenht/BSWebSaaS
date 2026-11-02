<?php

declare(strict_types=1);

// CI only (the cwv job): the router for PHP's built-in server, answering with the compression
// production's Caddy applies (`encode zstd gzip`, docker/caddy/Caddyfile), so Lighthouse measures
// the bytes a visitor downloads. Chrome asks a plain-http host for gzip, so this answers gzip.
// Run from the project root: php -S 0.0.0.0:8099 -t public scripts/cwv-serve.php

$public = dirname(__DIR__).'/public';
$path = urldecode((string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/'));
$gzip = str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip');

// The text types Caddy's encoder compresses by default; fonts (woff2) and photos are compressed already.
$types = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'json' => 'application/json',
    'svg' => 'image/svg+xml',
    'txt' => 'text/plain; charset=utf-8',
    'xml' => 'application/xml',
];

if ($path !== '/' && ! str_contains($path, '..') && is_file($public.$path)) {
    $type = $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    if ($type === null || ! $gzip) {
        return false; // the built-in server sends the file as it is
    }
    header('Content-Type: '.$type);
    header('Content-Encoding: gzip');
    header('Vary: Accept-Encoding');
    echo gzencode((string) file_get_contents($public.$path), 6);

    return true;
}

ob_start('ob_gzhandler'); // compresses only when the request accepts gzip
require $public.'/index.php';
