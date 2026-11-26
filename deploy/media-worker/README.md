# Media Worker (`baoson-media`)

The only public reader of the private R2 bucket `baoson-media`, on `files.baoson.net/*`. Deployed apart
with wrangler, never by the app's deploy engine: `docs/runbooks/media-worker-deploy.md`.

| Request | Answer |
|---|---|
| `GET/HEAD /{key}` | the raw object, Content-Type from the extension only; Range, If-* |
| `GET /{key}?w=320` (320/640/960/1280/1600/2048) | cf.image resize to avif/webp/jpeg from Accept; another width is 400 |
| first segment starting with `_`, unknown extension | 404 |
| `/{old folder}/…/{file}` listed in `vars.LEGACY_PREFIXES` | 301 to `/{code}/{file}` (query kept) when that copy holds the same bytes; else the old object |

Every answer carries `X-Content-Type-Options: nosniff` and a sandbox CSP.

- `src/index.js`: the handler. `src/keys.js`: which paths are servable keys, their types, the legacy map.
- `wrangler.jsonc`: bucket binding, route, and `vars.LEGACY_PREFIXES` (old folder ending in `/` →
  website code). Add a line only after that website's `media:rekey-site` ran on the bucket.
- Tests (CI runs them in the `frontend` job; run before every deploy):
  `node --test deploy/media-worker/test/worker.test.js`
