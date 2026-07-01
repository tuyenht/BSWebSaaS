# Epic Work Order — Hệ thống Media: Storage + Serving + Resilience (BSWebSaaS)

> **Lưu đề xuất:** `docs/notes/media-system-epic.md`
> **Loại:** Claude Code epic work order + decision record. Tự chứa đủ spec.
> **Supersede:** thay TẤT CẢ prompt media trước (`media-storage-tier1-tier2*`). Đây là bản cuối.
> **Trạng thái:** council đã thẩm định nhiều vòng, Tuyền chốt hướng. Chạm schema + RLS + thêm service ngoài (Worker) → **STOP-gate (PR-0) bắt buộc**, và **one-PR-one-thing** (làm tuần tự, không gộp).

---

## 0. Kiến trúc chốt (toàn cảnh để Claude Code nắm trước khi làm)

- **Storage:** R2 `baoson-media` (location apac, **PRIVATE** — chỉ Worker đọc qua binding). Lưu **một master WebP** mỗi ảnh.
- **Key (SEO + content-addressed):** `{tenant}/{collection}/{slug}-{hash}.{ext}`
  - `tenant` = slug tenant ổn định (cô lập trong bucket dùng chung).
  - `collection` = enum **thô, cố-định-lúc-upload** (`products|projects|team|content|general`) — **KHÁC** ltree category (cái sửa được, ở DB).
  - `slug` = `Str::slug(title)` (tín hiệu SEO chính; xử lý dấu tiếng Việt).
  - `hash` = 12 hex đầu SHA-256 **nội dung file** → dedup + deterministic + cache-bust khi đổi nội dung. Tính **một lần lúc upload, lưu DB, không tái sinh khi render**.
  - `ext` = `.webp` (raster) / `.svg` (vector giữ nguyên).
- **Serving:** **Cloudflare Worker** = plane duy nhất. Đọc R2 (binding), set `Cache-Control` immutable, transform `cf.image` (format=auto + width whitelist), fallback raw-master.
- **Tách URL (công tắc):** mọi URL qua **một resolver đa-driver** `config('media.driver') ∈ {cloudflare, imgproxy, static}`. Switch = đổi 1 env + clear view cache.
- **Resilience ladder:** L0 resolver · L1 edge cache · L2 master-as-raw-fallback · L3 rclone R2→B2 · L4 imgproxy cold-ready. **Bỏ L5** (hot standby + DNS riêng).
- **Markup:** `<img>` + `srcset`/`sizes` + `width`/`height` + `alt`. format=auto → **không `<picture>`**.

---

## 1. Trình tự PR (one-PR-one-thing)

| PR | Nội dung | Phụ thuộc |
|---|---|---|
| **PR-0** | Survey + ghi ADR vào `design.md` (doc-only) | — |
| **PR-1** | Storage layer Laravel: migration + model + `MediaStorage` (key content-hash) | PR-0 |
| **PR-2** | URL resolver đa-driver (L0) + accessor model ủy thác | PR-1 |
| **PR-3** | Cloudflare Worker serving plane (cf.image + whitelist + raw-fallback) | PR-2 (config) |
| **PR-4** | rclone R2→B2 cron (L3) | độc lập |
| **PR-5** | imgproxy cold-ready (L4 — không vào serving path) | PR-2 (driver branch) |
| **PR-6** | Helper render `<img>` responsive (srcset/sizes/width/height/alt) | PR-2 |
| **PR-7** | Công cụ transform động Pexels-style (full-param) + parity R2 ↔ Local (imgproxy) | PR-2,3,5 |

**Ngoài epic này (epic SEO riêng sau):** ImageObject schema, image sitemap, per-tenant image domain, L5 hot standby.

---

## 2. Bất biến toàn cục (mọi PR phải tự kiểm)

- Multi-tenant: bảng có `site_id` + RLS **fail-closed + FORCE**; model `BelongsToSite`; không lộ chéo tenant; runtime role NOSUPERUSER/NOBYPASSRLS.
- KHÔNG cột `url`; URL **chỉ** qua resolver derive từ config. KHÔNG hardcode domain ở bất kỳ đâu (kể cả JSON scaffold/Blade).
- KHÔNG lộ id thô (key dùng slug + content-hash). KHÔNG đẻ content type mới · KHÔNG bảng pages · KHÔNG EAV/meta cho field hay-query · KHÔNG float tiền.
- Soft-delete + RLS kèm `deleted_at IS NULL`.
- Width whitelist trong Worker (chống bơm phồng transform). Bucket **private** (không public-access).

---

## PR-0 — Survey + ADR (doc-only, STOP-gate)

**Survey, báo cáo 🟦/🟨/✅, CHƯA sửa code:**
1. Đã có bảng/model media chưa? Luồng upload động đã tồn tại chưa?
2. Scaffold An Vinh tham chiếu ảnh thế nào — tĩnh pre-optimized (Option C) qua path, hay flow động?
3. Liệt kê mọi chỗ hardcode URL tuyệt đối `https://files.baoson.net/...`.
4. Disk `r2` đã có key `url` chưa? Module Tenancy lấy tenant hiện tại + **slug ổn định** ra sao?
5. Cấu trúc namespace/thư mục để đặt model/service/resolver.

**Cổng quyết định:**
- Nếu **GĐ hiện tại chỉ ảnh tĩnh, chưa có upload động** → PR-1/PR-2 bảng+service có thể premature. **STOP, hỏi Tuyền:** làm full epic, HAY chỉ làm PR-2 (resolver) + PR-3 (Worker) + dọn URL hardcode trước, hoãn bảng/service (PR-1) tới khi có upload?
- Nếu thấy xung đột spec (bảng media đã có cột `url`, hoặc path theo ngày) → STOP, báo, đề xuất migration sửa, chờ duyệt.

**ADR (doc-only commit):** ghi quyết định kiến trúc media (key SEO content-hash · bucket private + Worker plane · resolver đa-driver · resilience ladder L0–L4 · bỏ imgproxy khỏi path chính · format=auto). **Xác định số ADR kế tiếp từ `design.md` live, KHÔNG đoán.** **Diff preview trước commit.** Commit doc **tách riêng** code.

---

## PR-1 — Storage layer (Laravel)

### Tests trước (red-first Pest)
- `media_assets` có `storage_key`, KHÔNG có cột `url`; model không mass-assign `url`.
- `buildKey` ra đúng `{tenantSlug}/{collection}/{slug}-{hash}.{ext}`; slug từ `Str::slug` (dấu tiếng Việt OK); collection slugify; hash khớp `^[0-9a-f]{12}$`; ext lowercase.
- **Content-hash deterministic:** cùng bytes → cùng key (dedup); bytes khác → key khác.
- **Gate#1 RLS:** `media_assets` cô lập theo `site_id`; site A không truy vấn được từ context site B (fail-closed).
- Soft-delete + RLS: bản `deleted_at` không lộ.

### Migration
```php
Schema::create('media_assets', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained()->cascadeOnDelete(); // cột tenant — RLS bám vào đây
    $table->string('storage_key');           // {tenant}/{collection}/{slug}-{hash}.{ext} — KHÔNG phải URL
    $table->string('collection', 64);        // enum thô fixed-at-upload (KHÁC ltree category)
    $table->string('mime', 100)->nullable();
    $table->unsignedBigInteger('size')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->unique(['site_id', 'storage_key']);
});
// Sau migration: ENABLE + FORCE ROW LEVEL SECURITY, policy fail-closed kèm deleted_at IS NULL.
```

### Model
```php
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Modules\Media\Services\MediaUrlResolver; // PR-2

class MediaAsset extends Model
{
    use BelongsToSite, SoftDeletes;
    protected $fillable = ['storage_key', 'collection', 'mime', 'size']; // KHÔNG 'url'

    /** URL canonical — ủy thác cho resolver (đa-driver). KHÔNG gọi Storage::url trực tiếp. */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => app(MediaUrlResolver::class)->url($this->storage_key),
        );
    }
}
```

### Service — chỉ lưu master, KEY content-hash, KHÔNG set CacheControl (Worker set)
```php
namespace App\Modules\Media\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorage
{
    private const DISK = 'r2';

    /** @return string storage_key để lưu DB — KHÔNG trả URL */
    public function store(UploadedFile $file, string $siteSlug, string $collection, string $title): string
    {
        $contents = file_get_contents($file->getRealPath());
        $key = $this->buildKey($siteSlug, $collection, $title, $file, $contents);

        // Bucket private + Worker set cache → KHÔNG cần CacheControl, KHÔNG cần ACL.
        Storage::disk(self::DISK)->put($key, $contents);

        return $key;
    }

    private function buildKey(string $siteSlug, string $collection, string $title, UploadedFile $file, string $contents): string
    {
        $hash = substr(hash('sha256', $contents), 0, 12);          // content-addressed
        $ext  = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'webp'));

        return sprintf('%s/%s/%s-%s.%s',
            Str::slug($siteSlug), Str::slug($collection), Str::slug($title), $hash, $ext);
    }
}
```
> **Ngữ nghĩa thay ảnh:** thay file = gọi `store()` lại → bytes khác → hash khác → key mới → cập nhật `storage_key`; object cũ để rclone/lifecycle dọn. URL đổi là ĐÚNG (ảnh khác).

---

## PR-2 — URL resolver đa-driver (L0 — công tắc switch)

### Tests trước
- `driver=cloudflare`: `url($key)` → `{base}/{key}`; `url($key, 800)` → `{base}/{key}?w=800`.
- `driver=static`: luôn `{base}/{key}` (raw master, không width).
- `driver=imgproxy`: ra URL có chữ ký HMAC hợp lệ + path xử lý.
- Đổi `config('media.driver')` → output đổi driver tương ứng (chứng minh switch bằng 1 config).

### Config
```php
// config/media.php
return [
    'driver'  => env('MEDIA_DRIVER', 'cloudflare'),                 // cloudflare | imgproxy | static
    'cloudflare' => ['base' => env('MEDIA_CF_BASE', 'https://files.baoson.net')],
    'imgproxy'   => [
        'base' => env('MEDIA_IMGPROXY_BASE'),                       // vd https://img.baoson.net
        'key'  => env('IMGPROXY_KEY'),                              // hex
        'salt' => env('IMGPROXY_SALT'),                             // hex
        'source_bucket' => env('MEDIA_SOURCE_BUCKET', 's3://baoson-media'),
    ],
    'widths'  => [320, 640, 960, 1280, 1600, 2048],                 // whitelist responsive
];
```

### Resolver
```php
namespace App\Modules\Media\Services;

class MediaUrlResolver
{
    public function url(string $key, ?int $width = null): string
    {
        return match (config('media.driver')) {
            'imgproxy' => $this->imgproxy($key, $width),
            'static'   => $this->base('cloudflare').'/'.ltrim($key, '/'),   // raw master, không transform
            default    => $this->cloudflare($key, $width),
        };
    }

    private function cloudflare(string $key, ?int $w): string
    {
        $url = $this->base('cloudflare').'/'.ltrim($key, '/');
        return $w ? $url.'?w='.$w : $url;                                   // Worker đọc ?w → cf.image
    }

    private function imgproxy(string $key, ?int $w): string
    {
        // HMAC-signed (verify lại đúng format theo version imgproxy khi kích hoạt — xem PR-5)
        $resize = $w ? "rs:fit:{$w}:0" : 'rs:fit:2048:0';
        $source = rtrim(config('media.imgproxy.source_bucket'), '/').'/'.ltrim($key, '/');
        $path   = "/{$resize}/plain/{$source}@webp";
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256',
                pack('H*', config('media.imgproxy.salt')).$path,
                pack('H*', config('media.imgproxy.key')), true)), '+/', '-_'), '=');
        return $this->base('imgproxy')."/{$sig}{$path}";
    }

    private function base(string $driver): string
    {
        return rtrim(config("media.{$driver}.base"), '/');
    }
}
```

---

## PR-3 — Cloudflare Worker serving plane

**Prereq (việc tay Tuyền, ngoài code):** bật **Images → Transformations** cho zone; tạo Worker binding R2 (bucket private); route Worker tại `files.baoson.net/*`.

`wrangler.jsonc`:
```jsonc
{
  "name": "baoson-media",
  "main": "src/index.js",
  "compatibility_date": "2026-06-01",
  "r2_buckets": [{ "binding": "MEDIA", "bucket_name": "baoson-media" }],
  "routes": [{ "pattern": "files.baoson.net/*", "zone_name": "baoson.net" }]
}
```

`src/index.js`:
```js
const ALLOWED_WIDTHS = new Set([320, 640, 960, 1280, 1600, 2048]);
const CACHE_CONTROL = 'public, max-age=31536000, immutable';
const ORIGIN_PREFIX = '/__origin/'; // path serve raw — phá vòng lặp cf.image

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    // (a) Path nội bộ → serve raw master từ R2 (không transform). Nguồn cho cf.image, tránh loop.
    if (url.pathname.startsWith(ORIGIN_PREFIX)) {
      return serveRaw(env, decodeURIComponent(url.pathname.slice(ORIGIN_PREFIX.length)));
    }

    const key = decodeURIComponent(url.pathname.slice(1));
    if (!key) return new Response('Not found', { status: 404 });

    const wParam = url.searchParams.get('w');
    const width = wParam ? parseInt(wParam, 10) : null;
    if (width !== null && !ALLOWED_WIDTHS.has(width)) {
      return new Response('Invalid width', { status: 400 });
    }

    // (b) Không width → serve raw master (canonical / L2 fallback).
    if (width === null) return serveRaw(env, key);

    // (c) Có width → transform qua cf.image, nguồn = path nội bộ raw, format=auto.
    const originUrl = `${url.origin}${ORIGIN_PREFIX}${encodeURIComponent(key)}`;
    const resp = await fetch(originUrl, {
      cf: { image: { width, format: 'auto', fit: 'scale-down' } },
      headers: { Accept: request.headers.get('Accept') || '' },
    });
    if (!resp.ok) return serveRaw(env, key); // transform lỗi → L2: trả master, ảnh không mất

    const headers = new Headers(resp.headers);
    headers.set('Cache-Control', CACHE_CONTROL);
    return new Response(resp.body, { status: resp.status, headers });
  },
};

async function serveRaw(env, key) {
  const obj = await env.MEDIA.get(key);
  if (!obj) return new Response('Not found', { status: 404 });
  const headers = new Headers();
  obj.writeHttpMetadata(headers);
  headers.set('Cache-Control', CACHE_CONTROL); // Tier 1 dời vào Worker
  headers.set('ETag', obj.httpEtag);
  return new Response(obj.body, { headers });
}
```
> Chỗ thiết-kế-sẵn-cho-tương-lai (chưa code bây giờ, ghi chú trong file): route `host → tenant prefix` cho per-tenant domain; token-check media nháp ngay đầu `fetch`. Hai cái này slot vào cùng Worker, không đổi URL công khai.
> Tùy chọn siết: `__origin/*` có thể chặn request ngoài bằng cách kiểm header `Via` (chỉ cho subrequest). Bỏ qua được vì media vốn công khai.

---

## PR-4 — rclone R2→B2 (L3 resilience + chống mất data)

**Prereq:** tạo bucket B2 `baoson-media-backup`; remote `r2` và `b2` (đều S3-compatible) trong `rclone.conf`.

Script `scripts/media-backup.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
rclone sync r2:baoson-media b2:baoson-media-backup \
  --fast-list --transfers 16 --checkers 32 \
  --log-file /var/log/media-backup.log --log-level INFO
```
Cron (vd 2h sáng hằng ngày):
```
0 2 * * * /opt/bswebsaas/scripts/media-backup.sh
```
> B2 vừa là backup chống mất data, vừa là **nguồn cho imgproxy** khi R2 chết (PR-5). Master content-hash nên sync idempotent, không trùng lặp.

---

## PR-5 — imgproxy cold-ready (L4 — KHÔNG vào serving path)

Mục tiêu: sẵn sàng switch trong ~5 phút khi Cloudflare Images chết, **không chạy nóng**. Tài liệu + file cấu hình, **không deploy**.

`docs/runbooks/imgproxy-failover.md` + `deploy/imgproxy/docker-compose.yml`:
```yaml
services:
  imgproxy:
    image: ghcr.io/imgproxy/imgproxy:latest
    environment:
      IMGPROXY_KEY: ${IMGPROXY_KEY}            # khớp config/media.php
      IMGPROXY_SALT: ${IMGPROXY_SALT}
      IMGPROXY_USE_S3: "true"
      IMGPROXY_S3_ENDPOINT: ${S3_ENDPOINT}     # R2 hoặc B2 (đổi 1 dòng khi R2 chết)
      AWS_ACCESS_KEY_ID: ${S3_KEY}
      AWS_SECRET_ACCESS_KEY: ${S3_SECRET}
      IMGPROXY_MAX_SRC_RESOLUTION: "25"
      IMGPROXY_STRIP_METADATA: "true"
    ports: ["8080:8080"]
```
Runbook ghi rõ quy trình switch: trỏ DNS `img.baoson.net` → host imgproxy → đổi `MEDIA_DRIVER=imgproxy` → `php artisan view:clear`. Đổi `IMGPROXY_S3_ENDPOINT` sang B2 nếu R2 cũng chết.
> Chữ ký HMAC trong resolver (PR-2) phải khớp `IMGPROXY_KEY/SALT`. **Verify đúng format theo version imgproxy khi kích hoạt** (test dựng 1 ảnh qua nó). Khuyến nghị: cron health-check hàng tuần dựng 1 ảnh để standby không "chết thầm".

---

## PR-6 — Helper render `<img>` responsive

Một Blade component / helper sinh `<img>` từ `MediaAsset` + `alt` (alt do caller truyền — gánh tín hiệu đa ngữ ở tầng này):
```php
// resources/views/components/responsive-image.blade.php (điều chỉnh theo tầng component thật)
@props(['media', 'alt', 'sizes' => '100vw', 'displayWidth' => 1280, 'loading' => 'lazy'])
@php
    $resolver = app(\App\Modules\Media\Services\MediaUrlResolver::class);
    $widths = config('media.widths');
    $srcset = collect($widths)
        ->map(fn ($w) => $resolver->url($media->storage_key, $w)." {$w}w")
        ->implode(', ');
@endphp
<img
    src="{{ $resolver->url($media->storage_key, $displayWidth) }}"
    srcset="{{ $srcset }}"
    sizes="{{ $sizes }}"
    alt="{{ $alt }}"
    loading="{{ $loading }}"
    decoding="async" />
```
> SVG (logo/icon): bỏ qua component này, nhúng `<img>`/inline-SVG thẳng — không transform. Hero LCP: truyền `loading="eager"` + cân nhắc `fetchpriority="high"`. `width`/`height` thật nên set theo tỉ lệ ảnh (chống CLS) — bổ sung khi biết kích thước hiển thị.

---

## PR-7 — Công cụ transform động (Pexels-style) + parity R2 ↔ Local

> Thêm 2026-07-01 (chốt với Tuyền). Mở rộng resolver PR-2 (chỉ `?w`) thành API transform đầy đủ, chạy **chuẩn trên CẢ R2 lẫn Local**, đổi bằng config.

**Vấn đề:** `cf.image` **chỉ chạy với R2 + Cloudflare** (resize ở edge). Triển khai **local** (dev / standalone không-Cloudflare) không có cf.image → cần plane transform khác.

**Chốt — KHÔNG đẻ resizer mới:** dùng **imgproxy (PR-5)** làm transform plane cho local. imgproxy đọc **local filesystem HOẶC S3 (R2/B2)**, HMAC-signed, free OSS, **cùng resolver + cùng whitelist**. ⇒ promote imgproxy từ "L4 cold" → **driver chủ động** cho mọi triển khai không-Cloudflare. cf.image vẫn là plane prod cho R2.

**Pairing (driver auto suy từ disk; vẫn override được):**

| Triển khai | `MEDIA_DISK` | `MEDIA_DRIVER` | Engine | Transform |
|---|---|---|---|---|
| Prod (An Vinh, Cloudflare) | `r2` | `cloudflare` | cf.image edge | ✅ full (metered) |
| Standalone / local-prod | `local` *(hoặc `r2`)* | `imgproxy` | imgproxy (local/S3 source) | ✅ full (self-host, free) |
| Dev nhanh | `local` | `static` | raw master | ❌ no resize |

→ `MEDIA_DRIVER` **mặc-định-suy-từ** `MEDIA_DISK` (`r2`→`cloudflare` · `local`→`imgproxy`). Ca thường = **đổi DUY NHẤT `MEDIA_DISK`**; chỉ set `MEDIA_DRIVER` khi muốn ép (vd R2 nhưng dùng imgproxy thay cf.image).

**API resolver (mở rộng từ `?w` → full param):**
- `transform($key, ['w'=>800,'h'=>600,'fit'=>'cover','q'=>80,'f'=>'auto','dpr'=>2])` + `preset($key,'card')`.
- Luồng: validate params theo whitelist `config('media.allow')` (loại/clamp ngoài range) → ký (imgproxy HMAC native; cf.image dựa Worker-whitelist) → driver build URL. `url($key, ?int $w)` cũ giữ nguyên (back-compat).

**Map vocab → engine (cùng output thị giác):**

| Param | cf.image (R2) | imgproxy (local/S3) |
|---|---|---|
| `w`/`h` | `width`/`height` | `rs:fit\|fill:w:h` |
| `fit` cover/contain/crop/pad/scale-down | `fit=` | resizing-type + `g:` |
| `q` | `quality` | `q:` |
| `f=auto/webp/avif` | `format=auto` (Accept) | `@webp`/`@avif` + Accept |
| `dpr` | `dpr=` | `dpr:` |
| `gravity` | `gravity=` | `g:` |

**Presets** (`config/media.presets`, dùng srcset storefront + admin): thumb·card·og·hero. **Warm** (tùy chọn `MEDIA_TRANSFORM_WARM`): job pre-gen thang preset lúc upload → storefront không bao giờ chạm resize-lạnh (bảo hiểm LCP) — áp cho cả 2 driver.

**Bảo mật (bắt buộc):** whitelist **MỌI param** (giá trị rời rạc → bound không-gian transform, chống bơm-phồng/DoS **dù key media đoán-được** — anti-enumerate đã waived) ở CẢ resolver lẫn Worker/imgproxy; bucket private; **raw-master fallback** (L2) khi transform lỗi. Switch driver = no-url-column (ADR-17) → URL derive lúc render → đổi config + `view:clear` → mọi ảnh đổi engine, **KHÔNG migration**.

**🔴 Gap đã VERIFY (01/07, đọc `MediaUrlResolver` + `config/media.php`):**
- **Local-serving CHƯA chạy:** resolver hiện KHÔNG có nhánh local — `cloudflare`/`static` đều trỏ `files.baoson.net` (Worker đọc R2 binding), `imgproxy` trỏ `source_bucket` mặc định `s3://baoson-media`. ⇒ `MEDIA_DISK=local` **GHI được nhưng SERVE hỏng** (URL trỏ R2/CF, không tới file local). Để local chạy đầy đủ phải thêm nhánh **`local`**: `Storage::disk('local')->url($key)` (raw, cần `public` disk + `storage:link`) HOẶC imgproxy `IMGPROXY_USE_LOCAL` + `source_bucket=local`. **Bắt buộc khi kích hoạt local-serving** (đây là câu trả lời thật cho "R2/local đều chạy đầy đủ?": tầng ghi xong, **tầng serve local còn thiếu nhánh này**).
- **cf.image CHƯA ký:** nhánh `cloudflare` chỉ dựa width-whitelist (KHÔNG HMAC như imgproxy đã có). P4 mở full-param + key media đoán-được (anti-enumerate đã waived) → khuyến nghị **HMAC CẢ 2 driver** (Worker verify chữ ký) để chặn billing-DoS, không chỉ whitelist.
- **`widths` vs `allow`:** `config/media.php` đã có `widths`(320–2048); P4 thêm `allow`(đa-param) phải **hợp nhất**, tránh 2 nguồn whitelist.

**Files:** mở rộng `MediaUrlResolver` (full-param + sign + nhánh local + nhánh imgproxy-local) · `config/media.php` (+`allow` whitelist params · +`presets` · disk→driver default) · Worker PR-3 (parse full params + whitelist) · imgproxy PR-5 (bật local-source, vào path khi `driver=imgproxy`) · (tùy) warm-job + `MediaObserver` purge-derivative khi Replace/Delete.

**Sequencing:** sau DAM P1–P3 (xem `plans/260630-2327-media-dam-upload/` §P4). Không chặn An Vinh launch (ảnh pre-optimized). Phụ thuộc PR-2/3/5.

---

## CI gate §6 (áp cho MỌI PR có code — tự chứng minh xanh)

Chạy và **dán output thật**: `pint` · `larastan`/phpstan (max level) · `pest` (test mới + Arch + **Gate#1 RLS** + query-budget). Test sau khi sửa code dùng `docker compose run --rm --no-deps` (tránh OPcache cũ).

## Commit discipline (mọi PR)

Conventional commits; **không** ref AI/Claude. Code và doc commit **riêng**; one-PR-one-thing. Anchor sửa file lớn theo chuỗi nội dung, không số dòng.

## Definition of Done (toàn epic)

- [ ] PR-0 survey báo cáo; nếu cần, Tuyền duyệt scope; ADR vào `design.md` (diff preview, commit doc riêng).
- [ ] PR-1..6 mỗi PR: test đỏ-trước nay xanh; `pint`+`larastan`+`pest` xanh có output; không vi phạm bất biến mục 2.
- [ ] Không cột `url`, không URL hardcode; RLS FORCE + fail-closed trên `media_assets`.
- [ ] Bucket R2 private; Worker set Cache-Control; width whitelist hoạt động; raw-fallback hoạt động khi transform lỗi.
- [ ] Switch driver chứng minh được bằng đổi 1 env (`cloudflare`↔`static` tối thiểu; `imgproxy` verify khi kích hoạt).
- [ ] rclone cron chạy, B2 có bản sao master.
- [ ] imgproxy cold-ready: runbook + compose sẵn, KHÔNG chạy nóng.
- [ ] PR-7: `transform()` full-param chạy cả `cloudflare` (R2) lẫn `imgproxy` (local/S3); whitelist mọi param + raw-fallback; switch bằng config (`MEDIA_DISK`/`MEDIA_DRIVER`); presets + (tùy) warm.

> Lưu ý kênh: đây là spec đã thẩm định để Claude Code thực thi + chạy gate §6 xác nhận. Mọi "xanh/verified" chỉ tính sau khi Claude Code chạy gate thật.
