# Runbook — Deploy the media serving Worker (ADR-17, PR-3)

> One-time platform setup. Touches **LIVE `files.baoson.net`** (currently an R2 public custom
> domain). Run the steps **in order** — step 3 (remove public domain) MUST precede step 4 (Worker
> route), the epic forbids public+private coexistence. Code: `deploy/media-worker/`.

## 0. Prereqs (on your machine, creds stay with you)
```bash
npm i -g wrangler                 # or: npx wrangler ...
wrangler login                    # browser OAuth — preferred (no token in any file)
# OR a SCOPED token (NOT the Global API Key):
#   export CLOUDFLARE_API_TOKEN=...   # perms: Account>Workers Scripts:Edit + Workers R2 Storage:Edit + Zone:baoson.net
```
⚠ **Do NOT use the Global API Key** (full-account god-mode). If it was shared anywhere, rotate it.

## 1. Create the PRIVATE R2 bucket
```bash
wrangler r2 bucket create bswebsaas-media
```
Leave it **private** — do NOT attach a public custom domain (the Worker is the only reader).

## 2. (carry the temp images over — optional, for the An Vinh launch)
The 4 temp images currently live in the OLD public bucket `baoson-media` at `sites/1/media/*`.
They are throwaway under the new scheme; re-keying + seeding happens in a later step. You may skip
this now and seed real keys later.

## 3. 🔴 Remove the public custom domain on `files.baoson.net`
Dashboard → R2 → `baoson-media` → Settings → Public access / Custom domains → **remove**
`files.baoson.net`. (This frees the hostname for the Worker route; until removed, the route in
step 4 conflicts.) Brief window where `files.baoson.net` 404s — fine, no live page uses it yet.

## 4. Deploy the Worker (creates the route + R2 binding)
```bash
cd deploy/media-worker
wrangler deploy
```
This binds `bswebsaas-media` as `MEDIA` and routes `files.baoson.net/*` to the Worker.

## 5. Enable Image Transformations on the zone
Dashboard → `baoson.net` → **Speed → Optimization → Image Transformations → Enable** (covers
`cf.image`; free tier ~5k unique transforms/mo — ample for An Vinh). Without it, `?w=` still works
but returns the raw master (no resize).

## 6. Verify
```bash
wrangler r2 object put bswebsaas-media/test/probe.webp --file=<any.webp>
curl -I https://files.baoson.net/test/probe.webp           # 200 raw master
curl -I "https://files.baoson.net/test/probe.webp?w=640"   # 200 transformed (smaller)
curl -I "https://files.baoson.net/test/probe.webp?w=999"   # 400 invalid width (whitelist)
wrangler r2 object delete bswebsaas-media/test/probe.webp
```

## 7. Rollback
```bash
wrangler delete                   # remove the Worker (frees the route)
```
Then re-attach `files.baoson.net` as the `baoson-media` public custom domain (step 3 reversed).

## After this runbook
Tell the session "Worker deployed" → it re-keys the brand images to `{tenant}/{collection}/{slug}-{hash}.webp`
on `bswebsaas-media`, wires the keys into An Vinh content (via the resolver), and seeds the launch.
