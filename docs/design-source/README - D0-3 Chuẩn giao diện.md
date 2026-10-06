# README — D0-3 Chuẩn giao diện SaaS Starter (phần 1: chữ & luật · sửa D0-3s · phần 2: mẫu trang)

## Phần 2 — Mẫu trang (`#mau-trang`, 05/10/2026)
8 mẫu, mỗi mẫu: sơ đồ vùng tĩnh (tỉ lệ 1:2, không animation) + quy tắc + liên kết màn gốc. Không chép markup màn gốc. Khung theo bề rộng KHUNG CHỨA thật trong Shell: màn 1280 → ≈974px; màn 768 → ≈720px; màn 390 → ≈342px.
| Mẫu | Anchor | Màn gốc | Khung vẽ | Điểm chính |
|---|---|---|---|---|
| 1 Danh sách | `#mau-1` | Quản lý Bài viết - Options (khuôn), Quản lý Trang (đối chiếu), Co giãn › Bảng → thẻ gọn | 974 · 720 · 342 | Thùng rác không hỏi + Hoàn tác 8 giây; "Bỏ lọc"; "Đã chọn N" ghi số theo nút; thẻ gọn dưới 768 khung chứa (600–767 hai cột); "‹ Trước · Sau ›"; "Khớp hơn 1.000" (Cần owner chốt) |
| 2 Chi tiết | `#mau-2` | vẽ mới | 974 · 720 · 342 | Tabs của trang; panel phải xuống dưới khi khung chứa < 940px |
| 3 Soạn/sửa | `#mau-3` | Page Editor - Options | 974 · 720 · 342 | Thanh thao tác dính trên từ 768 khung chứa; dưới đó save-bar dính đáy + "⋯"; save-status không mã nội bộ; "Thêm tùy chọn" gập |
| 4 Mục cài đặt | `#mau-4` | Admin Shell › Cài đặt + đề xuất 1c/1d | 974 · 720 · 342 | Thanh Lưu dính đáy có số thay đổi + số lỗi; "Nâng cao" gập cuối; onDirty(n) + onInvalid(n) + firstError |
| 5 Hộp hai khung | `#mau-5` | Hộp duyệt | 974 · 720 · 342 | Khung đọc giãn 100dvh trừ khung Shell; dưới 940px một khung + Quay lại |
| 6 Hướng dẫn nhiều bước | `#mau-6` | vẽ mới, stepper | 974 · 720 · 342 | Bước có tên, "Tiếp: <tên bước>"; Lưu và để sau |
| 7 Bảng điều khiển | `#mau-7` | Tổng quan Shell + đề xuất 1b | 974 · 720 · 342 | stat-card; fmtCompact 1 đơn vị; Việc cần làm; Hoạt động = Dòng thời gian |
| 8 Trang lỗi/tiện ích | `#mau-8` | vẽ mới | 974 (trong + ngoài Shell) · 342 | Không có quyền · Không tìm thấy (trong Shell); 404 · 500 · Đang bảo trì (ngoài Shell) |

### Việc cho backend (phần 2)
- Chuyển vào Thùng rác không hỏi + Hoàn tác (build đang hỏi xác nhận).
- `app/Modules/Admin/Livewire/Entries/Index.php` nạp hết rồi chia trang trong bộ nhớ → chia trang ở CSDL + bộ đếm sẵn theo website cho số trên tab.
- `<x-bs-admin::pagination>` thêm chế độ "‹ Trước · Sau ›" không tổng.
- `Platform/Sites/Index.php`, `Platform/Tenants/Index.php`, `Platform/Audit/Index.php` đang `paginate()` có tổng → chia trang theo con trỏ. [CHƯA ĐỐI CHIẾU REPO lượt này — theo prompt]
- Cần owner chốt: lọc ra quá 1.000 dòng hiện "Khớp hơn 1.000" thay số chính xác (danh sách hàng chục nghìn dòng).
- Hợp đồng nhúng: `onDirty(n)` số, `onInvalid(n)`, `bs-embed-cmd {act:'firstError'}` (khác §13 — LESSONS).

### Kiểm hiển thị (cả trang chuẩn, gồm phần nợ D0-2) — [ĐO BẰNG SCRIPT, `_audit/w360.html`, Chrome của môi trường thiết kế, 05/10]
| Bề rộng | Chữ thường | Chữ lớn (+2px) | Ghi chú |
|---|---|---|---|
| 360 | Đạt — trang không cuộn ngang | Đạt sau khi sửa (trước sửa tràn 11px do chuỗi props dài ở `#bang-tra` → thêm `overflow-wrap:anywhere` cho ô `.bt-row`) | cuộn trong khung: bảng dữ liệu `.tw` ở `#co-gian`, `#he-thong` (ngoại lệ bảng dữ liệu); `#dieu-khien` có phần tử `overflow:hidden` khi Chữ lớn — soát bằng mắt (TODO) |
| 390 | Đạt | Đạt | như trên |
| 640 (≈ màn 1280 phóng 200%) | Đạt | — | |
| 768 | Đạt | — | |
| 844 (điện thoại xoay ngang) | Đạt | — | |
| 1024 | Đạt | — | |
| 1440 | Đạt — 0 phần tử vượt khung | — | |
| 5 mục mới phần 1 + `#mau-trang` ở mọi bề rộng trên | 0 phần tử vượt khung | 0 | |
| Chế độ tối | [CHƯA KIỂM ĐƯỢC] — công cụ chụp quá giờ trên trang này; chỉ dùng token nên tự lật [SUY RA TỪ CODE] | | |
| Safari iPhone/iPad 16.4+ · Firefox · Samsung Internet | [CHƯA KIỂM ĐƯỢC] — môi trường thiết kế chỉ có Chrome; Claude Code kiểm | | |

### Tiêu chí dễ dùng của mẫu trang
1–8, 10–12: [SUY RA TỪ CODE] — mỗi mẫu ghi tiêu đề + 1 nút chính, ≤ 3 bước, trạng thái có chữ, không cuộn ngang. 9: [ĐO BẰNG SCRIPT] không tràn ở 360/390/768 (bề rộng màn, không phải bề rộng khung chứa trong Shell).

## Tổng quan
Bổ sung vào `Chuẩn giao diện SaaS Starter.dc.html` các luật về chữ mà mọi màn sau dùng chung: Bảng thuật ngữ, định dạng số/ngày/dung lượng, giọng văn, trạng thái màn còn thiếu, 12 tiêu chí dễ dùng có hình mẫu đúng/sai, 4 người dùng mẫu. Lượt D0-3s sửa 24 điểm tự rà (AUDIT.md › "Tự rà lượt D0-3 phần 1" › "Kết quả D0-3s"). Phần 2 (8 mẫu trang `#mau-trang`, kiểm hiển thị còn nợ): xem TODO.md "D0-3 phần 2".

## File
- `Chuẩn giao diện SaaS Starter.dc.html` — 5 mục mới + bổ sung `#phan-hoi`; CSS mới trong một khối `<style>` riêng, tiền tố `tp-`.
- `Chuẩn giao diện - Component bổ sung.dc.html` — nhãn nút AI theo C.15 ("Gợi ý <tên trường>" / "Viết lại" / "Tạo ảnh"); "Mô tả hiện trên Google".
- `content-ui.js` — thêm `BSUI.fmtNum` · `fmtBytes` · `fmtAgo` · `fmtCompact` (chỉ thêm).
- `content-workflow.js` — chỉ đổi giá trị `label`/`why` + màu `c`/`bg` của `UI` sang token. Khóa `ROLES`/`WF`/`DEMO_WF` giữ nguyên.

## Mức hoàn thiện
Phần 1 hoàn thiện về nội dung; kiểm hiển thị của 5 mục mới ở 360px dời sang phần 2 (công cụ chụp quá giờ trên trang này — LESSONS #28).

## Màn & trạng thái
Anchor: `#thuat-ngu` · `#dinh-dang` · `#giong-van` · `#tieu-chi` · `#nguoi-dung-mau`; mục lục đầu trang trỏ đúng.
`#phan-hoi` thêm 3 trạng thái:
- **Chỉ được xem** — chip "Chỉ được xem" (icon mắt); ô chỉ đọc, không có nút Lưu; câu nói nhóm quyền nào sửa được. Khác nhóm quyền "Chỉ xem".
- **Khóa theo gói (cả màn)** — Tweak "Lời mời nâng gói" tắt → "Chưa bật cho website này" + "Liên hệ hỗ trợ"; bật → 1 dòng lợi ích + "Xem gói nâng cấp".
- **Không lưu được** — ô "Tiêu đề trang" gõ được (`defaultValue`), chữ giữ nguyên; "Lưu lại" báo "Đã lưu thay đổi. Chữ bạn nhập được giữ nguyên."

## Tương tác
- Bảng thuật ngữ / định dạng: dưới 640px khung chứa mỗi dòng thành thẻ gọn (dòng 1 thao tác + trạng thái, dòng 2 nghĩa, dòng 3 "Không dùng: …"). Không cuộn ngang.
- Bảng định dạng chạy thật qua `BSUI`, 4 cột vi · en · ja · zh.
- "5 phút trước": `<time datetime>` + aria-label đầy đủ; giờ đầy đủ hiện khi rê chuột, focus bàn phím, chạm.
- Số rút gọn mẫu: chữ rút gọn `aria-hidden="true"` + chữ ẩn `.sr` ghi số đầy đủ.

## Ánh xạ dữ liệu (đã đối chiếu repo `tuyenht/BSWebSaaS@main`, commit f0a78d79cee1, 05/10/2026 16:21 UTC)
- Nhóm quyền website: `app/Modules/Rbac/Registry/PermissionRegistry.php:190–206` — `owner · admin · editor · author · contributor · viewer`; tên hiện: `lang/vi.json:806–811` (`rbac.role.author` = "Tác giả" → cần đổi "Người viết").
- Nhóm quyền nền tảng: `PermissionRegistry.php:141–152` `platformRoles()` có **3** khóa `super_admin` (level 100) · `support` (50: tenants.view, sites.view, audit.view, sites.impersonate) · `billing` (40: billing.manage, tenants.view). Chốt (Claude Code sửa A2, 06/10): `super_admin` = "Quản trị nền tảng" · `support` = "Nhân viên hỗ trợ" (chữ repo `admin.support.staff`, `lang/vi.json:221`) · `billing` = "Nhân viên thanh toán" (đổi gói của website + xem khách hàng).
- Trạng thái: `entries` `database/migrations/2012_01_01_000000_expand_entry_status_and_add_view_count.php:25` ('draft','pending','published','scheduled','private','archived'); `products` `…/2009_01_01_000000_create_catalog_tables.php:105` ('draft','published','archived'); khách liên hệ `…/2005_01_01_000000_create_forms_tables.php:79` ('new','read','archived','spam').
- Thùng rác ảnh & tệp 15 ngày (`config/media.php`): [CHƯA ĐỐI CHIẾU REPO] — lấy theo prompt.

## Quyền & gói
Không đổi quyền. `BSCW.can('Người viết','entries.publish')` = false, `BSCW.can('Biên tập','entries.publish')` = true (khóa và mảng quyền không đổi) [SUY RA TỪ CODE].

## Token
`BSCW.UI`: chữ `--color-admin-{success,secondary,warning,info,dark}-text`, `--color-accent` (Không công khai), `--color-body-muted` (Đã gỡ khỏi website); nền `color-mix` 8–14% cùng token. Không cần dự phòng Safari < 16.2 (Tailwind 4 đòi Safari 16.4+; `admin-base.css` đã dùng `color-mix`). Nơi đọc `c`/`bg`: `Quản lý Bài viết - Options` (badgeStyle, dotStyle, tab, menu trạng thái), `Hộp duyệt` (dotColor), `content-ui.js` `badge()` — đều ghép vào chuỗi CSS, không vẽ canvas/SVG, không tính tương phản → không cần `getComputedStyle`. Lớp `tp-` chỉ dùng token; bóng tooltip = `--shadow-pop`.

## Icon
ri-draft-line · ri-global-line · ri-calendar-schedule-line · ri-archive-line · ri-lock-2-line · ri-inbox-unarchive-line · ri-chat-check-line · ri-eye-line · ri-error-warning-line · ri-save-3-line · ri-customer-service-2-line · ri-vip-crown-2-line · ri-mail-unread-line · ri-check-double-line · ri-shield-check-line · ri-loader-4-line · ri-server-line · ri-question-line · ri-check-line · ri-close-line · ri-arrow-right-s-line · ri-more-2-fill · ri-checkbox-circle-fill.

## Bảng thuật ngữ
Bản đầy đủ ở `#thuat-ngu`; mỗi dòng ghi "điểm N" (1–16 đã chốt). Cột "Không dùng" chỉ chứa từ gắn với một điểm, kèm nghĩa bị cấm.

### Luật grep (Xong khi số 3)
Grep trang chuẩn không phân biệt hoa thường, **bỏ qua mọi phần tử mang `data-term-bad="true"` và con của nó** (cột "Không dùng", ví dụ "Sai" ở `#tieu-chi` và `#giong-van`), không bỏ qua chỗ nào khác. Kết quả 05/10 [ĐO BẰNG SCRIPT]: 0 "xóa tất cả" · "đặt lịch" · "lên lịch" · "vai trò" · "riêng tư" · "lưu trữ" · "xuất bản" · "tác giả" · "đã chép". Nút/title/aria-label của trang chuẩn và trang Component bổ sung: 0 "bằng AI" · "AI dịch" · "Viết mô tả" (lượt D0-3s sửa thêm "Tạo bằng AI" → "Tạo ảnh" ở trang Component bổ sung; câu mô tả bảng ứng viên còn trích nhãn cũ — là câu, không phải nút).

### Từ không dùng
Chỉ cụm sai trong MỌI ngữ cảnh — grep máy được.
```
xuất bản → đăng
đã xuất bản → đã đăng
đặt lịch → hẹn giờ đăng
lên lịch → hẹn giờ đăng
đã lên lịch → đã hẹn giờ
gỡ bài → đưa về nháp
riêng tư → không công khai
vai trò → nhóm quyền
chủ sở hữu → chủ website
đăng nhập dưới tên khách → đăng nhập dưới tên khách hàng (grep cả cụm, loại "khách hàng" theo sau)
đăng nhập social → đăng nhập nhanh
xóa tất cả → bỏ lọc
xoá tất cả → bỏ lọc
xóa lọc → bỏ lọc
xoá lọc → bỏ lọc
hoàn nguyên → bỏ thay đổi
đã chép → đã sao chép
phê duyệt → duyệt đăng
ai dịch → dịch
dịch bằng ai → dịch
viết bằng ai → viết mới
viết bài mới bằng ai → viết mới
viết trang mới bằng ai → viết mới
viết cả bài bằng ai → viết mới
nhờ ai viết → nâng cấp n chỗ
viết tiêu đề & mô tả → gợi ý tiêu đề & mô tả
mở rộng lại bằng ai → mở rộng lại
bổ sung bằng ai → nâng cấp bài viết
làm mới bằng ai → nâng cấp bài viết
tạo bằng ai → tạo ảnh
gợi ý bằng ai → gợi ý <tên trường>
ai gợi ý → gợi ý <tên trường>
viết giúp → gợi ý <tên trường>
viết lại đoạn bằng ai → viết lại
ghi đè bằng bản ai → viết lại
xoá → xóa
huỷ → hủy
khoá → khóa
tuỳ → tùy
```

### Soát bằng mắt
Từ ngắn dễ trúng nhầm (tên khóa, đường dẫn tệp, biến) — không grep máy.
- `site` — Sai: "Liên hệ nhà cung cấp để nâng gói cho site này." · Đúng: "… cho website này." (biến `:site`, tên prop `site-langs` giữ).
- `chép` — Sai: nút "Chép". · Đúng: "Sao chép"; "Chép trình bày / Dán trình bày" của Page Builder giữ.
- `lưu trữ` — Sai: nút "Lưu trữ" cho bài đã đăng. · Đúng: "Gỡ khỏi website". (Khách liên hệ `archived`: rà theo nghĩa.)
- `tác giả` — Sai: tên nhóm quyền "Tác giả". · Đúng: "Người viết"; "tác giả bài" (người viết ra bài) được dùng.
- `người xem` — Sai: tên nhóm quyền "Người xem". · Đúng: "Chỉ xem"; "người xem website" được dùng.
- `danh mục` — Sai: "Danh mục" cho nhóm bài/sản phẩm. · Đúng: "Chuyên mục".
- `menu` — Sai: "Menu" cho màn dựng menu website. · Đúng: "Trình đơn"; nút "Menu" trên điện thoại giữ.
- `khách hàng` — Sai: "Khách hàng" cho người đặt hàng. · Đúng: "Người mua".
- `từ chối` — Sai: "Từ chối" bài gửi duyệt. · Đúng: "Trả lại".
- `hạn ngạch` / `hạn mức` — Sai: "Hạn mức từ AI". · Đúng: "Hạn ngạch từ AI"; "Hạn mức số website".
- `oauth` — Sai: "Đăng nhập OAuth". · Đúng: "Đăng nhập nhanh" (tên khóa/tệp giữ).
- `copy` — Sai: nút "Copy". · Đúng: "Sao chép" (tên hàm `copy()` giữ).
- `slug`, `media`, `meta`, `robots`, `sitemap`, `smtp` — Sai: nhãn "Slug", "Media". · Đúng: "Đường dẫn", "Thư viện ảnh & tệp"; chữ kỹ thuật chỉ trong "Nâng cao" kèm câu đời thường (tiêu chí 3). Tên khóa/tệp giữ.

### Đề xuất chờ owner
Từ lượt D0-3 phần 1 tự thêm vào cột "Không dùng", không gắn được điểm nào trong 16 điểm — đã bỏ khỏi bảng, chờ owner chốt có cấm hay không:
Admin · Editor · Contributor · Trình duyệt · Phục hồi · Xóa hẳn · Xóa (cho Chuyển vào Thùng rác) · Gỡ thành viên · Để nháp · Đăng ngay · OK · Tenant · Visitor · Lead · Impersonate · Lượt AI (gộp; CLAUDE.md §7a đã cấm) · Quota · Plan · Tag · Slug · URL · Meta description · Mô tả trên Google · OG image · Media · Người dùng · User · Liên hệ (cho người) · Domain · Cấu hình DNS · CNAME · SSL · Chứng chỉ · Dashboard · Inbox · Forms · Audit log · Settings · Cấu hình SMTP · Sitemap · Robots · GTM · Tag Manager · Vẽ ảnh · Chỉ đọc (cho trạng thái màn). "Cập nhật" đã bỏ hẳn (không cấm).

## Định dạng (C2)
`BSUI.fmtNum(n, locale)` · `fmtBytes(b, locale)` · `fmtAgo(d, {locale, tz, now})` · `fmtCompact(n, {locale, max})`; ngày giờ `fmtDate` + `SITE_TZ`; tiền `vnd`.
Phép thử [ĐO BẰNG SCRIPT — chạy content-ui.js, 05/10]: vi `fmtCompact(1234567)` = "1,2Tr" · ja `fmtCompact(123456)` = "12.3万" · zh `fmtCompact(250000000)` = "2.5亿" · en `fmtCompact(1234567)` = "1.2M" · `fmtCompact(45000, {max: 2,5e9})` = "<0,1Tỷ" (en "<0.1B") · `fmtCompact(980)` = "980" · `fmtBytes(1258291)` = "1,2 MB" · `fmtNum(1234567)` = "1.234.567" · `fmtAgo` 5 phút → "5 phút trước", 3 giờ → "3 giờ trước", 50 giờ → "03/10/2026 21:22", en 5 phút → "5 minutes ago".
Ngày dd/mm/yyyy, 24 giờ cho mọi ngôn ngữ — giữ theo prompt C2 (repo chưa có định dạng ngày theo ngôn ngữ).

## Chuỗi cần dịch
Chữ của 5 mục mới + 3 trạng thái màn; nhãn mới trong `content-workflow.js` (Đã hẹn giờ · Không công khai · Đã gỡ khỏi website · Đưa về nháp · Gỡ khỏi website · "Lưu nháp — chưa hiện trên website" · "Để không công khai — thành viên có quyền vẫn xem được" · "Nhóm quyền của bạn không được gửi duyệt"); toast "Đã lưu thay đổi. Chữ bạn nhập được giữ nguyên."

## Kết quả 12 tiêu chí dễ dùng (phần 1)
| # | Kết quả | Lý do |
|---|---|---|
| 1 | [SUY RA TỪ CODE] | Mỗi mục có tiêu đề + 1 dòng mô tả; chưa thấy tận mắt sau D0-3s (chụp quá giờ) |
| 2 | [SUY RA TỪ CODE] | Mỗi mục 1 bước từ mục lục |
| 3 | [SUY RA TỪ CODE] | Thuật ngữ kỹ thuật chỉ ở cột "Không dùng"/trong ngoặc kèm câu đời thường |
| 4 | [SUY RA TỪ CODE] | Nút mẫu có chữ; nút chỉ icon ở ví dụ "Sai" mang aria-label nói rõ là ví dụ sai |
| 5 | [SUY RA TỪ CODE] | Ví dụ lỗi nói cách sửa |
| 6 | [SUY RA TỪ CODE] | "Không lưu được" giữ dữ liệu, ô gõ được |
| 7 | [SUY RA TỪ CODE] | Chip trạng thái có icon + chữ |
| 8 | [SUY RA TỪ CODE] | Nút giữ 1 dòng; ví dụ "Sai" cố ý xuống dòng |
| 9 | [CHƯA KIỂM ĐƯỢC] | Chưa đo 360px — phần 2 |
| 10 | [SUY RA TỪ CODE] | Không thêm tùy chọn nâng cao |
| 11 | [SUY RA TỪ CODE] | Cỡ chữ mới đều `calc(Npx + var(--fsp))` |
| 12 | Đạt [ĐO BẰNG SCRIPT] | Grep theo luật `data-term-bad` ra 0 (xem trên) |

Kiểm `Hộp duyệt` + `Quản lý Bài viết - Options` (Xong khi số 4) [ĐÃ RENDER VÀ THẤY, 05/10]: huy hiệu "Đã đăng", "Đã hẹn giờ", menu "Khác" ra "Đã hẹn giờ · Không công khai"; chấm "Đang phản biện"/"Chờ duyệt" của Hộp duyệt đúng màu ở sáng và tối (đặt `data-bs-theme=dark`); console 0 lỗi.

**Tweak nhóm quyền — đổi lần lượt 6 nhóm** [ĐÃ RENDER VÀ THẤY + ĐO BẰNG SCRIPT, 06/10, đổi prop `role` qua runtime như Tweak; console.error + window.onerror: 0]:
| Nhóm quyền | Hộp duyệt | Quản lý Bài viết - Options |
|---|---|---|
| Chủ website | "Giao cho tôi 2"; nút "Duyệt đăng"; chấm Đang phản biện · Chờ duyệt | "Viết bài mới" mở; tab "Tất cả 59" (không có "Bài của tôi"); huy hiệu Đã đăng · Bản nháp |
| Quản trị | như Chủ website | như Chủ website |
| Biên tập | như Chủ website | như Chủ website; băng nhóm quyền "Được đăng bài · sửa được mọi bài" |
| Người viết | 0 bài; trạng thái trống "Không có bài nào chờ bạn" + "Xem Hàng chờ duyệt"; không có nút duyệt | "Viết bài mới" mở; thêm tab "Bài của tôi 20"; băng "Được gửi duyệt · chỉ sửa bài của mình"; nút Sửa/Xóa trên bài người khác mờ |
| Cộng tác viên | như Người viết | như Người viết |
| Chỉ xem | như Người viết | "Viết bài mới" khóa, tooltip "Vai trò Chỉ xem không được tạo bài" (chữ cũ — TODO đã có: đổi "Nhóm quyền …") |
Chưa mở được menu trạng thái bằng script để đếm từng bước theo nhóm (bộ chọn menu không bắt được) — [CHƯA KIỂM ĐƯỢC từng mục menu]; khóa/nút theo nhóm khớp `BSCW.can` [SUY RA TỪ CODE]. Ghi nhận ngoài phạm vi: trạng thái trống của Hộp duyệt có emoji trong câu "thành thơi nhé" — màn này lượt này không sửa.

## Kiểm hiển thị
| Bề rộng × trình duyệt | Kết quả |
|---|---|
| 360 / 390 / 768 / 1024 / 1440 × Chrome | [CHƯA KIỂM ĐƯỢC] — chụp theo anchor quá giờ (7 giây, trang ≈ 270 KB); đo lại ở phần 2 bằng `_audit/harness.html` |
| Safari iOS 16.4+ / Firefox / Samsung Internet | [CHƯA KIỂM ĐƯỢC] — môi trường thiết kế không có; Claude Code kiểm |

Verifier 06/10: gọi 3 lần, không trả kết quả (trang nặng, công cụ chụp quá giờ) — [CHƯA KIỂM ĐƯỢC]; Claude Code kiểm bằng zip.

## Việc cho backend
1. **`lang/vi.json` — đổi GIÁ TRỊ, giữ tên khóa** (đã đối chiếu, số dòng `lang/vi.json`):
| Khóa | Dòng | Hiện tại | Đổi thành |
|---|---|---|---|
| `entries.status_published` | 576 | Đã xuất bản | Đã đăng |
| `entries.bulk_publish` | 633 | Xuất bản | Đăng |
| `entries.status_scheduled` | 578 | Đã lên lịch | Đã hẹn giờ |
| `entries.schedule_set_action` | 630 | Đặt lịch | Hẹn giờ đăng |
| `entries.scheduled_set` | 621 | Đã đặt lịch đăng | Đã hẹn giờ đăng |
| `entries.schedule_future_only` | 631 | Thời điểm lên lịch phải ở tương lai. | Giờ hẹn phải sau bây giờ — chọn giờ khác. |
| `entries.err_schedule_in_past` | 755 | Lên lịch cần một thời điểm trong tương lai. | Giờ hẹn phải sau bây giờ — chọn giờ khác. |
| `entries.status_private` | 579 | Riêng tư | Không công khai |
| `entries.status_archived` | 581 | Lưu trữ | Đã gỡ khỏi website |
| `catalog.status_archived` | 560 | Lưu trữ | Đã gỡ khỏi website |
| `catalog.status_draft` | 558 | Nháp | Bản nháp |
| `forms.status_archived` | 322 | Lưu trữ | rà theo nghĩa (không tự đặt chữ) |
| `forms.action_archive` | 329 | Lưu trữ | rà theo nghĩa |
| `workflow.state_archived` | 850 | Lưu trữ | rà theo nghĩa |
| `workflow.action_archive` | 859 | Lưu trữ | Gỡ khỏi website |
| `workflow.action_retract` | 858 | Gỡ bài | Đưa về nháp |
| `rbac.role.author` | 809 | Tác giả | Người viết |
| `admin.role` | 248 | Vai trò | Nhóm quyền |
| `admin.select_role` | 249 | Chọn vai trò | Chọn nhóm quyền |
| `admin.change_role` | 250 | Đổi vai trò | Đổi nhóm quyền |
| `rbac.invitation.roles_label` | 815 | Vai trò | Nhóm quyền |
| `admin.wfb.actions_hint_role` | 707 | …vai trò… | …nhóm quyền… |
| `admin.wfb.wait_line_live` | 709 | …vai trò… · "gỡ bài" | …nhóm quyền… · "đưa về nháp" |
| `admin.wfb.err_forbidden` | 728 | Vai trò của bạn… | Nhóm quyền của bạn… |
| `entries.category_label` | 15 | Danh mục | Chuyên mục |
| `catalog.category_label` | 496 | Danh mục | Chuyên mục |
| `admin.users` | 245 | Người dùng | Thành viên |
| `admin.media` | 479 | Thư viện | Thư viện ảnh & tệp |
| `admin.contacts` | 562 | Liên hệ | Khách liên hệ |
| `admin.social_login` | 129 | Đăng nhập Social | Đăng nhập nhanh |
| `entries.filter_clear_all` | 611 | Xóa tất cả | Bỏ lọc |
| `entries.filter_reset` | 608 | Đặt lại | Bỏ lọc |
| `admin.inbox.clear_filters` | 785 | Xoá lọc | Bỏ lọc |
| `forms.status_read` | 321 | Đã đọc | Đã xem |
| `forms.status_spam` | 323 | Spam | Thư rác |
| `forms.action_mark_spam` | 330 | Đánh dấu spam | Đánh dấu thư rác |
| `forms.action_not_spam` | 331 | Không phải spam | Không phải thư rác |
| `admin.support.not_allowed` | 213 | Tài khoản của bạn không được đăng nhập dưới tên thành viên website. | rà theo nghĩa (đã không có "vai trò") |
| `entries.action_destroy` · `entries.destroyed` · `entries.force_delete_confirm` | 593 · 625 · 627 | Xoá … | Xóa … |
| `admin.turnstile_login_note` | 235 | khoá | khóa |
| `rbac.invitation.error_invalid` | 827 | huỷ | hủy |
| `admin.turnstile_login_desc` · `admin.wf.locked_error` · `admin.wf.upgrade_contact` | 232 · 667 · 688 | site | website |
Đối chiếu bằng `github_search_code` trên `lang/`; quét có giới hạn — khóa chứa "site"/kiểu dấu mới ngoài danh sách trên: [CHƯA ĐỐI CHIẾU REPO đủ].
2. **Bộ định dạng dùng chung** (số · tiền · dung lượng · ngày giờ theo `SiteTime`), sửa chỗ lệch: `…/livewire/entries/index.blade.php` (lượt xem `number_format`; ngày hẹn giờ `isoFormat('D MMM, Y')`); dung lượng ảnh trong bộ chọn ảnh; `…/livewire/forms/inbox.blade.php`, `…/forms/detail.blade.php`, `…/platform/audit/index.blade.php` hiện giờ UTC — [CHƯA ĐỐI CHIẾU REPO lượt này; lấy theo prompt].
3. **Thùng rác tự xóa hẳn** bài, trang, sản phẩm, trình đơn sau 30 ngày.
4. **Tên nhóm quyền nền tảng** (thêm chuỗi dịch, ví dụ `rbac.platform_role.*`): `super_admin` "Quản trị nền tảng" · `support` "Nhân viên hỗ trợ" · `billing` "Nhân viên thanh toán".
5. **Trạng thái màn mới** cho `<x-bs-admin::empty-state>`: `read-only` · `plan-locked` · `save-failed` (không xóa dữ liệu form khi lưu lỗi).
