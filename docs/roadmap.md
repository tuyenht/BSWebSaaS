# Roadmap — BSWebSaaS (cập nhật 24/09/2026)

> Nguồn "đang ở đâu / làm gì tiếp" DUY NHẤT được theo dõi trong git (thay master backlog 24/06 trong `plans/`,
> vốn bị `.gitignore` và đã lỗi thời). Chi tiết thiết kế ở `design.md`; tiến độ từng commit ở `git log`.
> Không ghi SHA của prod ở đây (lỗi thời nhanh) — xem nhật ký deploy.

**Where we are:** ✅ Spine (Tenancy/RLS/RBAC/Provisioning) → ✅ CMS + Media + Catalog + Admin Velzon → ✅ An Vinh LIVE →
✅ Workflow engine + Entitlement + Workflow UI (Phase 1) → ✅ Audit độ phủ thiết kế + 14 quyết định (24/09) →
🔶 **BẠN Ở ĐÂY: thiết kế đủ 100% màn SaaS Starter (design-first) + các việc nền đi kèm** → ⬜ build core theo từng handoff →
⬜ đóng gói core v1.0.

## 1. Mạch chính — thiết kế đủ & chuẩn hoá 100% màn SaaS Starter (từ 24/09)
- Brief: `design-briefs/saas-starter-design-first/` — 25 lượt Claude Design, 6 sóng: D0 nền (5) → D1 nội dung (8) →
  D2 người dùng (5) → D3 cài đặt (2) → D4 nền tảng (3) → D5 lỗi + QA liền mạch (2).
- Khu đăng nhập giữ nguyên (owner 23/09). Bộ AI, mặt TMĐT, Khối tái dùng, Builder v3: ngoài bộ này.
- Cổng nghiệm thu mỗi handoff: đối chiếu code + 12 tiêu chí dễ dùng → `design_handoff_<slug>/` → cập nhật
  `saas-starter-screen-map.md` → mới build. Việc backend không cần UI chạy song song.

## 2. Quyết định 24/09 (Q1–Q14, owner chốt theo khuyến nghị — `design.md` changelog v2.23)
| Trạng thái | Việc |
|---|---|
| ✅ Xong | Q14 luật kiến trúc "module tính năng không đọc thẳng `Tenancy\Models`" có hiệu lực lại + danh sách nợ 8 class · trang 404 hiện đúng tên website (white-label) — PR #67 · Q10 dòng báo thành công sau đặt lại mật khẩu / xác minh email — PR #68 · Q13 `docs/` là nơi bền cho brief + roadmap + sổ màn — PR #69 |
| ✅ Backend xong (UI chờ thiết kế) | Q2 ngôn ngữ "cho khách xem" (`site_locales.is_public`) + hạn mức `locales.max` (mặc định 2) + đường ghi `Tenancy\Actions\ConfigureSiteLocale`; sitemap/hreflang chỉ liệt kê địa chỉ mở được — màn D3-1 · Q3 trình đơn trỏ Sản phẩm (`link_type` `product`, Catalog đăng ký qua `Cms\Navigation\MenuTargets`) + trình đơn không còn link tới trang/sản phẩm đã gỡ hoặc trong thùng rác — màn D1-2 · Q4 lời mời thành viên (`invitations` + trả lời đồng nhất, vá lỗ dò email) + màn nhận lời mời theo khung đăng nhập (Q9) — tab Lời mời chờ D2-2 · Q1 thông tin doanh nghiệp theo §14: cột còn thiếu + `provinces`/`wards` + đường đọc `Tenancy\Context\SiteIdentity` (website đọc NAP theo ngôn ngữ; ngôn ngữ mặc định giữ nguyên `general.brand` tới bước contract) + lệnh `site-profiles:sync-from-brand`, `admin-units:import` — màn D3-1 |
| 🔄 Đang làm (owner duyệt shape 24/09) | Q5 bảng thông báo — đặc tả `plans/260924-0103-saas-starter-schema-increments/` |
| ⏳ Làm cùng màn | Q6 ngôn ngữ/cỡ chữ theo người (`users.preferences`) + Q7 trang Tài khoản — khi build D2-3 · Q8 Shell chung 2 mặt — khi build D0-4/D4 · Q9 màn mới khu đăng nhập — theo §LR |
| ⏭ Sau | Q11 palette An Vinh v2 (chờ bộ sinh bảng màu + khách duyệt) · Q12 bộ AI = module sau core v1 · trả nợ 8 class của Q14 qua `Tenancy\Context` |

## 3. Mạch khác đang mở
- **RBAC Console:** backend nhóm quyền tuỳ chỉnh xong (`rbac.custom_roles`, mặc định tắt); UI = lượt D2-1.
- **Core Web Vitals:** job `cwv` là gate từ 24/09 cho `/`, `/lien-he`, `/tin-tuc` (LCP ≤ 1,5s · JS ≤ 30KB · axe 0 vi phạm).
- **Đăng nhập 3 cửa (§LR B1–B6):** tường minh mặt phẳng · cửa owner · đăng nhập chung + chọn website · chuyển website ·
  đăng nhập hỗ trợ (thiết kế ở D4-3) — chưa code.
- **Builder v3:** danh mục section v3.1 chờ phía thiết kế; PR 1a.1 còn bộ sinh bảng màu `--brand-50..900` + thang spacing.
- **Đa ngôn ngữ giao diện:** ja/zh thiếu 182 khoá mỗi bản (vi/en đủ).

## 4. Để sau (có chủ đích)
Commerce GĐ1 (giỏ hàng + COD + VietQR) · thanh toán/billing (chỉ để chỗ chờ) · bộ AI · Meilisearch · đóng gói core v1.0
(installer, quét white-label, tài liệu EN, bench 1M bản ghi/tenant, versioning — backlog "G-P").
