# Nhật ký vận hành (ops log)

> Ghi các thao tác vận hành ở tầng **máy chủ / hạ tầng** không thuộc code repo: cài/gỡ gói hệ thống,
> thay đổi thủ công trên host, sự cố + cách xử lý. Quy trình lặp lại nằm ở `docs/runbooks/`; file này
> là NHẬT KÝ theo thời gian (mới nhất trên cùng). Máy chủ dùng chung **dev + prod: `103.82.27.129`**.
> Ràng buộc cài/gỡ gói hệ thống: xem `CLAUDE.md` §7 (Host safety).

## 2026

### 2026-09-28 — Deploy 2 lần (Q14 · lịch sử phiên bản) + dọn phiên Claude nhàn rỗi

- **Máy chủ (sáng):** swap đầy (2042/2047 MB) vì 12 phiên Claude nhàn rỗi trong tmux (~3,2 GB, mỗi phiên kèm 1 container `boost:mcp`). Owner cho đóng hết, chỉ giữ phiên đang làm → RAM dùng 5,3 → 2,4 GB, swap 2,0 → 0,7 GB. Prod không bị đụng.
- **Deploy 1:** `10:30 (+07)` — prod `0cd245e` → `d9eded6` (6 commit: 5 tài liệu + module tính năng đọc qua `Tenancy\Context`), 0 migration. Dump: `prod-predeploy-0cd245e-20260928-033043.sql.gz`. Hậu kiểm: 30/30 địa chỉ trong sitemap → 200, log 0 lỗi.
- **Deploy 2:** `14:18 (+07)` — prod `d9eded6` → `405afac` (2 commit: lưu trang hẹn giờ/riêng tư #117 + lịch sử phiên bản #118). 1 migration `2023_01_01_000000_create_entry_revisions_table` (34 ms, chạy bằng `pgsql_admin`). Dump: `prod-predeploy-d9eded6-20260928-071835.sql.gz`. Dữ liệu: `cms:snapshot-published` ghi 20 bản gốc, khớp 20 bản dịch đang công khai (1 site, 0 lỗi).
- **Hậu kiểm deploy 2:** `/` `/lien-he` (có ô đồng ý) `/admin/login` → 200, `/admin` → 302, qua Caddy → 200; 30/30 địa chỉ sitemap → 200; log app + horizon 0 lỗi; `entry_revisions` bật RLS + FORCE; `cms:prune-revisions` chạy 00:00 hằng ngày.
- **Script deploy (bản cục bộ):** lệnh rollback chỉ gỡ ô đồng ý khi quay về code trước `bfaf682` — trước đó nó luôn gỡ, kể cả khi code đích vẫn hỗ trợ ô này (bắt buộc về pháp lý); thêm bước `cms:snapshot-published` khi lệnh có trong bản mới. Rollback về `d9eded6` không cần khôi phục dump (lô chỉ thêm bảng).

### 2026-09-27 — Deploy lô #94–#113 + diễn tập khôi phục lần đầu

- **Deploy:** `23:29 (+07)` — owner chạy script deploy có ghim SHA: prod `9daf094` → `0cd245e` (33 commit, 0 migration, không đổi dependency). Dump trước deploy: `/opt/bswebsaas/db-backups/prod-predeploy-9daf094-20260927-162912.sql.gz`. Dữ liệu: ô đồng ý thêm vào 1 form liên hệ (`forms:sync-contact-fields`), 7 chuyên mục được ghi `path` (`cms:rebuild-taxonomy-paths`).
- **Hậu kiểm:** `/`→200, `/lien-he`→200 (có ô đồng ý), `/tin-tuc`→200, `/admin`→302, `/admin/login`→200; log app 0 dòng lỗi; Horizon chạy.
- **Lần preflight đầu (23:22) dừng:** tra CI của commit (`gh run list --commit`) trả rỗng dù CI đã xanh; cùng lệnh chạy lại vài phút sau ra `completed:success`. Nguyên nhân chưa xác định (không tái hiện được); prod không bị đụng. Script deploy giờ in lỗi thật của `gh` và thử lại 3 lần.
- **Diễn tập khôi phục lần đầu:** `23:30 (+07)` — owner chạy tay `scripts/restore-drill-prod-db.sh` trên bản prod: đạt (chi tiết: `runbooks/prod-db-backup-restore.md` §9). Log `/var/log/bsweb-restore-drill.log` (600).
- **Crontab (owner, 23:4x):** backup `15 3 * * *` chạy từ `/opt/bswebsaas/prod/scripts/` (đọc `.env` prod — đủ 6 biến bắt buộc) + diễn tập `45 4 1 * *` → `/var/log/bsweb-restore-drill.log` (runbook §3); dòng cũ chạy script từ `dev/` đã bỏ.
- **Tạo lại container (owner, 23:5x):** `docker compose up -d` trong bản prod — app/horizon/scheduler chạy `bsweb/app:prod` (`e00aba…`, PHP 8.5.7), PgBouncer theo digest; valkey/pg/caddy không đổi. Hậu kiểm: app + horizon healthy, 0 restart; `/` `/lien-he` (có ô đồng ý) `/tin-tuc` `/admin/login` → 200, `/admin` → 302; log 0 lỗi. Build cũ `da1e043…` đã không còn trong kho image từ trước (không giữ được làm đường lùi); đường lùi = `bsweb/app:prod` hiện tại, cùng runtime đã đo.
- **Giám sát (owner):** healthchecks.io 2 check (backup đêm `15 3 * * *` grace 2h · diễn tập `45 4 1 * *` grace 1 ngày, múi `Asia/Ho_Chi_Minh`), URL trong `.env` prod (`BACKUP_`/`RESTORE_HEALTHCHECK_URL`, script host tự đọc); diễn tập chạy lại 23:52 để thử ping — đạt, không có cảnh báo ping. UptimeRobot cho av1.baoson.net + website.baoson.net.
- **Backup đêm đầu từ bản prod (28/09 03:15 +07):** upload `db-bswebsaas-20260927-201501.sql.gz`, prune giữ 12 / xoá 2 (bản 23/08 quá 35 ngày; bản 19/09 là bản thừa trong tuần ISO của 20/09 — đúng chính sách §5), không có cảnh báo ping healthcheck.

### 2026-09-25 — Cài rồi gỡ ImageMagick (công cụ đo một lần cho PR #92)

- **Cài:** `2026-09-25 11:48 (+07)` — `apt-get install -y imagemagick` trên host `103.82.27.129`, kéo theo tổng **38 gói** (1 chính + 37 phụ thuộc).
- **Lý do:** pixel-diff before/after cho PR #92 (AA màu + focus) — dùng `compare` / `identify` / `convert` để chứng minh thay đổi navy→accent KHÔNG đổi light-mode. Công cụ đo một lần, **không** phải phụ thuộc của app (app chạy trong container, không có extension `imagick`; composer/config không tham chiếu ImageMagick).
- **Gỡ:** `2026-09-25 17:16 (+07)` — `apt-get remove -y <38 gói>` (KHÔNG `autoremove`, chỉ đúng bộ đã cài). Mô phỏng `apt-get -s remove` + thực tế đều gỡ đúng 38 gói: **docker-ce không bị đụng** (bản đang cài không phụ thuộc `libltdl7`; tên xuất hiện trong `apt-cache rdepends` chỉ là cross-version artifact), `fontconfig-config` vẫn thoả nhờ `fonts-dejavu-core` đã cài. Giải phóng ~99.9 MB.
- **Hậu kiểm:** dpkg còn 0/38 gói; `convert`/`compare`/`identify` biến mất; docker daemon + prod containers Up/healthy; `/`→200, `/lien-he`→200, `/admin`→302, `/admin/login`→200; app/horizon 0 lỗi.
- **38 gói:** `fonts-droid-fallback`, `fonts-noto-mono`, `fonts-urw-base35`, `ghostscript`, `imagemagick`, `imagemagick-6-common`, `imagemagick-6.q16`, `libdjvulibre-text`, `libdjvulibre21`, `libfftw3-double3`, `libfontenc1`, `libgs-common`, `libgs10`, `libgs10-common`, `libidn12`, `libijs-0.35`, `libimath-3-1-29t64`, `libjbig2dec0`, `libjxr-tools`, `libjxr0t64`, `liblqr-1-0`, `libltdl7`, `libmagickcore-6.q16-7-extra`, `libmagickcore-6.q16-7t64`, `libmagickwand-6.q16-7t64`, `libnetpbm11t64`, `libopenexr-3-1-30`, `libopenjp2-7`, `libpaper-utils`, `libpaper1`, `libraw23t64`, `libwebpdemux2`, `libwebpmux3`, `libwmflite-0.2-7`, `netpbm`, `poppler-data`, `xfonts-encodings`, `xfonts-utils`.
- **Rút ra → `CLAUDE.md` §7 (Host safety):** không cài/gỡ gói hệ thống trên host chung khi chưa được owner duyệt; công cụ đo phải nằm trong repo hoặc container.
