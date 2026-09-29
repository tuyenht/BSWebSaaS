# Chuẩn hoá 4 cụm UI lặp giữa Page Editor và Quản lý Bài viết

Yêu cầu: "hợp không giao" — lấy ưu điểm của CẢ HAI bản, áp vào một chuẩn chung, mount lại ở
mỗi file theo đúng data/state riêng của file đó (KHÔNG tách shared DC — hai file có shape data
khác nhau: Page Editor dùng `d.title`/`this.state.data[loc]`, Bài viết dùng `fTitle`/`this.doc()`).
Nút nào cũng phải có `onClick` trỏ tới method thật — đã audit, gần hết đã có handler thật (xem dưới).

## Đã xác nhận: KHÔNG có nút chết
- Page Editor: `onWriteTitle→aiOpenField('title')`, `onWriteExcerpt→aiOpenField('excerpt')`,
  `onOgPick→openMedia()`, `onOpenMedia→openMedia()` — đều thật.
- `aiOpenField` MỞ CÙNG modal `state.ai` (phase:'pick', drafts, scoring `aiBest`, badge "Nên dùng"
  qua `v.recOn`/`v.recWhy`) như bôi-đen-viết-lại — nghĩa là Page Editor ĐÃ có bảng chấm điểm nhiều
  phương án đúng chuẩn CLAUDE §"gợi ý AI" — KHÔNG cần port thêm, chỉ khác vỏ ngoài (modal nổi định vị
  theo field) so với Bài viết (panel `aiP` nhúng ngay dưới field). Để yên, không việc gì phải sửa.

## Cụm 1 — Nút AI cạnh nhãn Tiêu đề / Mô tả ngắn
Page Editor (dòng ~199, ~240): pill tròn nhỏ icon `ri-quill-pen-line` + chữ "AI" (không rõ hành
động), KHÔNG có bộ đếm ký tự cho Tiêu đề.
Bài viết (dòng ~462, ~529): nút full-text `✨ Gợi ý tiêu đề` / `✨ Viết mô tả từ nội dung bài` +
bộ đếm màu bên phải (`titleCount`/`titleCountColor`, `excCount`/`excCountColor`).
QUYẾT: đổi Page Editor 2 nút này sang icon `ri-sparkling-2-line` + chữ đầy đủ đúng việc sẽ làm
("Gợi ý tiêu đề" / "Viết mô tả từ nội dung trang") để rõ hành động hơn cụm "AI" trơn, giữ kiểu pill
nhỏ 22px cho gọn (Page Editor đang chật hàng hơn Bài viết vì có thêm chip ngôn ngữ + đường dẫn).
KHÔNG thêm bộ đếm mới cho Tiêu đề trang (không có giới hạn cứng, tránh bịa ngưỡng) — excerpt đã có
`{{excerptLen}}/160` sẵn, giữ nguyên.
Bài viết: giữ nguyên, đã chuẩn.

## Cụm 2 — Ảnh đại diện
Page Editor rỗng (dòng ~471-478): tiêu đề "Ảnh đại diện" + khung dashed "Kéo–thả hoặc chọn ảnh đại
diện" + 1 nút "Chọn ảnh đại diện" (`onOpenMedia`) → mở MediaPicker. KHÔNG có: nút Tải lên riêng,
nút Tạo bằng AI riêng, chip hạn ngạch ảnh.
Bài viết rỗng (dòng ~708-733 khu vực `noImage`): khung dashed + 2 nút riêng —
`onUpload` ("Tải ảnh của bạn lên — không tốn lượt nào") và `onGenImage`
("Tạo N phương án ảnh bằng AI", style/label suy từ gói `genImgStyle/genImgLabel/genImgTitle`) +
dòng ghi chú hạn ngạch `imgQuotaNote`. Đây là bản ĐÚNG chuẩn CLAUDE §7a (ảnh thật ưu tiên trước
AI, hạn ngạch riêng sổ ảnh, "Tạo" không "Vẽ").
Bài viết có ảnh (dòng ~703-707 khu vực filled, cùng khối `hasImage`): thumbnail theo tỷ lệ thật +
tên file + chip tỷ lệ + nút "Thử tỷ lệ khác" (`onCycleRatio`) + cảnh báo tỷ lệ xấu khi cần.
Page Editor có ảnh (dòng ~479+ sau `featuredEmpty`, chưa đọc hết — cần đọc `featThumbStyle`/
`featLabel`/`onRemoveFeatured`/`recents`/`pickRecent` khu vực; đã biết: có ô nhập ALT
`onFeaturedAlt`, và có "recents" — chọn nhanh từ ảnh gần dùng, Bài viết KHÔNG có "recents" nhưng
CÓ chip tỷ lệ + nút đổi tỷ lệ mà Page Editor không có).
QUYẾT (hợp): Page Editor rỗng → đổi thành 2 nút như Bài viết (Tải lên / Tạo bằng AI) + ghi chú hạn
ngạch — dùng ĐÚNG state/quota đã có sẵn trong Page Editor (đọc `this.AIQ`/imgUsed nếu có, hoặc
thêm state ảnh tương tự `imgUsed` như Bài viết nếu Page Editor chưa có sổ ảnh riêng — PHẢI ktra
trước khi viết, đừng đoán). Page Editor có ảnh → thêm chip tỷ lệ + nút đổi tỷ lệ như Bài viết, GIỮ
nguyên ô ALT + recents đã có (Bài viết không có recents nhưng đó là điểm mạnh riêng của Page Editor
— giữ, không bỏ). Bài viết rỗng/có ảnh: giữ nguyên (đã chuẩn hơn).
CẦN ĐỌC TRƯỚC KHI SỬA: Page Editor đoạn `featuredFilled` (sau dòng 478), method `openMedia/
pickRecent/removeFeatured`, và xem Page Editor có khái niệm "lượt tạo ảnh"/imgUsed chưa (grep
`imgUsed|imgLeft|imageQuota` trong Page Editor — CHƯA GREP, làm trước).

## Cụm 3 — Thẻ SEO
Page Editor "Tối ưu SEO" (dòng ~311-372): thẻ GẬP (`onToggleSeo`/`seoChevron`), banner mẹo tĩnh +
quota chip + 1 nút "Gợi ý lại bằng AI" (`onAiSeo`) áp cho CẢ tiêu đề+mô tả cùng lúc, input Tiêu đề
SEO (đếm /60) + Mô tả SEO (đếm /160) + chip "Hoàn tác bản AI" riêng, khối "Ảnh chia sẻ (OG image)"
(thumbnail + `onOgPick` chọn ảnh OG riêng ngoài ảnh đại diện), khối "Xem trước trên Google".
KHÔNG CÓ: ô từ khoá, bảng kiểm sẵn-sàng-lên-Google.
Bài viết "Hiện thế nào trên Google" (dòng ~582-660+): thẻ KHÔNG gập, 1 nút "Viết tiêu đề & mô tả"
(`onAiSeo`) cùng chỗ với tiêu đề thẻ, ô "Từ khoá muốn lên top" (`fKeyword`/`onKeywordChange`, ghi
rõ "để AI viết tiêu đề, mô tả và kiểm tra bài — không gửi cho Google"), Tiêu đề trên Google (đếm
màu `seoTitleColor`/`seoTitleCount`), Mô tả trên Google (đếm màu tương tự), khối xem trước, khối
CHECKLIST "Bài đã sẵn sàng lên Google chưa" (`seoChecks` list + `seoScoreStyle`/`seoScoreText`
kiểu "N/8 mục đạt"). KHÔNG CÓ: gập/mở thẻ, ảnh OG riêng, chip "Hoàn tác bản AI" (dùng `undoSeo`
dạng nút riêng dưới, không phải chip cạnh label).
QUYẾT (hợp): Bài viết đã có Ô TỪ KHOÁ + CHECKLIST là 2 tính năng tốt hơn hẳn (checklist đúng
chuẩn CLAUDE §"chấm điểm theo tiêu chí kiểm được") — PORT sang Page Editor: thêm ô từ khoá (Page
Editor cần 1 nguồn "từ khoá SEO" mới — kiểm xem đã có biến `aiSeoKw()` chưa, CÓ RỒI dùng làm mock/
nguồn, cần thêm state field lưu từ khoá do người dùng nhập, ví dụ `d.seoKeyword`) + checklist
tương tự (số mục kiểm phù hợp NGỮ CẢNH TRANG: có tiêu đề SEO, có mô tả SEO, có ảnh đại diện/OG,
có nội dung ≥ ngưỡng, có từ khoá, đường dẫn không trùng — Page Editor không có "chuyên mục" nên bỏ
mục đó, thay bằng mục liên quan Loại trang nếu hợp lý, hoặc bỏ nếu không có tương đương — ĐỪNG BỊA
mục không thật). Giữ nguyên phần Page Editor có mà Bài viết chưa show trong thẻ này: khối "Ảnh
chia sẻ (OG image)" riêng (Bài viết có thể đang dùng ảnh đại diện làm OG mặc định không cho đổi
trong thẻ này — không rõ, ĐỪNG suy diễn, chỉ giữ nguyên Page Editor). Đổi thẻ Page Editor sang
KHÔNG GẬP (đồng bộ Bài viết — thẻ SEO đủ quan trọng để luôn mở, đỡ 1 click) HOẶC giữ gập nếu lo
thẻ quá dài sau khi thêm checklist — QUYẾT: giữ gập (Page Editor đã có nhiều thẻ dài ở cột chính,
gập giúp đỡ rợp trang; Bài viết có ít thẻ hơn ở cột đó nên để mở được). Đổi chip "Hoàn tác bản AI"
của Page Editor giữ nguyên kiểu chip (đã tốt, gọn hơn nút riêng dòng của Bài viết) — không cần đổi
ngược lại Bài viết vì đó là chi tiết nhỏ, không phải trọng tâm yêu cầu.
CẦN ĐỌC TRƯỚC KHI SỬA: renderVals đầy đủ của khối SEO Page Editor (đã có phần lớn, còn thiếu
`aiSeoKw()` definition, `aiSeoBtnStyle` full, và cách `d.metaTitle/metaDesc` khởi tạo) + renderVals
Bài viết đoạn `seoChecks`/`mockOpts('seo',...)`/`fKeyword` field definition (đã thấy 1 dòng check
ở "Bước ĐÃ SỬA" cũ: `mockOpts()` nhánh seo dùng `d.keyword`) — grep `fKeyword|seoChecks|onKeywordChange`
trong Bài viết để lấy đủ trước khi viết Page Editor's checklist tương tự.

## Cụm 4 — Thẻ Đăng / Trạng thái
Page Editor "Đăng" (dòng 374-427, ĐÃ ĐỌC ĐỦ): header (label + pill trạng thái) → "Trạng thái"
DROPDOWN (`onToggleStatus`/`statusMenuOpen`/`stItems`, 1 click mới thấy hết lựa chọn) → [nếu
scheduled: ô giờ] → "Hiển thị" (nhãn + icon+text + mô tả `visDesc`) → chia dòng → [nếu editMode:
Tác giả/Cập nhật/Lượt xem] → chia dòng → "Lịch sử phiên bản" dropdown (`onToggleRev`/`revItems`)
→ [addMode: ghi chú trang mới]. KHÔNG CÓ: banner việc-còn-thiếu-trước-khi-đăng, danh sách trạng
thái luôn hiện, ghi chú tự lưu/thời gian đọc ở thẻ này.
Bài viết "Đăng bài" (dòng ~657-694, ĐỌC MỘT PHẦN — cần đọc thêm phần đầu thẻ đã có: banner
`hasBlockers`/`blockerList`, `showWfSteps`/`wfSteps`, badge trạng thái hiện tại `curBadgeStyle`/
`curDotStyle`/`curStatusLabel`, DANH SÁCH LUÔN HIỆN `statusChoices` (mỗi item: dot+label+check khi
active+lock khi khoá, không cần mở dropdown), [showSched: ô giờ], rồi 3 dòng ghi chú
`savedNote`/`autoNote`+`autoIcon`+`autoColor`/`readTime`. KHÔNG CÓ trong thẻ này: Hiển thị/Tác
giả/Lượt xem/Lịch sử phiên bản (có thể ở thẻ khác của Bài viết, chưa kiểm — ĐỪNG suy diễn là thiếu).
QUYẾT (hợp):
1. Port banner "Cần xong mấy việc này mới đăng được" sang Page Editor — Page Editor đã có validation
   rời rạc (`viTitleEmpty` chặn nút Đăng) nhưng KHÔNG hiện banner tổng hợp trước. Thêm 1 method
   `pageBlockers()` liệt kê thiếu gì (tối thiểu: chưa có tiêu đề bản gốc — đã có logic ở `publish()`,
   chỉ cần trình bày lại thành banner giống Bài viết, KHÔNG bịa thêm điều kiện Page Editor chưa có).
2. Đổi "Trạng thái" từ dropdown sang DANH SÁCH LUÔN HIỆN như Bài viết (đúng CLAUDE §0 "thao tác ít
   nhất" — 0 click thấy hết lựa chọn thay vì 1 click mở dropdown). Tái dùng đúng `stItems` đã có
   sẵn (label/dotStyle/active/locked) — chỉ đổi PHẦN TEMPLATE render từ dropdown-button+menu thành
   list luôn hiện, KHÔNG đổi renderVals logic (đã đúng), xoá `onToggleStatus`/`statusMenuOpen`/
   `statusArrow`/`statusBtnStyle` nếu không còn nơi dùng khác (grep trước khi xoá).
3. Thêm 3 dòng ghi chú cuối thẻ (đã lưu lần nào / tự lưu / thời gian đọc) — Page Editor đã có state
   tương đương (`S.savedAt`/`S.autoSavedAt`/`S.dirty`, xem `savedText`/`savedDot` local const đã
   thấy ở method render trước đó) — TÁI DÙNG, không viết lại logic, chỉ thêm khối template hiện
   `savedText` (đã có biến, cần grep xem đã có key trả về renderVals chưa, có thể đã có
   `savedNote`-tương-đương tên khác) + đọc thời gian ước tính (Page Editor có tính "Đọc: N phút"
   không? Nếu chưa có, TÍNH TỪ `bodyWords()` giống Bài viết `readTime`, KHÔNG bịa số).
   Giữ nguyên Hiển thị/Tác giả/Lượt xem/Lịch sử phiên bản của Page Editor (Bài viết không có trong
   thẻ này nhưng đó là điểm mạnh riêng, không bỏ).
CẦN ĐỌC TRƯỚC KHI SỬA: đọc Bài viết dòng ~583-660 phần ĐẦU thẻ (banner/wfSteps/statusChoices) lấy
tên biến chính xác (đã đọc phần GIỮA/CUỐI ở lượt trước — offset ~656-694 trong lần đọc cũ, số dòng
hiện tại có thể lệch vì Bài viết đã chỉnh sửa nhiều lần, PHẢI grep lại tên biến, đừng dùng số dòng
cũ). Đọc Page Editor quanh method `render()`/renderVals chỗ tính `savedText`/`savedDot` để lấy tên
biến chính xác đưa vào renderVals nếu chưa có key public.

## Thứ tự làm (mỗi cụm xong thì ready_for_verification, đừng dồn hết rồi mới verify)
1. Cụm 1 (nhỏ, rõ, ít rủi ro) — làm trước.
2. Cụm 2 (cần grep hạn ngạch ảnh Page Editor trước).
3. Cụm 4 (đổi dropdown→list là template-only, rủi ro thấp; banner+ghi chú cần đọc thêm).
4. Cụm 3 (lớn nhất, cần thêm state từ khoá SEO mới — làm sau cùng khi đã quen nhịp 2 file).

Sau mỗi cụm: `ready_for_verification` cả 2 file liên quan, sửa lỗi verifier báo rồi mới sang cụm sau.
