# Chuẩn hoá Page Editor ↔ Quản lý Bài viết — tiếp tục ở đây (đợt 4)

File liên quan: `Page Editor - Options.dc.html`, `Quản lý Bài viết - Options.dc.html`,
`Trình soạn nội dung.dc.html`, `content-workflow.js`. Đọc trước: `CLAUDE.md`, `LESSONS.md`, `github.md`.

## ĐÃ XONG (đừng làm lại)
0-lim. **Giới hạn ký tự tập trung ở `BSSEO.LIMITS` + `BSSEO.meter()`** — 6 trường (title · seoTitle ·
   seoDesc · excerpt · alt · slug), mỗi trường có `min / best / display / max`. Mô tả Google **nhập được
   tới 200 ký tự**, vượt 160 chỉ cảnh báo vàng, vượt 200 mới đỏ. Dải màu 6 mức (xám → vàng → xanh dương →
   **xanh lá** → vàng → **đỏ**) + dải 4px dưới ô có vùng đẹp tô xanh mờ và vạch mốc hiển thị.
   Áp ở cả hai màn qua `this.mtr(field, len)`; mọi câu chấm điểm/gợi ý AI đọc `this.lim(field)`.
   **Không màn nào được viết lại số 60/160/200.** Bảng đầy đủ: `Chuẩn SEO cho 2 luồng biên tập.md` §A-bis.
0-seo. **Bảng kiểm SEO 20 mục dùng chung `content-seo.js` (`window.BSSEO`)** — 3 nhóm (bắt buộc 7 · nên có 8 ·
   nâng cao 5), mỗi mục kèm cách sửa (`auto` máy tự làm không trừ từ AI · `ai` có báo số từ · `manual` kèm
   câu hướng dẫn một dòng). Đã áp vào **cả hai màn**: nhóm gập được, dải "Còn N mục chưa đạt" tách hai
   đường **Tự hoàn thiện** (miễn phí) và **Nhờ AI viết N chỗ** (mở hộp `Nâng cấp nội dung` với nhóm
   "Việc SEO còn thiếu" tick sẵn), nút sửa nhanh trên từng dòng. Bài viết 20 mục · Trang 18 mục.
   Hộp `Nâng cấp nội dung` nhận prop mới `seoJobs`. **Page Editor nay đã mount hộp này** (`upgradeOpen`).
   Chi tiết: `Chuẩn SEO cho 2 luồng biên tập.md`. Không màn nào khai lại bảng kiểm.
0. **Quy ước giao diện dùng chung `content-ui.js` (`window.BSUI`)** — token màu/bo/đổ bóng/khoảng cách/kiểu chữ
   + pattern component (thẻ, nhãn+ô nhập, nút, chip, huy hiệu trạng thái, dải cảnh báo, panel AI, popover,
   menu, hàng chọn trạng thái, khung ảnh fit tỷ lệ, link hành động). Bộ ba nguồn dùng chung hiện có:
   `ai-quota.js` (BSAI) · `content-workflow.js` (BSCW) · `content-ui.js` (BSUI). **CHƯA áp vào 4 màn** —
   xem mục CHƯA LÀM.
0b. Trong lượt này còn làm xong ở màn Bài viết + trình soạn chung:
   - Panel "Phương án AI" khi mở thì **ẩn ô nhập cũ** (tiêu đề / mô tả ngắn / cặp SEO), đóng panel là ô nhập
     trở lại — panel đã hiện "Chữ hiện tại" nên không cần hai chỗ.
   - Panel AI cho cặp SEO: **di lên ngay dưới 2 ô Tiêu đề/Mô tả Google**, có "Cặp hiện tại", chấm điểm +
     huy hiệu "Nên dùng", và mỗi lần chạy trả **3 phương án**; nút "3 phương án khác" lấy 3 cái KẾ TIẾP
     trong kho (không lặp lại) — `mockOpts(field, d, r)` + `this._aiRound`.
   - **Ảnh chia sẻ (OG) nằm trong khung xem trước Google**: ảnh vuông 92px + nút "Đổi ảnh" + dòng ghi rõ
     đang dùng ảnh đại diện hay ảnh riêng + "Dùng lại ảnh đại diện"; không có ảnh thì khung chỉ hiện chữ.
     Khối "Ảnh chia sẻ (OG image)" rời đã bỏ.
   - **Trình soạn chung: bảng thuộc tính ảnh** — nút Chèn ảnh mở bảng (Chọn ảnh có sẵn → tab Thư viện /
     Tải ảnh mới lên → tab Tải lên; mô tả alt; chú thích; cỡ Tràn khung·Lớn·Vừa·Nhỏ; căn trái/giữa/phải),
     **bấm vào ảnh trong bài mở lại bảng** để sửa hoặc xoá; chọn nhiều tệp một lúc vẫn chèn thẳng như cũ.
     `MediaPicker` nhận prop `tab` để mở đúng tab.
1. Đợt 3: Page Editor dùng trình soạn chung; chuẩn hoá `<p>`; gộp nút AI; Cụm 1 (AI tiêu đề/mô tả),
   Cụm 2 (ảnh đại diện), Cụm 3 (SEO), Cụm 4 (Đăng/Trạng thái Page Editor).
2. **Trình soạn nội dung: khung soạn có chiều cao giới hạn + cuộn trong** (`editorMaxH` = 520px,
   toàn màn hình = `calc(100vh - 236px)`), áp cho cả ô HTML nguồn; bong bóng AI tự ẩn khi đoạn đã
   bôi đen cuộn ra ngoài (`onBodyScroll` + kiểm tra rect trong `onSelChange`).
3. **`content-workflow.js` (`window.BSCW`) — nguồn duy nhất cho trạng thái · vai trò · quy trình**,
   bám repo: `PermissionRegistry` (24 quyền, 6 vai trò corporate), `EntryPolicy` (own/any),
   `WorkflowTemplateRegistry` (none · admin_approval · editorial), `ApplyEntryWorkflowTransition`
   (bước → trạng thái công khai), migration 2012 (`entries.status` có **scheduled** và **private**).
   Hai màn đều load script này trong `<helmet>` + nghe `bs-cw-ready`. **Không màn nào khai lại bảng.**
4. **Quản lý Bài viết**: bỏ bảng ROLES/WF nội bộ → dùng BSCW; đổi khoá `sched` → **`scheduled`**;
   thêm trạng thái **`private`** (tab + tier-1) và **`archived`** (chỉ từ `published`, cần
   `entries.publish`); sửa `stLabel()` còn khoá mồ côi `pub/review` (toast từng ghi "undefined");
   `bulkCanTrash` nay xét `canDelete` từng dòng (own/any) thay vì chỉ `entries.delete.any`;
   bài `scheduled/private` khi bật quy trình được tính ở **bước đầu** (`wfStateOf`) nên không còn
   kẹt "không còn bước nào"; bỏ màu tím tự chế `#8b5cf6` → dùng token AdminKit.
5. **Page Editor**: bỏ mô hình quyền giả (2 nút "Quản trị/Biên tập" + luật sai "biên tập không được
   đăng" — repo cho editor **có** `entries.publish`) → **select 6 vai trò + select 3 chế độ quy trình**;
   khoá trạng thái `pub/sched/priv` → `published/scheduled/private`; danh sách trạng thái và nút Đăng
   nay sinh từ BSCW (tier-1 canh quyền, hoặc **Bước tiếp theo** + dải bước khi có quy trình);
   thêm blocker "Bước tiếp theo cần quyền của vai trò khác" / "Chưa chọn thời điểm đăng".

## TRẢ LỜI CỦA NGƯỜI DÙNG (đợt này) — làm theo đúng thứ tự dưới
- (1)(2) Hai mục "chưa chắc là lỗi": người dùng không chọn đáp án, yêu cầu **tự khảo sát rồi đề xuất,
  lấy ưu điểm của cả hai**. Kết luận đã kiểm: panel AI SEO ở Bài viết **có chạy** nhưng nằm ở tận
  cuối thẻ SEO → phải di lên ngay dưới 2 ô Tiêu đề/Mô tả Google. Cảnh báo "never resolved" ở màn
  danh sách là **cosmetic** (giá trị vẫn hiện đúng trên màn — chụp ảnh xác nhận), do runtime cảnh báo
  ở nhịp streaming; không phải lỗi dữ liệu.
- (3) Ô nhập Page Editor → **bo 4px + nền trắng** (token AdminKit). *(mới làm cho danh sách trạng thái,
  còn lại chưa)*
- (4) Tiêu đề thẻ → **chuẩn theo Page Editor: 13px gọn** (đưa Bài viết 16px về 13px).
- (5) Trang **không có "Mô tả ngắn"**, bài viết thì có → đề xuất: bỏ thẻ Mô tả ngắn ở Page Editor,
  chỉ giữ "Mô tả SEO" (150–160) trong thẻ SEO; nút AI mô tả chuyển vào đó.
- (6) Ảnh đại diện Page Editor → **link luôn hiện** như Bài viết (bỏ overlay hover).
- (7) Mô tả ngắn 120–200 + xem trước thẻ tin — chỉ còn áp cho Bài viết (theo (5)).
- (8) Panel "Phương án AI" → **chèn ngay dưới trường** ở cả hai màn; panel nổi chỉ dành cho việc
  cấp bài (viết cả bài / viết lại đoạn bôi đen).
- (9) Lịch sử phiên bản → đề xuất: thẻ luôn hiện 3 bản gần nhất + "Xem tất cả" mở danh sách đầy đủ.
- (10) Thẻ Đăng → đồng bộ: Page Editor thêm "tự lưu nháp" + thời gian đọc; Bài viết thêm dòng
  "Hiển thị" (giờ đã có trạng thái `private` để bám vào).
- (11) **Thứ tự: rà 20 nhóm nút trình soạn chung TRƯỚC** → rồi chuẩn hoá cặp theo (3)–(10) → cuối
  cùng audit responsive (mobile/tablet) cho mọi khối mới.

## CHƯA LÀM
- **Writer bước "Kiểm định" phải chấm theo `BSSEO.audit`** (hiện vẫn dùng bộ tiêu chí riêng), và nút
  "Tự chỉnh N chỗ" chỉ sửa mục còn đỏ theo đúng danh sách đó.
- **Trang: ô mô tả ảnh đại diện** — `imgAlt` mới sửa được qua nút "Tự sửa" trong bảng kiểm, chưa có ô nhập
  ở thẻ ảnh đại diện (Bài viết đã có).
- Chốt: nhóm 1 còn đỏ thì **chặn nút Đăng** hay chỉ cảnh báo như hiện nay.
- **Kiểm lại luồng "Việc lớn → Trợ lý viết" bằng tay** (tôi đã sửa 6 lỗi ghép nối, xem mục 0c) — đặc biệt
  bước cuối "Chèn N phần": phải thấy tiêu đề/mô tả/nội dung đổi thật, và hộp hỏi trước khi ghi đè.
- Audit "Nâng cấp bài này" đã áp 8 điểm (xem cuối file). Còn 2 điểm chưa làm: **xem trước phần sẽ thêm
  trước khi chèn** (nhóm "Thêm phần còn thiếu") và **tiến trình từng việc khi chạy** (hiện chạy 1 nhịp).
- **Phân vai hai công cụ AI cho thân bài** (đã chốt hướng, chưa dựng):
  · `Nâng cấp nội dung.dc.html` = cửa duy nhất khi bài ĐÃ có chữ (việc nhỏ, checkbox, chi phí, giữ/hoàn tác).
  · `AI Writer Panel v2.dc.html` = xưởng viết khi bài TRỐNG, và khi chọn việc lớn "Viết lại toàn bài"
    (mở Writer với nội dung hiện tại làm nguyên liệu thay vì chạy im lặng).
  · Bài trống → nút "Viết bài bằng AI" mở thẳng Writer (không hiện 3 việc nhỏ).
  · Cả Bài viết và Page Editor dùng đúng cặp này; Bài viết hiện CHƯA mount Writer.
- **Page Editor mount hộp `Nâng cấp nội dung.dc.html`**: ĐÃ XONG ở lượt này (xem mục 0-seo) — panel
  "AI giúp gì cho bài này?" cũ vẫn còn, cần chốt bỏ hay giữ song song.
- **Áp `content-ui.js` vào 4 màn** (Bài viết, Trang, thêm/sửa bài, thêm/sửa trang): thay style lặp bằng
  BSUI.* để "sửa một chỗ, đổi mọi chỗ"; kèm trang chuẩn `Chuẩn giao diện nội dung.dc.html` (specimen:
  token + pattern + quyết định lấy ưu điểm của từng màn) — CHƯA dựng.
- Rà 1-1 20 nhóm nút của `Trình soạn nội dung.dc.html` (đậm/nghiêng/gạch, danh sách, thụt lề, căn lề,
  bảng, hr, chèn ảnh/video, xem HTML, toàn màn hình, undo/redo, ký tự đặc biệt, màu chữ/nền, cỡ chữ,
  kiểu đoạn, xoá định dạng, liên kết, thanh đường dẫn thẻ, thu gọn thanh công cụ).
- Chuẩn hoá cặp theo các câu trả lời (3)–(10).
- Audit responsive cho các khối mới (checklist SEO, banner blockers, danh sách trạng thái/bước duyệt,
  select vai trò/quy trình ở Page Editor, khung soạn có max-height).
- Việc treo cũ: kiểm các lời gọi AI viết tiêu đề/mô tả đã dùng `aiSeoKw()` (từ khoá) làm nguồn chưa.

## Bẫy đã mắc — đừng lặp lại
- **Sửa file `.dc.html` tên tiếng Việt bằng regex**: `run_script` không mở được đường dẫn có dấu →
  `copy_files` sang tên ASCII tạm (`tmp-posts.html`), chạy regex, rồi `copy_files … move:true` về chỗ cũ.
  Luôn **log độ dài đoạn khớp** trước khi thay: `const kw = String(d.keyword…` xuất hiện 2 nơi nên
  `[\s\S]*?` đã nuốt 43k ký tự trong lần đầu. Neo thêm dòng thứ hai cho đủ duy nhất.
- **str_replace nhiều dòng**: nếu `new_string` bỏ sót dòng cuối của `old_string` là mất luôn dòng đó
  (đã mất `if (has('faq'))` một nhịp). Đọc lại vùng vừa sửa ngay sau khi chạy.
- **Chụp màn hình khi có modal `position:fixed`**: phải cuộn về đầu trang trước khi chụp, nếu không
  ảnh chụp ra trông như modal không mở.
- **Không tự gõ lại tiếng Việt trong `old_string`** của str_replace (sai một dấu là cả lô 26 edit bị
  huỷ: "hẹn"→"hỏn", "hẳn"→"hản"). Luôn **copy nguyên văn từ output `read_file`**, và chia lô nhỏ để
  lỗi khu trú.
- `run_script` **không nhận đường dẫn có ký tự tiếng Việt** → với các file `.dc.html` này phải dùng
  `str_replace_edit` (nhiều edit trong một lệnh, atomic).
- Không str_replace bằng text bị grep cắt ngắn — luôn `read_file` lấy dòng đầy đủ.
- `activeIsDefault`/`DEF` khai muộn trong renderVals của Page Editor → cần "đang ở bản gốc" ở đoạn
  trước đó thì dùng `S.loc === this.defLang`.
- Verify ngay sau mỗi cụm, đừng dồn.
