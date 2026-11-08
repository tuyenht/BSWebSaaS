#!/usr/bin/env node
/*
 * Static server for the admin-preview screenshots. Serves the rendered admin HTML
 * (storage/app/admin-preview/*.html) with its real assets so a headless browser can shoot it:
 *   /screens/<name>.html  → storage/app/admin-preview/<name>.html (preview host rewritten to relative)
 *   /livewire-<hash>/*    → the installed livewire dist, so Alpine/Livewire boot (tabs, modal work)
 *   POST /livewire/update → an empty valid response (no back end behind this static copy)
 *   everything else       → public/ (the built assets live under public/build)
 *
 * Not shipped to any environment — a local dev helper driven by run.sh. Port via PORT (default 8098).
 */
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const SCREENS = process.env.ADMIN_PREVIEW_DIR || join(repo, 'storage/app/admin-preview');
const PUBLIC = join(repo, 'public');
const LIVEWIRE_DIST = join(repo, 'vendor/livewire/livewire/dist');
const HOST_TOKEN = 'preview.platform.test';
const PORT = Number(process.env.PORT || 8098);
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.mjs': 'text/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ico': 'image/x-icon', '.json': 'application/json', '.map': 'application/json' };

// Livewire prints its <script> and its styles (which hide wire:loading parts until a request runs)
// only once per app instance; a multi-page render leaves them on the first page only. Borrow the
// dashboard's for any page that lacks them, so Alpine boots and loading parts stay hidden everywhere.
async function livewireTag(pattern) {
    try {
        const dashboard = await readFile(join(SCREENS, 'dashboard.html'), 'utf8');
        return (dashboard.match(pattern) || [''])[0]
            .replace(new RegExp(`https?://${HOST_TOKEN}/`, 'g'), '/');
    } catch {
        return '';
    }
}
const LIVEWIRE_SCRIPT = /<script src="https?:\/\/[^"]*\/livewire-[^>]*><\/script>/;
const LIVEWIRE_STYLES = /<!-- Livewire Styles --><style[^>]*>[\s\S]*?<\/style>/;

const send = (res, code, type, body) => { res.writeHead(code, { 'content-type': type }); res.end(body); };

createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    try {
        if (req.method === 'POST') {
            return send(res, 200, TYPES['.json'], JSON.stringify({ components: [], assets: [] }));
        }
        if (url.pathname.startsWith('/screens/')) {
            let html = (await readFile(join(SCREENS, normalize(url.pathname.slice('/screens/'.length))), 'utf8'))
                .replace(new RegExp(`https?://${HOST_TOKEN}/`, 'g'), '/');
            if (!html.includes('/livewire.js') && !/\/livewire-[0-9a-f]+\//.test(html)) {
                html = html.replace('</body>', `${await livewireTag(LIVEWIRE_SCRIPT)}\n</body>`);
            }
            if (!html.includes('<!-- Livewire Styles -->')) {
                html = html.replace('</head>', `${await livewireTag(LIVEWIRE_STYLES)}\n</head>`);
            }
            return send(res, 200, TYPES['.html'], html);
        }
        const livewire = url.pathname.match(/^\/livewire-[0-9a-f]+\/(.+)$/);
        const file = livewire ? join(LIVEWIRE_DIST, normalize(livewire[1])) : join(PUBLIC, normalize(url.pathname));
        return send(res, 200, TYPES[extname(file)] || 'application/octet-stream', await readFile(file));
    } catch {
        return send(res, 404, 'text/plain', 'not found');
    }
}).listen(PORT, '127.0.0.1', () => console.log(`admin-preview server on http://127.0.0.1:${PORT}`));
