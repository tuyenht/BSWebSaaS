# D0-2s2 · Sửa D0-2 sau kiểm lần 2 (dán vào CHÍNH chat D0-2)

Lượt D0-2s2 — sửa sau lần kiểm thứ hai của Claude Code (04/10, bản zip 17:21; số dòng dưới đây là của bản đó).

**Được sửa (chỉ đúng các chỗ nêu dưới):**
- `Chuẩn giao diện SaaS Starter.dc.html` — gọi tắt "trang";
- `Chuẩn giao diện - Component bổ sung.dc.html` — gọi tắt "trang bổ sung";
- `Danh sách co giãn - Đã chốt.dc.html` — đúng 2 dòng ở mục 10;
- `content-seo.js` — đúng 2 khóa ở mục 11;
- `README - D0-2 Chuẩn giao diện.md`;
- `TODO.md`;
- `LESSONS.md` — đúng 1 dòng ở mục 13;
- `AUDIT.md` — chỉ thêm ở bước 0.

**Không được:**
- sửa CLAUDE.md;
- sửa màn khác: lỗi của màn khác chỉ ghi vào TODO.md ở mục 14;
- vẽ lại hay đổi bố cục ngoài các chỗ nêu;
- dùng màu ngoài token var(--…).

**Kiểm sau khi sửa** ở bề rộng thật 360 / 390 / 768 / 1024 / 1240 × sáng + tối × Chữ lớn, cả khi pointer:coarse. Đo cuộn ngang bằng `document.documentElement.scrollWidth` so với `clientWidth`. KHÔNG so với `innerWidth`: trang tràn thì `innerWidth` nới theo, nên sẽ không thấy lỗi. Đo vùng bấm bằng `getComputedStyle` (height / min-height): phần tử nằm trong khung xem thu nhỏ (zoom) mà đo bằng `getBoundingClientRect` sẽ ra nhỏ hơn thật.

**Thứ tự làm:** 0 → 1 → 2 → 3 → 4 → 6 → 7 → 11 → 5 → 8 → 9 → 10 → 12 → 13 → 14. Nếu sắp hết lượt: chốt mục đang làm, ghi các mục còn lại vào TODO.md với tên "D0-2s2 phần 2". Không bỏ dở một mục.

0. **AUDIT.md: thêm mục kiểm.**
   - Chép NGUYÊN VĂN khối nằm giữa dòng "▼ BẮT ĐẦU MỤC AUDIT ▼" và dòng "▲ HẾT MỤC AUDIT ▲" (cuối prompt này) vào cuối AUDIT.md. Không chép hai dòng mốc đó, không sửa gì khác trong AUDIT.md.
   - Khối đó là hồ sơ. Việc của lượt này chỉ là các mục 1–14 dưới đây. Lỗi của màn khác và của repo trong khối đó KHÔNG làm ở lượt này.

1. **CAO — Trang cuộn ngang ở 360/390.**
   - Nguyên nhân: nhãn hẹp ở dòng 1133 ("Chờ backend: lưu tỉnh/phường đã chọn ở Thông tin chung") dùng `.sx-note`, mà `.sx-note` (dòng 90) có `white-space:nowrap`. Đo được scrollWidth 396; bật Chữ lớn thì 454, vì thêm `.sx-code` dòng 89 ở "Bảng tra cho build" dòng 1368.
   - `.sx-note`: bỏ `white-space:nowrap`; thêm `white-space:normal;overflow-wrap:anywhere;max-width:100%;align-items:flex-start` (icon đứng đầu dòng thứ nhất).
   - `.sx-code` (dòng 89): đổi `white-space:nowrap` thành `white-space:normal` và thêm `overflow-wrap:anywhere`. Chỉ thêm `overflow-wrap` thì không có tác dụng khi còn `nowrap`.
   - Kiểm ở 360 và 390 × sáng + tối × chữ thường + Chữ lớn:
     - scrollWidth = clientWidth;
     - không phần tử nào có mép phải vượt bề rộng, trừ phần tử nằm trong khung cuộn có chủ ý (`.tw`, khung xem trước) hoặc trong khung `overflow:hidden`;
     - toast `.tst-wrap` nằm gọn trong màn.
   - Ghi số đo vào bảng "Kiểm hiển thị" của README.

2. **CAO — Thanh ngôn ngữ trên cảm ứng.**
   - `.lb-pill` cao 36px (dòng 244) và chỉ lên 44px qua `@container (max-width:520px)` (dòng 257). Vùng bấm nới bằng `::after` lại bị `.lb-l{overflow:hidden}` (dòng 242) cắt mất.
   - Trong khối `@media (pointer:coarse){…}` ở dòng 316 (có từ D0-2s mục 6), thêm `.lb-pill,.lb-more,.lb .sx-btn{height:44px;min-height:44px}`. Phải tăng kích thước thật, không nới bằng `::after`.
   - Kiểm ở 768 và 1024 cảm ứng: mọi pill, `.lb-sel` và nút Dịch cao ≥ 44px.

3. **CAO — Câu lý do ở ô tích khóa phải đọc được.**
   - Dòng 447: cả nhãn bị làm mờ bởi `.sx-check.off{opacity:.6}` (dòng 102), nên câu "Gói hiện tại chưa có bình luận." chỉ đạt 2,59:1 (sáng) / 3,25:1 (tối).
   - Bỏ `opacity` khỏi `.sx-check.off`. Thay bằng:
     - `.sx-check.off input{opacity:.6}`: chỉ ô tích mờ (WCAG không đòi tương phản cho điều khiển đang khóa);
     - `.sx-check.off>span{color:var(--color-body-muted,#5b6275)}`;
     - câu lý do `#cb-why` giữ nguyên, không mờ thêm.
   - Kiểm: tên việc và câu lý do ≥ 4,5:1 ở sáng và tối.

4. **CAO — Mẫu Dải thông báo (dòng 725–728) vẽ bằng markup của trang.**
   - Lý do: Alert của DS tô chữ bằng màu gốc. Nền sáng chỉ đạt: cảnh báo 1,65:1 · thành công 2,35 · thông tin 2,70 · nguy hiểm 2,77. Nền tối: thông tin 4,43 · nguy hiểm 4,45. Chữ cũng không lớn lên khi bật Chữ lớn.
   - Làm giống cách đã làm với Tabs ở D0-2s mục 10. Mỗi loại gồm:
     - nền = 10% màu gốc (`color-mix`);
     - chữ = `var(--color-admin-<loại>-text,…)`;
     - icon riêng + chữ đậm mở đầu, giữ nguyên nội dung;
     - cỡ chữ `calc(14px + var(--fsp))`.
   - Bảng tra dòng 1560 (Alert) và dòng Alert trong README: giữ "cần sửa" và thêm lý do "Alert của DS tô chữ bằng màu gốc (cảnh báo 1,65:1) và không theo Chữ lớn — trang vẽ bằng markup riêng tới khi DS sửa; Blade ở repo đã đúng (-text, nền 10% — alert.blade.php:13-16)".
   - Kiểm: cả 4 loại ≥ 4,5:1 ở sáng và tối; bật Chữ lớn thì chữ lớn lên.

5. **Nhóm avatar "Xem cả 8 người" (dòng 1299).**
   - DS Avatar dùng chữ tắt `#405189` (token DS cũ): nền tối chỉ đạt 1,96:1.
   - Đổi sang `.avi` của trang (dòng 185, đang dùng ở hàng danh sách), giữ chồng lên nhau như cũ.
   - Kiểm: chữ tắt ≥ 4,5:1 ở sáng và tối.

6. **CAO — Thanh hàng loạt chỉ đếm dòng làm được.**
   - Dòng 2007 đếm `nSel` (mọi dòng đang chọn).
   - Dữ liệu mẫu `BULK0` (dòng 1518): thêm trường `st` cho từng bài — b1 'draft' · b2 'draft' · b3 'published' · b4 'draft' · b5 'pending'. Hiện trạng thái này bằng chip nhỏ cạnh tên, chữ theo `BSCW.UI` và màu token.
   - Xem như "Người viết" (`entries.submit`, không có `entries.publish`):
     - nút là "Gửi duyệt (N)", với N = số bài CỦA MÌNH đang ở trạng thái nháp;
     - K = số bài đang chọn còn lại; K > 0 thì hiện sẵn câu "Gửi duyệt: K bài sẽ được bỏ qua — nhóm quyền này chỉ gửi duyệt được bản nháp của mình.".
   - Xem như "Biên tập":
     - nút là "Đăng (N)", với N = số bài chưa đăng (`st` khác 'published');
     - K > 0 thì hiện "Đăng: K bài đã đăng sẽ được bỏ qua.".
   - Luật này thay luật "Biên tập: hai nút đếm đủ" của D0-2s mục 3.
   - Toast sau khi bấm dùng N (không dùng số dòng đang chọn).
   - N = 0 thì nút khóa và hiện câu: Người viết "Không có bản nháp nào của bạn trong các bài đã chọn."; Biên tập "Các bài đã chọn đều đã đăng.".
   - Chip trạng thái dùng lại `.sc[data-s]` (dòng 158–162, đã theo token và đổi theo nền tối), chữ lấy `BSCW.UI[st].label`. Không dùng màu hex trong `BSCW.UI`.
   - Mỗi câu "bỏ qua" mở đầu bằng tên nút, để người dùng biết câu nói về nút nào: "Gửi duyệt: 3 bài sẽ được bỏ qua — …", "Chuyển vào Thùng rác: 2 bài của người khác sẽ được bỏ qua — …".
   - README "Quyền & gói": ghi đúng luật này.
   - README "Việc cho backend":
     - dòng "Chuyển vào Thùng rác hàng loạt" (dòng 252): chỉ thay ý (a) bằng "(a) mọi thao tác hàng loạt (Thùng rác · Khôi phục · Xóa vĩnh viễn · Đưa về nháp · Nhân bản) bỏ qua dòng không đủ quyền rồi báo số — hiện cả 5 hàm dừng ở dòng đầu tiên không đủ quyền và giữ thay đổi của các dòng trước (`app/Modules/Admin/Livewire/Entries/Index.php` `bulkApply`)". GIỮ nguyên ý (b) toast Hoàn tác và ý (c) bỏ hộp xác nhận;
     - thêm ngay dưới một dòng mới "Gửi duyệt / Đăng hàng loạt": cột Dữ liệu cần lưu ghi "—"; cột Luật viết liền trong một ô: "(1) Gửi duyệt = có quyền gửi duyệt VÀ được sửa đúng bài đó, và chỉ từ Bản nháp (owner chốt theo khuyến nghị 04/10 tối; repo ĐÃ CÓ từ 04/10, PR #168: `EntryPolicy::submit` + quy trình duyệt + thao tác hàng loạt) — với nhóm Người viết / Cộng tác viên nghĩa là chỉ bản nháp của mình. (2) Đăng / Gửi duyệt hàng loạt: dòng không làm được thì bỏ qua và báo số; chỉ đếm dòng thật sự đổi trạng thái — Gửi duyệt chỉ đếm bản nháp; Đăng không đếm bài đã đăng (bài đang chờ duyệt được Đăng thì có đếm); không đụng bài trong Thùng rác."

7. **CAO — Sửa trạng thái trong bảng tra (dòng 1559–1591) và bảng tương ứng trong README.** Repo thật: `app/Modules/Admin/resources/views/components/`.

   | Dòng | Component | Sửa thành |
   |---|---|---|
   | 1563 | Icon | `'co'` → `'sua'`; props "name (Remix 4.9.1 — thiết kế dùng tên đủ hậu tố ri-…-line / -fill; cách đặt tên ở repo chốt khi build) — cần sửa: icon.blade.php chỉ có Heroicons + 60 khóa ri-* không hậu tố, tên lạ thành hình tài liệu; 50/74 tên thiết kế dùng chưa có" |
   | 1568 | Pagination | `'co'` → `'sua'`; props "per-page 10/20/50 [MỚI] · mode pages\|cursor [MỚI] (danh sách lớn dùng cursor) — repo chỉ có paginator · current · last" |
   | 1572 | Tooltip | `'co'` → `'sua'`; props "text · position (tên prop ở repo) · bấm được trên cảm ứng [MỚI]" |
   | 1559 | Badge | `'co'` → `'sua'` (icon [MỚI]) |
   | 1562 | Card | props "title · slot header / footer · actions [MỚI] · padding [MỚI]" |
   | 1574 | Breadcrumb | cột props thêm "repo đã có một phần: slot breadcrumbs trong page-header" |
   | 1575 | brand-logo | props "context sidebar / topbar" |
   | 1576 | rich-editor | props "target · profile · value · rows" |
   | 1578 | theme-option | props "axis · value · name · label · store" |
   | 1577 | settings-shell | bỏ "(cần sửa chữ mục đang chọn → accent, E9)" — repo đã đúng |
   | 1590 / 1591 | Thông báo nổi / Hộp xác nhận | giữ MỚI, thêm "repo đã có lớp JS (sweet-toast.js: chỉ chữ, 2,4 giây, không Hoàn tác; sweet-confirm.js) — bản build THAY lớp này, không thêm hệ thứ hai" |

8. **Nút chỉ có icon trên cảm ứng.**
   - Hàng bảng ở ≥ 1024 (`.dl-icons`, dòng 832) có tên nút chỉ trong `title`, mà `title` không hiện khi không rê chuột.
   - Trong `@container (min-width:1024px)` thêm `@media (hover:none){.dl-acts{display:flex;grid-area:icons}.dl-icons{display:none}}`. `.dl-acts` (dòng 831) đã có Sửa · Xem · "⋯" (Nhân bản, Chuyển vào Thùng rác).
   - Kiểm ở 1024 cảm ứng: không cuộn ngang, nút ≥ 44px.

9. **Một kiểu focus cho lớp bật lên.**
   - Panel ngôn ngữ (dialog dòng 1222, danh sách `role="menu"` dòng 1225 — menu sống duy nhất của trang; không biến các mẫu tĩnh thành menu sống):
     - mở ra thì focus vào mục đang chọn, không có thì vào mục đầu;
     - ↑↓ di chuyển, Home / End về đầu / cuối;
     - Esc đóng và trả focus về nút mở — giống Danh sách co giãn `.m5` dòng 305.
   - Popover lọc (dòng 563): mở ra thì focus vào điều khiển đầu tiên trong popover.
   - Lịch: mở ra thì focus vào ngày đang chọn.
   - Ghi luật này vào README mục "Tương tác".

10. **Danh sách co giãn — đúng 2 dòng.**
    - Dòng 24 `a:hover{color:var(--color-admin-primary,…)}` → `a:hover{color:var(--color-accent,#2050a0);text-decoration:underline}`. Lý do: nền tối chỉ đạt 2,12:1. Trang chuẩn dòng 32 có cùng luật: sửa y như vậy.
    - Dòng 214: thêm `.srch5 input::placeholder{color:var(--color-placeholder,…)}`. Nền tối hiện chỉ đạt 3,5:1.

11. **`content-seo.js` — trần chặn cứng khớp repo.** Chỉ thêm khóa `hard`, không đổi min / best / display / max:
    - `slug`: thêm `hard: 200`. Khớp `BSUI.slugify` (cắt 200) và repo `max:200`. Hiện `hardCap('slug')` trả 113 (90 × 1,25) nhưng chưa ô nào dùng. Thước vẫn đỏ từ 91.
    - `excerpt`: thêm `hard: 255`. Cột repo `entry_translations.excerpt` dài 255; hiện chặn ở 300.
    - Ghi 2 dòng này vào README "Ánh xạ dữ liệu".
    - Kiểm: trong console, `BSSEO.hardCap('slug')` = 200 và `BSSEO.hardCap('excerpt')` = 255. Gõ thử 260 ký tự vào ô "Mô tả ngắn" của length-field (trang bổ sung): ô dừng ở 255. `content-seo.js` là file dùng chung: mở Page Editor và Quản lý Bài viết (ô Mô tả ngắn cắt theo `cap('excerpt')`) để kiểm ô dừng ở 255. Chỉ kiểm, không sửa hai màn đó.

12. **Trang bổ sung.**
    - (a) `.cp code` (dòng 131): trong `@media (max-width:479px)` cho giá trị xuống dòng (`white-space:normal;overflow-wrap:anywhere`), bỏ "…"; thêm `.cp:has(code){align-items:flex-start}`. Giá trị sẽ chép (đường dẫn, bản ghi SPF) phải đọc đủ.
    - (b) Trong `@media (pointer:coarse)`: `.in{min-height:44px;opacity:1}` (dòng 79; `opacity:1` cho Firefox, như trang dòng 70) và `.cp-in{min-height:44px}` (dòng 128). Giữ khung `.cp` là div (khung còn chứa nút Sao chép — đổi thành label thì bấm vào chữ cố định sẽ bấm luôn nút, và tên ô bị lẫn chữ của nút). Thay vào: ô `.cp-in` cao hết khung (`align-self:stretch`), và khi bấm vào chỗ trống của khung (đệm, không trúng ô hay nút) thì gọi `preventDefault` rồi focus ô.
    - (c) Thêm công tắc "Chữ lớn" như trang:
      - `--fsp` mặc định 0px, bật thì 2px;
      - mọi cỡ chữ của trang bổ sung viết thành `calc(Npx + var(--fsp))`;
      - kiểm ở 360 và 1240 khi bật Chữ lớn: không cuộn ngang, không cắt chữ.
    - (d) Menu mục lục đầu trang: link cao ≥ 44px khi cảm ứng (bằng padding), để vùng bấm của các link liền nhau không chồng nhau.
    - (e) Dòng 262 (Props của nút Sao chép): đổi thành "chép bằng vùng chọn ẩn trước, không được thì dùng Clipboard API". Khớp `content-ui.js` và README.

13. **Nhỏ, ở trang.**
    - Danh sách "Chuyển vào Thùng rác" (dòng 707): dưới 400px, nút xuống dưới tên; tên bài xuống dòng đủ chữ (không giới hạn số dòng, không "…"). Người dùng phải thấy mình đang xóa bài nào.
    - Đường dẫn (dòng 1083): dưới 480px cho xuống dòng (`white-space:normal;overflow-wrap:anywhere`), không cắt. Nhãn bậc màu (dòng 653) và mục cây (dòng 1056): chỗ cắt "…" thêm `title` bằng chữ đầy đủ.
    - Dòng 1636–1639: `let th = null; try { th = localStorage.getItem('bs-scheme'); } catch (e) {}` rồi `theme: th || this.props.theme || 'light'`. Mở riêng phải theo nền đã chọn ở Shell; đổi Tweak lúc đang mở vẫn áp (dòng 1744).
    - Ô `.sPlain` trong khung `.sx-search` (CSS dòng 114–119; ví dụ dòng 506, 819): giữ khung là div; khi cảm ứng, ô cao hết khung (`.sx-search>input{align-self:stretch}`); bấm vào icon / đệm của khung thì focus ô. Không đổi khung thành label (khung dòng 504 có nút "Bỏ từ khóa").
    - Dòng 2152: bỏ danh sách nhóm quyền dự phòng (`'Tác giả','Hỗ trợ','Xem'` sai tên). Chỉ dùng `Object.keys(W.ROLES)`; thiếu BSCW thì hiện một dòng "Chưa tải được danh sách nhóm quyền — tải lại trang." thay cho bảng không cột.
    - Tên nhóm component — owner chốt 04/10 tối: repo đổi HẲN thẻ sang `<x-bs-admin::…>` trong một lần, không giữ thẻ `<x-admin::…>` song song (Claude Code làm PR #167, kèm test chặn tên cũ). Sửa 3 chỗ cho khớp:
      - README dòng 73: thay câu "Repo hiện dùng `admin::`: khai thêm `bs-admin` trỏ cùng thư mục, giữ `admin::` làm tên phụ tới khi đổi xong mọi view (xem Việc cho backend)." bằng "Repo đổi hẳn thẻ `<x-admin::…>` sang `<x-bs-admin::…>` trong một lần (owner chốt 04/10 tối) — không còn thẻ `<x-admin::…>`; namespace view `admin::` (đường dẫn view) là chuyện khác, giữ nguyên.";
      - README dòng 257 (Việc cho backend, dòng `bs-admin`): cột Luật ghi "Đổi hẳn `<x-admin::…>` → `<x-bs-admin::…>` trong một PR, không giữ thẻ `<x-admin::…>` (namespace view `admin::` giữ nguyên); test chặn thẻ `<x-admin::` mới";
      - LESSONS.md: KHÔNG sửa dòng 34 (LESSONS chỉ thêm, không sửa). Thêm 1 dòng cuối: "WHEN nói repo dùng tên nhóm component nào THEN repo đã đổi hẳn thẻ sang <x-bs-admin::…> một lần, không còn thẻ <x-admin::…> (thay vế 'song song rồi chuyển dần' của dòng 34) NOT ghi repo giữ thẻ <x-admin::…> song song | evidence: owner chốt 04/10 tối 'Đổi luôn sang bs-admin cho chuẩn luôn' | 2026-10-04".
    - README dòng 255 (múi giờ): ghi dạng giá trị `{"tz":"<tên IANA>"}`. SiteTime (`app/Modules/Tenancy/Context/SiteTime.php`) chỉ nhận dạng này; ghi chuỗi trần sẽ bị bỏ qua mà không báo lỗi.

14. **TODO.md.**
    - Dòng "D0-2 · DS: thêm các component MỚI…" (mục "Lộ trình D0"): thêm vào cuối "· Alert: chữ dùng -text (hiện màu gốc, cảnh báo 1,65:1) và theo --fsp · Avatar: chữ tắt dùng token G2 (tokens/colors.css còn #405189, nền tối 1,96:1)".
    - Mục "D0-2 phần 3": thêm 1 dòng "Sửa kèm khi chạm màn (từ kiểm Claude Code 04/10):"
      - Block Library: nút chép mã tham chiếu phải chép thật bằng `BSUI.copy` (hiện chỉ báo, không chép — `copyRef` dòng 203), nhãn "Sao chép mã tham chiếu";
      - toast có vùng `role="status"` ở 8 màn: Page Editor · Quản lý Bài viết · Page Builder v3 · Email Settings · Trình đơn · Floating Contact · Block Library · Language Context;
      - Page Editor - Options: khung gốc dòng 66 `width:min(1240px,100%)` (hiện tràn 185px ở 390);
      - Email Settings: nút chép biến (dòng 549, JS dòng 1472–1474) đổi `aria-label` / `title` thành "Đã sao chép <biến>";
      - Language Context: mẫu thanh ngôn ngữ dòng 274–283 ở 390 chuyển sang "+N" / ô chọn như trang chuẩn (hiện tràn 54px);
      - Floating Contact: xóa mã chết `copyLabel` (dòng 439, còn chữ "Đã chép").

Xong khi:
- (0) AUDIT.md có mục "Kiểm D0-2 bởi Claude Code (04/10/2026)", chép nguyên văn.
- (1) Ở 360 và 390 × sáng + tối × Chữ lớn: scrollWidth = clientWidth; số đo đã ghi trong README.
- (2) Thanh ngôn ngữ cao ≥ 44px ở 768 và 1024 cảm ứng.
- (3) Câu lý do ô khóa, 4 mẫu Dải thông báo và chữ tắt avatar đều ≥ 4,5:1 ở sáng và tối; mẫu Dải thông báo lớn lên khi bật Chữ lớn.
- (4) Chọn cả 5 bài:
  - Xem như "Người viết" → "Gửi duyệt (2)" và câu "Gửi duyệt: 3 bài sẽ được bỏ qua…";
  - Xem như "Biên tập" → "Đăng (4)" và câu "Đăng: 1 bài đã đăng sẽ được bỏ qua.".
- (5) Bảng tra + README đã sửa đúng 12 dòng ở mục 7.
- (6) Menu ngôn ngữ mở ra thì focus vào mục đang chọn; Esc trả focus về nút.
- (7) `BSSEO.hardCap('slug')` = 200; ô Mô tả ngắn dừng ở 255 (trang bổ sung, Page Editor, Quản lý Bài viết).
- (8) Trang bổ sung có Chữ lớn; ô nhập ≥ 44px khi cảm ứng; ở 360 bản ghi SPF đọc đủ.
- (9) TODO.md có dòng DS và 6 việc theo màn ở mục 14.
- (10) README dòng 73 + 257 không còn "song song" / "đổi dần" / "tên phụ"; LESSONS.md có dòng mới ở cuối (dòng 34 giữ nguyên); README "Việc cho backend" có dòng "Gửi duyệt / Đăng hàng loạt", và dòng Thùng rác hàng loạt vẫn giữ Hoàn tác + bỏ hộp xác nhận.

Gọi ready_for_verification và sửa hết lỗi verifier báo. Phần verifier không chạy được thì ghi [CHƯA KIỂM ĐƯỢC + lý do] vào README. Xong thì xuất zip gửi Claude Code kiểm lại trước khi sang D0-3.

▼ BẮT ĐẦU MỤC AUDIT ▼
## Kiểm D0-2 bởi Claude Code (04/10/2026)

Bản kiểm: zip xuất 04/10 17:21, sau D0-2s và D0-2 phần 3 (Component bổ sung). Chỉ kiểm và báo — không sửa file thiết kế. Bỏ qua `_audit/` và `uploads/`. Repo đối chiếu: `tuyenht/BSWebSaaS` nhánh main `70afa11` (chỉ đọc).
Cách kiểm: Chromium 154 không giao diện, điều khiển qua DevTools Protocol. Bấm, gõ, rê chuột và nhấn phím đều là sự kiện nhập thật, không gọi `element.click()`. Giả lập `prefers-color-scheme`, `pointer:coarse` / `hover:none` và viewport điện thoại. Đã chạy 56 lượt cho 3 trang chuẩn (28 + 14 + 14 — hai trang sau không có Chữ lớn): 7 bề rộng (360 / 390 / 768 / 1024 / 1024 cảm ứng / 1240 / 1440) × sáng + tối × chữ thường + Chữ lớn. Với 9 màn đã chạm, đo ở 1240 và 390. Danh sách D0-2s được kiểm từng mục.
Bằng chứng: [ĐÃ RENDER VÀ THẤY] = đã chụp ảnh và nhìn · [ĐO BẰNG SCRIPT] = số đo của script · [SUY RA TỪ CODE] = đọc mã · [CHƯA KIỂM ĐƯỢC + lý do].
Chưa kiểm được: Safari iOS / iPadOS / Firefox / máy cảm ứng thật, xoay ngang, phóng 200%, trình đọc màn hình (chỉ có một nhân Chromium giả lập). Chữ lớn ở `Chuẩn giao diện - Component bổ sung` và `Danh sách co giãn - Đã chốt` không đo được vì hai trang không có công tắc này.

**Tổng: Chặn 0 · Cao 12 · Trung bình 23 · Thấp 21 (56 dòng).**
- D0-2s: đủ 16/16 mục và 8/8 tiêu chí "Xong khi", nhưng mục 2 (nhãn hẹp tỉnh/phường) gây cuộn ngang ở 360/390 (dòng C1).
- Kết luận của bước kiểm D0-2s về mục 11 "không cuộn ngang" là SAI: bước đó so `scrollWidth` với `innerWidth`, mà `innerWidth` đã bị nới theo chỗ tràn.

### Đã đạt (đo thật)
- Nút Sao chép mục 3 trang Component bổ sung, đủ 4 kiểu (chữ cố định · chỉ icon · ô nhập sửa được · ô nhiều dòng chỉ đọc), ở 1240 (chuột) và 390 (cảm ứng):
  - chữ đổi "Đã sao chép", `aria-label` đổi theo, sau 2,0 giây trở về "Sao chép";
  - clipboard khớp đúng từng ký tự;
  - sửa ô nhập rồi bấm chép thì ra đúng chữ VỪA GÕ;
  - bấm Space trên nút được, `role="status"` đọc đúng câu;
  - khi chặn cả hai cách chép: tự bôi đen + hiện câu hướng dẫn, Esc đóng;
  - chép được cả khi trang không được cấp quyền Clipboard. [ĐO BẰNG SCRIPT]
- `content-ui.js`:
  - `BSUI.slugify` cắt 200 rồi mới bỏ "-" hai đầu, đúng với tiếng Việt có dấu, đ/Đ, chuỗi 250 ký tự, ký tự thứ 200 là dấu cách, chuỗi toàn ký hiệu, emoji;
  - `BSUI.trapFocus`: Tab / Shift+Tab đi vòng, Esc đóng, trả focus về nút đã mở;
  - `BSUI.watchBottomBar`: đặt `--sbar-h` và `--kb`, hàm gỡ đưa cả hai về 0. Chưa màn nào gọi, đúng như README ghi.
  [ĐO BẰNG SCRIPT]
- `content-seo.js`:
  - BANDS chỉ còn `var(--token, dự phòng)`;
  - `meter()` trả đủ icon / band / lo / hi / tick ở mọi mốc biên của 6 trường: 0 ký tự luôn `empty`, vượt max luôn `over`, lo ≤ hi, tick nằm trong 0–100.
  [ĐO BẰNG SCRIPT]
- Page Editor, ô "Mô tả ngắn": gõ thật 150 / 199 / 200 / 201 / 260 ký tự. Thước có mốc 200, chữ và màu khớp `content-seo.js` (đạt ≤ 200, vàng 201–240, đỏ > 240). [ĐÃ RENDER VÀ THẤY]
- Thứ tự Tab = thứ tự nhìn ở mọi bề rộng, 0 chỗ đảo. [ĐO BẰNG SCRIPT]
- Esc đóng popover / menu / hộp thoại / trượt đáy / lịch và trả focus về nút đã mở: đạt 236/238 lượt. 2 lượt trượt là do trang rộng hơn màn ở 390 (dòng C1); Enter / Space trên đúng 2 nút đó vẫn đạt. [ĐO BẰNG SCRIPT]
- Không còn chức năng chỉ hiện khi rê chuột: 0 luật `:hover` làm hiện phần tử, 0 trình nghe `mouseenter`. [ĐO BẰNG SCRIPT]
- Chữ < 12px: 0, trừ chữ trong khung xem thu nhỏ (có chủ ý). [ĐO BẰNG SCRIPT]
- Lỗi console:
  - 0 lỗi JS ở 3 trang chuẩn và 9 màn đã chạm;
  - chỉ có cảnh báo "does not conform" lúc nạp trước khi JS gán giá trị (DOM cuối đúng) và favicon 404.
  [ĐO BẰNG SCRIPT]
- Chữ "Đã sao chép" đã thống nhất ở các nút chép thật: Hàng đợi ảnh, Block Library, Page Editor, Trình đơn, Floating Contact (toast), Quản lý Bài viết, Đăng nhập nhanh, 2 trang chuẩn. "Đã chép trình bày" ở Page Builder là cặp chép/dán định dạng, README giữ có chủ ý. [ĐO BẰNG SCRIPT]

### Lỗi — Cao (12)
1. **C1 · `Chuẩn giao diện SaaS Starter.dc.html`** · `.sx-note` (CSS dòng 90, `white-space:nowrap`), chỗ dùng ở dòng 1133 "Chờ backend: lưu tỉnh/phường đã chọn ở Thông tin chung"
   - Trang cuộn ngang ở 360 và 390, cả sáng lẫn tối: scrollWidth 396.
   - Bật Chữ lớn: scrollWidth 454, thêm `.sx-code` (dòng 89, nowrap) ở "Bảng tra cho build" (dòng 1368).
   - Toast `.tst-wrap` cũng lòi theo.
   - Cao · [ĐO BẰNG SCRIPT]
2. **C2 · trang chuẩn** · "Dải thông báo trong trang", dòng 725–728 (DS `Alert`)
   - Chữ dùng màu gốc thay cho `-text`. Tương phản nền sáng: cảnh báo 1,65:1 · thành công 2,35 · thông tin 2,70 · nguy hiểm 2,77. Nền tối: thông tin 4,43 · nguy hiểm 4,45.
   - Bảng tra (dòng 1560) ghi "-text", tức mẫu vẽ trái với chính chuẩn.
   - Cao · [ĐO BẰNG SCRIPT]
3. **C3 · trang chuẩn** · Thanh ngôn ngữ `.lb-pill` (dòng 244, cao 36px)
   - Chỉ lên 44px qua `@container (max-width:520px)` (dòng 257), tức xét theo bề rộng chứ không xét `pointer:coarse`.
   - Vùng bấm nới bằng `::after` bị `.lb-l{overflow:hidden}` (dòng 242) cắt mất.
   - Hậu quả: trên tablet cảm ứng (768 / 1024), pill và `.lb-sel` chỉ bấm được 36px.
   - Cao · [ĐO BẰNG SCRIPT]
4. **C4 · trang chuẩn** · Ô tích khóa, dòng 447 (`.sx-check.off{opacity:.6}`, dòng 102)
   - Câu lý do "Gói hiện tại chưa có bình luận." chỉ đạt 2,59:1 (sáng) / 3,25:1 (tối).
   - Đây là thông tin người dùng cần đọc, không phải nút tắt.
   - Cao · [ĐO BẰNG SCRIPT]
5. **C5 · trang chuẩn** · DS `Avatar` ở nhóm "Xem cả 8 người", dòng 1299
   - Nền tối: chữ tắt `#405189` trên `#1b2643` chỉ đạt 1,96:1.
   - Nguồn màu là design system: `_ds/…/tokens/colors.css` còn `#405189`, chưa sang G2.
   - Cao · [ĐO BẰNG SCRIPT]
6. **C6 · `Block Library - Options.dc.html`** · `copyRef` dòng 203 + nút dòng 91
   - Nút báo "Đã sao chép mã tham chiếu…" nhưng không chép gì. Thử: đặt sẵn chữ mẫu vào clipboard, bấm nút, clipboard vẫn giữ chữ mẫu.
   - Nhãn nút còn "Chép mã tham chiếu".
   - Có từ trước; lượt này chỉ đổi chữ.
   - Cao · [ĐO BẰNG SCRIPT]
7. **C7 · 8/9 màn đã chạm** · Page Editor, Quản lý Bài viết, Page Builder v3, Email Settings, Trình đơn, Floating Contact, Block Library, Language Context
   - Không có `role="status"` / `aria-live` cho toast, nên "Đã sao chép…" và mọi toast khác không được trình đọc màn hình đọc.
   - Chỉ Đăng nhập nhanh (dòng 334) làm đúng.
   - Có từ trước.
   - Cao · [ĐO BẰNG SCRIPT]
8. **C8 · `Page Editor - Options.dc.html`** · dòng 66 `id="1a"`
   - Khung gốc rộng cứng `width:1240px`, trong khi màn chị em Quản lý Bài viết (dòng 100) dùng `min(1240px,100%)`.
   - Ở 390 tràn ngang 185px.
   - Có từ trước.
   - Cao · [ĐO BẰNG SCRIPT]
9. **C9 · trang chuẩn + README** · Bảng tra dòng 1563 "Icon · có sẵn" là sai
   - Repo `app/Modules/Admin/resources/views/components/icon.blade.php` chỉ có Heroicons + 60 khóa `ri-*` không hậu tố (`ri-close`).
   - Tên lạ tự thành hình tài liệu (dòng 114).
   - Trong 74 tên icon thiết kế dùng, 50 tên không có.
   - Đúng phải là "cần sửa".
   - Cao · [ĐO BẰNG SCRIPT]
10. **C10 · trang chuẩn + README** · Bảng tra dòng 1568 "Pagination · có sẵn, per-page 10/20/50 · mode pages|cursor" là sai
    - `pagination.blade.php:1-5` chỉ có paginator / current / last: không chọn số dòng, không có kiểu cursor.
    - Danh sách lớn phải dùng cursor (CLAUDE.md §3.1).
    - Đúng phải là "cần sửa".
    - Cao · [SUY RA TỪ CODE]
11. **C11 · trang chuẩn** · Thanh hàng loạt, Xem như "Người viết", dòng 2007 (`'Gửi duyệt (' + nSel + ')'`)
    - Đếm mọi dòng đang chọn, kể cả bài của người khác và bài đã đăng / đang chờ duyệt.
    - Ở repo:
      - `EntryPolicy::submit` (`app/Modules/Cms/Policies/EntryPolicy.php:58-61`) không xét bài của ai;
      - `Index::changeStatus` / `bulkPublish` không gọi `authorize('update')`;
      - bài đã đăng vẫn bị đếm là "xong" (`PublishEntry.php:86-88`, `Index.php:390-391`).
    - Thiết kế đang chép đúng lỗ hổng quyền này của repo.
    - Owner đã chốt (04/10 tối, theo khuyến nghị): gửi duyệt = có `entries.submit` VÀ được sửa bài đó, chỉ từ Bản nháp; repo đã sửa xong 04/10 (PR #168).
    - Cao · [SUY RA TỪ CODE]
12. **C12 · repo `app/Modules/Admin/Livewire/Entries/Index.php:457-466`** · `bulkApply`
    - Dòng đầu tiên không đủ quyền là dừng (403).
    - Các dòng trước đó đã đổi vẫn được lưu: Laravel biến lỗi thành phản hồi 403 ngay bên trong transaction của request, nên transaction không bị hủy.
    - Không có toast, ô chọn vẫn giữ.
    - Cùng mẫu này ở 5 hàm: bulkTrash, bulkRestore, bulkForceDelete, bulkDraft, bulkDuplicate. README mới ghi bulkTrash.
    - Cao · [SUY RA TỪ CODE]

### Lỗi — Trung bình (23)
1. Trang chuẩn · danh sách "Chuyển vào Thùng rác" dòng 707 · Ở 360 / 390 tên bài bị cắt còn "Khuyến m…", không có cách xem đủ tên nên người dùng không biết mình đang xóa bài nào · TB · [ĐÃ RENDER VÀ THẤY]
2. Component bổ sung · `.cp code` dòng 131 (nowrap + ellipsis) · Ở 360 / 390, giá trị cần chép (đường dẫn, bản ghi SPF) bị cắt và không có cách xem đủ · TB · [ĐÃ RENDER VÀ THẤY]
3. Component bổ sung · ô `.in` dòng 79 (`min-height:40px`) · Ô của length-field / alt ảnh cao 42px trên cảm ứng · TB · [ĐO BẰNG SCRIPT]
4. Component bổ sung · ô `.cp-in` dòng 128 / 253 · Cao 28px, nằm trong `div.cp` không phải `label` nên chạm vào phần đệm sẽ trượt · TB · [ĐO BẰNG SCRIPT]
5. Trang chuẩn · 4 nút chỉ có icon ở hàng bảng ≥ 1024 (Xem · Sửa · Nhân bản · Thùng rác), dòng 831–832 · Tên nút chỉ nằm trong `title`, mà `title` không hiện trên cảm ứng · Bảng tra dòng 1572 hứa Tooltip "bấm được trên cảm ứng" · TB · [SUY RA TỪ CODE]
6. Trang chuẩn + Danh sách co giãn · Có 2 kiểu focus cho menu: ở trang chuẩn (lọc dòng 563, panel ngôn ngữ dòng 1209–1225, lịch) focus ở lại nút mở; ở Danh sách co giãn (`.m5` dòng 305) focus vào mục đầu · TB · [ĐO BẰNG SCRIPT]
7. Trang chuẩn dòng 32 + Danh sách co giãn dòng 24 · `a:hover{color:var(--color-admin-primary)}` ở nền tối chỉ đạt 2,12:1 (đo ở Danh sách co giãn) · TB · [ĐO BẰNG SCRIPT]
8. Danh sách co giãn · `.srch5 input` dòng 214 · Chữ gợi ý không theo token, ở nền tối chỉ đạt 3,5:1 · TB · [ĐO BẰNG SCRIPT]
9. Trang chuẩn · chữ DS `Alert` · Không lớn lên khi bật Chữ lớn (12/1468 nút chữ không đổi, đều là mẫu Alert) · TB · [ĐO BẰNG SCRIPT]
10. Component bổ sung · cả trang · Không có công tắc Chữ lớn, 0 chỗ dùng `--fsp`, mọi cỡ chữ là px cố định → 7 component "Làm ngay" chưa có hành vi chữ lớn · TB · [SUY RA TỪ CODE]
11. `Email Settings.dc.html` · nút chép biến `.em-vcopy` dòng 549 / JS 1472–1474 · Chép xong chỉ đổi icon; `title` / `aria-label` vẫn là "Sao chép {token}" và trang không có vùng `role="status"` → không có phản hồi bằng chữ · TB · [ĐÃ RENDER VÀ THẤY]
12. `Quản lý Bài viết - Options.dc.html` · `.ptabs` dòng 129 · Ở 390 tràn ngang 228px. Đã có trong TODO "đồng bộ thẻ gọn dưới 1024px" · TB · [ĐO BẰNG SCRIPT]
13. `Language Context - Design Spec.dc.html` · mẫu Thanh ngôn ngữ `.lc-pill` dòng 274–283 · Ở 390 năm pill không chuyển sang "+N" / ô chọn, trang tràn 54px · TB · [ĐO BẰNG SCRIPT]
14. Repo `livewire/entries/index.blade.php:184-214, 330, 450` · Nút theo hàng và nút hàng loạt hiện theo cờ chung của website (`Index.php:573-576`), không xét quyền từng hàng → nhóm quyền author vẫn thấy "Chuyển vào Thùng rác" ở bài người khác và bị 403 · TB · [SUY RA TỪ CODE]
15. Repo `Index.php:257-271` · `saveTitle` (sửa nhanh) không giới hạn độ dài, cột `entry_translations.title` là varchar(255) → tiêu đề > 255 ký tự gây lỗi DB · TB · [SUY RA TỪ CODE]
16. Repo `Index.php:278-300` · `saveSlug` bỏ qua `max:200` của `EntryContentRules.php:26`; `routes.path` là varchar(255) · TB · [SUY RA TỪ CODE]
17. Repo `Concerns/WithMediaLibraryPicker.php:343-358` · Alt ảnh không có giới hạn ở máy chủ · TB · [SUY RA TỪ CODE]
18. `content-seo.js:69` / `content-ui.js` slugify / repo · Đường dẫn có 3 mức trần khác nhau: thước max 90 (chặn cứng 113), slugify 200, repo `max:200` → mốc chặn của thước (113) lệch với repo (200); hiện chưa ô đường dẫn nào áp mốc chặn · TB · [SUY RA TỪ CODE]
19. `content-seo.js:67,88` · Mô tả ngắn chặn cứng ở 300 (240 × 1,25), nhưng cột `entry_translations.excerpt` là varchar(255) và `EntryContentWriter.php:68-73` từ chối > 255 · TB · [SUY RA TỪ CODE]
20. Repo `forms/inbox.blade.php:35`, `forms/detail.blade.php:16`, `platform/audit/index.blade.php:25` · Hiện giờ theo UTC (`config/app.php` timezone UTC), lệch 7 tiếng · Chỉ 3 chỗ này bỏ qua SiteTime · TB · [SUY RA TỪ CODE]
21. README dòng 111–112 + bảng tra dòng 1590–1591 · Thông báo nổi / Hộp xác nhận ghi "MỚI", nhưng repo đã có lớp JS (`resources/js/sweet-toast.js`: chỉ chữ, 2,4 giây, không Hoàn tác; `resources/js/sweet-confirm.js`) → bản build phải THAY, không thêm hệ thứ hai · TB · [SUY RA TỪ CODE]
22. Bảng tra dòng 1572 "Tooltip · có sẵn · placement" · Repo `tooltip.blade.php` dùng prop `position`, chỉ hiện khi rê chuột / focus, không chạm được → phải là "cần sửa" · TB · [SUY RA TỪ CODE]
23. README "Quyền & gói" (quyền mới mặc định TẮT trừ Chủ website) · `PermissionRegistry.php:191` cho Quản trị mọi quyền website, nên quyền mới tự BẬT cho Quản trị → cần danh sách loại trừ (đổi RBAC, COUNCIL) · TB · [SUY RA TỪ CODE]

### Lỗi — Thấp (21)
1. Trang chuẩn · Chữ bị cắt "…" mà không có `title`: đường dẫn dòng 1083 (360 / 390); nhãn bậc màu dòng 653 khi bật Chữ lớn; mục cây dòng 1056 khi bật Chữ lớn ở 360 · Thấp · [ĐO BẰNG SCRIPT]
2. Danh sách co giãn · `.ttl` (line-clamp 2, dòng 41) · Cắt gọn 2 dòng, không cắt nửa chữ, nhưng không có `title` · Thấp · [ĐÃ RENDER VÀ THẤY]
3. Component bổ sung · menu mục lục đầu trang (link cao 21px) · Ở 360 / 390 vùng bấm 44px của các link liền nhau chồng lên nhau (6 cặp) · Thấp · [ĐO BẰNG SCRIPT]
4. Trang chuẩn · `.bt-row` dòng 270 (`order:3/4/5` dưới 640px) · Thứ tự nhìn ô bảng tra khác thứ tự DOM; mỗi hàng chỉ có 1 điểm focus nên Tab không bị đảo · Thấp · [SUY RA TỪ CODE]
5. Trang chuẩn · dòng 641 `<input type="color" value="{{ brand }}">` · Cảnh báo console mỗi lần nạp · Thấp · [ĐO BẰNG SCRIPT]
6. Trang chuẩn · dòng 1636 · `props.theme` thắng `localStorage['bs-scheme']` → mở riêng không theo nền đã chọn ở Shell · Thấp · [ĐO BẰNG SCRIPT]
7. Trang chuẩn · thanh "Xem thử" dính (khoảng dòng 327) · Ở 360 + Chữ lớn cao khoảng 190px, chiếm 22% màn · Thấp · [ĐÃ RENDER VÀ THẤY]
8. Trang chuẩn · ô `.sPlain` (dòng 116 / 819) cao 38px nằm trong `div` cao 44px không phải `label` → chạm mép trượt · Thấp · [ĐO BẰNG SCRIPT]
9. Trang chuẩn · khung 390 · Vùng bấm của `a.dl-tl` chồng lên chip trạng thái `button.sc.bt` (mẫu tĩnh) · Thấp · [ĐO BẰNG SCRIPT]
10. `Floating Contact Panel.dc.html:439` · Mã chết `copyLabel` còn chữ "Đã chép" · Thấp · [SUY RA TỪ CODE]
11. Component bổ sung dòng 262 · Props của copy ghi "thử Clipboard API, không được thì vùng chọn ẩn", ngược với `content-ui.js` và README (vùng chọn ẩn trước, Clipboard API sau) · Thấp · [SUY RA TỪ CODE]
12. Bảng tra dòng 1559 "Badge · có sẵn" thêm `icon` [MỚI] · Repo `badge.blade.php` chưa có icon → "cần sửa" · Thấp · [SUY RA TỪ CODE]
13. Bảng tra dòng 1562 "Card · title · actions · padding" · Repo có `title` + slot `header` / `footer`, chưa có actions / padding · Thấp · [SUY RA TỪ CODE]
14. Bảng tra dòng 1577 settings-shell "cần sửa chữ mục đang chọn → accent (E9)" · Đã lỗi thời: repo đã dùng `text-accent` (`settings-shell.blade.php:61`) · Thấp · [SUY RA TỪ CODE]
15. Bảng tra dòng 1575 / 1576 / 1578 · Props ghi sai so với file Blade: brand-logo là `context` (sidebar / topbar); rich-editor là `target · profile · value · rows`; theme-option là `axis · value · name · label · store` · Thấp · [SUY RA TỪ CODE]
16. Repo `theme-option.blade.php:13` · Ảnh thu nhỏ gắn cứng `#405189` (màu cũ) · Thấp · [SUY RA TỪ CODE]
17. Bảng tra dòng 1574 "Breadcrumb · DS chưa Blade" · Repo đã có một phần: slot `breadcrumbs` trong `page-header.blade.php:12-16` · Thấp · [SUY RA TỪ CODE]
18. Repo `pagination.blade.php:26` · `aria-label="Pagination"` tiếng Anh cứng, không qua `__()` · Thấp · [SUY RA TỪ CODE]
19. Trang chuẩn dòng 2152 · Danh sách nhóm quyền dự phòng `['…','Tác giả','Hỗ trợ','Xem']` không khớp BSCW / registry · Thấp · [SUY RA TỪ CODE]
20. README dòng 255 (múi giờ) · Không ghi dạng giá trị: SiteTime chỉ nhận `{"tz":"<IANA>"}` (`SiteTime.php:86-95`); ghi chuỗi trần sẽ bị bỏ qua mà không báo lỗi · Thấp · [SUY RA TỪ CODE]
21. Repo `lang/vi.json:550` `catalog.price_hint` · Gọi giá trống là "chế độ giới thiệu sản phẩm", trái README dòng 254 (giá trống ≠ chế độ giới thiệu) · Thấp · [SUY RA TỪ CODE]

### Đối chiếu repo — trả lời từng câu
- **`bs-admin`:**
  - Repo chưa khai. Hiện chỉ có `Blade::anonymousComponentPath(…/components, 'admin')` ở `app/Modules/Admin/Providers/AdminServiceProvider.php:84`. Namespace view `admin::` (dòng 77) là chuyện khác, không đổi.
  - Thêm `Blade::anonymousComponentPath($components, 'bs-admin')` là đủ. Đã thử: `<x-bs-admin::badge>` ra HTML giống `<x-admin::badge>`.
  - Cách Claude Code khuyến nghị: đổi một lần trong một PR (393 thẻ / 34 file + test), kèm test chặn `<x-admin::` mới. Lý do: README đang định giữ hai tên song song rồi chuyển dần, nghĩa là cùng một component có hai tên mà không có gì ngăn code mới dùng tên cũ.
  - Owner đã chốt (04/10 tối): đổi hẳn thẻ trong một PR, không giữ thẻ `<x-admin::…>` (namespace view `admin::` giữ nguyên). Claude Code làm PR #167.
- **Bảng tra ↔ `components/`:**
  - Có 18 file Blade, bảng tra có đủ cả 18; 50 dòng MỚI đều chưa có file.
  - Sai trạng thái: Icon, Pagination (Cao), Tooltip (TB), Badge (Thấp).
  - Chưa chính xác: Card, Breadcrumb, Toast / Hộp xác nhận (có JS), props của 3 dòng "Blade chưa DS", ghi chú E9.
- **Quyền theo dòng:**
  - README đúng ở cả 3 ý: `publish` chỉ xét `entries.publish` (`EntryPolicy.php:64-67`); `delete` phân biệt `.own` / `.any` (`:39-43`); người không có `entries.publish` mà có `entries.submit` bấm Đăng thì bài thành chờ duyệt (`PublishEntry.php:83-99`).
  - Kèm 3 lưu ý: bài đã đăng vẫn bị đếm là xong; bài theo quy trình duyệt bị bỏ qua; `submit` không xét bài của ai (C11).
  - `bulkTrash` có xét quyền từng dòng nhưng dừng ở dòng đầu bị từ chối (C12).
- **Giới hạn ký tự** (thiết kế max / chặn cứng → repo):

  | Trường | Thiết kế | Repo |
  |---|---|---|
  | title | 100 / 125 | `max:200`, cột 255 (sửa nhanh không giới hạn) |
  | seoTitle | 80 / 100 | sản phẩm `max:255` |
  | seoDesc | 200 / 250 | 255 |
  | excerpt | 240 / **300** | cột **255** |
  | alt | 150 / 188 | không giới hạn |
  | slug | 90 / **113** | **200** |

  Lệch ở excerpt, slug và alt (TB 17–19).
- **Múi giờ:**
  - SiteTime đọc nhóm `general`, khóa `timezone`, giá trị `{"tz":…}`, mặc định Asia/Ho_Chi_Minh (`app/Modules/Tenancy/Context/SiteTime.php:28-33,79-95`).
  - Đã dùng ở danh sách bài, hẹn giờ, Cài đặt và mail mời.
  - Chưa có chỗ ghi. 3 trang hiện giờ UTC (TB 20).
- **"Việc cho backend" trong README:**

  | Tình trạng | Mục |
  |---|---|
  | Đã có ở repo | Thùng rác cho bài / trang / sản phẩm / ảnh (SoftDeletes); khóa `rbac.role.author` (chờ chốt tên) |
  | Có một phần | Hoàn tác (đã có restore, chưa có toast kèm nút); `users.preferences` (đã có cột, chưa có khóa tz / coach) |
  | Còn thiếu thật | `--brand-N-ink` · đường ghi tỉnh / phường · bỏ qua dòng không đủ quyền và báo số · bỏ hộp xác nhận Thùng rác · "Liên hệ báo giá" ngoài website · chỗ ghi + nhãn múi giờ · `can.*` theo dòng · `bs-admin` · `help_faqs` |
  | Mâu thuẫn | "quyền mới mặc định TẮT" (TB 23) |

### Việc cần sửa — xếp theo mức độ
**Cao**
- Lượt sửa trang chuẩn kế tiếp (D0-2s2):
  - C1 cuộn ngang;
  - C3 pill 44px trên cảm ứng;
  - C4 câu lý do ô khóa;
  - C9 / C10 sửa trạng thái Icon / Pagination trong bảng tra;
  - C11 "Gửi duyệt (N)" / "Đăng (N)" chỉ đếm dòng làm được.
- Prompt DS (G2):
  - C2 Alert dùng `-text` và theo `--fsp`;
  - C5 token chữ tắt Avatar.
  Trong lúc chờ, trang chuẩn vẽ mẫu Alert bằng markup của trang.
- D0-2 phần 3, theo từng màn:
  - C6 Block Library chép thật bằng `BSUI.copy`, nhãn "Sao chép mã tham chiếu";
  - C7 vùng `role="status"` cho toast ở 8 màn;
  - C8 Page Editor `width:min(1240px,100%)`.
- Repo:
  - C12 bulk bỏ qua dòng không đủ quyền và báo số (5 hàm);
  - C11 phía repo: `submit` đòi quyền sửa bài + chỉ từ Bản nháp (đã chốt, đã có ở repo từ 04/10 — PR #168).

**Trung bình**
- D0-2s2: TB 1–10, 18, 19 (độ dài đường dẫn / mô tả ngắn trong `content-seo.js`), 21, 22.
- D0-2 phần 3: TB 11, 13. TB 12 đã có trong TODO.
- Repo: TB 14–17, 20, 23 (COUNCIL).

**Thấp**
- D0-2s2: Thấp 1, 3, 6, 8, 11–15, 17, 19, 20.
- Màn: Thấp 10.
- Repo: Thấp 16, 18, 21.
- Ghi nhận, không sửa: Thấp 2, 4, 5, 7, 9.

### Chuyển sang D0-3 (Bảng thuật ngữ)
- **Tên nhóm quyền `author`.**
  - Thiết kế dùng "Người viết" 23 lần / 7 file (`content-workflow.js:33`). Repo `lang/vi.json:793` `rbac.role.author` = "Tác giả" (ja 投稿者, zh 作者). Repo cũng dùng "Trả lại tác giả" (`:841`) và "người viết" trong 5 câu.
  - D0-3 chốt hai từ khác nhau:
    - tên nhóm quyền: "Người viết" — đã chốt ở D0-3 mục C.1;
    - người đứng tên một bài: "tác giả", giữ "Trả lại tác giả".
  - Repo đổi giá trị khóa theo sau.
- **Bộ từ nút AI.**
  - Hiện có hơn 20 nhãn cho vài việc: "Viết lại" 31 · "AI dịch" 16 · "Dịch bằng AI" 9 · "Viết mô tả" 8 · "Gợi ý tiêu đề" 6 · "Viết bằng AI" 4 · "Nhờ AI" 4 · "Viết cả bài bằng AI" 2 · "Mở rộng lại bằng AI" 2 · "Bổ sung / Làm mới bằng AI" · "Viết tiêu đề & mô tả" · "Tạo bằng AI" · "Gợi ý bằng AI" · "AI gợi ý" · "Viết giúp" · "Viết lại đoạn bằng AI" · "Ghi đè bằng bản AI".
  - Đề xuất theo TODO "D0-2 phần 3": "<động từ> <đối tượng>", không thêm "AI" / "bằng AI" vì icon đã nói. Cụ thể:
    - ô trống → "Gợi ý <tên trường>";
    - ô có chữ → "Viết lại";
    - bài có chữ → "Nâng cấp bài viết";
    - bài trống → "Viết mới";
    - dịch → "Dịch";
    - ảnh → "Tạo ảnh".
  - D0-3 chốt, rồi gom ở lượt riêng sau D0-3.
- Chữ nút chép đã thống nhất "Sao chép" / "Đã sao chép" — đưa vào bảng thuật ngữ.
- Tên định dạng icon (`ri-x-line` như Remix, hay `ri-x` như repo): chốt ở lượt build. Claude Code khuyến nghị dùng tên Remix đủ hậu tố, vì thiết kế đã dùng và gói `remixicon` đã có trong `package.json`.

▲ HẾT MỤC AUDIT ▲
