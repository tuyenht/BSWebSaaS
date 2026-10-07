# README — D1-3 · Danh sách nội dung: một khuôn cho Trang, Tin tức, Dịch vụ, Dự án (07/10/2026)

## Tổng quan
`Quản lý Bài viết - Options.dc.html` là MỘT danh sách cho mọi loại nội dung (prop `contentType` từ Shell / Tweak "Loại nội dung" khi mở riêng). Trang = cây cha – con + cột Mẫu trang, không chuyên mục; Tin tức / Dịch vụ / Dự án = chuyên mục (chính), thẻ, tác giả. Quyền tính theo TỪNG dòng (BSCW + EntryPolicy own/any), đọc/ghi kho `BSCW.store` dùng chung. Phần Thêm/Sửa không đổi (D1-4b).

## File
| File | Việc |
|---|---|
| `Quản lý Bài viết - Options.dc.html` | chỉ phần danh sách (CSS `.ql-*`, khung chứa `ql`; template danh sách; logic `/* danh sách (D1-3 …) */`) |
| `Quản lý Trang - Options.dc.html` | chỉ dải đầu trang "Đã gộp vào Quản lý Bài viết - Options (D1-3) — chỉ để đối chiếu" |
| `shell-screens.js` | `TYPE.list` → `Quản lý Bài viết - Options` cho mọi loại (kể cả `pages.list`), `turn: 'D1-3'` |
| `Admin Shell.dc.html` | khối gắn sẵn (`mContent` khớp `<loại>.list`); bỏ `zoom` Chữ lớn cho `<loại>.list` vì màn dùng `--fsp` |
| `SaaS Starter - Bản đồ màn hình.md` | dòng 8–9 |
| `TODO.md` · `LESSONS.md` | đóng dòng · ghi bài học |

## Mức hoàn thiện
Prototype tương tác, dữ liệu mẫu trong `BSCW.store` (Tweak "Số bài" 12 / 60 / 180 khi mở riêng).

## Màn & trạng thái
Có dữ liệu · Đang tải (khung xám, hiện ngay) · Trống (theo tab / theo lọc, có "Bỏ lọc") · Lỗi ("Thử lại") · Không có quyền · Khóa theo gói (theo Tweak "Lời mời nâng gói") · Thành công (toast `role="status"`, Hoàn tác 8 giây). Tweak "Trạng thái danh sách".

## Tương tác
- Tab trạng thái theo quy trình đang bật (BSCW.UI) + "<loại> của tôi" khi nhóm quyền chỉ sửa bài của mình + Thùng rác; < 1280 khung chứa: tab ít dùng vào "Khác", dải tab cuộn trong dải. Đổi tab → bỏ chọn hết (`Index.php:119-125`).
- Ô tìm + Lọc (Chuyên mục — không có ở Trang; Mẫu trang — chỉ Trang; Người tạo; Bản dịch đủ / thiếu / chưa có; Ngôn ngữ) → dòng "Đang lọc: chip × · Bỏ lọc · Khớp N / M"; Sắp xếp; Chọn; Cột (Tiêu đề khóa).
- Dòng: tiêu đề + đường dẫn sửa tại chỗ; chip trạng thái bấm đổi (chỉ bước quy trình + quyền); "Hẹn giờ đăng"; chip ngôn ngữ bấm mở menu "Sửa bản / Thêm bản"; người tạo (chữ cái đầu); "Sửa" hiện sẵn (Trang thêm "+ Trang con"); "⋯": Xem trên website · Sao chép đường dẫn | Nhân bản · Chuyển lên / Chuyển xuống (Trang) | Chuyển vào Thùng rác (cuối, đỏ).
- Trang: cây cha – con, kéo-thả trong cùng trang cha (có nút Chuyển lên/xuống thay thế); đang tìm hoặc lọc → danh sách phẳng có đường cha ("Giới thiệu › Lịch sử ›"), kéo-thả tắt kèm lý do. Dòng Trang mở `Page Editor - Options` bằng `bs-goto {screen:'pages.edit', params:{slug}}`.
- Hàng loạt: "Đã chọn N" + mỗi nút đếm dòng làm được + câu lý do dòng bị bỏ qua. Đo: Người viết chọn 2 bài nháp của mình + 3 bài người khác → "Gửi duyệt (2)" + "“Gửi duyệt” bỏ qua 3 bài: 3 bài của người khác."; Quản trị chọn 5 bài không ai đang sửa → "Đăng (5)" (có 1 dòng đang bị người khác mở → "Đăng (4)" + "bỏ qua 1 bài: đang có người khác sửa"); sang Thùng rác → "Đã chọn" về 0.
- Thùng rác: chuyển vào làm ngay + toast "Hoàn tác" 8 giây (trang có trang con → hỏi "Chuyển trang này và N trang con"); Khôi phục; Xóa vĩnh viễn = hộp xác nhận nguy hiểm, nút Hủy được focus; dòng "Tự xóa vĩnh viễn sau N ngày" gắn Chờ backend.
- Thẻ gọn < 768 khung chứa: dòng 1 ô chọn + tiêu đề + trạng thái · dòng 2 đường dẫn · ngôn ngữ · người tạo · ngày · dòng 3 "Sửa" rộng hết + "⋯" (trượt đáy); 600–767 hai cột; thanh hàng loạt dính đáy.

## Ánh xạ dữ liệu (repo `tuyenht/BSWebSaaS@main`)
- `entries` (type page|post · status draft|pending|published|scheduled|private|archived — migration `2012_…_expand_entry_status…` · bước duyệt · người được giao · `parent_id` · tác giả · `published_at` · `view_count` · `deleted_at`) + `entry_translations` (title · slug · status theo ngôn ngữ).
- Danh sách + tab + Thùng rác: `app/Modules/Admin/Livewire/Entries/Index.php`; bỏ chọn khi đổi tab `Index.php:119-125`; quyền cả màn `Index.php:832`; lọc theo loại `Index.php:850-867`.
- Quyền dòng: `EntryPolicy` (`entries.edit.own|any`, `entries.delete.own|any`, `entries.publish`, `entries.submit`) qua `BSCW.can`; bước: `WorkflowTemplateRegistry.php`, `ApplyEntryWorkflowTransition.php`.
- Ghi cho build: bản đang chạy truyền `canPublish` cho CẢ màn (`Entries/Index.php:832`) — khi build tính theo TỪNG dòng như prototype (grep tệp thiết kế: 0 `canPublish`).

## Quyền & gói (ai thấy gì)
| Nhóm quyền | Nút trên dòng | Hàng loạt |
|---|---|---|
| Chỉ xem | không "Sửa", không chip đổi trạng thái, không "⋯" Thùng rác | không có ô chọn |
| Người viết (bài của mình) | "Sửa" + "Gửi duyệt" ở bài của mình; bài người khác không có "Sửa"; không "Đăng" | Gửi duyệt (n của mình) |
| Biên tập / Quản trị (mọi bài) | đủ; bài người khác đang sửa → khóa + "<tên> đang sửa" | Đăng / bước quy trình (n) |
"Lời mời nâng gói" TẮT → nút AI ẩn; BẬT → khóa + "Xem gói nâng cấp".

## Token
Bảng hex → token (hai bản nguồn viết hex; phần danh sách 0 hex ngoài `var(…)`):
| Hex cũ | Vai trò | Token |
|---|---|---|
| `#2050a0` chữ / viền / tab chọn / số đếm | chữ thương hiệu | `var(--color-accent)` |
| `#2050a0` nền nút chính, trang đang chọn | nền dưới chữ trắng | `var(--color-admin-primary)` |
| `#878a99` · `#ced4da` · `#9aa1ac` · `#adb5bd` chữ | chữ phụ | `var(--color-body-muted)` |
| `#fff` băng thông tin / thẻ | nền | `var(--color-surface)` |
| `#f3f3f9` · `#f4f5fa` | nền trang | `var(--color-body)` |
| `#e9ebec` · `#e9ecf4` | kẻ | `var(--color-border)` / `--color-border-strong` |
| `#c78729` "Đã lên lịch" vàng | Đã hẹn giờ | `var(--color-admin-secondary-text)` |
| `#0ab39c` | Đã đăng | `var(--color-admin-success-text)` (chấm: `--color-admin-success`) |
| `#f7b84b` | Chờ duyệt | `var(--color-admin-warning-text)` |
| `#3577f1` nền | nút chính | `var(--color-admin-primary)` |

## Icon
`ri-list-check` · `ri-user-3-line` · `ri-delete-bin-line` · `ri-delete-bin-2-line` · `ri-arrow-go-back-line` · `ri-send-plane-line` · `ri-inbox-unarchive-line` · `ri-git-branch-line` · `ri-draft-line` · `ri-file-copy-line` · `ri-drag-move-2-line` · `ri-arrow-up-down-line` · `ri-links-line` · `ri-link` · `ri-external-link-line` · `ri-eye-line` · `ri-translate-2` · `ri-git-merge-line` · `ri-node-tree` · `ri-more-2-fill` · `ri-filter-3-line` · `ri-time-line`.

## Chuỗi cần dịch (mới)
"<Loại> của tôi" · "“{việc}” bỏ qua N {loại}: …" · "Đang tìm hoặc lọc nên trang hiện thành danh sách phẳng, có trang cha phía trước. Kéo-thả để đổi thứ tự tạm tắt — bỏ lọc để sắp xếp lại." · "Chuyển trang này và N trang con vào Thùng rác?" · "Xóa vĩnh viễn N {loại}?" · "Tự xóa vĩnh viễn sau N ngày" · "Đã gộp vào Quản lý Bài viết - Options (D1-3) — chỉ để đối chiếu" · "<tên> đang sửa".

## Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Tab + Thùng rác, tìm, lọc, chọn cột, Dòng/trang (cả hai bản) | giữ nguyên vị trí | đang chạy | luật (e) |
| Trang: chip ngôn ngữ chỉ hiện khi rê chuột | bấm mở menu Sửa bản / Thêm bản | mới | không gì chỉ làm được bằng rê chuột |
| Trang: `canPublish` cả màn | quyền từng dòng BSCW | mới | LESSONS #18 |
| Bài viết: lọc Chuyên mục + Bản dịch | giữ, thêm Mẫu trang cho Trang | hợp cả hai | LESSONS #15 |
| Xóa có hộp hỏi | làm ngay + Hoàn tác 8 giây | mới | luật Thùng rác |

## Kết quả 12 tiêu chí dễ dùng
1 Đạt (tiêu đề + 1 dòng mô tả + 1 nút chính "Viết bài mới" / "Thêm trang") · 2 Đạt (menu › Danh sách › chip trạng thái: 2 bước) · 3 Đạt · 4 Đạt (icon kèm chữ; "⋯" có aria-label) · 5 Đạt (đường dẫn trùng: gợi ý "…-2") · 6 Đạt (Thùng rác + Hoàn tác; xóa vĩnh viễn hỏi) · 7 Đạt (chip = màu + icon + chữ; chấm ngôn ngữ kèm tên trong menu) · 8 Đạt (tab cuộn trong dải, nút 1 dòng; lỗi chồng "Bản nháp"/"Khác" ở 768–1279 đã sửa lượt này) · 9 Đạt theo đo 390 (thẻ gọn) — cảm ứng thật [CHƯA KIỂM ĐƯỢC] · 10 Đạt (hàng loạt, lọc nhanh, phím tắt của Shell) · 11 Đạt (đo Chữ lớn, sửa tràn 17px ở 360 lượt này) · 12 Đạt (grep: 0 "Xóa tất cả" · "Đặt lịch" · "Vai trò" · "Riêng tư" · "xuất bản" · "lên lịch" · "Danh mục" ở 2 tệp danh sách).

## Kết quả Chất lượng hoàn thiện (A–E)
- A Đạt — thang 4/8, một nút chính, chuyển động theo `admin-base.css` (tắt khi giảm chuyển động).
- B Đạt — chữ ≥ 14 / chú thích ≥ 12; kéo-thả có nút thay thế; toast < 1 giây.
- C Đạt [SUY RA TỪ CODE] — Esc đóng lớp bật, focus trả về nút mở (`_opener`), hộp xác nhận focus Hủy, 1 h1 (Shell khi nhúng), toast `role="status"` `aria-live`.
- D Đạt [ĐO BẰNG SCRIPT] — `bs-scheme=dark`, `H.measure` ở 1240 và 390: **0** chữ dưới ngưỡng (danh sách mặc định, Tin tức, 2 ngôn ngữ). Safari/Firefox [CHƯA KIỂM ĐƯỢC — môi trường thiết kế chỉ có Chrome].
- E — đo dưới đây; console 0 lỗi (standalone + Shell `posts.list`, `pages.list`).

## Kiểm hiển thị [ĐO BẰNG SCRIPT, `_audit/harness.html`]
| Bề rộng | Chữ thường | Chữ lớn | Trong Shell 390 |
|---|---|---|---|
| 360 | sw 345 = cw 345 | sw 345 = cw 345 (trước sửa 362) | — |
| 390 | 375 = 375 | 375 = 375 | `posts.list` 390 = 390, thẻ grid 3 dòng · `pages.list` 390 = 390 |
| 768 | 753 = 753 | 753 = 753 | — |
| 1024 | 1009 = 1009 | 1009 = 1009 | — |
| 1440 | 1425 = 1425 | 1425 = 1425 | — |
Safari iOS · Firefox · Samsung · xoay ngang 844×390 · 200% · cảm ứng thật: [CHƯA KIỂM ĐƯỢC] — Claude Code bằng zip.

## Lưới mẫu (mục 8)
Đã chạy (Chrome, 60 mục, chỉ danh sách): 1240 × {Người viết, Quản trị} × Đăng thẳng × 2 ngôn ngữ (hàng loạt, Thùng rác) · 909 × Trang × Quản trị (tìm "lich su" → danh sách phẳng, kéo-thả tắt) · 390 × Tin tức / Trang × Biên tập (Shell) · 360–1440 × Tin tức × Biên tập × Chữ thường / Chữ lớn.
Còn lại cho D5-2: 3 bề rộng × Chỉ xem × 3 chế độ duyệt · "Duyệt trước khi đăng" / "Quy trình biên tập" × Người viết ở 390 / 768 · 1 ngôn ngữ (ẩn chip mã) × 3 bề rộng · 4 ngôn ngữ × 390 · luồng D0-5 Gửi duyệt → Hộp duyệt → "Đã đăng" chạy lại `_audit/d05-run.html` sau lượt này [CHƯA KIỂM ĐƯỢC trong lượt — Shell chụp quá giờ].

## Việc cho backend
1. Danh sách một loại phải gồm bài có chuyên mục chính nằm trong NHÁNH của loại — `Index.php:850-867` chỉ lấy bài có chuyên mục chính đúng bằng gốc của loại, trong khi quyền và quy trình đã đi theo nhánh.
2. Chia trang ở CSDL — `Index.php` nạp hết rồi chia trong bộ nhớ; số trên tab lấy từ bộ đếm theo website; quá 1.000 dòng hiện "Khớp hơn 1.000" · Cần owner chốt (danh sách hàng chục nghìn dòng).
3. Quyền từng dòng thay `canPublish` cả màn (`Index.php:832`).
4. Lọc Chuyên mục (repo chưa lọc) · Chờ backend.
5. Lượt xem 30 ngày qua — repo chỉ có `view_count` tổng; nhãn hiện "Lượt xem" · Chờ backend.
6. Tự xóa vĩnh viễn bài trong Thùng rác sau N ngày (repo chỉ tự dọn Thư viện ảnh & tệp, `media:purge-trash`) · Chờ backend.
7. Khóa sửa đồng thời ("<tên> đang sửa") — dữ liệu: người đang mở + thời điểm, hết hạn sau ~2 phút không hoạt động · Chờ backend.
8. Trang: đổi thứ tự trong cùng trang cha (cột `sort_order` theo `parent_id`) + đổi đường dẫn trang cha thì trang con đổi theo và tự chuyển hướng địa chỉ cũ · Chờ backend.

## Verifier
Xem dòng cuối sau ready_for_verification; nếu công cụ chụp quá giờ: [CHƯA KIỂM ĐƯỢC] — Claude Code kiểm bằng zip.

## Phần 3 (07/10) — bản tương tác "Danh sách co giãn" #5a chuyển vào trang chuẩn
- `Chuẩn giao diện SaaS Starter.dc.html` › Co giãn: thẻ mới `#co-gian-tuong-tac` "Danh sách co giãn · bản tương tác" — khung 390 + 768, Tweak trong thẻ: Mẫu (Bài viết · Sản phẩm · Trang) × Xem như (Biên tập — mọi bài · Người viết — bài của mình · Chỉ xem) × Website bật (4 / 1 ngôn ngữ).
- Đủ tương tác của #5a: menu trạng thái (BSCW `tier1`, chỉ bước được phép) · menu bản ngôn ngữ (Sửa bản / Thêm bản / Xem bản) · tab + "Khác" (đang ở tab trong "Khác" thì nút hiện tên + số) · một nút "Lọc (N)" (nhóm tích + số, chân "Bỏ lọc" · "Xong · Khớp N / M") · Sắp xếp · Chọn nhiều + "Chọn tất cả" · hộp Hẹn giờ đăng · "⋯" (Xem · Sao chép đường dẫn | Sửa nhanh · Nhân bản | Chuyển vào Thùng rác đỏ, cuối) · Thùng rác + Hoàn tác 8 giây · tab Thùng rác (Khôi phục · Xóa vĩnh viễn…). Dưới 600px bảng chọn trượt đáy, từ 600px menu cạnh nút; Esc / bấm ra ngoài đóng, trả focus về nút mở; mũi tên lên/xuống trong menu.
- Bổ sung theo luật 06/10 so với #5a: không có quyền → ẩn nút (thay vì mờ); bài người khác đang sửa → "Sửa" khóa + dòng "<tên> đang sửa" hiện sẵn; Chỉ xem → không có "Chọn", nút chính "Xem", dòng "Chỉ xem"; nút hàng loạt đếm dòng làm được + câu bỏ qua (đo: Người viết chọn 5 → "Chuyển vào Thùng rác" bỏ qua 2 bài của người khác; Biên tập chọn 5 → "Đăng (3)" + "bỏ qua 2 bài: 1 đã đăng, 1 Trần Minh Anh đang sửa"); đổi tab → bỏ chọn hết. Nhãn trạng thái đọc BSCW.UI ("Đã hẹn giờ", "Không công khai"); bỏ "Đặt lịch" / "Đã lên lịch" / "Riêng tư" / "Danh mục" của bản cũ.
- CSS mới chỉ ở `admin-components.css`, tiền tố `dli-` (khung chứa `.fr`), dùng lại `.sx-btn` · `.sx-seg` · `.sx-search` · `.lcs/.lc` · `.avi`; chỉ token.
- Đã xóa `Danh sách co giãn - Đã chốt.dc.html` (không còn tệp nào trỏ tới, trừ ghi chép lịch sử trong README D0-2 / AUDIT).
- Đo [ĐO BẰNG SCRIPT, `_audit/harness.html`, trang chuẩn 1280]: 2 khung · 10 dòng; 0 phần tử vượt khung 390/768; console 0 lỗi; Esc đóng menu, focus về chip trạng thái. Chế độ tối, Chữ lớn, Safari/Firefox của thẻ mới [CHƯA KIỂM ĐƯỢC — công cụ chụp trang chuẩn quá giờ] → Claude Code bằng zip.
