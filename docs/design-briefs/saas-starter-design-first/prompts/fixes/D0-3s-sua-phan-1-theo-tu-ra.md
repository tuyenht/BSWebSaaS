# D0-3s · Sửa phần 1 theo bản tự rà, rồi làm phần 2 (dán vào CHÍNH chat D0-3)

Lượt D0-3s. Claude Code đã đọc bản tự rà 24 điểm của bạn (AUDIT.md, mục "Tự rà lượt D0-3 phần 1") và đối chiếu với repo. Sửa hết cả 24 điểm, theo các chốt dưới đây. Chưa xuất zip.

**A. Chốt các câu bạn hỏi** (Claude Code chốt thay owner, đã đối chiếu repo):
1. Từ bạn tự thêm vào cột "Không dùng": chỉ giữ từ nào gắn được với một trong 16 điểm đã chốt, và ghi rõ nghĩa bị cấm. Ví dụ: "Phê duyệt" → "Duyệt đăng" (điểm 14); "Từ chối" chỉ cấm khi nói về bài gửi duyệt → "Trả lại". Bỏ "Cập nhật" khỏi danh sách. Từ nào không gắn được điểm nào thì đưa sang dòng "Đề xuất chờ owner" trong README D0-3, không đưa vào cột "Không dùng".
2. Nhóm quyền nền tảng: repo chỉ có một nhóm là `super_admin`, còn lại là quyền nền tảng lẻ, chưa gom thành nhóm có tên.
   - Ghi `super_admin` = "Quản trị nền tảng".
   - Nhân viên đăng nhập dưới tên khách để hỗ trợ = "Nhân viên hỗ trợ" (repo đã dùng chữ này).
   - Bỏ "Hỗ trợ khách hàng" và "Thanh toán" khỏi danh sách tên nhóm. "Thanh toán" còn trùng tên menu. Ghi [CHƯA CHỐT — chờ màn nhân sự nền tảng].
3. Sản phẩm `archived` = "Đã gỡ khỏi website": giữ. Repo đang ghi "Lưu trữ" (`catalog.status_archived`), mà chữ này bị cấm ở điểm 5. Thêm khóa này vào danh sách `lang/vi.json` của Việc cho backend. Còn `forms.status_archived` và `workflow.state_archived` thì ghi "rà theo nghĩa", không tự đặt chữ mới.
4. "Gỡ khỏi website" khác Bảng thuật ngữ của design system: làm theo prompt, vì owner đã duyệt. Ghi 1 dòng LESSONS.md nêu điểm khác này.
5. Khối "Từ không dùng" trong README chỉ chứa cụm từ sai trong mọi ngữ cảnh, để grep máy được. Từ ngắn dễ trúng nhầm (`site`, `chép`, `slug`, `media`…) chuyển sang danh sách con "Soát bằng mắt", mỗi từ kèm 1 ví dụ sai và 1 ví dụ đúng.

**B. Chốt các lỗi kỹ thuật:**
6. Ví dụ "Sai" trong `#tieu-chi` và cột "Không dùng" của bảng thuật ngữ: bọc bằng phần tử mang `data-term-bad`. Phép grep của "Xong khi" số 3 bỏ qua đúng các phần tử này và không bỏ qua chỗ nào khác. Ghi luật grep này vào README.
7. `fmtCompact`:
   - en, ja, zh rút gọn theo `Intl.NumberFormat` của chính ngôn ngữ đó (ja 万/億, zh 万/亿), vẫn một đơn vị cho cả biểu đồ.
   - Phép thử: vi `fmtCompact(1234567)` = "1,2Tr"; ja `fmtCompact(123456)` = "12.3万"; zh `fmtCompact(250000000)` = "2.5亿"; en `fmtCompact(1234567)` = "1.2M".
   - Giá trị lớn hơn 0 mà rút gọn ra 0 thì hiện "<0,1Tỷ" (theo đơn vị của biểu đồ), không hiện "0Tỷ".
   - Trình đọc màn hình đọc số đầy đủ: chữ rút gọn đặt `aria-hidden="true"`, kèm một chữ ẩn (chỉ trình đọc màn hình đọc) ghi số đầy đủ. Không dùng `aria-label` trên `span` trơn, vì nhiều trình đọc bỏ qua.
8. Ngày tiếng Anh dạng dd/mm/yyyy: KHÔNG sửa. Mục C2 của prompt chốt ngày dd/mm/yyyy, giờ 24h cho mọi ngôn ngữ, và repo cũng chưa có định dạng ngày theo ngôn ngữ. Đánh dấu điểm này "giữ theo prompt" trong AUDIT.md.
9. Màu nhãn trong `content-workflow.js`:
   - Chuỗi token pha màu: KHÔNG cần bản dự phòng cho Safari dưới 16.2. Repo build bằng Tailwind 4, vốn đã đòi Safari 16.4 trở lên, và luật focus trong `admin-base.css` cũng đã dùng `color-mix`.
   - Việc phải làm: tìm mọi nơi đọc trường màu này để vẽ (canvas, SVG), nối chuỗi hoặc tính tương phản. Chỗ nào cần mã màu thật thì đọc giá trị đã tính bằng `getComputedStyle`.
   - Thử: mở `Hộp duyệt` và `Quản lý Bài viết - Options`, sáng + tối. Tweak nhóm quyền chọn được; huy hiệu đúng chữ mới, đúng màu; console 0 lỗi. Đây là "Xong khi" số 4.
10. Chip "Chỉ đọc" → "Chỉ được xem", trùng tên trạng thái, khác nhóm quyền "Chỉ xem". Thêm cặp này vào bảng thuật ngữ.
11. Ô "Tiêu đề trang" ở trạng thái "Không lưu được": gõ được, console không có cảnh báo React.
12. Nút "Lưu lại" hiện đúng câu của việc lưu, không hiện "Đang thử tải lại…".
13. Ví dụ của `#tieu-chi`: mỗi tiêu chí có 1 hình nhỏ "đúng" và 1 hình nhỏ "sai", dựng từ component đã có, như prompt yêu cầu.

**C. Quy trình:**
14. Mở `github.md` và repo. Đối chiếu từng chỗ README D0-3 nói về repo: nhãn trạng thái khách liên hệ, khóa nhóm quyền, và từng khóa `lang/vi.json` (khóa có thật, giá trị hiện tại là gì). Chỗ nào đã đối chiếu thì ghi đường dẫn tệp. Chỗ nào không đối chiếu được thì ghi [CHƯA ĐỐI CHIẾU REPO], không đoán.
15. README chỉ ghi "Đạt" cho tiêu chí đã thấy tận mắt hoặc đã đo. Còn lại ghi [SUY RA TỪ CODE] hoặc [CHƯA KIỂM ĐƯỢC + lý do]. Trong chat, không báo "xong" trước khi verifier trả kết quả.
16. Gọi ready_for_verification cho cả trang chuẩn LẪN trang Component bổ sung, rồi sửa hết lỗi verifier báo.

**Xong khi:**
- Cả 24 điểm trong AUDIT.md đều có kết quả: đã sửa, giữ theo prompt (kèm lý do), hoặc chờ owner (chỉ những mục ở A1).
- Xong khi số 3 của D0-3: grep ra 0, theo luật `data-term-bad`.
- Xong khi số 4 và số 5 của D0-3 đạt, kèm các phép thử ở mục 7 và mục 9.
- Verifier đạt cho cả 2 trang.

Sau đó làm tiếp phần 2 ngay trong lượt này, theo prompt D0-3 (8 mẫu trang và phần kiểm hiển thị còn nợ, gồm phần của D0-2 và 5 mục mới ở 360px). Sắp hết lượt thì chốt phần đang làm, ghi TODO.md, rồi báo owner. Owner sẽ gửi "Làm D0-3 phần 2" để làm tiếp.

Xong cả hai phần: KHÔNG xuất zip ở chat này. Owner sẽ chạy D0-2s5 ở chat D0-2, rồi xuất một zip chung cho cả hai lượt. Báo owner đúng một câu: "D0-3 xong — dán D0-2s5 vào chat D0-2."
