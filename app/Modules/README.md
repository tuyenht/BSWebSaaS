# `app/Modules/` — Modular Monolith (chốt v2.8)

> Diễn giải ADR-12 "package-first" = **ranh-giới-module-first**, KHÔNG tách Composer
> package ngày-1. Đây là **Modular Monolith** (CLAUDE.md §9). Chi tiết normative:
> `docs/design.md` Mục 22.1.

## Quy ước

- Mỗi **bounded context** = một thư mục `app/Modules/<Context>/`, PSR-4 `App\Modules\<Context>\`
  (một autoload root duy nhất — không `composer.json` riêng cho tới Phase 3).
- Bộ khung thư mục trong mỗi module (tạo khi cần, không tạo rỗng sẵn):
  `Models/ · Actions/ · Policies/ · Http/ · Scopes/ · Providers/` và `Contracts/` (nếu module
  có điểm swap/external cần interface).
- **`Tenancy/`** = nền của Gate #1 (Site resolver · `BelongsToSite` · RLS · `SET LOCAL` ·
  Octane reset · Entitlement). **Là module nền — phụ thuộc 0 module khác.** *(Code Gate #1
  sẽ viết vào đây ở prompt riêng — hiện để trống.)*
- **`Shared/Contracts/`** = interface liên-module ở điểm **swap/external** mà design đã chốt bọc
  interface (DomainProvisioner, WHMCS License, AI SDK) VÀ ở cạnh nào mà thiếu nó sẽ thành chu trình
  (vd `Component` cho Theme, `TenantMailConfigurator` cho Forms gửi thư qua cấu hình mail của Admin).
  KHÔNG ép interface mọi cạnh nội bộ.
- **`Shared/Support/`, `Shared/Concerns/`** = tiện ích thuần dùng chung (`Cast`, `TurnstileVerifier`,
  `RichTextSanitizer`, `VideoEmbed`, `HasUlidColumn`) — chỉ phụ thuộc Laravel/PHP, KHÔNG phụ thuộc
  module nào.

## Ranh giới được ép thế nào

- **arch test** ép **hướng phụ thuộc acyclic**: `tests/Arch/ModuleDependencyGraphTest.php` dựng đồ thị
  từ code (mọi tên `App\Modules\<X>` ngoài chú thích, PHP + Blade) và đỏ khi có BẤT KỲ chu trình nào;
  `tests/Arch/ModuleBoundariesTest.php` giữ các cạnh cấm cụ thể (vd `Tenancy` không dùng module tính
  năng nào). Vi phạm = CI đỏ.
- FK/pivot **xuyên module được phép** (đồ thị quan hệ là MỘT — vd `entry_taxonomies`,
  `product_taxonomies`). Chủ sở hữu pivot = module `Taxonomy`.

## Tầng phụ thuộc (acyclic — đồ thị thực tế)

Phụ thuộc chỉ trỏ **lên trên**. `Tenancy` là nền (Gate #1), `Shared` không phụ thuộc module nào.

```
Shared            (interface + tiện ích thuần — không phụ thuộc module nào)
  └─ Tenancy      (RLS + BelongsToSite + Entitlement; nền — chỉ phụ thuộc Shared)
       ├─ Identity    (user + hashing)
       │    ├─ Audit
       │    ├─ Rbac   (role/permission trên nền user; dùng Audit)
       │    ├─ Notifications (thông báo trong ứng dụng: site_notifications + kênh + tuỳ chọn
       │    │                theo người; Cms/Forms/Admin gửi QUA nó, không chiều ngược lại)
       │    ├─ Workflow  (máy trạng thái duyệt, subject-agnostic ở code)
       │    └─ Media     (thư viện ảnh & tệp)
       ├─ Theme       (trình bày; KHÔNG biết content; dùng Media)
       └─ Cms         (content: entries/taxonomy/routing/menu; dùng Theme, Workflow, Media)
            ├─ Catalog      (sản phẩm; dùng Cms)
            ├─ Forms        (biểu mẫu + khách liên hệ; gửi thư qua Shared\Contracts)
            ├─ Storefront   (mặt phẳng public)
            └─ Admin        (mặt phẳng admin — hub: dùng mọi module bên dưới)
       └─ Provisioning (genesis — ráp mọi module theo từng site)
```

Nợ khái niệm (không phải chu trình code, ghi để không tăng thêm): bảng `workflow_log.entry_id` có FK
tới `entries` (Workflow ở code không biết Cms, nhưng schema thì có); `Tenancy` chứa `ContentSection`,
`PermalinkConfig`, `SiteProfile` (khái niệm website); `Rbac\Registry\PermissionRegistry` liệt kê quyền
nội dung; `Shared\Contracts\Component` là hợp đồng block storefront. Gỡ khi có dự án phi-website
dùng lõi.

Cạnh được arch test ép (ngoài "Tenancy không dùng module tính năng nào"):

- **Theme content-agnostic**: chỉ render primitive (`PageRenderer::render(type,…)`), KHÔNG
  chạm Cms/Storefront/Admin/Provisioning/Identity/Rbac/Audit → mọi module nạp component vào
  registry của Theme mà không tạo chu trình.
- **Cms** không trỏ lên consumer của nó (Storefront/Admin/Provisioning).
- **Identity** nằm dưới **Rbac**; **Storefront** độc lập với **Admin**.
- Module tính năng không chạm thẳng `Tenancy\Models` (dùng bề mặt công khai: trait
  `BelongsToSite`, các lớp `Tenancy\Context` — `Tenancy`, `CurrentSite`, `SiteLocale`,
  `SiteTime`, `PermalinkConfig`, `SiteDirectory`, `SiteIdentity`, `ContentSections`, `SiteSettings`
  (chỉ đọc nhóm trong `READABLE_GROUPS`; nhóm chứa thông tin đăng nhập như `email`, `oauth_social`
  không mở) — và đường ghi `Tenancy\Actions`,
  vd `ConfigureSiteLocale` bật/tắt ngôn ngữ theo hạn mức gói). Việc chạy nền quét mọi website đi qua
  `Tenancy::eachSite()` (vào ngữ cảnh từng site) hoặc `Tenancy::siteIds()` (chỉ lấy id theo keyset,
  không vào site) — không module nào tự nạp bảng `sites`. Ngoại lệ: shell `Admin` + orchestrator
  `Provisioning` được đọc model registry của Tenancy (vd `ContentSection`). Luật được ép
  **từng module một** (`tests/Arch/ModuleBoundariesTest.php`), không còn ngoại lệ: 5 chỗ đọc thẳng
  có từ trước khi luật được sửa đã chuyển sang `Tenancy\Context` (27/09).

## Hợp đồng đăng ký module

Mỗi module công bố bề mặt qua **ServiceProvider** (khai ở `bootstrap/providers.php`). 5 điểm
mở rộng, đều **idempotent + additive**:

| Bề mặt | Cách đăng ký | Ví dụ |
|---|---|---|
| **Component** (block storefront) | `app(ComponentRegistry::class)->register($type, $component)` trong `boot()`; registry CHÍNH LÀ allow-list render + validate (BlockRenderer + ValidPageJson dùng chung) | `CmsServiceProvider` đăng ký `latest_posts` (`LatestPostsComponent`) |
| **Permission** | khai trong `Rbac\Registry\PermissionRegistry` (catalog + role template); `ProvisionSite` vật chất hóa theo từng site | `entries.*`, `media.*` |
| **Section (nav)** | một dòng `content_sections` (key/label/`entry_type`/`permission_prefix`/`taxonomy_id`/template), seed bởi `ProvisionSite` | `pages`, `posts` (Tin tức) |
| **Nav item** | tự suy ra: `Admin\Navigation\AdminNav` liệt kê `content_sections` ∩ quyền `{permission_prefix}.view` của user | link "Tin tức" |
| **Route** | `routes/web.php`, một trong 3 mặt phẳng (platform host · `/admin` · storefront `Route::fallback`) — mỗi mặt phẳng mang middleware riêng (tenant context · `site.active` · `auth` · `tenant.member`) | `admin.entries.*`, catch-all storefront |

Theme tự khai namespace `theme::` (`View::addNamespace`) + các section tĩnh; module khác nạp
component data-bound vào CÙNG registry singleton đó.

## Migration

- **Tập trung** ở `database/migrations/`, đánh số theo tier FK: core `1xxx` · content `2xxx`
  · commerce `3xxx`… (đảm bảo thứ tự FK khi `migrate`). Chỉ chuyển per-module khi extract Phase 3.

## Đóng-gói Composer & eject

- **Hoãn tới Phase 3.** Exporter dời `app/Modules/*` (đã bật theo entitlement) → `packages/*`,
  swap driver, chèn License middleware. Vì ranh giới đã sạch → thao tác **cơ học**.
- Nhắc: ~80% công sức eject là **rút dữ liệu per-tenant (ETL `site_id=X`) + driver-swap +
  hạ RLS→N=1**, không phải di chuyển code. Đừng tối ưu cấu trúc code sớm cho eject.
