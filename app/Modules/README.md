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
- **`Shared/Contracts/`** = interface liên-module CHỈ ở điểm **swap/external** mà design đã
  chốt bọc interface (DomainProvisioner, WHMCS License, AI SDK). KHÔNG ép interface mọi cạnh
  nội bộ.

## Ranh giới được ép thế nào

- **arch test** (`tests/Arch/ModuleBoundariesTest.php`) ép **hướng phụ thuộc acyclic**;
  `Tenancy` không được dùng bất kỳ module tính năng nào. Vi phạm = CI đỏ.
- FK/pivot **xuyên module được phép** (đồ thị quan hệ là MỘT — vd `entry_taxonomies`,
  `product_taxonomies`). Chủ sở hữu pivot = module `Taxonomy`.

## Migration

- **Tập trung** ở `database/migrations/`, đánh số theo tier FK: core `1xxx` · content `2xxx`
  · commerce `3xxx`… (đảm bảo thứ tự FK khi `migrate`). Chỉ chuyển per-module khi extract Phase 3.

## Đóng-gói Composer & eject

- **Hoãn tới Phase 3.** Exporter dời `app/Modules/*` (đã bật theo entitlement) → `packages/*`,
  swap driver, chèn License middleware. Vì ranh giới đã sạch → thao tác **cơ học**.
- Nhắc: ~80% công sức eject là **rút dữ liệu per-tenant (ETL `site_id=X`) + driver-swap +
  hạ RLS→N=1**, không phải di chuyển code. Đừng tối ưu cấu trúc code sớm cho eject.
