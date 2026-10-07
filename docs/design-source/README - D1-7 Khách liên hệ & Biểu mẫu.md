# D1-7 · Khách liên hệ & Biểu mẫu — handoff (07/10/2026)

## Tổng quan
Hai màn MỚI cho module Liên hệ:
- **Khách liên hệ** — mẫu 5 Hộp hai khung: danh sách (tab trạng thái, tìm, lọc biểu mẫu / thời gian, chọn nhiều, "Tải thêm") + khung đọc (Gọi · Zalo · Email một chạm, đổi trạng thái, đồng ý của khách chỉ đọc). Dữ liệu cá nhân: Tải danh sách về máy (xác nhận lại mật khẩu / mã 2 lớp), Thời hạn lưu giữ, Xóa theo yêu cầu của khách.
- **Biểu mẫu** — danh sách theo khuôn mẫu 1 (chỉ cột riêng) + trình sửa mẫu 3: 6 loại ô, kéo-thả + Lên / Xuống, ô đồng ý khóa ở cuối, thanh ngôn ngữ, lời cảm ơn, email nhận thư báo, xem trước, cách đặt vào trang.

## File
| File | Ghi chú |
|---|---|
| `Khách liên hệ.dc.html` | mới · link `admin-components.css` ngay sau `admin-base.css` |
| `Biểu mẫu.dc.html` | mới · như trên |
| `shell-screens.js` | `contacts` + `forms` → `kind:'mount'`, `status:'ready'` |
| `Admin Shell.dc.html` | chỉ khối gắn: 2 `<sc-if>` + `dc-import … embedded`, cờ `mContacts` · `mForms` · `ctForm` · `onEmbedDirtyKl` · `onEmbedDirtyFm` |
| `SaaS Starter - Bản đồ màn hình.md` | dòng 24–26 → ready |
| `TODO.md` · `LESSONS.md` | khối D1-7, 3 dòng bài học |

## Mức hoàn thiện
Hi-fi, tương tác đủ trên dữ liệu mẫu (58 liên hệ: 21 Liên hệ + 37 Yêu cầu báo giá; 12 Mới — khớp số "12" trên menu Shell). Phần backend chưa có gắn "Chờ backend" / "Cần owner chốt", gom dưới Tweak **notes** ("Hiện ghi chú thiết kế", mặc định bật).

## Màn & trạng thái
Tweak `viewState` + các Tweak quyền / gói.

| Trạng thái | Khách liên hệ | Biểu mẫu |
|---|---|---|
| Có dữ liệu | ✓ | ✓ |
| Trống | "Chưa có khách nào liên hệ" + 2 bước + "Xem trang Liên hệ" · "Đặt biểu mẫu vào trang khác" | Không áp dụng: biểu mẫu "Liên hệ" luôn được tạo sẵn (`EnsureContactForm`). Có trạng thái "Không có biểu mẫu nào khớp" + Bỏ lọc |
| Đang tải | khung xương 6 dòng (không chạy animation) | 3 dòng |
| Lỗi | "Chưa tải được…" + Thử lại | như trái |
| Không có quyền | nói nhóm quyền đang dùng + nhóm xem được (đọc BSCW) + Về Tổng quan | cần `forms.manage`; nhóm có `forms.view` thấy thêm "Mở Khách liên hệ" |
| Khóa theo gói (module tắt) | Tweak `moduleOn`=tắt; "Lời mời nâng gói" TẮT → "Chưa bật cho website này — liên hệ hỗ trợ"; BẬT → 1 dòng lợi ích + "Xem gói nâng cấp" | như trái |
| Thành công | toast `role="status"` 8 giây, có "Hoàn tác" khi hoàn tác được | toast bật/tắt + Hoàn tác, lưu |
| Biểu mẫu đang tắt | Tweak `formOff`: dải vàng + "Bật lại" (Biên tập) / "Nhờ người quản trị bật lại" (Chỉ xem) | dòng có nhãn "Đang tắt" + công tắc |

## Tương tác
**Khách liên hệ**
- Khung chứa ≥ 940px: hai khung, khung đọc dính, cao `100dvh − --sticky-top − 32px`, cuộn riêng. Dưới 940: chạm dòng → chi tiết toàn màn (danh sách ẩn), nút "Quay lại danh sách" được focus; thanh dính đáy: **Gọi <số>** (pri, 48–50px) · Zalo · Email · ⋯. Không có số → nút to là "Gửi email cho khách". "Quay lại" trả focus đúng dòng vừa mở (đo: `afterBackFocusMa = expectMa`).
- Nhúng: mở liên hệ phát `bs-goto {screen:'contacts', params:{ma:<ulid>}, force:true}`; Shell truyền lại `ma` → mở đúng liên hệ khi tải lại hash. Từ Biểu mẫu, "N liên hệ" mở `params:{form:<ulid biểu mẫu>}` → prop `form-ma` → lọc sẵn.
- Mở liên hệ "Mới": chỉ người có `forms.manage` tự chuyển sang Đã xem (như `Detail.php:34`).
- Tab: Tất cả (không gồm Thư rác) · Mới · Đã xem · Đã xử lý · Thư rác · Thùng rác. Số trên tab tính theo các bộ lọc khác.
- Tìm theo tên / email / số điện thoại (số so theo chữ số, từ 3 số). Lọc Biểu mẫu (tick có số) và Thời gian (Mọi lúc · Hôm nay · 7 ngày · 30 ngày · Tùy chọn từ–đến), chân "Bỏ lọc · Xong". Dòng "Đang lọc: chip × … Bỏ lọc · Khớp N / M".
- Chọn nhiều → "Đã xem (N)" · "Đã xử lý (N)" · "Là thư rác (N)": N = số dòng nút đó làm được; dòng đã ở trạng thái đó bỏ qua, có câu giải thích; toast + Hoàn tác.
- Khung đọc: trạng thái là nhóm 3 nút (Mới · Đã xem · Đã xử lý). Liên hệ ở Thư rác: dải đỏ + "Không phải thư rác" → Đã xem. `is_spam`: dòng "Bộ lọc tự động cho là thư rác"; sau "Không phải thư rác" vẫn hiện "Bộ lọc tự động cho là thư rác — đã đánh dấu Không phải thư rác" (cột `is_spam` không đổi).
- ⋯ (chỉ `forms.manage`): Là thư rác / Không phải thư rác · | · Chuyển vào Thùng rác (đỏ, toast Hoàn tác, Chờ backend) · **Xóa theo yêu cầu của khách…** (cuối, đỏ) → hộp giữa màn kiểu SweetAlert2, Hủy được focus, nút "Xóa hẳn liên hệ này".
- ⋯ đầu trang: "Thời hạn lưu giữ liên hệ…" → 12 / 24 (mặc định, "nên dùng") / 36 tháng / Không tự xóa (hiện cảnh báo luật). "Thư rác luôn tự dọn sau 30 ngày."
- "Tải danh sách về máy": hộp nói "Tệp chứa dữ liệu cá nhân của N khách", nhập lại mật khẩu (hiện/ẩn, cho dán) hoặc mã 6 số; lỗi cạnh ô ("Nhập mật khẩu đăng nhập của bạn để tiếp tục." · thử "sai" để xem lỗi sai mật khẩu); nút ghi "Tải tệp về máy (N)"; chặn bấm 2 lần ("Đang chuẩn bị tệp…").
- Phím: `/` tới ô tìm; Esc đóng hộp / menu / bảng lọc; menu ↑↓; hộp thoại giữ Tab trong hộp và trả focus về nút đã mở.

**Biểu mẫu**
- Dòng: tên + nhãn "Hệ thống" / "Đang tắt" · công tắc "Đang bật/Đang tắt" cạnh tên (nút `role="switch"` có chữ) · số liên hệ (mở Khách liên hệ đã lọc) · email nhận · **Sửa** · ⋯ (Xem trang có biểu mẫu · | · Xóa… đỏ, cuối). Biểu mẫu "Liên hệ" của hệ thống không có Xóa.
- Dưới 768px khung chứa: thẻ gọn — dòng 1 tên + công tắc, dòng 2 "21 liên hệ · email", dòng 3 "Sửa" rộng hết + ⋯ (bảng trượt đáy dưới 600px).
- Xóa còn liên hệ: "Xóa biểu mẫu "Yêu cầu báo giá" và 37 liên hệ đã nhận?" + gợi ý Tắt; nút Hủy (focus) · Tắt biểu mẫu · "Xóa biểu mẫu và 37 liên hệ". Không còn liên hệ: "Xóa biểu mẫu …? Không hoàn tác được".
- Trình sửa: thanh ngôn ngữ (chỉ khi ≥ 2 ngôn ngữ; pill có chấm trạng thái + sao vàng bản nguồn; khung chứa < 760px thành ô chọn), tiến độ, "Còn N từ AI", nút Dịch ("Dịch sang N ngôn ngữ" ở bản nguồn / "Dịch bản X" ở bản đích, chỉ điền ô trống, mờ khi không còn gì thiếu), dòng trạng thái dưới thanh.
- Ô theo ngôn ngữ (chip mã ở cuối nhãn): tên biểu mẫu, tên ô, lựa chọn, lời cảm ơn. Dùng chung (khóa + chip "Chung" ở bản đích): bật/tắt, loại ô, bắt buộc, thứ tự, email nhận. العربية: ô nhập + xem trước `dir="rtl"`.
- Ô: kéo biểu tượng ⠿ hoặc nút Lên / Xuống (có chữ, ẩn chữ dưới 420px, giữ aria-label); Sửa mở khung: tên ô, loại (6 loại), lựa chọn (Danh sách chọn / Ô tích), Bắt buộc, "Xóa ô này" (toast Hoàn tác). Thêm ô → menu 6 loại.
- Ô đồng ý: luôn ở cuối, ngoài danh sách kéo, không nút Lên/Xuống/Sửa/Xóa; câu theo ngôn ngữ do hệ thống cấp; "Bắt buộc theo luật bảo vệ dữ liệu cá nhân; câu chữ do hệ thống cập nhật."; liên kết "Chính sách bảo mật" vẽ chỗ — "Chờ nội dung pháp lý".
- Thanh Lưu dính đáy: "N thay đổi chưa lưu · N lỗi · Xem lỗi đầu tiên" · Bỏ thay đổi · "Lưu — hiệu lực ngay". Rời trình sửa còn thay đổi → hộp Lưu thay đổi / Bỏ thay đổi / Hủy; `beforeunload` khi còn thay đổi. Nhúng: `onDirty(n)`, nghe `bs-embed-cmd {act:'save'|'reset'|'firstError'}`; mở bằng `bs-goto {screen:'forms', params:{ma}}`.

## Ánh xạ dữ liệu (repo `tuyenht/BSWebSaaS@main`)
| Thiết kế | Repo |
|---|---|
| Danh sách, lọc trạng thái, 20/trang | `app/Modules/Admin/Livewire/Forms/Inbox.php:26–50` (`status` ∈ new/read/archived/spam, `paginate(20)`) |
| Mở chi tiết theo `ulid`, tự Đã xem chỉ với `forms.manage` | `Forms/Detail.php:27–37` |
| Đổi trạng thái cần `forms.manage` | `Forms/Detail.php:39–48` |
| `forms`: ulid, name/success_message theo ngôn ngữ, notify_emails, status active/disabled | `database/migrations/2005_01_01_000000_create_forms_tables.php:22–33, 73` |
| `form_fields`: label theo ngôn ngữ, type 6 loại, is_required, options, sort_order | cùng tệp `:36–49, 74` |
| `form_submissions`: data, name, email, phone, source_url, ip_address, user_agent, status, is_spam | cùng tệp `:51–68, 75` |
| Xóa biểu mẫu xóa luôn liên hệ | `form_submissions.form_id … cascadeOnDelete()` (`:55`) |
| Biểu mẫu "contact" + 5 ô, ô `consent` bắt buộc, câu do nền tảng cập nhật | `app/Modules/Forms/Actions/EnsureContactForm.php:22–31, 55–76` |
| Quyền `forms.view` / `forms.manage` | `PermissionRegistry.php:207` (theo prompt) · `content-workflow.js` ROLES |

Trạng thái hiển thị (đề xuất, **Cần owner chốt chữ**): new → Mới · read → Đã xem · archived → Đã xử lý · spam → Thư rác.

## Quyền & gói (ai thấy gì)
| Nhóm | Khách liên hệ | Biểu mẫu |
|---|---|---|
| Chủ website · Quản trị · Biên tập (`forms.manage`) | đủ: đổi trạng thái, chọn nhiều, ⋯, tải danh sách, thời hạn lưu giữ | đủ |
| Chỉ xem (`forms.view`) | xem + Gọi / Zalo / Email; KHÔNG ô chọn, đổi trạng thái, ⋯, Tải danh sách; mở "Mới" không tự đổi trạng thái; ghi chú "Cần owner chốt" (đọc được dữ liệu cá nhân) | Không có quyền (+ lối sang Khách liên hệ) |
| Người viết · Cộng tác viên | Không có quyền | Không có quyền |
| Module tắt | khóa theo Tweak "Lời mời nâng gói" | như trái |
| Nút Dịch | — | hiện khi ≥ 2 ngôn ngữ và website được cấp AI (`aiGranted`); không đủ từ → mờ + lý do |

## Token
Chỉ `var(--…)`: `--color-surface|surface-muted|surface-hover|body|border|border-strong|border-input|heading|body-text|body-muted|body-faint (chỉ icon trang trí)|accent|admin-primary|placeholder`, `--color-admin-{info,success,warning,danger}` (chấm, nền pha) + `-text` (chữ) + `--color-admin-danger-solid` (nút Xóa), `--color-brand-gold` (sao nguồn) · `--color-brand-gold-text` (chip từ AI), `--color-white` (chữ trên nền đặc của xem trước), `--sticky-top`, `--fsp`, `--focus-ring-color`. Focus: luật chung của `admin-base.css` (Focus 1b); ô tìm `data-focus-wrap`, công tắc `.dsw` / `role="switch"`, không viết focus riêng.

## Icon (Remix 4.9.1)
ri-survey-line · ri-download-2-line · ri-more-2-fill · ri-search-line · ri-calendar-line · ri-arrow-down-s-line · ri-close-line · ri-mail-line · ri-mail-unread-line · ri-mail-open-line · ri-check-double-line · ri-spam-2-line · ri-delete-bin-line · ri-delete-bin-6-line · ri-shield-flash-line · ri-shield-check-line · ri-phone-line · ri-chat-3-line · ri-mail-send-line · ri-mail-check-line · ri-arrow-left-line · ri-arrow-go-back-line · ri-information-line · ri-time-line · ri-lock-line · ri-shield-keyhole-line · ri-wifi-off-line · ri-refresh-line · ri-vip-crown-line · ri-customer-service-2-line · ri-home-4-line · ri-pause-circle-line · ri-play-circle-line · ri-external-link-line · ri-search-eye-line · ri-eye-line · ri-eye-off-line · ri-loader-4-line · ri-file-list-3-line · ri-checkbox-circle-line · ri-error-warning-line · ri-add-line · ri-pencil-line · ri-draggable · ri-arrow-up-line · ri-arrow-down-line · ri-arrow-up-s-line · ri-text · ri-list-check · ri-checkbox-line · ri-file-text-line · ri-translate-2 · ri-star-fill · ri-star-line · ri-sparkling-2-line · ri-save-3-line · ri-layout-masonry-line · ri-shield-star-line · ri-filter-3-line · ri-alert-line.

## Chuỗi cần dịch (Cần owner chốt chữ)
| Khóa `lang/vi.json` (dòng 321–331) | Hiện ở repo | Đề xuất |
|---|---|---|
| trạng thái `read` | Đã đọc | Đã xem |
| trạng thái `archived` | Lưu trữ | Đã xử lý |
| trạng thái `spam` | Spam | Thư rác |
| hành động đánh dấu spam | Đánh dấu spam | Là thư rác |
| (mới) | — | Không phải thư rác · Đánh dấu đã xem · Bộ lọc tự động cho là thư rác · Xóa theo yêu cầu của khách… · Tải danh sách về máy · Thời hạn lưu giữ liên hệ · Khách đã đồng ý: «…» — lúc … |
Biểu mẫu (mới): Thêm biểu mẫu · Nhận liên hệ từ khách · Các ô khách cần điền · Thêm ô · Một dòng chữ · Email · Số điện thoại · Đoạn văn · Danh sách chọn · Ô tích · Gửi thư báo tới · Lời cảm ơn khách thấy · Đặt biểu mẫu vào trang · Mở Trình dựng trang · Lưu — hiệu lực ngay. Grep 2 tệp: 0 "Spam" · "Lưu trữ" · "Đã đọc" · "xoá" (khóa dữ liệu `spam` viết thường là enum của repo, giữ nguyên).

## Màn đang chạy · Bản mới · Giữ bản nào · Vì sao
| Màn đang chạy (repo) | Bản mới | Giữ | Vì sao |
|---|---|---|---|
| Inbox: bảng + lọc trạng thái, 20/trang, trang Detail riêng | Hộp hai khung, tab trạng thái có số, tìm, lọc, chọn nhiều, "Tải thêm" | Bản mới | mẫu 5 + yêu cầu prompt; giữ đủ lọc trạng thái của repo (thành tab) |
| Detail: nút đổi trạng thái | Nhóm 3 nút trạng thái + Thư rác vào ⋯ / dải Thư rác | Bản mới | việc hay làm hiện sẵn, việc hiếm vào ⋯ |
| (chưa có màn biểu mẫu) | Danh sách + trình sửa | Bản mới | màn mới |

## Kết quả 12 tiêu chí dễ dùng
1. Đạt — tiêu đề + 1 dòng mô tả; KLH: nút chính trong chi tiết là "Gọi"; BM: "Thêm biểu mẫu" / "Lưu — hiệu lực ngay".
2. Đạt — gọi lại khách: menu → chạm liên hệ → Gọi (3 bước).
3. Đạt — "IP" chỉ trong khối gập "Thông tin thêm về lần gửi" kèm câu giải thích; CSV kèm "mở được bằng Excel".
4. Đạt — nút chỉ icon (⋯, đóng) có aria-label + title.
5. Đạt — placeholder là ví dụ; lỗi nói cách sửa (mật khẩu, mã 6 số, email, tên).
6. Đạt — Thùng rác + Hoàn tác (Chờ backend); xóa hẳn / xóa biểu mẫu có hộp nói số; trình sửa chặn rời trang.
7. Đạt — trạng thái = nền + icon + chữ; chấm ngôn ngữ kèm chữ trong tooltip / chữ ẩn.
8. Đạt — tab/nút/chip `nowrap`, tên dài cắt "…" + title ở danh sách, xuống dòng ở chi tiết; [CHƯA KIỂM ĐƯỢC] bằng mắt mọi chuỗi dịch dài +40%.
9. Đạt — 390: chi tiết toàn màn, Gọi dính đáy cao 50px; 768: 1 khung + Quay lại (dưới 940).
10. Đạt — phím `/`, Esc, ↑↓ trong menu; chọn nhiều; lọc nhanh; nâng cao gập.
11. Đạt — Chữ lớn (`--fsp` 2px, đọc `bs-ui.fontSize` / Tweak `bigText`): 0 tràn ở 360–1440 (đo dưới).
12. Đạt — dùng Bảng thuật ngữ: Xóa · Hủy · Bỏ lọc · Tải xuống → "Tải … về máy" (giữ chữ của prompt).

## Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt — thang 4/8, cỡ chữ 12/13/14/16/18/20 (+ --fsp); một nút chính mỗi vùng; khung đang tải không animation.
- B. Đạt — nhãn luôn hiện; kéo-thả có nút Lên / Xuống; phản hồi < 1 giây (toast, "Đang lưu…", "Đang chuẩn bị tệp…", chặn bấm 2 lần); lỗi mạng có Thử lại.
- C. Đạt một phần — 1 h1 khi đứng riêng (nhúng: h1 của Shell), hộp thoại có tên + aria-modal, Esc, giữ Tab, trả focus; toast `role="status"`. [CHƯA KIỂM ĐƯỢC] trình đọc màn hình thật.
- D. [CHƯA KIỂM ĐƯỢC] đo tương phản chế độ tối — chỉ dùng token (0 hex ngoài `var()`), chưa đo getComputedStyle ở `data-bs-theme="dark"` — Claude Code bằng zip.
- E. Đo bằng `_audit/harness.html` (07/10): xem Kiểm hiển thị. Console 0 lỗi khi mở 2 tệp.

## Kiểm hiển thị
`H.layouts(['Khách liên hệ.dc.html','Biểu mẫu.dc.html'],[360,390,768,1024,1440])`, đứng riêng — `scrollWidth = clientWidth` ở mọi ô:
| Bề rộng | KLH thường | KLH Chữ lớn | BM thường | BM Chữ lớn |
|---|---|---|---|---|
| 360 | 345/345 đạt | đạt | đạt | đạt |
| 390 | 375/375 đạt | đạt | đạt | đạt |
| 768 | 753/753 đạt | đạt | đạt | đạt |
| 1024 | 1009/1009 đạt (1 mục "sticky" của khung đọc — dự kiến) | đạt | đạt | đạt |
| 1440 | 1425/1425 đạt (như trên) | đạt | đạt | đạt |
Trong Shell: `H.mk('s',390,844,0,0,'../Admin Shell.dc.html#/site/contacts')` → 390/390 đạt, màn mount; `#/site/forms` → 390/390 đạt.
Luồng 390 (đứng riêng): chạm dòng 4 → danh sách ẩn, Gọi cao 50px, thanh `position:sticky`, focus "Quay lại danh sách"; Quay lại → focus đúng dòng.
Chưa kiểm: trình sửa Biểu mẫu qua harness (chỉ đo màn danh sách khi tải), 844×390 xoay ngang, 200%, chế độ tối, Safari iOS / iPad, Firefox, Samsung Internet — Claude Code bằng zip.
Verifier: xem dòng cuối.

## Việc cho backend
| Tính năng | Dữ liệu cần lưu | Luật |
|---|---|---|
| Tìm tên / email / SĐT | chỉ mục trên `form_submissions(site_id, name/email/phone)` — quyết định schema lúc build | số so theo chữ số, ≥ 3 số |
| Lọc khoảng ngày, theo biểu mẫu | dùng chỉ mục `(site_id, form_id, created_at)` sẵn có | ngày theo giờ website |
| Hàng loạt Đã xem / Đã xử lý / Là thư rác | — | cần `forms.manage`; bỏ qua dòng đã ở trạng thái đó, trả về số đã làm + số bỏ qua |
| "Tải thêm" (thay số trang) | con trỏ theo `created_at, id` | 15/lần |
| Thùng rác liên hệ | `deleted_at` (soft delete) | Hoàn tác 8 giây; tự xóa hẳn sau 30 ngày |
| Tải danh sách về máy (CSV) | dòng nhật ký: người tải, lúc, số dòng, bộ lọc | chỉ `forms.manage`; xác nhận lại mật khẩu / mã 2 lớp như "Xuất dữ liệu" ở Cài đặt |
| Thời hạn lưu giữ | `forms_settings.retention_months` ∈ 12/24/36/null (mặc định 24) | việc chạy định kỳ xóa hẳn liên hệ quá hạn; thư rác 30 ngày — **luật sư xác nhận thời hạn trước khi build** |
| Xóa theo yêu cầu của khách | nhật ký "đã xóa 1 liên hệ theo yêu cầu" KHÔNG kèm dữ liệu cá nhân | xóa hẳn ngay, không Thùng rác |
| Ô đồng ý | — | backend chặn sửa / xóa / bỏ bắt buộc ô `consent`; mọi biểu mẫu mới tự có ô đồng ý, luôn ghim cuối |
| Liên kết "Chính sách bảo mật" trong câu đồng ý | URL trang chính sách theo ngôn ngữ | Chờ nội dung pháp lý (`docs/design.md` §33 #3) |
| Màn Biểu mẫu + mục menu `forms` | — | Thêm / sửa / xóa biểu mẫu (`forms.manage`); xóa kéo theo liên hệ (đã cascade) |
| Thư báo | `forms.notify_emails` (đã có) | gửi theo từng biểu mẫu (`SubmitForm`); thư rác không gửi |
| Đổi chữ trạng thái | `lang/*.json` | theo bảng Chuỗi cần dịch |
| Bật lại biểu mẫu từ dải báo | `forms.status` | `forms.manage` |

**Cần owner chốt:** chữ trạng thái; nhóm "Chỉ xem" (`forms.view`) đọc được dữ liệu cá nhân của khách (`PermissionRegistry.php:207`) — thiết kế vẽ như repo; thời hạn lưu giữ mặc định 24 tháng (luật sư).

Verifier: chờ kết quả (xem lượt sửa sau nếu có).

Canvas giao nộp: mở đứng riêng ở cửa sổ ≥ 1240px thì cuối mỗi màn có khung xem 390 × 844 và 768 × 1024 (iframe `#frame`, tắt khi `embedded` hoặc cửa sổ hẹp).
