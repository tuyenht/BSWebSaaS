# Roadmap — BSWebSaaS (cập nhật 28/09/2026)

> Nguồn "đang ở đâu / làm gì tiếp" DUY NHẤT được theo dõi trong git (thay master backlog 24/06 trong `plans/`,
> vốn bị `.gitignore` và đã lỗi thời). Chi tiết thiết kế ở `design.md`; tiến độ từng commit ở `git log`.
> Không ghi SHA của prod ở đây (lỗi thời nhanh) — xem nhật ký deploy.

**Where we are (28/09):** ✅ như dưới + lô §2b (#94–#113) trên prod, diễn tập khôi phục đạt, giám sát bật, prod chạy image riêng, hết nợ Q14, lịch sử phiên bản bài/trang (backend) trên prod → 🔶 **BẠN Ở ĐÂY: thiết kế D0 (chưa handoff nào về) ∥ việc nền không cần UI** → ⬜ build core theo handoff → ⬜ đóng gói (3 kênh).

**Where we were (24/09):** ✅ Spine (Tenancy/RLS/RBAC/Provisioning) → ✅ CMS + Media + Catalog + Admin Velzon → ✅ An Vinh LIVE →
✅ Workflow engine + Entitlement + Workflow UI (Phase 1) → ✅ Audit độ phủ thiết kế + 14 quyết định (24/09) →
🔶 **BẠN Ở ĐÂY: thiết kế đủ 100% màn SaaS Starter (design-first) + các việc nền đi kèm** → ⬜ build core theo từng handoff →
⬜ đóng gói core v1.0.

## 1. Mạch chính — thiết kế đủ & chuẩn hoá 100% màn SaaS Starter (từ 24/09)
- Brief: `design-briefs/saas-starter-design-first/` — 28 lượt Claude Design, 6 sóng: D0 nền (6) → D1 nội dung (10) →
  D2 người dùng (5) → D3 cài đặt (2) → D4 nền tảng (3) → D5 lỗi + QA liền mạch (2).
- Khu đăng nhập giữ nguyên (owner 23/09). Bộ AI, mặt TMĐT, Khối tái dùng, Builder v3: ngoài bộ này.
- Cổng nghiệm thu mỗi handoff: đối chiếu code + 12 tiêu chí dễ dùng → `design_handoff_<slug>/` → cập nhật
  `saas-starter-screen-map.md` → mới build. Việc backend không cần UI chạy song song.

## 2. Quyết định 24/09 (Q1–Q14, owner chốt theo khuyến nghị — `design.md` changelog v2.23)
| Trạng thái | Việc |
|---|---|
| ✅ Xong | Q14 luật kiến trúc "module tính năng không đọc thẳng `Tenancy\Models`" có hiệu lực lại + danh sách nợ 8 class (hết nợ 27/09: 3 lệnh chạy nền qua `Tenancy::siteIds()/eachSite()`, 5 class qua `Tenancy\Context` — `ContentSections`, `SiteSettings`, `SiteDirectory::nameOfCurrent`) · trang 404 hiện đúng tên website (white-label) — PR #67 · Q10 dòng báo thành công sau đặt lại mật khẩu / xác minh email — PR #68 · Q13 `docs/` là nơi bền cho brief + roadmap + sổ màn — PR #69 |
| ✅ Backend xong + lên prod 24/09 (UI chờ thiết kế) | Q2 ngôn ngữ "cho khách xem" (`site_locales.is_public`) + hạn mức `locales.max` (mặc định 2 → **1** từ 26/09, lên prod 27/09; cấp thêm theo site bằng `site:locale`) + đường ghi `Tenancy\Actions\ConfigureSiteLocale`; sitemap/hreflang chỉ liệt kê địa chỉ mở được — màn D3-1 · Q3 trình đơn trỏ Sản phẩm (`link_type` `product`, Catalog đăng ký qua `Cms\Navigation\MenuTargets`) + trình đơn không còn link tới trang/sản phẩm đã gỡ hoặc trong thùng rác — màn D1-2 · Q4 lời mời thành viên (`invitations` + trả lời đồng nhất, vá lỗ dò email) + màn nhận lời mời theo khung đăng nhập (Q9) — tab Lời mời chờ D2-2 · Q1 thông tin doanh nghiệp theo §14: cột còn thiếu + `provinces`/`wards` + đường đọc `Tenancy\Context\SiteIdentity` (website đọc NAP theo ngôn ngữ; ngôn ngữ mặc định giữ nguyên `general.brand` tới bước contract) + lệnh `site-profiles:sync-from-brand`, `admin-units:import` — màn D3-1 · Q5 thông báo trong ứng dụng: module `Notifications` + `site_notifications` (duyệt bài, khách liên hệ, tắt 2 lớp) — chuông + trang chờ D2-4 |
| ⏳ Làm cùng màn | Q6 ngôn ngữ/cỡ chữ theo người (`users.preferences`) + Q7 trang Tài khoản — khi build D2-3 · Q8 Shell chung 2 mặt — khi build D0-4/D4 · Q9 màn mới khu đăng nhập — theo §LR |
| ⏭ Sau | Q11 palette An Vinh v2 (bộ sinh bảng màu xong 26/09 — chờ khách duyệt) · Q12 bộ AI = module sau core v1 (bổ sung 29/09: công tắc nền tảng "Lời mời nâng gói", mặc định tắt, owner bật khi đã có gói) |

**Audit sau merge 24/09 (đã sửa + lên prod):** migration `is_public` hỏng khi có ngôn ngữ đang tắt (sửa trước deploy) · hạn mức thư mời đếm cả thư gửi lại · `admin-units:import` nhận CSV của Excel (BOM, `;`) · sản phẩm đăng trong admin có địa chỉ công khai + 301 khi đổi đường dẫn (+ lệnh `catalog:sync-routes`; prod không có sản phẩm nào thiếu) · chữ của giao diện website theo ngôn ngữ trang, link trang chủ giữ ngôn ngữ, hover chân trang hết bị CSP chặn, `img-src` lấy từ cấu hình · ja/zh đủ khoá + CI `lang:check` (`composer qa` chạy đúng các bước như CI, không còn bị cắt ở 300 giây) · (phiên song song) nhật ký kiểm toán ghi trong savepoint riêng — một dòng nhật ký lỗi hay header `X-Request-Id` quá dài không còn làm mất cả request · không xoá được nhóm quyền tuỳ chỉnh mà một lời mời đang chờ còn hứa · hạn mức thư mời khoá theo site.

## 2b. Quyết định 26/09 (sau audit tổng thể — `design.md` changelog v2.27)
| Trạng thái | Việc |
|---|---|
| ✅ Chốt | Website trên core CMS cũ: làm lại giao diện sau; dữ liệu chuyển bằng AI đọc → phân tích → viết lại (có người duyệt), không import thẳng — cần bộ AI (sau core v1) · Đóng gói 3 kênh: Starter nội bộ → Standalone Export (Phase 3) → bán mã nguồn · An Vinh giữ `av1.baoson.net` · dev + prod chung 1 máy trong giai đoạn này |
| ✅ Xong + lên prod 27/09 | Backup DB prod kiểm hạn token trước khi chạy, giữ bản dump cục bộ khi không upload được, ping healthcheck tuỳ chọn; token mới thay 26/09 (upload lại OK) · gỡ vòng phụ thuộc Admin ↔ Forms + arch test kiểm chu trình toàn đồ thị · `locales.max` mặc định 1 + lệnh operator `site:locale` · ô đồng ý bắt buộc trên biểu mẫu liên hệ, lưu đúng câu khách đã tick (`forms:sync-contact-fields` thêm ô cho site cũ) · đường ghi Trình đơn (backend D1-2) · bộ sinh bảng màu thương hiệu 10 bậc (backend D3-1) · brief luật sư đợt 1 (chờ gửi) |
| ✅ Xong + lên prod 27/09 (#106–#113) | Diễn tập khôi phục hằng tháng (`scripts/restore-drill-prod-db.sh`; lần đầu trên prod 27/09 đạt: 62/62 bảng, 36/36 policy RLS, 40/40 migration) + backup chạy từ bản prod + ghim wrangler 4.101.0 + ghim PgBouncer theo digest · form liên hệ nói đủ các ngôn ngữ của site + làm mới câu đồng ý khi lời văn pháp lý đổi (`forms:sync-contact-fields --refresh-consent`) · đường ghi Chuyên mục (backend D1-1: `Cms\Routing\CategoryDependents`, tối đa 3 cấp, bài/sản phẩm đổi đường dẫn + 301 trong cùng giao dịch, bài rời chuyên mục phải xác nhận, xoá chuyên mục → link trình đơn trỏ theo; `cms:rebuild-taxonomy-paths` ghi `path` cho chuyên mục cũ) · lỗi tải ảnh theo ngôn ngữ người dùng, không lộ chi tiết nội bộ · thông báo nội dung/duyệt lấy từ bản dịch · nhãn quy trình duyệt theo ngôn ngữ mặc định của site · luật an toàn host + `docs/ops-log.md` |
| ✅ Xong 27/09 (owner) | Healthcheck cho backup + diễn tập (healthchecks.io, 2 check) · UptimeRobot av1 + website.baoson.net · container prod tạo lại sang tag `bsweb/app:prod` + PgBouncer theo digest |
| ⏭ Sau | lệnh nạp cấu hình site từ một file (trình đơn, chuyên mục, trang, thông tin doanh nghiệp, màu) để giao website khách không phải chờ màn quản trị — cần số site đang chờ · An Vinh mở en (khi khách dịch xong, duyệt qua xem trước) rồi ja · nợ khái niệm của lõi (FK `workflow_log.entry_id`, `ContentSection`/`PermalinkConfig` trong Tenancy, quyền nội dung trong Rbac) — ghi thành danh sách chỉ-giảm, gỡ khi có dự án phi-website · chuỗi hiển thị còn viết cứng tiếng Việt: nhãn danh mục module/tính năng (`ModuleCatalog`/`FeatureRegistry`, làm cùng D4) + chuỗi dự phòng trong `resources/js/media-*.js` |

## 3. Mạch khác đang mở
- **Lịch sử phiên bản bài/trang (D1-4c):** backend ✅ lên prod 28/09 (`entry_revisions`, khôi phục, giữ ≥ 20 bản/90 ngày;
  design §8 v2.29) — màn chờ handoff D1-4c. Còn mở, cần quyết cùng handoff D1-4d: **bản nháp đè bản công khai** (sửa bài
  đang công khai chưa hiện ra ngay mà chờ "Cập nhật"/duyệt) — đổi hành vi storefront + trình soạn ⇒ COUNCIL riêng.
  ⚠ Rủi ro đang có (29/09): website BẬT quy trình duyệt thì người có quyền sửa bài vẫn lưu thẳng thay đổi lên bài đang
  công khai, không qua duyệt (`Entries/Editor.php` `save()`). Owner chốt C5 30/09 (design v2.31): tắt duyệt → lên ngay; bật duyệt →
  thay đổi chờ duyệt; đặc tả COUNCIL `plans/reports/council-260930-0025-c5-draft-over-live-spec-report.md`. Chưa bật quy trình
  duyệt cho website khách nào trước khi C5 lên prod.
- **RBAC Console:** backend nhóm quyền tuỳ chỉnh xong (`rbac.custom_roles`, mặc định tắt); UI = lượt D2-1.
- **Core Web Vitals:** job `cwv` là gate từ 24/09 cho `/`, `/lien-he`, `/tin-tuc` (LCP ≤ 1,5s · JS ≤ 30KB · axe 0 vi phạm).
- **Đăng nhập 3 cửa (§LR B1–B6):** tường minh mặt phẳng · cửa owner · đăng nhập chung + chọn website · chuyển website ·
  đăng nhập hỗ trợ (thiết kế ở D4-3) — chưa code.
- **Builder v3:** danh mục section v3.1 chờ phía thiết kế; PR 1a.1: bộ sinh bảng màu `--brand-50..900` ✅ 26/09 (chưa stylesheet nào đọc) — còn thang spacing + chuyển website sang các vai màu.
- **Đa ngôn ngữ giao diện:** vi/en/ja/zh đủ khoá (24/09), CI chặn lệch bằng `lang:check`. Trang 404 chung còn chỉ tiếng Việt → thiết kế ở D5-1.
- **Còn mở (nhỏ, chưa gấp):** xem trước ngôn ngữ chưa công khai (câu hỏi cho D3-1) · sitemap > 50.000 địa chỉ cần sitemap index (khi có site lớn) · nhập lại mật khẩu trước khi tắt 2 lớp (làm cùng D2-3) · `tenant_users` chưa có chỗ đọc (chốt ở D4-1). (Trần thư mời theo từng địa chỉ: ✅ ≤ 3 thư / địa chỉ / site / 24 giờ — v2.26.)

## 4. Để sau (có chủ đích)
Commerce GĐ1 (giỏ hàng + COD + VietQR — các website đang chờ đều là site giới thiệu, chưa cần) · thanh toán/billing (chỉ để
chỗ chờ) · bộ AI (dịch hỗ trợ cho khách + chuyển nội dung website cũ) · Meilisearch · tên miền riêng tự phục vụ (khi có
khách cần) · đóng gói core v1.0 (installer, quét white-label — gồm đổi tiền tố token CSS mượn tên template tham khảo
`--vz-*` cùng lúc đồng bộ design system với Claude Design —, tài liệu EN, bench 1M bản ghi/tenant, versioning — backlog "G-P").
