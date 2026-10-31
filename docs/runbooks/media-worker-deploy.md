# Runbook — Deploy the media serving Worker (ADR-17, PR-3)

> One-time platform setup. Touches **LIVE `files.baoson.net`** (currently the public custom domain
> of the existing `baoson-media` R2 bucket). Run the steps **in order** — step 2 (make the bucket
> private) MUST precede step 3 (Worker route); the epic forbids public+private coexistence.
> Code: `deploy/media-worker/`. Bucket: existing `baoson-media`.

## 0. Auth (creds stay with you — pick ONE)

**Easiest + safest for a one-time setup — OAuth, nothing stored:**
```bash
npm i -g wrangler        # or use npx wrangler ...
wrangler login           # opens a browser, scoped to this session, no token to manage
```

**If you prefer an API token (e.g. for later CI/automation) — a SCOPED token, NOT the Global Key:**
Cloudflare dashboard → **My Profile → API Tokens → Create Token → Custom token**, with:
- Permissions:
  - **Account › Workers Scripts › Edit**   (deploy the Worker)
  - **Account › Workers R2 Storage › Edit** (bucket + object ops)
  - **Zone › Workers Routes › Edit**        (the `files.baoson.net/*` route)
- Account Resources: Include → your account
- Zone Resources: Include → Specific zone → **baoson.net**

Then: `export CLOUDFLARE_API_TOKEN=<the token>` before running wrangler.

> ⚠ **NEVER use the Global API Key** — it is full-account god-mode (every zone, DNS, billing,
> delete-anything); one mistake or leak = whole-account blast radius. Cloudflare officially
> recommends scoped tokens. If the Global Key was shared/typed anywhere, **rotate it** (My Profile
> → API Tokens → Global API Key → Roll).

## 1. Use the existing `baoson-media` bucket (nothing to create)
`baoson-media` already exists and already holds the temp images (`sites/1/media/*`). No
`wrangler r2 bucket create`. It only needs to become **private** (step 2) — the Worker becomes its
sole reader. The existing objects stay and keep serving (via the Worker); they are re-keyed to the
content-hash scheme when the launch is seeded.

## 2. 🔴 Make `baoson-media` private — remove its public custom domain
Dashboard → R2 → `baoson-media` → Settings → Public access / Custom domains → **remove**
`files.baoson.net`. (Frees the hostname for the Worker route in step 3; the Worker then becomes the
bucket's only reader.) Brief window where `files.baoson.net` 404s — fine, no live page uses it yet.

## 3. Deploy the Worker (creates the route + R2 binding)
```bash
node --test deploy/media-worker/test/worker.test.js   # must pass before every deploy
cd deploy/media-worker
wrangler deploy
```
This binds `baoson-media` as `MEDIA` and routes `files.baoson.net/*` to the Worker.

The Worker sends the Content-Type from the key's extension (jpg, jpeg, png, webp, gif, avif, pdf),
never the object's stored metadata; every other extension and every key whose first segment starts
with `_` (uploads in flight) is a 404. Responses carry `X-Content-Type-Options: nosniff` and a
sandbox CSP; a PDF is `Content-Disposition: attachment`.

## 4. Enable Image Transformations on the zone
Dashboard → `baoson.net` → **Speed → Optimization → Image Transformations → Enable** (covers
`cf.image`; free tier ~5k unique transforms/mo — ample for An Vinh). Without it, `?w=` still works
but returns the raw master (no resize).

## 5. Verify (an existing temp image already in the bucket)
```bash
curl -I https://files.baoson.net/sites/1/media/about-bao-on.jpg            # 200 raw master (via Worker)
curl -I "https://files.baoson.net/sites/1/media/about-bao-on.jpg?w=640"    # 200 transformed (smaller)
curl -I "https://files.baoson.net/sites/1/media/about-bao-on.jpg?w=999"    # 400 invalid width (whitelist)
curl -I https://files.baoson.net/_incoming/x.jpg                         # 404 uploads in flight are never public
```

## 6. Rollback
```bash
wrangler delete                  # remove the Worker (frees the route)
```
Then re-attach `files.baoson.net` as the `baoson-media` public custom domain (step 2 reversed).

## After this runbook
Tell the session **"Worker deployed"** → it re-keys the brand images to
`{tenant}/{collection}/{slug}-{hash}.webp` on `baoson-media`, wires the keys into An Vinh content
(via the resolver), and seeds the launch.
