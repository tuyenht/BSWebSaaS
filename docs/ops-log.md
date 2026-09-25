# Nhật ký vận hành (ops log)

> Ghi các thao tác vận hành ở tầng **máy chủ / hạ tầng** không thuộc code repo: cài/gỡ gói hệ thống,
> thay đổi thủ công trên host, sự cố + cách xử lý. Quy trình lặp lại nằm ở `docs/runbooks/`; file này
> là NHẬT KÝ theo thời gian (mới nhất trên cùng). Máy chủ dùng chung **dev + prod: `103.82.27.129`**.
> Ràng buộc cài/gỡ gói hệ thống: xem `CLAUDE.md` §7.1.

## 2026

### 2026-09-25 — Cài rồi gỡ ImageMagick (công cụ đo một lần cho PR #92)

- **Cài:** `2026-09-25 11:48 (+07)` — `apt-get install -y imagemagick` trên host `103.82.27.129`, kéo theo tổng **38 gói** (1 chính + 37 phụ thuộc).
- **Lý do:** pixel-diff before/after cho PR #92 (AA màu + focus) — dùng `compare` / `identify` / `convert` để chứng minh thay đổi navy→accent KHÔNG đổi light-mode. Công cụ đo một lần, **không** phải phụ thuộc của app (app chạy trong container, không có extension `imagick`; composer/config không tham chiếu ImageMagick).
- **Gỡ:** `2026-09-25 17:16 (+07)` — `apt-get remove -y <38 gói>` (KHÔNG `autoremove`, chỉ đúng bộ đã cài). Mô phỏng `apt-get -s remove` + thực tế đều gỡ đúng 38 gói: **docker-ce không bị đụng** (bản đang cài không phụ thuộc `libltdl7`; tên xuất hiện trong `apt-cache rdepends` chỉ là cross-version artifact), `fontconfig-config` vẫn thoả nhờ `fonts-dejavu-core` đã cài. Giải phóng ~99.9 MB.
- **Hậu kiểm:** dpkg còn 0/38 gói; `convert`/`compare`/`identify` biến mất; docker daemon + prod containers Up/healthy; `/`→200, `/lien-he`→200, `/admin`→302, `/admin/login`→200; app/horizon 0 lỗi.
- **38 gói:** `fonts-droid-fallback`, `fonts-noto-mono`, `fonts-urw-base35`, `ghostscript`, `imagemagick`, `imagemagick-6-common`, `imagemagick-6.q16`, `libdjvulibre-text`, `libdjvulibre21`, `libfftw3-double3`, `libfontenc1`, `libgs-common`, `libgs10`, `libgs10-common`, `libidn12`, `libijs-0.35`, `libimath-3-1-29t64`, `libjbig2dec0`, `libjxr-tools`, `libjxr0t64`, `liblqr-1-0`, `libltdl7`, `libmagickcore-6.q16-7-extra`, `libmagickcore-6.q16-7t64`, `libmagickwand-6.q16-7t64`, `libnetpbm11t64`, `libopenexr-3-1-30`, `libopenjp2-7`, `libpaper-utils`, `libpaper1`, `libraw23t64`, `libwebpdemux2`, `libwebpmux3`, `libwmflite-0.2-7`, `netpbm`, `poppler-data`, `xfonts-encodings`, `xfonts-utils`.
- **Rút ra → `CLAUDE.md` §7.1:** không cài/gỡ gói hệ thống trên host chung khi chưa được owner duyệt; công cụ đo phải nằm trong repo hoặc container.
