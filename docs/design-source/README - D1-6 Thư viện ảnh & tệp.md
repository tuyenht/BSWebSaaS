# D1-6 · Thư viện ảnh & tệp — README handoff

## Tổng quan
Trang quản lý đầy đủ ảnh & tệp của một website: dung lượng đã dùng, thư mục, lưới / danh sách, lọc, chọn nhiều, panel chi tiết (mô tả ảnh theo ngôn ngữ, địa chỉ tệp, nơi đang dùng, thay tệp), Thùng rác 15 ngày. Nút "Tải ảnh lên" mở `MediaPicker` thẳng tab Tải lên › Thiết bị (không vẽ lại 9 nguồn). Ngoại lệ của mẫu 1: lưới ảnh, không ép theo bảng Bài viết.

## File
- `Thư viện ảnh & tệp.dc.html` — mới. Link `admin-base.css` → `admin-components.css` → token DS → Google Inter URL chuẩn.
- `MediaPicker.dc.html` — sửa: prop `startSource="device"`, `uploadDemo`, `dropFiles`, `onUploaded`; danh sách tải lên có lỗi từng tệp; nút "Lọc" ở điện thoại; sửa kèm (xem dưới).
- `shell-screens.js` (mục `media` → `kind: 'mount'`, ready) · `Admin Shell.dc.html` (khe `mMedia` + `onEmbedDirtyMd`) · `SaaS Starter - Bản đồ màn hình.md` dòng 22 → ready (dòng 23 giữ) · `TODO.md` · `LESSONS.md`.

## Mức hoàn thiện
Hi-fi tương tác (prototype). Dữ liệu mẫu 24 tệp (21 đang có + 3 trong Thùng rác), 5 thư mục (1 thư mục trống), 1 ảnh đang dùng ở 3 nơi.

## Màn & trạng thái (Tweak "Trạng thái màn", "Nhóm quyền", "Dung lượng", "Module Thư viện bật")
| Trạng thái | Cách xem |
|---|---|
| Có dữ liệu | mặc định |
| Thư viện trống — "Tải ảnh đầu tiên" + kéo thả | Trạng thái màn = empty |
| Thư mục trống | bấm "Ảnh sự kiện 2026" |
| Không khớp lọc — "Bỏ lọc" | Lọc PDF + Đang dùng ở thư mục Sản phẩm |
| Đang tải (khung xương 8 ô) | loading |
| Lỗi + "Thử lại" | error |
| Không có quyền | noperm |
| Chỉ xem (không nút tải / xóa / ô chọn) | Nhóm quyền = Chỉ xem hoặc Cộng tác viên |
| Khóa theo gói | Module Thư viện bật = tắt (Lời mời TẮT: "Chưa bật cho website này — liên hệ hỗ trợ"; BẬT: lợi ích + "Xem gói nâng cấp") |
| Sắp đầy (≥ 80%, vàng + chữ) | Dung lượng = warn (412/500, mặc định) |
| Hết dung lượng — "Tải ảnh lên" bị chặn + "Dọn Thùng rác" + "Liên hệ hỗ trợ"/"Xem gói nâng cấp" | Dung lượng = full |
| Thùng rác trống + "Tự xóa vĩnh viễn sau 15 ngày" | Thùng rác › Dọn sạch Thùng rác |

## Tương tác
- Tải lên: nút chính (đầu trang, `#h-dz`) hoặc kéo thả tệp vào trang ở máy tính (lớp phủ "Thả tệp vào đây…"); điện thoại dùng nút. Tệp thả vào được chuyển cho MediaPicker (`dropFiles`). Tệp xong → vào thư mục đang mở (`onUploaded`).
- Thư mục (`#h-tr`): Tất cả ảnh · thư mục · Chưa phân loại · Thùng rác (có số). Tạo (Enter), đổi tên tại chỗ, Chuyển lên/xuống, Xóa — thư mục trống xóa ngay + Hoàn tác; thư mục có tệp hỏi "Xóa thư mục và đưa N tệp về Tất cả ảnh". Khung chứa < 768px: ô chọn "Thư mục" + nút ⋯ mở bảng trượt đáy.
- Lưới / danh sách (sx-seg), ô tìm (phím tắt `/`), "Lọc" (Loại tệp · Định dạng · Tình trạng, có số; chân "Bỏ lọc · Xong · Khớp N"), dòng "Đang lọc: chip × … Bỏ lọc · Khớp N / M", sắp xếp gồm "Thứ tự thủ công" (kéo thả ở máy tính + nút Lên/Xuống ở mọi thiết bị — Chờ backend). 390 = lưới 2 cột.
- Chọn nhiều → "Di chuyển (N)" (`media.upload`) · "Chuyển vào Thùng rác (N)" (`media.delete`); trong Thùng rác "Khôi phục (N)" · "Xóa vĩnh viễn (N)".
- Panel chi tiết (`#h-dr`, trượt phải; < 520px toàn màn): xem trước đúng tỷ lệ gốc (ngang/dọc/vuông, không cắt); thanh ngôn ngữ (pill ≤ 3, từ 4 ngôn ngữ: nguồn + đang sửa + "+N ⌄" có ô tìm từ 6); "Dịch sang N ngôn ngữ"/"Dịch bản X" khi website được cấp AI (chi phí tính trước, chỉ điền ô trống); Mô tả ảnh (length-field 188) + câu "Mô tả ảnh giúp Google và người khiếm thị hiểu ảnh — đổi ở đây là đổi cho mọi nơi dùng ảnh này"; Tiêu đề ảnh; Địa chỉ tệp + "Sao chép"; kích thước, dung lượng, định dạng, thư mục, người tải, ngày tải; "Đang dùng ở N nơi" theo nhóm Sản phẩm / Ảnh đại diện / Trong nội dung (bấm → `bs-goto` với mã công khai: `catalog.list {code}`, `<loại>.edit {slug}`) + "Ảnh chèn thẳng trong thân bài chưa được đếm"; "Thay tệp…" (hỏi: "Địa chỉ tệp sẽ đổi — chỗ nào ngoài website đang dùng địa chỉ cũ cần dán lại"). Menu ⋯: Tải xuống · Di chuyển sang thư mục… · ─ · Chuyển vào Thùng rác (đỏ, cuối).
- Chuyển vào Thùng rác: ảnh chưa dùng → làm ngay + toast "Hoàn tác" 8 giây; ảnh đang dùng → hỏi "Ảnh đang dùng ở 3 nơi — chuyển vào Thùng rác thì 3 nơi đó mất ảnh trên website" (Hủy được focus). Xóa vĩnh viễn / Dọn sạch → hỏi, nêu số tệp + MB.
- Hợp đồng nhúng: `embedded` ẩn h1 + mô tả, giữ nút "Tải ảnh lên" + thanh dung lượng; `onDirty(0|1)` theo panel chi tiết; `bs-embed-cmd {id:'media', act:'save'}` lưu mô tả; chặn `beforeunload` khi chưa lưu; đóng panel khi còn sửa → hỏi "Đóng mà chưa lưu?".
- Bàn phím: Esc đóng lớp trên cùng; Tab giữ trong hộp/panel; đóng trả focus về nút đã mở.

## Ánh xạ dữ liệu (repo `tuyenht/BSWebSaaS@main`)
- Trang `/admin/media` = `app/Modules/Admin/Livewire/Media/Library.php` + `Concerns/WithMediaLibraryPicker.php` (nguồn máy ảnh + đám mây chưa có: `WithMediaLibraryPicker.php:405`).
- `media` (`app/Modules/Media/Models/Media.php`): `ulid` (HasUlidColumn) · `folder_id` · `alt`/`title` JSONB theo ngôn ngữ (dòng 30–31, 74–75) · `ALT_MAX_LENGTH = 188` (dòng 44) · soft delete. `mediables.sort_order` (`Mediable.php:21`).
- `media_folders` (`MediaFolder.php:13`): 1 cấp, FK SET NULL → tệp về "Tất cả ảnh".
- Tệp nhận: `app/Modules/Media/Actions/UploadMedia.php` — `MAX_KB = 10240`, `MIME_EXT` jpg/png/webp/gif/pdf kiểm bằng magic bytes; SVG loại khỏi GĐ1.
- Thùng rác: `config/media.php` `trash_retention_days` = 15, `media:purge-trash` hằng ngày.
- Lỗi: `app/Modules/Media/Exceptions/MediaRejectedException.php` `REASONS` (16 mã) + `KEYS` (unsupported_type → `media.err_mimes`, too_large → `media.err_max`, url_invalid → `media.picker_url_invalid`); câu ở `lang/{vi,en,ja,zh}.json:345–361, 429`.
- Nơi dùng: `app/Modules/Admin/Support/MediaUsageLocator.php`.
- Quyền: `content-workflow.js` (bám `PermissionRegistry.php`).

## Quyền & gói (ai thấy gì)
| Nhóm quyền | Xem | Tải lên · sửa mô tả · thư mục · di chuyển | Thùng rác · Khôi phục · Xóa vĩnh viễn |
|---|---|---|---|
| Chủ website · Quản trị · Biên tập | ✓ | ✓ | ✓ |
| Người viết | ✓ | ✓ | — (không có nút "Chuyển vào Thùng rác") |
| Cộng tác viên · Chỉ xem | ✓ | — (không nút tải, ô chọn khóa) | — |
Dung lượng theo gói `media.storage_mb` (mặc định 500 MB, `ai-quota.js` FEATURES) — chưa áp ở backend. AI Dịch theo §7: Tweak "Website được cấp AI"; tắt + Lời mời TẮT → ẩn hẳn; tắt + Lời mời BẬT → "Dịch — Xem gói nâng cấp".

## Token
`--color-body · surface · surface-muted · surface-hover · border · border-strong · border-input · heading · body-text · body-muted · accent · admin-primary · admin-{success,warning,danger,info}(-text) · brand-gold(-text) · white · placeholder · focus-ring-color`. Không hex ngoài `var(…)`. MediaPicker: màu nhận diện nguồn bên thứ 3 đặt sau token riêng `--mp-c-{dropbox,gdrive,gphotos,onedrive,unsplash,box,url,audio}`, nền máy ảnh `--mp-cam-*`.

## Icon (Remix 4.9.1)
upload-2 · upload-cloud-2 · folder-3 · folder-open · folder-add · folder-reduce · folder-transfer · stack · inbox · delete-bin · delete-bin-2 · arrow-go-back · filter-3 · filter-off · search · grid · list-check · more-2-fill · close · file-pdf-2 · links · subtract · time · translate-2 · sparkling-2 · star-fill · file-copy · check · download-2 · image-edit · image-add · image · hard-drive-2 · alert · error-warning(-fill) · wifi-off · lock-2 · vip-crown · customer-service-2 · shopping-bag-3 · file-text · refresh · loader-4 · save-3 · eye · information · arrow-up · arrow-down · arrow-right-s · settings-3 · close-circle · checkbox-circle(-fill).

## Chuỗi cần dịch
### Lỗi tải lên (MediaPicker `Component.ERR()` — thay câu repo `media.err_*`)
| Mã | vi | en | ja | zh |
|---|---|---|---|---|
| unsupported_type (`media.err_mimes`) | Định dạng này chưa nhận được — chỉ nhận ảnh JPG, PNG, WebP, GIF hoặc tệp PDF. Hãy lưu lại sang JPG hoặc PNG rồi tải lên. | This file type isn't accepted — only JPG, PNG, WebP, GIF images or PDF files. Save it as JPG or PNG and upload again. | この形式は使えません。JPG・PNG・WebP・GIF 画像または PDF のみです。JPG か PNG で保存し直してからアップロードしてください。 | 暂不支持此格式，仅支持 JPG、PNG、WebP、GIF 图片或 PDF 文件。请另存为 JPG 或 PNG 后重新上传。 |
| (SVG, mới) | Ảnh SVG chưa nhận được — chỉ nhận JPG, PNG, WebP, GIF hoặc PDF. Hãy xuất logo sang PNG rồi tải lên. | SVG images aren't accepted yet — only JPG, PNG, WebP, GIF or PDF. Export the logo as PNG and upload it. | SVG 画像はまだ使えません。ロゴを PNG で書き出してアップロードしてください。 | 暂不支持 SVG 图片。请将标志导出为 PNG 后上传。 |
| too_large (`media.err_max`) | Tệp nặng {size}, quá mức 10 MB. Hãy nén ảnh hoặc giảm kích thước rồi tải lên lại. | This file is {size}, over the 10 MB limit. Compress or resize it and upload again. | ファイルが {size} あり、上限 10 MB を超えています。圧縮または縮小してからアップロードしてください。 | 文件大小为 {size}，超过 10 MB 上限。请压缩或缩小后重新上传。 |
| image_too_large | Ảnh lớn hơn 10 MB. Hãy giảm kích thước ảnh (ví dụ còn 2000 điểm ảnh chiều ngang) rồi tải lên lại. | The image is over 10 MB. Make it smaller (for example 2000 pixels wide) and upload again. | 画像が 10 MB を超えています。横 2000 ピクセル程度に縮小してからアップロードしてください。 | 图片超过 10 MB。请缩小（例如宽 2000 像素）后重新上传。 |
| file_invalid | Tệp bị hỏng hoặc chưa lưu trọn nên không mở được. Hãy mở thử tệp trên máy, lưu lại rồi tải lên lần nữa. | The file is damaged or incomplete. Open it on your device, save it again and upload once more. | ファイルが壊れているか保存が不完全です。端末で開いて保存し直し、もう一度アップロードしてください。 | 文件已损坏或未完整保存。请在设备上打开并重新保存后再上传。 |
| upload_session_invalid | Lần tải này để quá lâu nên đã dừng. Bấm Thử lại để tải tệp lên lần nữa. | This upload took too long and stopped. Select Try again to upload the file again. | アップロードに時間がかかりすぎたため停止しました。「再試行」を押してください。 | 上传时间过长已停止。请点击"重试"重新上传。 |
| upload_missing | Tệp chưa tới được máy chủ, có thể do mạng chập chờn. Bấm Thử lại khi mạng ổn. | The file didn't reach us, possibly due to a weak connection. Select Try again when you're back online. | ファイルが届きませんでした。接続が安定したら「再試行」を押してください。 | 文件未能送达，可能是网络不稳定。网络恢复后请点击"重试"。 |
| storage_failed | Chưa lưu được tệp — không phải lỗi của bạn. Bấm Thử lại sau ít phút. | We couldn't save the file — it's not your fault. Try again in a few minutes. | ファイルを保存できませんでした（お客様の操作の問題ではありません）。数分後に再試行してください。 | 文件暂未保存成功（并非您的问题）。请几分钟后重试。 |
| url_invalid (`media.picker_url_invalid`) | Địa chỉ ảnh chưa đúng. Dán địa chỉ bắt đầu bằng https:// và kết thúc bằng .jpg, .png, .webp hoặc .gif. | That image address isn't right. Paste one starting with https:// and ending in .jpg, .png, .webp or .gif. | 画像のアドレスが正しくありません。https:// で始まり .jpg・.png・.webp・.gif で終わるアドレスを貼り付けてください。 | 图片地址不正确。请粘贴以 https:// 开头、以 .jpg、.png、.webp 或 .gif 结尾的地址。 |
| host_unresolvable | Không tìm thấy trang web của địa chỉ này. Kiểm tra lại chính tả địa chỉ. | We can't find the website for this address. Check the spelling. | このアドレスのサイトが見つかりません。綴りを確認してください。 | 找不到该地址对应的网站，请检查拼写。 |
| private_address | Địa chỉ này chỉ mở được trong mạng riêng của bạn. Hãy tải ảnh về máy rồi tải lên từ máy. | This address only opens inside your own network. Download the image, then upload it from your device. | このアドレスは社内ネットワークでしか開けません。画像を保存して端末からアップロードしてください。 | 该地址只能在您的内部网络中打开。请先下载图片，再从设备上传。 |
| redirect_invalid | Địa chỉ này dẫn sang một trang không phải ảnh. Mở ảnh trên trình duyệt, chép đúng địa chỉ của ảnh rồi dán lại. | This address leads to a page that isn't an image. Open the image in your browser, copy its exact address and paste it again. | このアドレスは画像ではないページに移動します。ブラウザで画像を開き、画像のアドレスをコピーして貼り直してください。 | 该地址跳转到的不是图片。请在浏览器中打开图片，复制图片本身的地址后重新粘贴。 |
| too_many_redirects | Địa chỉ này chuyển qua quá nhiều trang. Mở ảnh trên trình duyệt, chép địa chỉ cuối cùng rồi dán lại. | This address goes through too many pages. Open the image in your browser, copy the final address and paste it again. | 転送が多すぎます。ブラウザで画像を開き、最後のアドレスをコピーして貼り直してください。 | 该地址跳转次数过多。请在浏览器中打开图片，复制最终地址后重新粘贴。 |
| fetch_failed (bỏ "HTTP :status") | Trang chứa ảnh không cho lấy ảnh này. Hãy tải ảnh về máy rồi tải lên từ máy. | The site hosting this image won't let us fetch it. Download the image, then upload it from your device. | 画像のあるサイトが取得を許可していません。画像を保存して端末からアップロードしてください。 | 图片所在网站不允许获取此图片。请先下载，再从设备上传。 |
| fetch_unreachable | Chưa mở được địa chỉ này. Kiểm tra lại địa chỉ hoặc thử lại sau ít phút. | We couldn't open this address. Check it or try again in a few minutes. | このアドレスを開けませんでした。確認するか数分後にお試しください。 | 暂时无法打开该地址。请检查或几分钟后重试。 |
| empty_response | Địa chỉ này không có ảnh nào. Kiểm tra lại địa chỉ ảnh. | There's no image at this address. Check the image address. | このアドレスに画像がありません。アドレスを確認してください。 | 该地址没有图片，请检查图片地址。 |
| not_an_image | Địa chỉ này không phải ảnh JPG, PNG, WebP hoặc GIF. Chép đúng địa chỉ của ảnh rồi dán lại. | This address isn't a JPG, PNG, WebP or GIF image. Copy the image's exact address and paste it again. | このアドレスは JPG・PNG・WebP・GIF 画像ではありません。画像のアドレスをコピーして貼り直してください。 | 该地址不是 JPG、PNG、WebP 或 GIF 图片。请复制图片本身的地址后重新粘贴。 |
| unexpected (`media.err_unexpected`, câu chung duy nhất) | Chưa tải được tệp này. Bấm Thử lại; nếu vẫn chưa được, hãy liên hệ hỗ trợ. | We couldn't upload this file. Try again; if it still fails, contact support. | アップロードできませんでした。再試行し、それでもだめならサポートにご連絡ください。 | 文件暂未上传成功。请重试；如仍失败，请联系客服。 |
"Thử lại" hiện cạnh tệp với upload_session_invalid · upload_missing · storage_failed · fetch_unreachable · unexpected.

### Câu kỹ thuật cần đổi trong repo
| Khóa | Hiện tại | Đề xuất (vi) |
|---|---|---|
| `media.err_fetch_failed` | Không tải được ảnh từ liên kết (HTTP :status). | (bảng trên — bỏ mã HTTP) |
| `media.err_private_address` | …địa chỉ nội bộ — không được phép. | (bảng trên) |
| `media.err_file_invalid` | Tệp không hợp lệ. | (bảng trên) |
| nhãn ô mô tả | Văn bản thay thế (ALT) | Mô tả ảnh |
| placeholder mô tả | Mô tả ảnh cho SEO & trợ năng… | Máy phay CNC đứng trong xưởng, nhìn từ phía trước (chỉ ví dụ) + dòng gợi ý dưới ô |
| `media.picker_role_cover/gallery/content` | 🖼 Ảnh đại diện · 🗂 Bộ ảnh sản phẩm · 📝 Trong nội dung | Ảnh đại diện · Bộ ảnh sản phẩm · Trong nội dung (bỏ emoji, dùng icon) |

### Chuỗi màn mới (vi; EN/JA/ZH theo cùng khóa `media.*` khi build)
Thư viện ảnh & tệp · Tải ảnh lên · Đã dùng {used} / {limit} · Sắp đầy — còn {n} MB. Dọn Thùng rác để có thêm chỗ. · Đã hết dung lượng — chưa tải thêm được. · Dọn Thùng rác · Thư mục · Tất cả ảnh · Chưa phân loại · Thùng rác · Thư mục mới · Tạo thư mục · Đổi tên · Lưu tên · Xóa thư mục · Xóa thư mục và đưa {n} tệp về Tất cả ảnh · Lọc · Lọc và sắp xếp · Loại tệp · Định dạng · Tình trạng · Đang dùng · Chưa dùng · Đang lọc: · Bỏ lọc · Khớp {n} / {m} · Mới nhất · Cũ nhất · Tên A → Z · Dung lượng lớn → nhỏ · Thứ tự thủ công · Lưới · Danh sách · Đã chọn {n} · Di chuyển ({n}) · Chuyển vào Thùng rác ({n}) · Khôi phục ({n}) · Xóa vĩnh viễn ({n}) · Bỏ chọn · Dùng ở {n} nơi · Còn {n} ngày · Tự xóa vĩnh viễn sau 15 ngày. · Dọn sạch Thùng rác · Chi tiết ảnh · Mô tả ảnh · Tiêu đề ảnh · Địa chỉ tệp · Sao chép · Đã sao chép · Đang dùng ở {n} nơi · Ảnh chèn thẳng trong thân bài chưa được đếm. · Thay tệp… · Chọn tệp thay thế · Ảnh đang dùng ở {n} nơi — chuyển vào Thùng rác thì {n} nơi đó mất ảnh trên website · Đã chuyển {n} tệp vào Thùng rác · Hoàn tác · Thả tệp vào đây để tải lên · Ẩn tệp đã xong · Chưa tải được · Xong.

## Kết quả 12 tiêu chí dễ dùng
1. Đạt — h1 + 1 dòng mô tả + 1 nút chính "Tải ảnh lên".
2. Đạt — menu › Thư viện › Tải ảnh lên › Chọn tệp (3 bước).
3. Đạt — không còn "ALT", "slug", "OAuth", "HTTP"; mô tả ảnh có câu giải thích.
4. Đạt — icon kèm chữ; nút chỉ icon (⋯, ×) có `aria-label` + `title`.
5. Đạt — placeholder là ví dụ ("may-phay-cnc", "Ảnh công trình"); lỗi tải lên nói cách sửa.
6. Đạt — Thùng rác + Hoàn tác 8 giây; hỏi khi ảnh đang dùng / xóa vĩnh viễn / xóa thư mục có tệp, nêu số; chặn rời trang khi chưa lưu mô tả.
7. Đạt — dung lượng: màu + icon + chữ; chip nơi dùng có icon + chữ; chấm ngôn ngữ kèm tooltip.
8. Đạt theo code — nút/chip/tab `white-space:nowrap`; tên tệp cắt "…" giữ đuôi + `title`. [CHƯA KIỂM ĐƯỢC bằng số đo trên 5 bề rộng.]
9. Đạt theo code — 390: lưới 2 cột, ô chọn thư mục, panel toàn màn, vùng bấm 44px khi cảm ứng.
10. Đạt — người thạo: chọn nhiều, phím `/`, lọc nhiều điều kiện, thứ tự thủ công.
11. Đạt theo code — `--fsp` cho mọi cỡ chữ (Tweak "Chữ lớn" hoặc `bs-ui`). [CHƯA KIỂM ĐƯỢC bằng harness.]
12. Đạt — Thêm/Lưu/Hủy/Bỏ lọc/Tải lên/Xóa theo bảng thuật ngữ; "Xóa · Hủy" kiểu dấu cũ.

## Kết quả Chất lượng hoàn thiện (A–E)
- A. Đạt theo code — thang 4/8px, cỡ chữ của DS, 1 nút chính mỗi vùng; khung xương hiện ngay; giữ chỗ ảnh bằng `aspect-ratio`; `prefers-reduced-motion` tắt chuyển động.
- B. Đạt theo code — chữ ≥ 13–14px, chú thích 12px, giãn dòng 1,5; kéo thả luôn có nút thay (Tải ảnh lên, Lên/Xuống); "Đang lưu…", chặn bấm 2 lần khi lưu.
- C. Đạt theo code — 1 h1 (ẩn khi nhúng); hộp thoại có tên, Esc, giữ Tab, trả focus; toast + bộ đếm có `aria-live`; ô nhập có nhãn thật.
- D. [CHƯA KIỂM ĐƯỢC] tương phản tối bằng số đo — chỉ dùng token; Safari/Firefox chưa mở.
- E. [CHƯA KIỂM ĐƯỢC] harness — môi trường thiết kế không chạy được phép đo trong lượt này; Claude Code đo bằng zip. Đo được: drawer 505/505 (scrollWidth/clientWidth), MediaPicker vùng cuộn 869/869; console tệp mới + MediaPicker + Hạn ngạch + Cài đặt xuất bản: 0 lỗi; Admin Shell: 0 lỗi (chỉ cảnh báo `{{ r.* }}` có sẵn từ trước).

## Kiểm hiển thị
| Bề rộng | Chrome (môi trường thiết kế) | Safari iOS · Firefox · Samsung |
|---|---|---|
| 1440 / 1240 / 1024 | Đạt bằng mắt (2 cột: thư mục + lưới 3–5 ô) | [CHƯA KIỂM ĐƯỢC] |
| 768 | Đạt theo code (container query `tv` ≤ 767 → ô chọn thư mục) | [CHƯA KIỂM ĐƯỢC] |
| 390 / 360 | Đạt bằng mắt ở 390 (đặt bề rộng khung gốc 390px): lưới 2 cột, nút chính rộng hết | [CHƯA KIỂM ĐƯỢC] |
| 844 × 390 xoay ngang | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] |
| Trong Shell 390 | [CHƯA KIỂM ĐƯỢC] — Shell không chụp được trong môi trường thiết kế (quá 7 giây); đã thử nhúng riêng (embedded, Người viết): đúng | |

## Sửa kèm
### Từ kiểm zip D0-6c
- (a) `Hạn ngạch AI và mua thêm.dc.html` + `Cài đặt xuất bản tự động.dc.html`: trả 2 câu `return` của hàm con về như cũ (`book()` · `cell('always')`), `s6Frames` là thuộc tính đầu của object trả về chính. Console 0 "logic class eval FAILED". Thêm `&& !this.props.embedded` vào `s6Frames` — khi logic chạy lại, Hạn ngạch nhúng trong Shell vẽ 2 iframe tự trỏ (Shell có hash `#/site/…`, không phải `#frame`).
- (b) `Quy trình duyệt.dc.html`: luật hẹp `.qd-h .qd-t{flex:1 1 100%}` chỉ áp đầu thẻ (`.qd-card > .qd-h`); đầu hộp "Đổi quy trình" một hàng: icon + tiêu đề (tối đa 2 dòng) + × 44×44px · "toà soạn" → "tòa soạn" ở `content-workflow.js`, `Trạng thái duyệt (khối soạn thảo).dc.html`, `README - Quy trình duyệt nội dung.md` (grep cả project trừ `_audit/`, `uploads/`: 0) · xóa `_tmp/` (57 tệp) · `LESSONS.md` dòng `.sx-dot` → "D0-6a chọn token màu gốc vì DS: chấm trạng thái giữ màu gốc".
### Từ kiểm zip D0-6b (MediaPicker)
- (a) nền ô tệp không phải ảnh: `color-mix(in srgb, <màu> 8%, transparent)` ở lưới và khung Chi tiết (grep `') + '14'`: 0); `tintBg` đám mây cũng đổi sang `color-mix`.
- (b) khung < 600px, tab Thư viện: ô tìm + nút "Lọc" (đếm điều kiện) + nút kiểu xem trên 1 hàng; sắp xếp / loại tệp / định dạng vào bảng trượt đáy "Lọc ảnh" (chân "Bỏ lọc · Xong · N ảnh", Esc đóng). Lưới ảnh còn ~490px ở 390 × 844 (≈ 2,7 hàng).
- Thêm: nhãn "Mô tả ảnh" (bỏ "Văn bản thay thế (ALT)"), placeholder là ví dụ, `maxlength=188`; "Sửa slug" → "Sửa đường dẫn"; bỏ emoji ở tác giả Unsplash; "Kết nối an toàn qua OAuth" → câu đời thường; 37 mã hex ngoài `var(…)` → token.

## Việc cho backend
| Tính năng | Dữ liệu cần lưu | Luật |
|---|---|---|
| Dung lượng theo gói | tổng `media.size` theo site (gồm Thùng rác) | `media.storage_mb` của gói; ≥ 100% chặn `UploadMedia`; Thùng rác tính vào dung lượng tới khi xóa vĩnh viễn |
| Mô tả + tiêu đề theo từng ngôn ngữ | `media.alt` / `media.title` JSONB (cột có) | mỗi bản ≤ 188 ký tự; hiện repo chỉ sửa bản ngôn ngữ mặc định |
| Đổi tên · sắp xếp · xóa thư mục | `media_folders.name`, `sort_order` | xóa → FK SET NULL (đã có); tên không trùng trong site |
| Thứ tự thủ công trong thư viện | thứ tự tệp trong thư mục (cột có, chưa build) | chỉ khi sắp "Thứ tự thủ công" |
| Thay tệp | giữ `media.id`, đổi `hash` + `storage_key` | địa chỉ mới (ADR-17); mọi `mediables` giữ nguyên; giữ mô tả |
| Đếm ảnh chèn thẳng trong thân bài | quét `entries.body` | bổ sung vào `MediaUsageLocator` |
| Máy ảnh | — | chỉ cần quyền camera của trình duyệt (`getUserMedia`), ảnh chụp đi qua `UploadMedia` |
| 6 nguồn đám mây | token OAuth theo người dùng | OAuth + Companion theo `docs/design_handoff_media_picker/OAUTH-SETUP.md` |
| SVG | — | **Cần owner chốt**: cần bộ làm sạch SVG (nguy cơ chèn mã độc); tới khi chốt vẫn báo "Ảnh SVG chưa nhận được" |
| Quyền thư mục | — | **Cần owner chốt**: đổi tên/xóa thư mục dùng `media.upload` (như tạo) hay quyền riêng — thiết kế đang dùng `media.upload` |

## Sửa theo owner 07/10
1. Giữ 2 tab "Tải lên tệp mới" · "Thư viện ảnh (N)" như bản đang có; bỏ nút "Tải ảnh lên" rời ở góc phải. Tab Tải lên nhúng `MediaPicker` (`bare`, không đầu/chân riêng), ghi rõ "Tệp tải lên sẽ vào: <thư mục>".
2. Giữ đủ 3 ô chọn cạnh ô tìm: "Mới nhất / sắp xếp" · "Tất cả loại tệp" · "Mọi định dạng" (bỏ nút "Lọc" gộp). Dòng "Đang lọc … Bỏ lọc · Khớp N / M" giữ nguyên.
3. Thuộc tính cho màn gọi: `tabs` = `both` (mặc định) · `upload` (chỉ tab Tải lên) · `library` (chỉ tab Thư viện) — chỉ 1 tab thì ẩn thanh tab; `startTab` = `library` (mặc định) · `upload` chọn tab mở sẵn. `tabs="upload"` mà nhóm quyền không tải lên được → thẻ "không có quyền".
4. Panel "Chi tiết ảnh" mở thì khóa cuộn trang ngoài (html/body + khung cuộn của Shell), chỉ còn 1 thanh cuộn trong panel; đóng thì trả lại.
5. Sửa "Địa chỉ tệp" hiện mã hàm: `BSPLAT.rootDomain` là hàm, giờ gọi hàm để lấy tên miền.
6. Nút đổi kiểu xem chỉ còn icon (lưới · danh sách), tooltip + aria-label "Dạng lưới" / "Dạng danh sách".
7. Diện mạo lưới theo bản đang chạy (av1.baoson.net/admin/media) và gọn hơn: ô vuông lớn bo 8px, ảnh phủ kín ô (chỉ ảnh thu nhỏ; panel Chi tiết vẫn xem đúng tỷ lệ gốc), tên tệp + kích thước đè trên đáy ảnh (lớp phủ tối, chữ trắng), ô chọn tròn ở góc trên phải (đã chọn = nền thương hiệu + dấu tích + viền ô), chip "Dùng ở N nơi / Chưa dùng / Còn N ngày" góc trên trái. Thanh công cụ: máy tính 1 hàng (tìm · 3 ô chọn · kiểu xem); hẹp: ô tìm 1 hàng, 3 ô chọn + kiểu xem lưới 2 cột. Cột Thư mục gọn hơn (hàng 38px).

## Gộp vào tệp chung
Không áp dụng — lượt chạy một mình, đã sửa thẳng `shell-screens.js`, Bản đồ, `TODO.md`, `LESSONS.md`.

## Verifier
(cập nhật sau khi verifier trả kết quả)

Lượt kế tiếp: **D1-7**.
