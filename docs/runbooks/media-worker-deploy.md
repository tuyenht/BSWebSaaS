# Runbook — Deploy the media serving Worker (ADR-17, PR-3)

> One-time platform setup. Touches **LIVE `files.baoson.net`** (since 14/11/2026 a proxied record in
> front of this Worker; before that the public custom domain of the `baoson-media` R2 bucket). Run the steps **in order** — step 2 (make the bucket
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

## 2. 🔴 Make `baoson-media` private — the media host is a plain proxied record, never an R2 custom domain
Done for `files.baoson.net` on 14/11/2026 (`docs/ops-log.md`). For any media host (a new platform host,
or the media host of a site exported to run on its own):
- Remove the bucket's public custom domain (Dashboard → R2 → bucket → Settings → Custom domains), so the
  Worker is the bucket's only reader.
- Point the host at the Worker with a **proxied `AAAA 100::`** record (a placeholder address: the Worker
  route answers every request). This is what gives the host HTTP/3: an R2 custom domain is served
  HTTP/2 only and its DNS record does not advertise h3, even when the zone has HTTP/3 on.
- The R2-owned record must be gone before the AAAA can be created, so there is a gap of about a second
  in which resolvers may cache "no such name" for the zone's SOA minimum (1800 s here). Do it when few
  visitors are on, after a rehearsal on a throwaway host (record + route, check `?w=640` returns
  `image/avif`, raw GET 200, Range 206, `w=999` → 400 and `alpn=h3`).
- Rollback: delete the AAAA, re-attach the custom domain; SSL becomes active after about 3–4 minutes,
  with the same short gap and negative caching as above. This makes every key of the bucket public again,
  uploads in flight under `_incoming/` included, so it is a last resort.

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
`cf.image`). Without it, `?w=` still works but returns the raw master (no resize).
Never switch it off while the Worker serves the host: a passed-through master carries no error, so the
Worker keeps it a year under the resized URL. A master no wider than the asked width also comes back
unchanged when transformations are on, so the output format alone cannot tell the two apart.

**Plan limits for the whole platform** (Cloudflare docs, read 14/11/2026). Every site's images go
through this one Worker, so the limits are shared by all sites:
- Workers Free: 100,000 requests a day (resets 00:00 UTC). Past it the route answers error 1027 —
  the route is *fail closed*, and *fail open* would not help, since nothing stands behind the `AAAA 100::`.
  Measured 07–14/11 with one demo site: 133–15,227 requests a day.
- Image Transformations Free: 5,000 unique transformations a calendar month (image + width; the format
  is not counted). Past it, sizes already cached keep working and a new size answers 9422, which the
  Worker turns into the raw master.
- Both need the paid plans before the platform carries many sites; that is the owner's decision.

## 5. Verify (an existing temp image already in the bucket)
```bash
curl -I https://files.baoson.net/sites/1/media/about-bao-on.jpg            # 200 raw master (via Worker)
curl -I "https://files.baoson.net/sites/1/media/about-bao-on.jpg?w=640"    # 200 transformed (smaller)
curl -I "https://files.baoson.net/sites/1/media/about-bao-on.jpg?w=999"    # 400 invalid width (whitelist)
curl -I https://files.baoson.net/_incoming/x.jpg                         # 404 uploads in flight are never public
curl -I https://files.baoson.net/sites/1/media/page.html                  # 404 only known file types (by extension) are served
#   every answer carries "X-Content-Type-Options: nosniff"; a .pdf is "Content-Disposition: attachment"
curl -s -r 0-1 -D - -o range.bin https://files.baoson.net/sites/1/media/about-bao-on.jpg
#   206 + "Content-Range: bytes 0-1/<size>" + "Accept-Ranges: bytes" — a background video needs this
#   (Safari/iOS plays a <video> only from ranged answers); a range past the end answers 416
```

## 6. Rollback
```bash
cd deploy/media-worker && wrangler rollback   # back to the previous Worker version; the host keeps answering
```
Do not `wrangler delete`: the host is a placeholder record (step 2), so without the Worker it answers
nothing. To give up the Worker altogether, first delete the `AAAA 100::` record, then re-attach the
public custom domain (step 2 reversed), knowing that this makes every key of the bucket public,
uploads in flight under `_incoming/` included.

## After this runbook
Tell the session **"Worker deployed"** → it re-keys the brand images to
`{tenant}/{collection}/{slug}-{hash}.webp` on `baoson-media`, wires the keys into An Vinh content
(via the resolver), and seeds the launch.
