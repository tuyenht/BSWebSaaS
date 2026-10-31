# Data & Performance Standards — Nền tảng multi-tenant scale (ADR-18)

> Chuẩn NORMATIVE cho nền dữ liệu đa-tenant khi scale. Freeze index ở `design.md` Phụ lục A (ADR-18);
> đây là chi tiết **why / trade-off / con-số / phương-án-loại**. CLAUDE.md có checklist mệnh lệnh ngắn.
> **Bối cảnh:** greenfield (data demo được DROP + re-seed, KHÔNG migration giữ data); đích ~10.000+
> **site** (1 tenant N site), 100M+ rows — đọc **"≥30M/BẢNG"** là ngưỡng vận hành, không phải tổng.
> Ưu tiên: chất lượng → hiệu năng → vận hành → tiến độ. Cô lập = **`site`** (`sites.id` = `site_id`),
> KHÔNG "tenant" (ADR-03: tenant 1—N site) — partition-key/index/scope ĐỀU theo `site_id`.

## Hiện trạng đã verify (GATE-0, 07/07)
- PK = `bigint identity` khắp nơi + `ulid` cho thực thể lộ ra (ADR-16) — chỉ `jobs_table` framework dùng uuid (exempt). ✅
- Cô lập: `site_id` + **RLS FORCE fail-closed**; `set_config('app.current_site_id',…,is_local=true)` (sống qua PgBouncer txn-pool). ✅
- `BelongsToSite` global scope **bơm `WHERE site_id = ?` TƯỜNG MINH** (bind) + fail-closed `1=0` + stamp on create. ✅
- Read-path tenant **đã trong explicit transaction**: `SetCurrentSite` chạy TRỌN request trong `Tenancy::enter` = `connection()->transaction()`. ✅
- **CHƯA partition** (D10 measure-first). CI thực: Pint · Larastan(max) · Pest(unit/feature/arch/query-budget) · migrate · FE-build. **KHÔNG chạy**: Infection/mutation · Rector · Lighthouse · axe (doc-drift §6 — đừng coi là "đã bảo vệ").

## Quyết định

### B · Partitioning — measure-first CÓ TRIGGER (không hoãn mơ hồ)
Greenfield xóa lý do gốc hoãn (migration 5000 site). Nhưng partition-now = over-engineer ở scale hiện tại + gánh ops solo-dev + **HASH-by-`site_id` KHÔNG cô lập whale** (rows whale rải đều mọi partition) → whale vẫn phải tách-DB (ADR-03). PG17 với 1 bảng ~100M + index-dẫn-`site_id` + keyset chạy tốt; partition lãi ở **≥~50M/BẢNG** hoặc archive-thời-gian.
- **Trigger (đo được, không cảm tính):** một bảng ứng viên chạm **≥30M rows** (pg_stat) **HOẶC** p99-query breach → mới partition. Chọn **30M (không 50M)** để retrofit còn nhanh (copy ít hơn).
- **Ứng viên + chiến lược pre-commit:** HASH `site_id` = `entries · products · media · mediables` (✅ tồn tại) + `orders/order_items` (⏳ **CHƯA tồn tại** — khi commerce build); RANGE `created_at` + **pg_partman** auto-tạo-kỳ (chống quên-kỳ → **INSERT-FAIL prod**) = `form_submissions · audit_logs` (audit_logs global).
- ⚠ **Trigger cần DETECTOR (audit 07/07):** "≥30M / p99-breach" chỉ bắn nếu có monitoring `pg_stat_user_tables` (row-count) + `pg_stat_statements` (p99). KHÔNG detector = measure-first thành *never-until-broken*. Dựng detector = task ops khi bảng lớn dần — KHÔNG phải giờ (bảng hiện tí hon).
- **KHÔNG partition:** config · RBAC (D6 team-key) · lookup · `tenants` · `domains` · `sites`.
- **Whale = tách-DB** (ADR-03 escape-hatch) là câu trả lời whale CHÍNH, KHÔNG phải partition.

### A · ID/PK — GIỮ `bigint identity + ulid` (ADR-16), single-PK now
Partition-by-`site_id` **bắt buộc** `site_id ∈ PK` ⇒ composite `(site_id, id)`. Nhưng:
- `id` global-unique (identity) → Eloquent giữ `$primaryKey='id'` (find/relation OK trong tenant-ctx; global scope thêm `site_id` → prune). Friction MODEL thấp.
- 🔴 Chi phí THẬT của composite-PK-now = **FK cross-table phải composite** `(site_id, ref_id)` (Postgres cấm `UNIQUE(id)` đơn trên bảng site-partitioned) → cascade toàn content-graph (~15 bảng con), **thuế MÃI MÃI** cho solo-dev khi 99% đời bảng còn nhỏ.
- ⇒ **single-PK now**; retrofit → partitioned (composite PK) khi chạm 30M = **1 lần/bảng, có kế hoạch** (create-partitioned + copy + swap qua pg_partman, cửa-sổ-bảo-trì; trigger sớm ⇒ copy rẻ). **Chi phí này ghi RÕ ở đây, không giấu.**

### C · Read/Write split — design-intent, KHÔNG enforce
ADR-15 single-origin, chưa replica. Dùng read/write-connection abstraction của Laravel (config-ready thêm replica sau) + đánh dấu analytics-read "replica-eligible". **KHÔNG gate CI** cho hạ tầng chưa tồn tại.
- ⚠ **Ràng buộc C↔D (audit 07/07 — KHÔNG phải flip-config tầm thường):** Laravel định tuyến MỌI query TRONG transaction → **write connection**. `SetCurrentSite` bọc TRỌN request trong transaction (cần cho SET LOCAL tenant-ctx) ⇒ read-replica sẽ **KHÔNG nhận traffic** nếu chỉ thêm config `read`/`write`. Kích hoạt split THẬT phải giải **tenant-context trên read-connection** (set GUC trên replica KHÔNG-qua-write-txn, HOẶC tách read-request GET ra khỏi write-txn — nối `READ ONLY` ở Trục D). Ghi rõ để "thêm replica sau" không vỡ kỳ vọng.

### D · Tenant-context dưới pooling — codify bất-biến + tinh chỉnh prod
- **Bất biến (đã có → codify chống-regress):** mọi query tenant trong explicit transaction (`Tenancy::enter`); global scope giữ `site_id=?` TƯỜNG MINH (BelongsToSite) — **thiết yếu cho partition-pruning** vì `current_setting()` là STABLE, không phải hằng plan-time (RLS runtime-prune kém hơn). Arch-test: raw tenant query phải kèm `site_id`.
- **Prod-scale (thêm):** `tenancy.assert=sample` (KHÔNG `strict` — strict = dev/CI; tránh round-trip assert mỗi request) · `SET TRANSACTION READ ONLY` cho GET (nhẹ pool; request bọc-txn giữ connection suốt đời request) · **cache domain→site_id O(1)** (Valkey) + **negative-cache host-không-map** (chống miss-flood DoS) — hiện `SetCurrentSite` query `domains` mỗi request **KHÔNG cache** = gap hot-path cần vá.

## Chuẩn xuyên suốt (áp MỌI bảng/list)
- **Index:** dẫn-đầu `site_id` KHỚP shape `(site_id → lọc → sort)`; partial `WHERE deleted_at IS NULL`; BRIN cho append-only-time; evidence pg_stat_statements; không over-index (mỗi index = phí ghi).
  - **Ngoại lệ FK (council 28/09):** xoá dòng cha ⇒ Postgres tìm dòng con bằng `WHERE fk = $1` (không `site_id`, bỏ qua RLS) ⇒ `(site_id, fk)` chỉ phục vụ được bằng cách quét toàn index (đo 5M dòng: 316 ms/lượt; index dẫn đầu `fk`: 0,06 ms). Bảng cha bị xoá cứng ⇒ index dẫn đầu cột FK (nullable ⇒ partial `IS NOT NULL`); FK ghép `(site_id, x)` chỉ tính index `(site_id, x, …)` hoặc dẫn đầu `x` — index dẫn `site_id` khác vẫn quét cả site (site lớn). Bảng cha không bao giờ xoá cứng, hoặc bảng con chỉ vài dòng mỗi site ⇒ ghi vào danh sách miễn của `ForeignKeyIndexCoverageTest` kèm lý do; guard không thấy đường xoá ⇒ thêm đường xoá cứng cho bảng cha trong danh sách = index bảng con trong cùng thay đổi. FK ghép `(site_id, fk)` (tra theo 1 site, dùng lại index sẵn có) = đường nâng cấp lúc retrofit partition (§A), không làm bây giờ.
  - **Ngoại lệ bảng chung của nền tảng (chủ dự án ký 31/10/2026):** bảng chung của nền tảng (danh sách D6, không chia theo website) được bắt đầu chỉ mục bằng cột lọc chính, phải có số đo. Số đo (số dòng, thời gian trước/sau) ghi trong migration. Áp dụng đầu tiên: `audit_logs` (`2038_01_01_000000_add_filter_indexes_to_audit_logs.php`, đo 5M dòng: trang đầu 1.105 ms → 0,04 ms, một người 390–459 ms → 0,24–3,6 ms) và tìm theo tên trên `users`/`sites` (chỉ mục trigram `pg_trgm` trên đúng biểu thức bỏ dấu mà câu tìm dùng, `2039_01_01_000100_add_name_search_indexes_to_users_and_sites.php`, đo với 20M dòng nhật ký: 450 → 58 ms mỗi trang). Bảng của website (có RLS) vẫn dẫn đầu `site_id`.
- **Pagination:** keyset cursor + tie-break unique `(…, id)`; cấm `OFFSET` list lớn; cấm `SELECT *`.
- **Aggregation:** rollup tăng-dần per `site_id` / materialized view; KHÔNG `COUNT(*)` live bảng lớn.
- **Cache:** domain→site_id + negative-cache + anti-stampede (lock/SWR); config/RBAC; fragment tag-invalidation; edge full-page khách-vô-danh.
- **Seeding (re-seedable):** **COPY** (không INSERT); phân bố **LỆCH** (vài whale + long-tail — để query-plan giống prod); ANALYZE sau load.
- **Pooling gotchas:** tránh LISTEN/NOTIFY · cursor WITH HOLD · temp-table xuyên-transaction (vỡ transaction-pooling).
- **Noisy-neighbor:** `statement_timeout` + monitor per-query.
- **Query Budget:** đã enforced (Pest) — đổi index/PK/partition KHÔNG được làm N+1 rơi khỏi budget.

## Phương án ĐÃ LOẠI (formal — để ADR ghi lại)
- **schema-per-tenant / database-per-tenant:** 10k site = 10k schema/DB → ops + connection + migration-fanout địa ngục; RLS shared-schema đã cô lập đủ.
- **UUIDv4:** random phá index-locality (page-split, WAL bloat).
- **UUIDv7:** lý do duy nhất (export/client-gen) không phải ưu tiên hiện tại; đổi lại PK-rộng nhân MỌI secondary index + friction Eloquent single-PK.
- **partition-now:** over-partition ở scale hiện tại; hash không giải whale; cascade composite-PK đầu cơ.
- **composite-PK-now:** thuế composite-FK-mãi-mãi trên content-graph **nặng hơn** 1 retrofit-có-kế-hoạch.

## Liên kết
ADR-03 (RLS 2 lớp + escape-hatch tách-DB) · ADR-12 (export N=1) · ADR-15 (single-origin, chưa replica) · ADR-16 (bigint+ulid) · ADR-17 (media). Session thực thi sau: bảng-ảnh-hưởng + remigration `| bảng | partition | PK | RLS | ghi chú |` qua Diamond++ (red-first Pest).
