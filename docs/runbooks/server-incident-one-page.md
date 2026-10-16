# Sổ tay sự cố máy chủ — một trang

Dành cho chủ dự án khi website prod có vấn đề và chưa mở được phòng Claude nào. Mọi lệnh gõ sau khi đăng nhập SSH bằng tài khoản root.

Viết 08/10/2026, sửa 16/10/2026, từ những gì đã kiểm trên máy. Các lệnh xem nhanh ở mục 1 và lệnh `du` ở mục 3 chỉ xem, không đổi gì.

**Chuẩn bị sẵn:** một điện thoại có ứng dụng SSH đăng nhập được vào máy chủ, và đăng nhập được GitHub (để đọc sổ tay này khi máy chủ không trả lời).

## 1. Xem nhanh: máy đang bị gì? (1 phút)

```
curl -s -o /dev/null -m 10 -w "%{http_code}\n" https://av1.baoson.net/
docker ps -a --format '{{.Names}} | {{.Status}}' | grep prod
free -m
df -h /
```

| Thấy gì | Nghĩa là | Xem mục |
|---|---|---|
| Dòng đầu in `200` | Website đang chạy; vấn đề (nếu có) ở chỗ khác | — |
| Một dịch vụ prod `Exited` hoặc `Restarting` | Dịch vụ đó sập | 2 |
| `df` báo `Use%` từ 90% trở lên | Ổ sắp đầy hoặc đã đầy | 3 |
| `free`: cột `available` dưới 500 và dòng `Swap` gần hết | Hết bộ nhớ | 4 |
| Không SSH được, hoặc mọi lệnh đều treo | Máy treo | 5 |

## 2. Một dịch vụ prod sập

Bảy dịch vụ prod: `bsweb_prod_app` (website), `bsweb-prod-caddy-1` (cổng vào, chứng chỉ), `bsweb_prod_pg` (cơ sở dữ liệu), `bsweb_prod_pgbouncer`, `bsweb_prod_valkey`, `bsweb_prod_horizon` (việc nền, gửi thư), `bsweb_prod_scheduler` (việc theo giờ). Tất cả đặt tự chạy lại.

Xem vì sao nó sập, rồi bật lại đúng dịch vụ đó:

```
docker logs --tail 40 bsweb_prod_app
docker restart bsweb_prod_app
```

- Chỉ dùng `docker restart <tên>`. **Không** gõ `docker compose up`, `down` hay `recreate` trong thư mục prod: lệnh đó tạo lại dịch vụ từ ảnh và tệp cấu hình hiện có, có thể đưa lên một bản khác bản đang chạy.
- Cơ sở dữ liệu (`bsweb_prod_pg`) sập và nhật ký nói tới hỏng dữ liệu hoặc hết chỗ: **dừng lại**, không bật đi bật lại. Xử lý ổ đầy (mục 3) trước; khôi phục dữ liệu theo `docs/runbooks/prod-db-backup-restore.md`.
- Sập ngay sau một lần deploy: quay về bản trước theo `docs/runbooks/prod-deploy.md`.

## 3. Ổ đầy

```
du -xsh /tmp /var/lib/containerd /var/log /root /home 2>/dev/null | sort -rh
```

Ba chỗ xoá được an toàn, theo thứ tự:

```
find /tmp/snap-private-tmp/snap.chromium/tmp -mindepth 1 -maxdepth 1 ! -name .snap -mmin +120 -exec rm -rf {} +
journalctl --vacuum-size=200M
docker builder prune -f
```

- Lệnh 1: hồ sơ tạm của trình duyệt kiểm màn hình, đã dùng xong hơn 2 giờ (ngày 08/10 chỗ này lên 12 GB).
- Lệnh 2: nhật ký hệ thống cũ. Lệnh 3: phần đệm dựng ảnh Docker.
- **Không** xoá gì trong `/var/lib/docker/volumes` (dữ liệu thật), `/opt/bswebsaas/prod`, `/swapfile*`.
- **Không** gõ `docker system prune` hay `docker image prune -a`: có thể xoá ảnh mà prod cần để khởi động lại.

Sau khi có chỗ trống, nếu cơ sở dữ liệu đã dừng vì hết chỗ: `docker restart bsweb_prod_pg`, chờ 30 giây, rồi `docker restart bsweb_prod_app`.

## 4. Hết bộ nhớ

```
ps -eo rss,user,comm --sort=-rss | head -n 12
```

Thường là trình duyệt (`chrome`) hoặc nhiều phòng Claude cùng mở. Giải phóng theo thứ tự ít hại nhất:

1. Tắt trình duyệt kiểm màn hình của root (không đụng Chrome của designbot): `pkill -u root -f snap-private-tmp-cdp`
2. Đóng các phòng Claude không dùng: `tmux ls` để xem, `tmux kill-session -t <tên phòng>` để đóng. Hội thoại không mất, mở lại bằng `cc <tên>`.
3. Chrome của designbot (máy thiết kế):
   - Website thật sập vì hết bộ nhớ: đóng Chrome của designbot NGAY (`pkill -u designbot -x chrome`; đúng chữ `-x`, vì `-f chrome` còn tắt luôn phòng Claude của designbot), kể cả khi một lượt thiết kế đang chạy dở. Website quan trọng hơn lượt thiết kế; lượt dở làm lại được.
   - Chỉ chậm, website vẫn chạy: vào phòng bằng `ccd`, bảo designbot đóng hẳn Chrome rồi mở lại **giữa hai lượt** (lượt đang chạy đã xong và đã lưu zip), không ngắt giữa chừng.
   - Thấy hộp "Page Unresponsive": bấm **Wait**, không bấm "Exit page".

Không tắt dịch vụ prod để lấy bộ nhớ: cả bảy dịch vụ cộng lại dùng chưa tới 0,5 GB.

## 5. Máy treo, không SSH được

Khởi động lại máy từ trang quản trị của nhà cung cấp. Sau khi máy lên (lần 08/10 mất khoảng 7 phút là mọi thứ chạy lại):

1. Chạy lại bốn lệnh ở mục 1; prod tự lên, không cần bật tay.
2. Mở lại các phòng: `cc BSWebSaaS-TT`, `cc BSWebSaaS-Design`, `ccd` rồi `claude --chrome -c`. Nếu `cc` báo không có lệnh (lệnh ngắn chỉ có khi đăng nhập theo cách thường), gõ lệnh đầy đủ: `claude-room BSWebSaaS-TT`, `claude-room BSWebSaaS-Design`.
3. Bảo một phòng Claude kiểm lại toàn bộ.

## 6. Khi nào gọi AI, khi nào tự làm

- Website trả lời bình thường nhưng một chức năng sai: mở phòng Claude, tả lại hiện tượng. Không cần sổ tay này.
- Website không trả lời: làm mục 1, rồi mục tương ứng; xong mới mở phòng Claude để tìm nguyên nhân gốc.
- Nghi lộ mật khẩu hoặc mã truy cập: đổi ngay ở nơi cấp (GitHub, Cloudflare, hộp thư), rồi báo một phòng Claude để rà chỗ đang dùng mã cũ.
