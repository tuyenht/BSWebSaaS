# D0-4 · Admin Shell v2 — README handoff

## Tổng quan
D0-4a (06/10/2026, phần 1 + phần 2): menu 2 mặt bám code thật, một nguồn cho 3 kiểu bố cục, mục đang chọn đúng một, câu chữ theo Bảng thuật ngữ D0-3, data-props tối thiểu; topbar gọn (Tìm · Trợ giúp · chuông · bộ chuyển website · menu người dùng), panel Trợ giúp, menu người dùng › Giao diện; khung Cài đặt chuẩn + thanh Lưu dính đáy + danh sách trang dưới 768px. Lượt kế tiếp: D0-4b (viết tiếp vào file này).

## File
- `Admin Shell.dc.html` — sửa tại chỗ (menu, 2 cột, ngang, trang trống, câu chữ, data-props; phần 2: topbar, Trợ giúp, tìm toàn màn, Giao diện, Cài đặt, thanh Lưu).
- `SaaS Starter - Bản đồ màn hình.md` — 17 dòng theo menu/Cài đặt mới; Quy trình duyệt → Nền tảng › Website › chi tiết › tab Quy trình duyệt.
- `TODO.md` — khối "D0-4a phần 2" + gắn lại đuôi lượt cho "D0-2 phần 3" và các dòng nêu trong prompt.
- `LESSONS.md` — 3 dòng (khác CLAUDE.md §16 về ô màu; mục đang chọn đúng một; menu một nguồn).

## Mức hoàn thiện
- Mục 1 Menu 2 mặt — xong.
- Mục 4 Câu chữ — xong.
- Mục 2 Topbar — xong. · Mục 3 Cài đặt — xong. · Mục 5 Bản đồ màn hình — xong. · Việc chuyển từ D0-3 phía Shell — xong; phía panel nhúng ghi TODO (D0-6a / D0-6b).

## Màn & trạng thái
- Mục chưa có màn: khung "đang được xây dựng" (giữ như cũ) + khối "Ghi chú thiết kế" ghi khóa quyền / module, và "Chờ backend" khi mục chưa có trong AdminNav/PlatformNav.
- Hộp duyệt: ẩn khi website tắt quy trình hoặc người dùng không duyệt được (`reviewQueue()` trả null); số 0 thì hiện mục, không hiện số. Prototype mặc định có 3 bài.

## Tương tác
- Dọc: bấm nhóm mở accordion; mục con có vạch + nền + `aria-current`. Nhóm đóng hoặc thanh icon thu nhỏ: vạch + `aria-current` nằm ở mục cha; mục icon có `aria-label` + tooltip.
- 2 cột: thanh icon = tiêu đề nhóm của nguồn menu (mục trước tiêu đề đầu = ô riêng); ô chứa mục đang chọn có vạch; cột phụ liệt kê mục, nhóm có mục con hiện tên nhóm + các mục con.
- Ngang: mục cha có nền + vạch khi chứa mục đang chọn, `aria-current` chỉ ở mục thật (mục lá hoặc mục con trong menu thả).
- Mặt: chỉ đổi bằng Tweak "Mặt" (xem thử prototype). Bản thật: 2 mặt ở 2 tên miền, không có nút chuyển mặt trong phiên; nhân viên nền tảng vào admin khách chỉ bằng "Đăng nhập dưới tên khách hàng" từ màn Website.

## Ánh xạ dữ liệu
- `app/Modules/Admin/Navigation/AdminNav.php` — thứ tự Tổng quan → loại nội dung {Thêm mới · Danh sách · Chuyên mục nếu `taxonomy_id`} → Sản phẩm (catalog, `catalog.view`/`catalog.manage`) → Hộp duyệt (`entries.review|approve` + `reviewQueue`) → media · contacts · menus · users → Cài đặt.
- `app/Modules/Admin/Navigation/PlatformNav.php` — tenants · sites · audit · settings (chỉ super_admin).
- Loại nội dung: `Component.CONTENT_TYPES()` thay cho bảng `content_sections` (sort_order, permission_prefix mặc định `entries`).

## Quyền & gói (khóa gắn trên từng mục; D0-4b dùng để ẩn)
Website: Tổng quan — mọi người · Loại nội dung `<prefix>.view` / Thêm mới `<prefix>.create` / Chuyên mục `taxonomies.manage` · Sản phẩm `catalog.view` + module catalog (Thêm mới `catalog.manage`) · Hộp duyệt `entries.review|entries.approve` + website bật quy trình · Thư viện ảnh & tệp `media.view` + media · Khách liên hệ `forms.view` + contacts · Biểu mẫu `forms.manage` (Chờ backend) · Trình đơn `menus.manage` + menus · Thành viên `users.manage` + users · Nhóm quyền `roles.manage` (Chờ backend) · Nhật ký hoạt động `activity.view` (đề xuất, Chờ backend; mặc định Chủ website + Quản trị) · Cài đặt `settings.manage` + settings.
Nền tảng: Tổng quan (Chờ backend) · Khách hàng `platform.tenants.view` · Website `platform.sites.view` · Gói & tính năng `platform.billing.manage|platform.entitlements.manage` (Chờ backend) · Loại website `platform.site_types.manage` (đề xuất, Chờ backend) · Nhân sự vận hành `platform.staff.manage` (đề xuất, Chờ backend) · Nhật ký hệ thống `platform.audit.view` · Đăng nhập hỗ trợ `platform.sites.impersonate` (Chờ backend, D4-3) · Cài đặt nền tảng — chỉ Quản trị nền tảng.
TMĐT (Để sau): giữ menu cũ; khóa `orders.*`, `buyers.*`, `inventory.view`, `promotions.manage`, `reports.view` là đề xuất.

## Token
Vạch mục đang chọn: `var(--color-brand-gold)` (thanh Đậm/Gradient/tối) · `var(--color-accent)` (thanh Sáng, ngang). Thanh Sáng: nền/chữ/viền qua `--color-surface`, `--color-body-text`, `--color-body-muted`, `--color-border*`. Badge vàng: `--color-admin-warning` + `--color-heading`.

## Icon (Remix)
ri-dashboard-2-line · ri-file-text-line · ri-newspaper-line · ri-briefcase-line · ri-folder-2-line · ri-box-3-line · ri-inbox-archive-line · ri-image-2-line · ri-mail-line · ri-survey-line · ri-node-tree · ri-team-line · ri-shield-user-line · ri-history-line · ri-settings-4-line · ri-building-2-line · ri-window-line · ri-price-tag-3-line · ri-layout-masonry-line · ri-user-star-line · ri-file-shield-2-line · ri-login-circle-line · ri-settings-3-line · ri-apps-2-line · ri-tools-line · ri-hammer-line (chỉ ghi chú thiết kế).

## Chuỗi cần dịch (mới/đổi)
Thư viện ảnh & tệp · Khách liên hệ · Biểu mẫu · Thành viên · Nhóm quyền · Nhật ký hoạt động · Hộp duyệt · Gói & tính năng · Loại website · Nhân sự vận hành · Đăng nhập hỗ trợ · Cài đặt nền tảng · Khách & website · Gói dịch vụ · Vận hành · Người mua · Nhóm người mua · Chuyển website · Nền tảng · mọi khách hàng · Quản trị nền tảng · Chủ website · Quản trị · Biên tập · Đã bỏ thay đổi · Bảo mật & xác thực 2 lớp · Tên miền medilab.vn đã bật kết nối bảo mật. · Tự động (nên dùng) · Mã hóa ngay khi kết nối · Không mã hóa.

## Kết quả 12 tiêu chí dễ dùng (phần 1 — chỉ menu)
1. Đạt cho menu: nhóm Nội dung / Công cụ / Hệ thống, nhãn đời thường. Topbar: chưa (phần 2).
2. Đạt: mọi việc chính ≤ 2 bước từ menu (nhóm → Thêm mới/Danh sách).
3. Đạt: menu không còn thuật ngữ; "Operator", "TLS", "2FA" đã bỏ khỏi Shell.
4. Đạt: mục icon-only ở thanh thu nhỏ có tooltip + aria-label.
5. Không áp dụng (phần 1 không thêm ô nhập).
6. Không áp dụng phần 1.
7. Đạt: mục đang chọn = nền + vạch + chữ đậm + aria-current.
8. Đạt: nhãn menu 1 dòng, "…" khi dài.
9. Chưa kiểm phần 1 ở 390/768 (phần 2 làm topbar + Cài đặt).
10. Đạt: mặc định gọn, không thêm điều khiển.
11. Chưa kiểm (Chữ lớn ở phần 2).
12. Đạt: nhãn theo Bảng thuật ngữ D0-3.

## Kiểm hiển thị
| Bề rộng × trình duyệt | Kết quả |
|---|---|
| 1151 (rail) · Chrome · Sáng | Đạt — 15 mục, đúng 1 aria-current (Tổng quan), chọn "Trang › Danh sách" → aria-current + vạch ở mục cha "Trang" [ĐÃ ĐO BẰNG SCRIPT] |
| 1440 / 1024 / 768 / 390 × sáng/tối × 3 bố cục | [CHƯA KIỂM ĐƯỢC] — công cụ chụp quá giờ với Shell ≈336 KB; Claude Code kiểm bằng zip |

Verifier: [CHƯA KIỂM ĐƯỢC] nếu công cụ chụp quá giờ — Claude Code kiểm bằng zip.

## Việc cho backend
- Biểu mẫu (`forms.manage`), Nhóm quyền (`roles.manage`): thêm vào `AdminNav::$features`.
- Nhật ký hoạt động website: quyền mới `activity.view` (mặc định Chủ website + Quản trị), màn + route.
- PlatformNav: Tổng quan, Gói & tính năng, Loại website (`platform.site_types.manage`), Nhân sự vận hành (`platform.staff.manage`), Đăng nhập hỗ trợ (`platform.sites.impersonate`, danh sách các lần đăng nhập dưới tên khách hàng).
- AdminNav còn đặt Quy trình duyệt trong Cài đặt (`settings.workflow`, `platform.workflows.manage`): owner 30/09 chuyển sang Nền tảng › Website › chi tiết › tab Quy trình duyệt (D4-1) — gỡ khỏi Cài đặt website.
- AdminNav đặt Bảo mật cá nhân trong Cài đặt (`settings.security`): chuyển sang Tài khoản của tôi.

## Phần 2 (06/10/2026)

### Topbar
- Thứ tự 1440: [Menu] · ô tìm "Tìm trang, bài viết, sản phẩm, cài đặt…" (gợi ý phím tắt Ctrl K / ⌘K từ 1024px) · Trợ giúp · chuông · bộ chuyển website · menu người dùng. Mặt Nền tảng: chỗ bộ chuyển website là nhãn "Nền tảng · mọi khách hàng".
- Dưới 640px: ô tìm thành kính lúp → hộp tìm toàn màn (ô 16px, nút ← đóng, Esc đóng). Ctrl/⌘+K: tới ô tìm, hoặc mở hộp tìm khi ô đang ẩn.
- Đã bỏ: nút toàn màn hình, nút sáng/tối, nút Tùy biến nổi góc phải, bộ chọn ngôn ngữ có cờ.
- Bộ chuyển website: hiện khi ≥ 2 website hoặc gói còn chỗ; hết chỗ → "Tạo website mới — khóa theo gói" (chữ trung tính, D0-4b nối Tweak "Lời mời nâng gói"); ô tìm từ 6 website. Chọn website khác = mở trang quản trị của website đó (toast nói rõ; chưa đăng nhập thì qua màn đăng nhập).
- Menu người dùng (cả 2 mặt): Tài khoản của tôi (mở hộp `acctShow` đang có, giữ nguyên) · Giao diện · Ngôn ngữ giao diện (tên bản địa, Tiếng Việt trước, không cờ) · Khóa màn hình (giữ nguyên) · Đăng xuất (hộp xác nhận đang có).
- Giao diện (bảng bên phải): Cỡ chữ Bình thường / Lớn · Bố cục · Chế độ màu Sáng / Tối / Theo máy · Chiều rộng · Vị trí · Độ rộng thanh bên · Màu thanh bên (Sáng · Đậm · Chuyển màu) · Màu thanh trên cùng · "Về mặc định". Đã gỡ khối "Loại tài khoản" (→ Tweak) và "Màu thương hiệu" (→ Cài đặt nền tảng › Thương hiệu trắng). Hai tầng: Cài đặt › Giao diện trang quản trị = mặc định của website; menu người dùng › Giao diện = lựa chọn riêng, đè lên mặc định. Lưu/đặt lại theo người dùng = `admin.theme.save` / `admin.theme.reset` (prototype: `localStorage['bs-ui']`).
- Chữ lớn: `--fsp: 2px` trên khung gốc (màn nhúng thừa hưởng) + nội dung Shell phóng 16/14 vì chữ Shell viết px (TODO · D0-5 bỏ phóng khi màn nhúng dùng `--fsp`).
- Trợ giúp: panel bên phải, "Đang xem: <màn>", 3 câu hỏi theo màn (Cài đặt · Tổng quan · Trình đơn · mặc định), Liên hệ hỗ trợ (gọi · Zalo · email từ `BSPLAT.support()`). Build: `<x-bs-admin::help-panel>`.

### Cài đặt
- Website: Chung {Thông tin chung · Giao diện website · Giao diện trang quản trị · Ngôn ngữ website} · Nội dung {Đường dẫn · Sản phẩm} · Tiếp thị & Kết nối {SEO & Chia sẻ · Chuyển hướng · Tích hợp & Theo dõi · Nút liên hệ nổi} · Hệ thống {Tên miền · Email · Đăng nhập nhanh · Gói & mức dùng} · Nâng cao (gập mặc định; tự mở khi đang ở mục bên trong hoặc khi tìm) {Bảo mật & Nâng cao · Dữ liệu & Phục hồi}. TMĐT thêm nhóm Bán hàng như cũ.
- Khóa mục giữ nguyên (`general · appearance · langs · seo · integrations · floating · domain · email · social · quota · backup · advanced · p-*`); mục mới: `admin-ui · permalinks · catalog · redirects` (khung, D3-1/D3-2 vẽ đủ).
- Quyền: mọi mục `settings.manage`; Tên miền `domains.manage`; Sản phẩm thêm module catalog. Bảo mật cá nhân → Tài khoản của tôi. Không có Quy trình duyệt.
- Nền tảng: Thông tin · Thương hiệu trắng (logo + màu trang quản trị cho cả nền tảng) · Email hệ thống · Mẫu email · Đăng nhập nhanh · Bảo mật & Nhật ký. "Gói & Giới hạn" → mục menu "Gói & tính năng" (`p-plans`, mở phần đã vẽ, không có cột mục).
- Mô tả 1 dòng viết lại bằng lời thường: 0 lần SMTP · OAuth · SSL · GTM · sitemap · robots · Operator [ĐO BẰNG SCRIPT].
- Dưới 768px (theo bề rộng cửa sổ): danh sách nhóm/mục dạng trang (tiêu đề "Cài đặt", "Chọn mục bạn muốn sửa."); bấm mục → màn mục + nút "‹ Cài đặt". Bỏ dải tab cuộn ngang. 768–1023: cột mục 220px + nội dung.
- Hash `#settings/<mục>` mở thẳng mục (cả trên điện thoại); hash lạ bị bỏ qua (đóng L1-C-06).

### Thanh Lưu dính đáy (mẫu 4) + hợp đồng nhúng
- "N thay đổi chưa lưu" (vàng, icon) hoặc "Mọi thay đổi đã được lưu" · "N lỗi" (đỏ, icon) + "Xem lỗi đầu tiên" · Bỏ thay đổi · Lưu. Bỏ cụm "Chưa lưu · Bỏ thay đổi · Lưu thay đổi" ở đầu trang. Lưu khi còn lỗi = đưa tới lỗi đầu tiên (`aria-disabled`).
- Form trong Shell: đếm ô đã đổi theo bản nháp của mục; lỗi = số ô không hợp lệ; "Xem lỗi đầu tiên" mở nhóm chứa ô lỗi rồi focus ô có `aria-invalid="true"`.
- Panel nhúng: `onDirty(n)` nhận số (true/false = 1/0), `on-invalid` → `onInvalid(n)`, Shell gửi `bs-embed-cmd {id, act:'firstError'}`. Phía panel: TODO D0-6a / D0-6b.

### Lỗi đã sửa kèm
- Shell có 2 hàm `componentDidUpdate` (hàm sau đè hàm trước) → gộp; Tweak "Mặt" đổi lúc chạy nay áp được.
- Tổng quan 360px: lưới giữa `1fr` → `minmax(0,…)` (thẻ biểu đồ tràn 19px).

## Kết quả "Xong khi" (đo 06/10 bằng `_audit/harness.html` + script, khung 1440×900, 390×800, 360×740)
1. Đạt [ĐO BẰNG SCRIPT] — Website dọc 15 mục; Nền tảng dọc: Tổng quan · Khách hàng · Website · Gói & tính năng · Loại website · Nhân sự vận hành · Nhật ký hệ thống · Đăng nhập hỗ trợ · Cài đặt nền tảng; thanh icon 2 cột Nền tảng: Tổng quan · Khách & website · Gói dịch vụ · Vận hành (suy từ cùng nguồn). Mỗi mục có perm/mod.
2. Đạt [ĐO BẰNG SCRIPT] — đúng 1 `aria-current="page"`, đúng 1 vạch đang nhìn thấy; tương phản chữ mục đang chọn / nền của nó:
   | Bố cục | Thanh Đậm | Thanh Sáng |
   |---|---|---|
   | Dọc (Website · mục con "Tin tức › Danh sách") | 7,00 | 6,72 |
   | Dọc (Nền tảng) | 7,00 | 6,72 |
   | Ngang (mục lá "Thư viện ảnh & tệp" / "Nhật ký hệ thống") | 6,61 | 6,61 |
   | 2 cột (Website "Danh sách" · Nền tảng "Website") | 7,05 | 6,61 |
   | Dọc · chế độ tối | 12,15 | — |
   Ngang khi mục con đang chọn: `aria-current` nằm ở mục con trong menu thả (ẩn tới khi rê/focus), mục cha có nền + vạch. 2 cột: ô nhóm ở thanh icon chỉ có nền (vạch ở mục của cột phụ); 1024–1279 khi cột phụ ẩn thì ô nhóm mang vạch.
3. Đạt [ĐO BẰNG SCRIPT] — 1151: Menu · Tìm · Trợ giúp · Thông báo · Chuyển website · Tài khoản; không còn nút toàn màn hình / sáng-tối / Tùy biến nổi. Harness `H.mk('f',390,800,0,0,'../Admin Shell.dc.html')`: scrollWidth 390 = clientWidth 390; 360: 360 = 360. Nút phải nhất kết thúc ở 368 (390) / 338 (360).
4. Đạt [ĐO BẰNG SCRIPT] — 14 mục website ở nhóm mở + 2 trong Nâng cao (gập); mô tả 0 từ cấm; 390 = danh sách trang, bấm "SEO & Chia sẻ" → màn mục, "‹ Cài đặt" → về danh sách; `#settings/email` ở 360 mở thẳng Email + "‹ Cài đặt" + thanh Lưu.
5. Đạt [ĐO BẰNG SCRIPT] — sửa "Tên công ty (pháp lý)" → "1 thay đổi chưa lưu … Lưu"; nhập "abc" vào "Email liên hệ" rồi đóng nhóm → "2 thay đổi chưa lưu · 1 lỗi · Xem lỗi đầu tiên"; bấm → nhóm mở, focus "Email liên hệ" (`aria-invalid="true"`).
6. Đạt [ĐO BẰNG SCRIPT] — grep phân biệt hoa thường: 0 "Chủ sở hữu · Biên tập viên · đã xuất bản · hoàn nguyên · 2FA · TLS · Operator" (chỉ còn tên biến `twofa*`); shopMenu 0 "Khách hàng".
7. Đạt — hộp Tài khoản (`acctShow`) và Khóa màn hình giữ nguyên; TODO đã gắn đuôi; lượt kế tiếp D0-4b.

## Kiểm hiển thị (phần 2)
| Bề rộng × trình duyệt | Kết quả |
|---|---|
| 1440 · Chrome · Sáng · 3 bố cục × thanh Đậm/Sáng | Đạt [ĐO BẰNG SCRIPT] (bảng mục 2) |
| 1151 · Chrome · Sáng | Đạt — thứ tự topbar [ĐO BẰNG SCRIPT] |
| 390 · Chrome · Sáng | Đạt — không cuộn ngang; tìm toàn màn 390×800; Trợ giúp 388px; Cài đặt danh sách trang [ĐO BẰNG SCRIPT] |
| 360 · Chrome · Sáng | Đạt — không cuộn ngang (sau sửa lưới Tổng quan) [ĐO BẰNG SCRIPT] |
| 768 / 1024 · chế độ tối · Chữ lớn · 200% · xoay ngang 844×390 | [CHƯA KIỂM ĐƯỢC] — hết lượt đo; Claude Code kiểm bằng zip |
| Safari iPhone/iPad · Firefox · Samsung Internet | [CHƯA KIỂM ĐƯỢC] — môi trường thiết kế chỉ có Chrome |

## Icon mới (phần 2)
ri-question-line · ri-arrow-left-line · ri-arrow-left-s-line · ri-palette-line · ri-translate-2 · ri-checkbox-blank-circle-line · ri-layout-4-line · ri-links-line · ri-route-line · ri-phone-line · ri-chat-3-line · ri-checkbox-circle-line · ri-close-circle-line · ri-lock-2-line.

## Chuỗi cần dịch (phần 2)
Tìm trang, bài viết, sản phẩm, cài đặt… · Trợ giúp · Đang xem · Hay hỏi ở màn này · Liên hệ hỗ trợ · Gọi tổng đài · Nhắn Zalo · Gửi email · Chuyển website · Tạo website mới — khóa theo gói · Gói hiện tại cho tối đa N website. Cần thêm? Liên hệ hỗ trợ. · Tài khoản của tôi · Giao diện · Ngôn ngữ giao diện · Cỡ chữ · Bình thường · Lớn · Theo máy · Về mặc định · Độ rộng thanh bên · Màu thanh bên · Màu thanh trên cùng · Chuyển màu · Giao diện trang quản trị · Đường dẫn · Chuyển hướng · Tên miền · Cài đặt nền tảng · Chọn mục bạn muốn sửa. · N thay đổi chưa lưu · Mọi thay đổi đã được lưu · N lỗi · Xem lỗi đầu tiên · Đã về mặc định của website · mô tả 1 dòng của 22 mục Cài đặt.

## Việc cho backend (phần 2)
- Chuyển phiên giữa các website của cùng một người (mỗi website một tên miền) — chưa có; hiện chọn website = mở trang quản trị ở tên miền đó, chưa đăng nhập thì qua màn đăng nhập.
- Khóa màn hình thật ở máy chủ: mọi yêu cầu trả về màn khóa tới khi nhập đúng mật khẩu; trang đang mở và phần chưa lưu giữ nguyên.
- Giao diện cá nhân: `admin.theme.save` / `admin.theme.reset` lưu thêm `fontSize` và `scheme = auto`; mặc định website (Cài đặt › Giao diện trang quản trị) là tầng dưới.
- Số website tối đa theo gói cho bộ chuyển website (hết chỗ → khóa "Tạo website mới").
- Kho bài trợ giúp theo màn + kênh liên hệ hỗ trợ (`BSPLAT.support`).
- Mục mới cần route/dữ liệu: Đường dẫn (mẫu đường dẫn theo loại nội dung), Chuyển hướng (bảng từ → sang, tự thêm khi đổi đường dẫn bài đã đăng), Cài đặt Sản phẩm, Giao diện trang quản trị (mặc định website).

Verifier: chạy sau khi giao; nếu quá giờ → [CHƯA KIỂM ĐƯỢC], Claude Code kiểm bằng zip.
