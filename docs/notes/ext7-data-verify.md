# EXT-7 data-continuity verify

> Read-only re-run · 2026-06-24 17:44 (+07) · evidence-only, no remediation.
> Method: bootstrap superuser (`POSTGRES_USER`) into app DB (`POSTGRES_DB`) → bypasses RLS even FORCE → real counts.
> Committed as EXT-7 closure evidence (gate blessed by Tuyền 24/06).

## Prod-stack pg (`bsweb_prod_pg`)
- volume mount: **`bsweb-prod_pgdata_prod`** → `/var/lib/postgresql/data` (own volume, NOT dev); + bind `/opt/bswebsaas/prod/docker/postgres/init` → `/docker-entrypoint-initdb.d` (ro)
- POSTGRES_USER / DB: **`bsweb` / `bswebsaas`**
- sites (id, name): **`1 | Cơ Khí Chính Xác An Vinh`** (1 row) — schema present (40 tables via `\dt`)
- content count by site_id (`entries`): **`site_id 1 → 1`**
- av1 host→site (`domains`): **`site_id 1 ← av1.baoson.net` (is_primary=t)** · `site_domains` table does not exist (host resolution via `domains`)

## Dev-stack pg (`bsweb_dev_pg`)
- volume mount: **`bsweb-dev_pgdata`** → `/var/lib/postgresql/data` (separate); + bind `/opt/bswebsaas/dev/docker/postgres/init`
- POSTGRES_USER / DB: **`bsweb` / `bswebsaas`**
- sites (id, name): **`1 | A`**, **`2 | B`** (2 rows — throwaway fixtures; 40 tables)
- content count by site_id (`entries`): **0 rows** (no entries any site)
- av1 host→site (`domains`): **a.test → site 1, b.test → site 2** — **`av1.baoson.net` absent in dev**

## Public av1.baoson.net
- curl status + final URL: **`HTTP/2 200`** (server: FrankenPHP Caddy) · `final: 200 https://av1.baoson.net/` (no redirect)
- markers: `<title>Cơ Khí Chính Xác An Vinh</title>` · `section.hero` (subheading "Chào mừng đến với Cơ Khí Chính Xác An Vinh") · `section.latest-posts` (empty `<ul>`) · `section.cta` → = scaffold seed (`ProvisionSite::seedHomepage` = hero/latest_posts/cta)

## Kết luận sơ bộ (1 dòng — KHÔNG tự tuyên đóng gate)
Prod cô lập trên volume riêng `pgdata_prod`, phục vụ data của chính nó (1 site An Vinh + `av1`→site1, scaffold seed); dev chỉ fixtures A/B (0 entries, không có `av1`); không lẫn dữ liệu — **chờ anh bless EXT-7**.
