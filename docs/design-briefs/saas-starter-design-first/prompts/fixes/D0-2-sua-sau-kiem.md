# D0-2s · Sửa D0-2 sau kiểm (dán vào CHÍNH chat D0-2)

Lượt D0-2 — sửa sau kiểm của Claude Code (04/10). Sửa tại chỗ `Chuẩn giao diện SaaS Starter.dc.html` + `README - D0-2 Chuẩn giao diện.md` + `content-ui.js`. Không vẽ lại, không đổi bố cục; chỉ sửa đúng các mục dưới. Màu chỉ qua token var(--…), không thêm màu mới, không gắn cứng hex.

1. CHẶN — Thanh ngôn ngữ mất ô đổi ngôn ngữ dưới 760px. Dòng `@container (max-width:759px){.lb-l .lb-pill,.lb-more{display:none}.lb-sel{display:inline-flex}}`: nút `.lb-sel` cũng mang lớp `.lb-pill` và nằm trong `.lb-l`, nên `.lb-l .lb-pill` (2 lớp) thắng `.lb-sel` (1 lớp) → ở khung 390 và 768 không còn cách đổi ngôn ngữ. Sửa thành `.lb-l .lb-pill:not(.lb-sel),.lb-more{display:none}.lb-l .lb-sel{display:inline-flex}` (hoặc tương đương), rồi soát cả chuỗi quy tắc ≤1199/≤759/≤559/≤459/≤340 sao cho quy tắc hẹp hơn luôn thắng. Đo bằng getComputedStyle ở bề rộng khung chứa 364, 734, 1206 và ghi số đo vào bảng "Kiểm hiển thị" của README. Sửa luôn nhãn nút: "Dịch sang 5 ngôn ngữ" phải đếm đúng số bản còn thiếu như panel ("Dịch 3 bản còn thiếu").

2. Bỏ hai nhãn "Chờ backend" sai — repo ĐÃ CÓ:
   - Ô đường dẫn: tự tạo chuyển hướng 301 khi đổi đường dẫn bài đã đăng = `database/migrations/2001_01_01_000000_create_redirects_table.php` + `app/Modules/Cms/Routing/RouteSync.php`.
   - Chọn địa chỉ 2 cấp: bảng dùng chung `provinces` + `wards` (+ `site_profiles.province_id/ward_id`) = `database/migrations/2021_01_01_000000_add_nap_fields_and_admin_units.php`, lệnh `admin-units:import`.
   Xóa 2 dòng tương ứng trong "Việc cho backend" của README, thêm 2 dòng này vào "Ánh xạ dữ liệu".

3. Thanh thao tác hàng loạt: bỏ luật "vai trò chỉ đăng được bài của mình" — repo không có luật đó (`app/Modules/Cms/Policies/EntryPolicy.php`: `publish` chỉ xét quyền `entries.publish`). Đổi ví dụ sang luật own/any thật: nhóm quyền Tác giả (author, có `entries.delete.own`) → nút "Chuyển vào Thùng rác (N)" chỉ đếm bài của chính mình + 1 câu lý do; nút "Đăng" khóa kèm lý do khi nhóm quyền không có `entries.publish`. Sửa cả mục "Quyền & gói" trong README. "Hoàn tác" của chuyển vào Thùng rác hàng loạt phải khôi phục thật các dòng vừa chuyển.

4. Ngày & giờ theo múi giờ WEBSITE (repo: `app/Modules/Tenancy/Context/SiteTime.php`, cài đặt nhóm `general` khóa `timezone`, mặc định Asia/Ho_Chi_Minh): dùng `SITE_TZ` + `BSUI.fmtDate` cho "hôm nay", lịch, lối tắt "Sáng mai 08:00…" và câu xem trước. Dòng gợi ý đọc tên múi giờ từ cấu hình ("Theo giờ của website: GMT+7 — đổi ở Cài đặt › Chung"), không gắn cứng "Giờ Việt Nam"/"GMT+7" ở ô giờ, hộp Đặt lịch, câu xem trước, dải thông tin. Khi múi giờ máy người xem khác múi giờ website thì thêm 1 dòng nhắc.

5. Không gì chỉ hiện khi rê chuột: lý do khóa ở Ô quyền (`lk-cell`), lý do mờ của 4 nút Lên/Xuống/Đưa vào trong/Đưa ra ngoài, lý do của nút Dịch ở Thanh ngôn ngữ, lý do ô tích "Cho khách bình luận" — bấm/chạm là hiện (popover ⓘ hoặc dòng trạng thái aria-live ngay dưới). Nút có lý do dùng aria-disabled="true" thay cho disabled để vẫn bấm được và đọc được lý do.

6. Vùng bấm ≥44px trên cảm ứng: thêm `@media (pointer:coarse)` cho `.sx-check{min-height:44px}`, ô ngày của lịch cao 44px, nút × của thẻ từ khóa rộng 44px (hoặc giãn cách ≥8px).

7. Hộp thoại trượt từ đáy ở điện thoại phải CHẠY THẬT: thêm nút "Mở hộp thoại" ở mục Co giãn, mở lớp `layer='sheet'` dùng `.ov.sheet` + `.dlg` (thanh kéo, chân có safe-area, giữ focus, Esc, bấm nền mờ, trả focus về nút đã mở) — dùng lại `openSheet/sheetApply` đang bỏ trống. Giữ khung tĩnh 390/768/1240 như hiện có.

8. Ô số tiền: theo repo giá được để trống (`products.price` nullable — chế độ giới thiệu, không giá) và 0 là giá hợp lệ. Bỏ dấu * bắt buộc; câu gợi ý đổi thành "Để trống nếu muốn hiện 'Liên hệ'"; xem trước hiện "Liên hệ" khi trống.

9. Chọn chuyên mục: repo mặc định đường dẫn bài PHẲNG `/ten-bai` (`app/Modules/Cms/Routing/PathBuilder.php`; chỉ có tiền tố chuyên mục khi website chọn kiểu đường dẫn có chuyên mục). Đổi câu "Đường dẫn lấy theo mục chính" và dòng "Đường dẫn bài" thành: mặc định `anvinh.vn/bang-gia-gia-cong-cnc-2026`; thêm 1 dòng "Nếu website chọn kiểu đường dẫn có chuyên mục: anvinh.vn/<mục chính>/…".

10. Bảng trạng thái cho component nền: thêm hàng `.sx-states` cho Nút (chính · thường · ghost · viền đỏ · đỏ × thường · rê chuột · focus · khóa · đang xử lý có vòng quay — prop `loading` mới) và Ô nhập (thường · focus · lỗi · khóa · chỉ đọc); thêm ô "Focus bàn phím" cho Ô tích và Nhóm lựa chọn; thêm thẻ Tabs (DS) đi bằng phím ←→ Home/End.

11. Thanh lọc: panel lọc không được làm trang cuộn ngang ở 360px — nút lọc ở nửa phải neo panel phải (như `.sx-pop.r`) hoặc giới hạn `max-width:calc(100cqw - 16px)`; dưới 768px mở dạng trượt từ đáy.

12. Dùng lại được ở màn khác: tách toàn bộ CSS component của trang (khối <style> từ `.sx-btn` tới hết các lớp component: `.dsw .sx-seg .sx-search .sx-chip .st .rt-row .tg-row .dlg .sbar .tst .psbar .ov .drw .cf .lb* .tw .pm* .tr-row .tok .dz .cal`…) ra file dùng chung `admin-components.css`, nạp NGAY SAU `admin-base.css`; trang chuẩn chỉ link file đó. Không sửa CLAUDE.md; ghi 1 dòng LESSONS + 1 dòng TODO cho D0-6 "mọi màn nạp admin-components.css". Trong `content-ui.js` thêm các hàm dùng chung đang nằm trong trang: `trapFocus(e)` (onLayerKey), `toast()` (8 giây + Hoàn tác), đo `--sbar-h`/`--kb`; ô đường dẫn gọi `BSUI.slugify` (giới hạn 80 → 200 ký tự cho khớp repo `max:200`).

13. Nhỏ, làm luôn: `#e0b028` (6 chỗ) → `var(--color-brand-gold,#e0b028)`; khung xem trước "nền tối #0f172a" đổi thành "nền tối (--ink của website, mặc định #111111)" và `BSUI.primaryText/primaryOnInk` mặc định ink #111111 như `app/Modules/Theme/Theming/ThemeResolver.php`; chỗ đệm dưới thanh Lưu dính dùng `height:var(--sbar-h)` thay 88px; câu "Khung chứa dưới 360px: nhãn nằm trên giá trị" — làm thật bằng container query hoặc xóa câu; nút "+5" ở Nhóm avatar mở danh sách 8 người; toast lỗi dùng role="alert".

14. Owner đã chốt (ghi vào README, không để "Cần owner chốt"):
   (a) Chính tả: kiểu dấu cũ Xóa · Hủy · khóa và "Bỏ lọc" — giữ như trang đang làm (LESSONS 04/10).
   (b) Khi "Lời mời nâng gói" TẮT: ẩn hẳn nút "Nâng gói" cạnh "Mua thêm" ở Thanh mức dùng và Chip hạn ngạch, thay bằng câu trung tính "Cần thêm? Liên hệ hỗ trợ" (đọc kênh hỗ trợ từ BSPLAT) — không để ngõ cụt; BẬT thì hiện "Xem gói nâng cấp" như hiện có. Vẽ cả hai theo Tweak `upsell`.

15. `uploads/` chỉ là tệp đính kèm cũ của các chat (có bản sao màn cũ ở `uploads/import_ready/` và tệp của dự án khác). Thêm 1 dòng vào TODO.md mục "Lộ trình D0", ngay dưới dòng `_audit/`: "uploads/ · tệp đính kèm cũ, không phải thiết kế: mọi lượt bỏ qua uploads/ khi grep, sửa, tìm bản trùng hay đối chiếu; không xóa · áp mọi lượt D0–D5".

Xong khi: ở khung 390 và 768 đổi được ngôn ngữ (có số đo getComputedStyle trong README), không còn nhãn "Chờ backend" cho tính năng repo đã có, không còn lý do chỉ nằm trong title, có `admin-components.css`, README cập nhật Ánh xạ dữ liệu + Việc cho backend + Kiểm hiển thị. Gọi ready_for_verification và sửa hết lỗi verifier báo. Xong thì xuất zip gửi Claude Code kiểm lại trước khi sang D0-3.
