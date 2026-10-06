# BSWebSaaS — Quy ước biên tập nội dung đa ngôn ngữ

Áp dụng cho **mọi** màn hình có nội dung biên tập: Cài đặt (tất cả các mục), Trang, Sản phẩm,
Bài viết, Danh mục, Form, SEO, Menu, Nội dung động và các module về sau.
Không thiết kế lại — dùng lại đúng thành phần và quy tắc dưới đây.

## 0. Nguyên tắc nền tảng: gánh việc khó thay khách hàng (bắt buộc, ưu tiên cao nhất)
Nền tảng **làm nhiều hơn, làm lâu hơn, xử lý phức tạp hơn ở phía sau** để khách hàng
**làm ít hơn, nhanh hơn, dễ hơn** mà vẫn đạt hiệu quả tối đa. Với mọi tính năng — nhất là
kết nối/tích hợp bên thứ 3 — luôn hỏi: "làm sao để người dùng thao tác ít nhất?"
- **Tự động hoá tối đa**: tự phát hiện, tự điền, tự kiểm tra, tự làm mới, tự khắc phục.
  Ví dụ email: ứng dụng OAuth do nền tảng sở hữu (nút "Kết nối" một chạm, không bắt khách tạo
  app / dán Client ID·Secret·Redirect URI); tự nhận máy chủ SMTP·cổng·mã hoá theo địa chỉ email;
  tự thêm & tự kiểm tra SPF/DKIM/DMARC; tự gửi thư kiểm tra sau khi kết nối.
- **Chỉ hỏi thông tin không thể tự suy ra** (đăng nhập, mật khẩu ứng dụng). Mọi thông số kỹ thuật
  suy được thì tự điền và **giấu sau "nâng cao"**, chỉ mở khi thật sự cần.
- **Mặc định thông minh**: chọn sẵn con đường đơn giản/an toàn nhất, để nhánh phức tạp là tuỳ chọn.
- **Giấu thuật ngữ IT** khỏi màn hình chủ SME; khái niệm kỹ thuật chuyển thành hành động một chạm
  hoặc trạng thái tự cập nhật, không bắt người dùng tự chẩn đoán.
- Độ phức tạp là gánh nặng của nền tảng, không đẩy sang người dùng. Khi cân nhắc "thêm việc cho
  hệ thống" vs "thêm thao tác cho khách" → luôn chọn thêm việc cho hệ thống.

## 1. Mô hình trước, giao diện sau
Trước khi dựng UI mới cho một module, xác định: quy tắc nghiệp vụ → nguyên tắc UX →
máy trạng thái → quy tắc thích ứng → mô hình tương tác. UI phải bám mô hình, không có ngoại lệ trực quan.
Tham chiếu gốc: `Language Context - Design Spec.dc.html`, đối chiếu cho Cài đặt: `Cài đặt - Đề xuất giao diện.dc.html`.

## 2. Ngữ cảnh ngôn ngữ (không phải bộ chọn ngôn ngữ giao diện)
- Thanh ngôn ngữ đổi **bản nội dung đang sửa**, không đổi ngôn ngữ CMS. Đặt ngay dưới tiêu đề trang.
- **Chỉ hiển thị khi website bật ≥ 2 ngôn ngữ.** 1 ngôn ngữ: ẩn hoàn toàn thanh, ẩn chip mã ngôn ngữ, không khoá trường nào.
- Ba tập ngôn ngữ, **không hardcode**, đọc từ cấu hình website:
  `planLangs` (gói nhà cung cấp cấp) ⊇ `siteLangs` (admin bật để soạn nội dung) ⊇ `pubLangs` (hiển thị cho khách);
  `defLang` = ngôn ngữ mặc định, phải nằm trong cả hai tập sau và **admin đổi được**.
- Thanh ngôn ngữ chỉ liệt kê `siteLangs`; ngôn ngữ chưa `pubLangs` ghi rõ "chưa hiển thị ra ngoài".
- Ngôn ngữ nguồn = `defLang`, đánh dấu bằng **sao vàng nhỏ** kèm tooltip — không dùng chữ "Nguồn"/"Mặc định".
  Mọi logic i18n (khoá trường `@lg`, bản nguồn, phạm vi nút Dịch) bám `defLang`, không hardcode `vi`.
- Quản lý ngôn ngữ nằm ở **Cài đặt › Ngôn ngữ website**: mỗi ngôn ngữ trong gói một hàng với
  công tắc "Dùng khi soạn" + "Hiển thị cho khách" + sao "Mặc định"; mặc định luôn bật và luôn hiển thị
  (khoá hai công tắc, tooltip giải thích); tắt "Dùng khi soạn" thì tự bỏ khỏi `pubLangs`.
  Liên kết "Quản lý ngôn ngữ" trong panel phải mở đúng mục này, không dừng ở thông báo.
- **Ba hành động ngôn ngữ đều phải hỏi xác nhận** (hộp thoại, không phải toast), nêu rõ hệ quả:
  bật "Website" khi chưa bật "Quản trị" → "Bật cả hai"; tắt "Quản trị" của ngôn ngữ đang công khai →
  "Tắt và gỡ khỏi website"; đổi ngôn ngữ chính → nói rõ bản mới thành bản nguồn để dịch.
- **Tắt một ngôn ngữ không bao giờ xoá nội dung đã dịch** — chỉ ẩn; bật lại là có ngay. Câu xác nhận
  phải nói điều này để admin yên tâm. Đổi ngôn ngữ chính cũng không đụng tới nội dung của bản nào.
- **Không dùng cờ quốc gia** (cờ là quốc gia, không phải ngôn ngữ). Chỉ tên bản địa.
- Không có nhãn chữ "Đang sửa bản" — chỉ icon dịch (`ri-translate-2`) có tooltip.

## 3. Trạng thái bằng chấm tròn
Chấm 8px ở **đầu** tên ngôn ngữ, kèm nhãn chữ trong tooltip/panel (không bao giờ chỉ dùng màu):
- viền nét đứt xám — chưa có nội dung
  (bản nguồn luôn tính đủ theo nội dung mặc định, không hiển thị 0/N gây hiểu nhầm)
- xanh dương `#299cdb` — AI vừa dịch, chưa kiểm
- vàng `#f7b84b` — còn thiếu trường
- xanh lá `#0ab39c` — đủ nội dung / đang hiển thị
- đỏ `#f06548` — lỗi thời so với nguồn (so sánh theo **từng trường**, không theo cả trang)

## 4. Quy tắc thích ứng (đo thật, không đoán)
Đo bề rộng thật bằng ResizeObserver + kiểm tra tràn sau khi vẽ (`scrollWidth > clientWidth`) rồi bớt dần.
**Không bao giờ để pill bị cắt nửa chữ.**
- Vừa chỗ → dải pill inline, một hàng, không xuống dòng.
- Không vừa → giữ nguyên **ngôn ngữ nguồn + ngôn ngữ đang sửa**, phần còn lại dồn vào chip `+N ⌄`
  (cùng chiều cao/bo/viền như pill, nằm liền nhóm).
- Hẹp / mobile / > 10 ngôn ngữ → một ô chọn + panel.
- Panel: tiêu đề + tiến độ, mỗi hàng 2 dòng (tên + trạng thái) và số trường đã điền,
  **tự hiện ô tìm kiếm từ 6 ngôn ngữ trở lên** (lọc theo tên bản địa và mã ISO),
  chân panel có "Dịch N bản còn thiếu" + "Quản lý ngôn ngữ".
- Bố cục thanh: **trái** = ngôn ngữ; **phải** = tiến độ + hạn ngạch AI + nút Dịch, ngăn bằng kẻ dọc mảnh.

## 5. Trường theo ngôn ngữ vs dùng chung
- Trường đa ngữ: đánh dấu `i18n: true`, nhãn mang **chip mã ngôn ngữ ở cuối** (VI/EN/JA).
  Mọi nội dung chữ hiển thị ra ngoài đều phải là đa ngữ (tên, khẩu hiệu, mô tả, địa chỉ,
  giờ làm việc, thông báo, tiêu đề SEO, meta description…).
- Trường dùng chung (mã số thuế, điện thoại, email, tên miền, link mạng xã hội, khoá API, màu, phông, ảnh):
  khi đang sửa bản khác nguồn thì **khoá, làm mờ, chip 🔒 Chung**, sửa ở bản nguồn.

## 6. Cài đặt: không có bước duyệt
Admin của site tự cập nhật → vòng đời rút gọn **Trống → Dịch máy → Đã lưu**.
Nút chính là "Lưu — hiệu lực ngay". Không có Nháp/Đăng, **không có** nút "Xác nhận bản dịch" hay "Dịch lại"
riêng — chỉ **một nút Dịch**, chưa ưng thì bấm lại. (Trang/Sản phẩm/Bài viết vẫn giữ bước soát trước khi đăng.)

## 7. Hạn ngạch AI (mọi tác vụ AI: dịch, viết bài, sinh nội dung)
Tác vụ AI tốn chi phí → có hạn ngạch, mặc định cấp một gói miễn phí giới hạn.
- Hiện chip theo **đúng sổ của tác vụ** cạnh nút chạy ("Còn 18.500 từ AI" · "Còn 54 lượt tạo ảnh"),
  kèm câu "đủ khoảng N …"; tooltip nêu gói, số đã dùng và số còn lại. Không bao giờ chip gộp "lượt AI".
- **Nói trước hệ quả**: tooltip nút ghi rõ số sẽ dùng **theo đúng đơn vị của sổ** (từ AI hoặc lượt tạo ảnh)
  và số còn lại sau khi chạy. Không bao giờ nói "lượt" cho tác vụ chữ.
- Còn ≤ 20% → dải cảnh báo vàng; hết → dải đỏ, nút mờ + chặn, kèm hai đường "Mua thêm" và "Nâng gói".
- **So chi phí thực trước khi cho bấm**, không chỉ so `còn > 0`: nhân số việc với đơn giá rồi mới mở nút
  (dịch 6 trường ≈ 840 từ, không phải 6). Bấm rồi mới báo thiếu là lỗi.
- Không đủ cho lần chạy hàng loạt → gợi ý chạy từng phần. Mỗi lần chạy trừ đúng số lượt.

## 7a. Hai loại hạn ngạch AI: chữ và ảnh — không bao giờ trộn (bắt buộc)
Giá thành hai loại khác nhau hàng chục lần nên **tách hai sổ riêng, hai đơn vị riêng, hai gói nâng riêng**.
Không quy đổi ảnh ra từ, không hiện một chip gộp "lượt AI" chung.

| | Nội dung chữ | Ảnh AI |
|---|---|---|
| Đơn vị | **từ AI sinh ra** (không phải số bài, không phải token) | **lượt tạo ảnh** = mỗi phương án tạo ra tính 1 |
| Prop chuẩn | `quota {used, limit}` | `imageQuota {used, limit}` |
| Chip | "Còn 18.500 từ AI" · icon `ri-sparkling-2-line` | "Còn 54 lượt tạo ảnh" · icon `ri-image-2-line` |
| Gói miễn phí | 5.000 từ/tháng | 5 lượt/tháng |
| Gói cơ bản | 60.000 từ/tháng | 20 lượt/tháng |

**Dùng từ "tạo", không dùng "vẽ"** (máy sinh ảnh, không phải hoạ sĩ), và phân biệt hai khái niệm:
**lượt tạo ảnh** = đơn vị trừ hạn ngạch · **ảnh** = thứ khách nhận được. Số phương án mỗi ảnh
**theo gói** (`v`): gói thấp 1 phương án (tạo lại được), gói cao 2–3 phương án để chọn — nên một ảnh
ở gói cao trừ nhiều lượt hơn. Mọi màn phải suy số ô/ảnh từ `v` của gói đang dùng, không hardcode 3.
Định giá (bắt buộc, vì ảnh đắt gấp hàng chục lần chữ): giá gói phân bổ **45% cho sổ chữ · 55% cho sổ ảnh**;
đơn giá trong gói tính trên **phần tăng thêm so với gói cơ bản** (đó là phần khách thật sự trả tiền), và
**bậc trên phải rẻ hơn bậc dưới**.
- **Giá vốn dùng để định giá là giá vốn đã gánh đủ**, không phải giá model thô: cộng hạ tầng, hỗ trợ,
  các lần chạy lỗi phải hoàn, phí thanh toán và chi phí gián tiếp không kê được. Mốc đang dùng:
  **1 lượt ảnh ≈ 2.600 ₫ · 1.000 từ ≈ 700 ₫**. Khi giá model đổi thì cập nhật mốc này rồi tính lại cả thang giá.
- **Mọi bậc phải giữ lãi gộp ≥ 30–50%** trên giá vốn đã gánh đủ. Không bao giờ để một lượt ảnh trong gói
  xuống dưới mốc đó — ảnh là chỗ dễ lỗ nhất, nên hạn ngạch ảnh trong gói phải khiêm tốn và
  **ảnh thật do khách tải lên (miễn phí) luôn là đường mặc định được trình bày trước**.
- Mua thêm luôn đắt hơn hạn ngạch trong gói (nói rõ tỷ lệ cho khách, không giấu), và trang định giá phải in
  đơn giá **cả hai loại** để khách tự kiểm được tỷ lệ đó.
- Trả trước 12 tháng chỉ **giảm giá** (bằng 10 tháng) — hạn ngạch vẫn cấp theo từng tháng, không cộng dồn.

Quy tắc tính ảnh:
- **Trừ theo số phương án thực sinh ra**, không theo số ô ảnh: vẽ 3 phương án cho 1 ô = 3 lượt.
  Nút phải ghi rõ "Vẽ N phương án" và tooltip nêu số lượt sẽ dùng.
- **Chọn phương án nào không trừ thêm**; bỏ cả loạt cũng không hoàn — lượt tính khi ảnh đã sinh.
- **Sửa mô tả rồi vẽ lại lần đầu của mỗi ô: miễn phí** (khách không phải trả tiền cho mô tả máy tự viết chưa đúng).
  Từ lần thứ hai trở đi tính bình thường.
- **Lỗi, kẹt hoặc người dùng huỷ giữa lần vẽ → hoàn đủ lượt**, kèm nút "Thử lại"/huỷ ngay tại ô.
  Không bao giờ để một ô đã trừ lượt mà không có đường chạy lại.
- **Hậu xử lý không tính lượt**: cắt tỷ lệ, nén, sinh alt/chú thích, gán vào bài.
- Ảnh thật do khách tải lên: **không tính lượt nào**, và luôn là lựa chọn được ưu tiên trình bày trước ảnh AI.
- Chạy hàng loạt: kiểm tra `iqLeft ≥ số ô × số phương án` **trước khi cho bấm**; không đủ thì nút mờ,
  đổi nhãn "Không đủ lượt tạo ảnh" và gợi ý chạy từng phần — không cho bấm rồi mới báo lỗi.
- Mỗi lần chạy có **timer/giao dịch riêng theo từng ô** (không dùng một biến timer chung cho nhiều ô):
  chạy nhiều ô cùng lúc không được làm ô khác kẹt ở trạng thái "đang chạy".
- Backend: sổ dùng ảnh riêng, idempotency key theo từng ô + từng lần vẽ, hoàn lượt là bút toán đảo — không tin client.

## 7a-bis. Gói và addon: mỗi loại hạn ngạch một sổ, mua thêm riêng (bắt buộc)
Gói dịch vụ cấp **hạn ngạch riêng cho từng loại** AI, và khách **mua addon riêng theo loại đang thiếu** —
không bắt nâng cả gói chỉ vì hết một loại.
- Mỗi loại là một sổ độc lập: `words` (từ AI), `images` (lượt ảnh); về sau thêm loại nào thì thêm sổ đó
  (vd. `voice` phút đọc, `video` giây dựng). **Không quy đổi chéo giữa các sổ**, không "mượn tạm" sổ khác.
- Gói cấp hạn ngạch **định kỳ theo tháng, không dồn** sang tháng sau. Addon là **dung lượng mua thêm**:
  cộng vào sổ tương ứng, **không hết hạn theo tháng** mà theo hạn dùng của addon (mặc định 12 tháng).
- **Thứ tự trừ: hạn ngạch tháng của gói trước, addon sau** — để khách không mất phần đã trả tiền khi chưa dùng hết
  phần miễn phí trong kỳ. Chip hiện tổng khả dụng; tooltip tách rõ "gói tháng: n · addon: m".
- Hết một loại → dải đỏ **chỉ chặn loại đó**, các tác vụ loại khác vẫn chạy bình thường.
  Nút chặn dẫn tới **hai lựa chọn cạnh nhau**: "Mua thêm <loại>" (addon, rẻ, tức thì) và "Nâng gói"
  (khi dùng thường xuyên) — kèm câu so sánh giúp khách tự chọn, không đẩy mặc định sang nâng gói.
- Trang mua addon nêu trước hệ quả: số lượt/từ nhận được, giá, hạn dùng, và "đủ cho khoảng N bài / N ảnh"
  quy theo mức dùng thật 30 ngày qua của chính website đó.
- **Hiển thị: đơn vị thật + quy đổi theo việc.** Đơn vị đo luôn là từ AI / lượt ảnh (một sổ, backend không quy đổi chéo),
  nhưng mọi chỗ hiện số phải kèm câu "đủ cho khoảng N …" tính theo **mức dùng thật 30 ngày qua của chính website đó**
  (vd. "Còn 18.500 từ AI — đủ khoảng 19 bài, hoặc 1.541 nhãn dịch"). Không bao giờ hiện số thô một mình:
  "còn 59.900 từ" đúng luật nhưng vô nghĩa với người dịch 12 nhãn.
- Trang chuẩn định nghĩa cách hiển thị này: `Hạn ngạch AI và mua thêm.dc.html` — mọi màn khác dùng lại đúng
  cách diễn đạt đó và trỏ về đây khi bị chặn. Không màn nào tự dựng UI mua thêm riêng.
- **Bảng gói, giá, số phương án ảnh và mọi mức quy đổi chỉ khai ở `ai-quota.js`** (`window.BSAI`):
  `plan()` gói đang dùng · `plans()` bảng gói · `variants()` số phương án · `enough()` / `enoughImages()`
  câu "đủ khoảng N …" · `cost()` / `imageCost()` chi phí. Không màn nào — kể cả trang định giá — được khai
  lại bảng gói hay hardcode `basic`: khách gói Pro phải thấy đúng hạn ngạch Pro.
- **Tự mua thêm khi gần hết** (auto top-up) mặc định TẮT. Bật thì chỉ mua **gói nhỏ nhất** của đúng loại,
  tối đa 2 lần/tháng/loại, gửi thư ngay sau mỗi lần, hoàn tiền được trong 7 ngày. Không bao giờ tự nâng gói.
- Backend: mỗi sổ một ledger riêng (gói và addon là hai nguồn cấp, có `source` trên từng bút toán),
  giao dịch nguyên tố, idempotency key theo lần chạy; hoàn lượt trả về **đúng nguồn đã trừ**.

## 7b. Module dùng chung nhiều loại tài khoản (Site/TMĐT/SaaS/Platform)
Rút ra từ Email Settings — áp dụng cho mọi module cấu hình xuất hiện ở cả cấp tenant lẫn cấp platform:
- **Một component, nhiều `accountType`** truyền qua prop (`site`/`ecommerce`/`saas`/`platform`), không fork file riêng.
  Danh sách mục con (mẫu, tuỳ chọn…) lọc theo bảng metadata gắn cờ loại tài khoản, không hardcode theo tên module.
- **SaaS không có "hộp thư/tài khoản của nhà cung cấp"** (không có khái niệm tài khoản cha) — mặc định rơi về
  lựa chọn độc lập đầu tiên (vd. Google), không hiện tuỳ chọn dùng chung.
- **Platform tái dùng nguyên component tenant**, chỉ thêm banner "bắt buộc cấu hình" + chip loại tài khoản
  trên từng dòng để admin biết mục nào áp dụng cho khách nào. Không dựng lại UI riêng cho platform.
- **Mục khoá bởi hệ thống** (không tắt được, không sửa được): badge "Hệ thống" riêng, ẩn khỏi bộ đếm
  "chưa lưu", và không có nút Sửa — chỉ Xem.
- **Trước khi dựng màn mới, đọc Admin Shell trước**: nếu Shell đã có chỗ làm việc đó (ví dụ hộp "Tài khoản
  của tôi" đã có Đổi mật khẩu + 2FA) thì bổ sung vào đó, không dựng màn trùng chức năng.

## 7c. Trạng thái "chưa lưu" ở cấp mục con (mẫu, dòng, bản ghi)
- Mỗi dòng có huy hiệu trạng thái độc lập: **Chưa lưu** (vàng, có nút hoàn tác riêng ngay cạnh) hoặc
  **Đã sửa** (xám trung tính, không phải màu cảnh báo — sửa xong và đã lưu không phải lỗi).
  Không dùng chung một chip cho hai trạng thái này.
- Bộ đếm "chưa lưu" tổng ở thanh dưới cùng phải cộng dồn theo số dòng đang ở trạng thái Chưa lưu,
  không chỉ theo cờ dirty toàn trang — để nút Lưu ở Admin Shell phản ánh đúng số lượng thực.
- Nút "Đưa về nội dung gốc" cho từng dòng: chỉ bật khi dòng đó đã từng bị sửa; tooltip nêu rõ hệ quả
  (mất cả bản dịch, không hoàn tác được) trước khi cho bấm.

## 7d. Ảnh logo / media co giãn theo tỷ lệ thật
Ảnh do khách tải lên có tỷ lệ bất kỳ (vuông/dọc/ngang) — khung hiển thị phải tự canh **fit theo tỷ lệ gốc**
trong một khung tối đa cố định (không crop, không méo), không giả định tỷ lệ vuông. Kiểm tra cả ba dạng
tỷ lệ trước khi coi là xong.

## 8. Quy ước giao diện chung (AdminKit)
- Nhóm cài đặt = **một thẻ liền khối**: tiêu đề là thanh trên cùng của thẻ, trường nằm trong cùng thẻ,
  ngăn bằng một đường kẻ. Không để tiêu đề rời khỏi nội dung.
- Lưới trường **2 cột cố định** (mobile 1 cột); trường dài chiếm trọn hàng. Không auto-fit gây hàng lẻ.
- Chú thích dài → nút **ⓘ cạnh tiêu đề trường**, bấm mở popover; đóng bằng Esc hoặc bấm ra ngoài.
  Không để dòng chữ dưới ô làm lệch chiều cao các cột.
  Popover phải `white-space:normal` (thoát quy tắc nowrap của nhãn), `z-index` cao hơn ô nhập và chip,
  neo phải (`right:0`) khi nằm ở cột phải để không tràn thẻ; mỗi lúc chỉ một popover mở.
  Trước khi giao, mở thử từng popover ở 3 bề rộng 1240 / 768 / 390px.
- **Font Inter (chốt L1-21a-3b, 03/10/2026)**: chữ thường Inter dùng Google qua đúng URL CHUẨN
  `https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap`. Trang mới phải có link URL CHUẨN
  trong helmet, đặt **sau** link `fonts/inter.css` / `styles.css` của design system. Giữ `inter.css` (Google URL CHUẨN
  không có chữ nghiêng). Chữ nghiêng giữ nguyên nguồn hiện có của từng trang: trang có inter.css dùng Inter-italic.woff2;
  Page Builder v3 dùng nghiêng tổng hợp từ Google Inter (không có face nghiêng). inter.css cho chữ thường rộng hơn Google Inter
  khoảng 4,07–4,70 % tuỳ weight (đo bề rộng CHUỖI A cỡ 14px, lượt 3b, H.stable cũ (bản ẩn)). Ký tự `→` (U+2192) nằm ngoài dải của cả hai nguồn
  nên vẽ bằng font dự phòng. Bỏ hẳn `inter.css` / tự host Google: việc L1-F-04, chưa làm.
- **Màu luôn qua token, không hex cứng cho nền/viền/chữ**: `var(--color-surface|surface-muted|surface-hover|body|border|border-strong|border-input|heading|body-text|accent|admin-*-text, #giá-trị-sáng)` — để chế độ tối tự lật. Mọi DC nạp `admin-base.css` (vùng bấm 44px cảm ứng, chữ ô nhập 16px, vòng focus) và đọc `localStorage['bs-scheme']`.
- **Màu section của storefront = BỘ MÀU, không chọn nền/chữ rời** (theo Shopify color schemes + Material 3):
  từ màu thương hiệu tự sinh 4 bộ Sáng · Nhạt · Thương hiệu · Tối (+ "Màu phụ" nếu khai màu thứ hai; tùy chỉnh từng bộ ở phần nâng cao, tự chỉnh về mức đạt) (chữ đậm dùng ink `#0f172a`, không pha màu thương hiệu với đen — ra màu bẩn); mỗi bộ gồm nền, tiêu đề, đoạn văn, chữ phụ,
  liên kết, nút chính (nền + chữ + hover), nút viền, thẻ con, ô nhập, đường kẻ, icon, vòng focus; sắc nhạt/đậm sinh bằng OKLCH (không trộn RGB với đen/trắng) — đo WCAG, chữ ≥4,5:1, nền nút ≥3:1. Section chỉ chọn một bộ (thẻ xem trước có
  tiêu đề + chữ + nút thật); không có ô chọn màu chữ riêng. Nền ảnh: chữ trắng + lớp phủ tự tăng tới mức đạt.
  Nút chính trên nền sáng/tối = nền màu thương hiệu + chữ tự chọn; trên nền thương hiệu = nút trắng hoặc ink `#0f172a`, chọn cái đạt ≥3:1 với nền. Ảnh nền là mục riêng, không nằm trong bộ màu. Đổi màu thương hiệu → cả 4 bộ tự tính lại. Chuẩn: `Page Builder v3` (`schemes()`, `inkOn()`, `fitInk()`).
- Mỗi màn kiểm trước khi giao ở 360 / 390 / 768 / 1024 / 1240 / 1440px + điện thoại xoay ngang × sáng + tối × chữ thường + chữ lớn, trên Chrome, Safari iOS và Firefox; bố cục co theo khung chứa (container query); dưới 768px bảng/hàng nhiều cột thành thẻ gọn (không xếp mỗi cột một dòng), công tắc đứng cạnh tên việc nó bật/tắt; trang không cuộn ngang.
- Đầu trang = cột trái (breadcrumb → tiêu đề → mô tả) + cụm nút bên phải **ngang hàng tiêu đề**
  (`margin-top` bù chiều cao breadcrumb). Cột trái phải co được (`flex:1 1 320px; min-width:0`),
  cụm nút `flex:0 0 auto` — nếu không, mô tả giữ nguyên bề rộng và đẩy nút xuống dòng dù còn chỗ trống.
- **Không đưa trạng thái qua hole trong thuộc tính `style`** (công tắc, chip, badge): runtime áp hole
  style trễ một nhịp so với chữ → công tắc "tắt" trong khi nhãn đã "bật". Dùng markup tĩnh hai nhánh
  `sc-if` + class CSS trong `<helmet>` (`.dsw` / `.dsw.on`).
- Ô tìm kiếm: khung bao ngoài sáng lên khi focus (`:focus-within`), input bên trong không viền/outline riêng.
- Điều khiển co theo nội dung, chỉ đặt max-width; không fix width.
- Vùng bấm ≥ 44×44px trên cảm ứng; mọi trạng thái có màu + hình dạng + nhãn chữ (WCAG 2.2 AA).
- **Tông Bảo Sơn Hoàng gia (3b, duyệt 01/10/2026)** — màu từ logo: primary/accent `#2050a0` = nền dưới chữ trắng (`--brand-solid`, cả sáng lẫn tối), chữ, viền, vòng focus (ở tối chữ/viền/vòng là `--brand-ink`); vàng `#e0b028` chỉ điểm nhấn (vạch mục đang chọn ở thanh bên, chip hạn ngạch), chữ vàng dùng `#8a6200`; thanh bên = brand pha `#0b1d45` (≈`#1c3c7a`); chữ màu mực xanh: tiêu đề `#1f2b4d` · chữ `#3c4765` · chữ phụ `--color-body-muted` `#5b6275` (tối `#98a0ae`); nền trang `#f4f5fa`, kẻ `#e9ecf4`. Không dùng xám than/slate lạnh. Tối: nền `#0f1729`, thẻ `#16203a`, thanh bên `#111a30`, chữ `#eef2fa`/`#c5cde0`/`#9aa5bf`, accent chữ `#8fb0ec` (`--brand-ink`) — màu thương hiệu làm chữ trên nền tối luôn qua `--brand-ink`. Lớp chuyển tiếp ở `admin-base.css`. Token cũ; `--color-body-faint` chỉ cho chi tiết trang trí · viền ô nhập `--color-border-input` · màu ngữ nghĩa 2 bậc: `-text` (chữ, kiểu soft) và `-solid` (nền dưới chữ trắng; secondary `#2f69d4`, không dùng `#3577f1` làm nền chữ) · focus (1b, chốt 05/10/2026, hình chuẩn = mẫu tĩnh 1b ở `Focus - Đề xuất.dc.html`; luật chung ở `admin-base.css`, màn không tự vẽ focus): màu vòng mọi loại = `var(--focus-ring-color)` (vùng nền đậm khai `#fff`, `.pop` khai lại `--brand-ink`); khe/viền trong = `var(--focus-inner, var(--color-surface))` (vùng nền đậm đặt `transparent`). Theo loại (loại hẹp thắng: nút đặc > ghost > nút viền > liên kết): **nút đặc** (`.sx-btn.pri/.dan`, `.b.pri`, `.btn.pri/.dan`, đoạn đang chọn `.sx-seg button[aria-checked=true]`/`.seg button[aria-pressed=true]`, ngày đã chọn `.cal-d[aria-selected=true]`, `[data-focus-kind=solid]`) = viền trắng 2px bên trong (`currentColor`) + quầng 3px 30%; **nút viền** (`.sx-btn`, `.b`, `.btn.sec/.dout`, `[data-focus-kind=outline]`, kể cả viền đỏ) = viền thành 2px màu vòng + quầng 3px 18%; **ghost/tab** (`.sx-btn.gho`, `.b.gh`, `.btn.gho`, `.tab`, `.sx-tab`, `[role=tab]`, đoạn/ngày chưa chọn, `[data-focus-kind=ghost]`) = nền 8% + viền trong 2px, không quầng; **liên kết** (`a[href]` không mang class nút) = nền 10% + viền NGOÀI 2px + gạch chân dày 2px; **điều khiển nhỏ** (checkbox/radio gốc, `.dsw`, `[role=switch]`; nút bọc cả chữ đánh dấu phần hình bằng `[data-focus-shape]`) = vòng tách quanh phần hình: khe 2px `--focus-inner` + vòng 2px; `[role=switch]` dạng nút có chữ vẽ như nút viền, không thêm vòng tách; **ô nhập/select/`[contenteditable]`** = viền 2px (viền + bóng trong 1px) + quầng 3px; **khung bao** (`.sx-search`/`.srch`/`.cp`/`[data-focus-wrap]`) chỉ sáng khi ô nhập bên trong focus (`:has(:is(input,textarea):focus)`), ô bên trong không tự vẽ, nút bên trong tự vẽ; **ô lỗi** `[aria-invalid=true]` (kể cả ô tích, khung bao có ô lỗi) giữ đỏ `--color-admin-danger-text`; **phần tử không nhận ra loại** (vd. nút đặc viết style inline) = outline 2px lệch −1px + viền trong 2px `--focus-inner` + quầng 4px 18%. Trạng thái dùng box-shadow phải ghép với focus hoặc chuyển sang viền/nền (vạch mục đang chọn thanh bên = lớp nền gradient). Luôn giữ outline (trong suốt khi vẽ bằng box-shadow) cho chế độ tương phản cao, không thêm `outline:none`. Bo 4px control / 6px card, shadow `0 1px 2px rgba(56,65,74,.15)`, Inter 14px, canvas `#f4f5fa`. Không tự chế màu mới.

## 9. Nút Dịch: phạm vi theo ngữ cảnh (bắt buộc)
- Đứng ở **bản nguồn** → nút ghi "Dịch sang N ngôn ngữ", chạy cho **tất cả** bản còn lại.
- Đứng ở **bản đích** → nút ghi "Dịch bản <tên bản>", chỉ chạy cho bản đó.
- **Chỉ điền ô còn trống**, không bao giờ đè nội dung người dùng đã sửa → bấm lại luôn an toàn.
  Không còn gì thiếu → nút mờ + `cursor:not-allowed`, tooltip "Mọi nhãn đã có bản dịch".
- Không đủ từ AI cho lần chạy này → mờ + chặn, tooltip nêu số từ cần và cách mua thêm. Chạy hàng loạt đặt trong panel
  ("Dịch N bản còn thiếu").
- Kiểu nút: nền brand nhạt (`rgba(32,80,160,.10)`) + icon `ri-sparkling-2-line` — nổi hơn nút phụ
  nhưng vẫn dưới nút Lưu. Ô sửa nội dung của bản nguồn phải **có sẵn giá trị mặc định** (không để
  placeholder rỗng) để AI có nguồn chuẩn mà dịch.

## 10. Dòng trạng thái dưới thanh ngôn ngữ (bắt buộc)
Một dòng duy nhất, ngay dưới thanh, đổi theo ngữ cảnh — luôn còn khi chip trạng thái bên phải bị ẩn:
- Bản nguồn: xám‑xanh trung tính — "Đây là bản nguồn — nhập đủ và chuẩn các trường có chip VI, rồi bấm
  Dịch để đổ sang N ngôn ngữ còn lại."
- Vừa dịch máy: xanh dương — "Bản <X> do AI dịch từ <nguồn> — sửa chỗ nào chưa ưng rồi bấm Lưu."
- Chưa có / còn thiếu: vàng. Sắp hết từ AI: vàng. Hết từ AI: đỏ, kèm cách mua thêm hoặc nâng gói.
Chip trạng thái ngắn ở bên phải thanh chỉ hiện khi **đủ chỗ cho toàn bộ pill** và thanh ≥ 810px;
pill luôn được ưu tiên hơn chip.

## 11. Thứ tự nhường chỗ của thanh (đo thật, không đoán)
Đo bằng ResizeObserver **gắn lại mỗi lần ref chạy** + fallback `window.resize` + đo lại sau mỗi lần render;
vòng bớt pill phải có khoá theo lần đo (huỷ `requestAnimationFrame` cũ) để không kẹt chip `+N`.
Các ngưỡng ẩn/hiện nên đặt bằng **container query** để không phụ thuộc state (state trễ vẫn không tràn thẻ).
Thứ tự: ẩn chip trạng thái → dồn pill vào `+N ⌄` → đổi sang ô chọn + panel → ẩn hạn ngạch → ẩn tiến độ →
rút tên ngôn ngữ còn mã ("VI ⌄") và nút chạy còn icon. Không bao giờ để phần tử tràn khỏi thẻ,
không cắt nửa chữ. Khi hẹp (≤520px) mọi điều khiển của thanh và panel phải ≥ 44px chiều cao;
panel kẹp `max-width: min(296px, calc(100cqw - 92px), calc(100vw - 32px))`.
Panel nhúng trong hộp thoại rộng ~550–700px không phải mobile: đặt ngưỡng bung dọc ở ~440px, và dùng lưới
cố định `icon · nội dung · hành động` cho hàng danh sách để không bao giờ bung thành 3 dòng.

## 12. Không làm mất chữ của người dùng
Chuyển ngôn ngữ khi còn thay đổi chưa lưu → hộp thoại chặn: Lưu nháp / Bỏ thay đổi / Huỷ.
Mọi hành động AI và đồng bộ đều hoàn tác được; xoá bản dịch giữ trong thùng rác 30 ngày.

## 13. Hợp đồng nhúng vào Admin Shell (bắt buộc cho mọi panel cài đặt)
Panel phải chạy được cả khi **đứng riêng** lẫn khi **nhúng trong Admin Shell** — một file, không fork:
- `embedded` → ẩn header + nút Lưu riêng, bỏ `min-height:100vh` (Shell đã có tiêu đề và nút Lưu).
- `onDirty(dirty)` → báo lên Shell mỗi khi trạng thái chưa lưu đổi, để bộ đếm và nút Lưu của Shell đúng.
- `embedId` + nghe event `bs-embed-cmd` `{id, act:'save'|'reset'}` trên `document` → Shell bấm Lưu thì panel lưu.
- Chặn `beforeunload` khi còn dirty; Esc và bấm ra ngoài đóng modal/popover; z-index modal panel < modal cấp Shell.
- Nối vào Shell: thêm `embed:'<id>'` vào mục trong `settingsGroupsAll()`, thêm `<div style="{{ xWrapStyle }}">`
  + `<dc-import … embedded on-dirty>` cạnh các panel cũ, và `mkEmbedDirty('<id>')` trong `renderVals`.
- Màn TOÀN TRANG (danh sách, trình soạn, hộp duyệt, thư viện) cũng nhận `embedded`, nhưng chỉ ẩn phần trùng Shell (breadcrumb, tiêu đề trang, khung 100vh); GIỮ thanh thao tác và nút quy trình của chính màn; vẫn báo `onDirty` và nghe `bs-embed-cmd`. Shell chỉ mount màn toàn trang đang mở (`sc-if`); panel Cài đặt giữ cách `xWrapStyle` ở trên.

## 14. Chuẩn câu chữ & tiêu mục
- Tiêu mục = danh từ mô tả đúng việc đang làm; không thuật ngữ kỹ thuật, không tiếng Anh lẫn lộn
  ("Đăng nhập nhanh", không "Đăng nhập Social"); bám bản chất tính năng, không bám tên nhà cung cấp.
- Mỗi câu một ý, ≤ ~20 từ; nói hệ quả trước, thao tác sau; nút là động từ.
- Không dùng "Bước 1/Bước 2" trong nội dung nếu số bước có thể đổi — trỏ theo tên mục ("ở mục 2 bên dưới").
  Mỗi lần đổi bố cục, grep các từ trỏ vị trí ("Bước", "bên dưới", "phía trên") để rà câu trỏ chéo.
- Placeholder mô tả **dạng dữ liệu** ("Khoá bắt đầu bằng GOCSPX-"), không bao giờ dãy `••••` giả nội dung.
- Màn cấu hình mặc định chỉ hiện ô cần điền + nút hành động; hướng dẫn, giá trị cần chép sang bên thứ 3,
  ô dán nhanh và xử lý lỗi nằm trong một khối gập lại ("Hướng dẫn cài đặt").

## 15. AI sửa nội dung đang có (trình soạn thảo) — bắt buộc
Màn chuẩn: `Page Editor - Options.dc.html`. Mọi module có ô nội dung dài dùng lại đúng các quy tắc này.
- **Không bao giờ ghi đè im lặng.** Có chữ sẵn → hiện **chữ hiện tại cạnh phương án AI**, người dùng bấm mới đổi;
  sau khi đổi luôn còn chip **"Hoàn tác gợi ý AI"**. Ô trống thì điền thẳng được.
- **Bôi đen → popup ngay dưới đoạn đã chọn** (không ở chân trình soạn, không giữa màn) để mắt không phải nhảy.
- **Giữ cấu trúc khi viết lại nhiều đoạn**: chỉ thay text node bên trong; tiêu đề, gạch đầu dòng, blockquote,
  bảng giữ nguyên thẻ. Ctrl+Z hoàn tác **tuần tự từng đoạn**, không nhảy cả khối.
- **Hoàn tác là sổ riêng của trình soạn** (~80 mốc), không dựa vào undo của trình duyệt: bao mọi lần AI chèn,
  đổi định dạng và viết lại.
- **Dán là làm sạch ngay lúc dán**: bỏ `div/span/class/style`, gỡ block lồng nhau; giữ `strong/em/a/ul/ol/table`;
  chặn `script/iframe/form`.
- **Video chèn vào bài là thẻ xem trước** (ảnh nền + nút play + nhãn thiết lập), không nhúng iframe sống trong
  vùng `contenteditable`; bấm thẻ mở lại hộp thoại để sửa hoặc xoá.
- **AI theo từng trường có ràng buộc riêng**: tiêu đề ≤ 60 ký tự (nguồn: từ khoá + tiêu đề hiện tại);
  mô tả ngắn / meta 150–160 ký tự (nguồn: các đoạn đầu của bài). Không áp dụng được thì nút mờ + nói rõ lý do.
- **Bài đã có nội dung → "Nâng cấp bài viết", không phải "Viết lại cả bài"**: dùng chính nội dung hiện tại làm
  nguyên liệu, liệt kê trước các việc sẽ làm kèm ước tính từ AI cho từng việc. "Viết mới" chỉ dành cho bài trống,
  và phải hỏi xác nhận nếu sẽ mất chữ đang có.
- **Không sinh ra chữ thì không trừ từ AI**: đoạn quá ngắn, không tìm được phương án khác, người dùng huỷ giữa lúc
  chạy, hoặc lỗi → nói rõ "không trừ từ AI nào". Alt ảnh, cắt/nén, gán bài là hậu xử lý, không trừ lượt.

## 16. Khung admin: ngưỡng, menu, lưu tùy biến (chốt 01/10/2026)
- Ngưỡng mobile **1024px** (không 992). ≥1280px sidebar đầy đủ. 1024–1279px tự thu về rail 76px; mở rộng tạm thời thì **phủ lên nội dung, không đẩy nội dung** (2 cột: rail + cột menu con dạng phủ; Ngang: dồn mục thừa vào "Khác").
- Dưới 1024px: drawer, nút mở có icon + chữ **"Menu"**. **Không làm BottomNav**, vì admin có nhiều mục chính, 4–5 ô không chứa đủ.
- **Màu thương hiệu áp toàn admin — 3 token, tra BẢNG TĨNH** (`BRAND_TOKENS` trong Admin Shell, bảng hex + tỷ lệ ở AUDIT.md 5.L1-A; không tính lúc chạy):
  - `--brand-solid` — **nền dưới chữ trắng**: nút chính, badge, công tắc bật, topbar Đậm, sidebar Đậm (pha `#0b1d45`), điểm sáng sidebar Gradient. Đạt trắng và trắng .85 ≥ 4,5 kể cả trên chip phủ trắng .08; vạch vàng ≥ 3 trên sidebar Đậm/Gradient.
  - `--brand-ink` — **chữ, link, viền nhấn, vòng focus**. Sáng: ≥ 4,5 trên `#fff`, `#f4f5fa`, nền brand 10%. Tối: `color-mix(in oklab, brand 38%, #e6eeff)`, ≥ 4,5 trên nền tối. Cùng tên token, giá trị theo `data-bs-theme`.
  - `--brand` (màu gốc) — **chỉ trang trí**: tint 5–12%, viền mảnh, thanh tiến độ, scrollbar. **Cấm dùng `--brand` thô cho chữ hoặc nền có chữ.**
  - `admin-base.css`: `--color-accent`/`--focus-ring-color` = `var(--brand-ink, …)`, `--color-admin-primary` = `var(--brand-solid, …)`, khai lại trên khung `[data-brand-scope]` của Shell. Dự phòng khi mở DC độc lập = giá trị cũ (`#2050a0`, tối `#8fb0ec`).
  - Panel/trang dùng `var(--color-accent, #2050a0)` cho chữ và `var(--brand-solid, #2050a0)` cho nền dưới chữ trắng; **không hex cứng `#2050a0`**, không tự khai `--brand`/accent riêng. Thêm màu thương hiệu mới = thêm một dòng vào bảng (tính trong OKLCH, giữ sắc độ, chỉ làm đậm tới khi vừa đạt).
  - **Bảng màu (Đợt C, 01/10/2026): cùng độ đậm, khác sắc độ, mỗi họ một màu, có một màu trung tính** — 6 màu: Bảo Sơn `#2050a0` (mặc định, mốc độ đậm) · Xanh cổ vịt `#0e7490` · Xanh lá `#15803d` · Chàm `#4338ca` · Mận `#9d174d` · Than chì `#334155`. Điều kiện để thêm màu: L (OKLCH) của `--brand-solid` trong ±0,06 so với Bảo Sơn (màu trung tính miễn), ΔE OKLab giữa mọi cặp solid ≥ 0,06 (bảng hiện tại min 0,091). Ô màu trong Tùy biến hiển thị `--brand-solid`, tooltip giữ tên. Vạch active vàng dùng chung cho mọi màu.
- Tùy biến giao diện lưu ở `localStorage['bs-ui']` (một key, có `version`, đọc lỗi thì dùng mặc định), vẫn giữ `bs-scheme` + `data-bs-theme` cho các DC khác. "Đặt lại" xoá key. Bản thật lưu phía server theo người dùng.
- Plane `platform` hiển thị là **"Nền tảng"**, không gọi "SaaS". Plane là bộ chọn xem thử của prototype; bản thật lấy theo tài khoản, không lưu theo trình duyệt.
- **Ngữ nghĩa không chỉ bằng màu (bắt buộc mọi màn):** nút nguy hiểm (Xóa, Hủy, Khóa…) luôn có icon + chữ; thao tác không hoàn tác được thì có hộp xác nhận. Chip trạng thái = nền nhạt + chữ đậm + icon; nút chính = nền đặc. Hai loại khác nhau về hình thức, không chỉ khác màu (vì màu thương hiệu có thể gần màu trạng thái).
- **Vòng focus trên vùng nền đậm** (sidebar Đậm/Gradient, rail, cột menu con, topbar Đậm ở chế độ Sáng): `--focus-ring-color: #fff`; popover `.pop` khai lại `var(--brand-ink)`. Phần tử cuộn của Shell có `scroll-padding-top: var(--sticky-top)`.

## 17. Quy trình rà soát (AUDIT.md)
- Mọi kết quả rà soát ghi vào `AUDIT.md`. Tóm tắt trong chat phải **khớp số liệu** với file.
- Mỗi lỗi ghi bằng chứng đúng một loại: [ĐÃ RENDER VÀ THẤY] / [ĐO BẰNG SCRIPT] / [SUY RA TỪ CODE] / [CHƯA KIỂM ĐƯỢC + lý do]. Không bỏ qua tổ hợp ngầm.
- Mức độ: Chặn · Cao · Trung bình · Thấp (định nghĩa ở AUDIT.md §4f). Sửa ở tầng gốc: token → `admin-base.css` → component dùng chung → shell → trang.
- Sau mỗi đợt sửa, kiểm lại các trang phụ thuộc + Shell ở 4 tổ hợp: Dọc-Sáng-1440, Ngang-Tối-1280, 2 cột-Sáng-1024, Dọc-Tối-390. Ghi changelog.
- Công cụ đo: `_audit/harness.html` (iframe theo bề rộng + đo tràn, tương phản, vùng bấm, cỡ chữ).
