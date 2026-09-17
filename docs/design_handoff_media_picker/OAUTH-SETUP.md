# OAuth & Uppy Companion — cấu hình sẵn cho các nguồn cloud

Các nguồn Dropbox / Google Drive / Google Photos / OneDrive / Box / Unsplash trong Media Picker
dùng **Uppy Companion** (server trung gian của Uppy) để OAuth + tải tệp từ dịch vụ về pipeline upload.
Camera (Webcam) và My Device KHÔNG cần Companion.

## 1. Kiến trúc
- Self-host Companion bằng Docker (dự án đã có docker-compose) tại subdomain riêng, ví dụ `https://companion.baoson.net`.
- Frontend Uppy trỏ `companionUrl` tới đó; sau khi user chọn tệp, Companion tải tệp và PUT vào endpoint upload hiện có (presign → R2 → register) hoặc trực tiếp lên R2 qua presigned URL.
- Token OAuth do Companion giữ trong session/Redis — KHÔNG lưu vào DB Laravel.

## 2. Service Docker (thêm vào docker-compose.prod.yml)
```yaml
companion:
  image: transloadit/companion:5
  restart: unless-stopped
  environment:
    COMPANION_SECRET: ${COMPANION_SECRET}            # openssl rand -hex 32
    COMPANION_PREAUTH_SECRET: ${COMPANION_PREAUTH_SECRET}
    COMPANION_DOMAIN: companion.baoson.net
    COMPANION_PROTOCOL: https
    COMPANION_DATADIR: /mnt/companion-data
    COMPANION_CLIENT_ORIGINS: https://admin.baoson.net   # CORS: chỉ admin
    COMPANION_UPLOAD_URLS: https://admin.baoson.net/*    # chống SSRF: chỉ cho upload về app
    COMPANION_REDIS_URL: redis://redis:6379/2
    # --- provider keys (mục 3) ---
    COMPANION_GOOGLE_KEY: ${COMPANION_GOOGLE_KEY}
    COMPANION_GOOGLE_SECRET: ${COMPANION_GOOGLE_SECRET}
    COMPANION_DROPBOX_KEY: ${COMPANION_DROPBOX_KEY}
    COMPANION_DROPBOX_SECRET: ${COMPANION_DROPBOX_SECRET}
    COMPANION_ONEDRIVE_KEY: ${COMPANION_ONEDRIVE_KEY}
    COMPANION_ONEDRIVE_SECRET: ${COMPANION_ONEDRIVE_SECRET}
    COMPANION_BOX_KEY: ${COMPANION_BOX_KEY}
    COMPANION_BOX_SECRET: ${COMPANION_BOX_SECRET}
    COMPANION_UNSPLASH_KEY: ${COMPANION_UNSPLASH_KEY}
    COMPANION_UNSPLASH_SECRET: ${COMPANION_UNSPLASH_SECRET}
  volumes:
    - companion-data:/mnt/companion-data
```
Reverse proxy (Caddy/FrankenPHP hoặc CF Tunnel) trỏ `companion.baoson.net` → container :3020.

## 3. Đăng ký từng nhà cung cấp (việc CON NGƯỜI phải làm — điền key vào .env)
Redirect URI của Companion theo mẫu: `https://companion.baoson.net/<provider>/redirect`

| Nguồn | Đăng ký tại | Redirect URI | Scope/API cần bật | Ghi chú |
|---|---|---|---|---|
| Google Drive | console.cloud.google.com → OAuth client (Web) | `/drive/redirect` | Google Drive API, scope `drive.readonly` | Cần OAuth consent screen; verify app nếu public |
| Google Photos | cùng Google Cloud project, cùng client | `/googlephotos/redirect` | Photos Library API, scope `photoslibrary.readonly` | Dùng chung GOOGLE_KEY/SECRET |
| Dropbox | dropbox.com/developers/apps | `/dropbox/redirect` | Scoped app: `files.metadata.read`, `files.content.read`, `account_info.read` | |
| OneDrive | portal.azure.com → App registrations | `/onedrive/redirect` | Microsoft Graph: `Files.Read.All`, `User.Read`; account type: cá nhân + tổ chức | Key = Application (client) ID |
| Box | app.box.com/developers/console | `/box/redirect` | OAuth 2.0 (user auth), scope Read | |
| Unsplash | unsplash.com/developers | (không OAuth user) | Access Key làm COMPANION_UNSPLASH_KEY | Demo 50 req/h; xin production để tăng. BẮT BUỘC hiện attribution tác giả + trigger download endpoint khi chèn ảnh |

## 4. .env bổ sung (đã dùng ở mục 2)
```
COMPANION_SECRET=
COMPANION_PREAUTH_SECRET=
COMPANION_GOOGLE_KEY=
COMPANION_GOOGLE_SECRET=
COMPANION_DROPBOX_KEY=
COMPANION_DROPBOX_SECRET=
COMPANION_ONEDRIVE_KEY=
COMPANION_ONEDRIVE_SECRET=
COMPANION_BOX_KEY=
COMPANION_BOX_SECRET=
COMPANION_UNSPLASH_KEY=
COMPANION_UNSPLASH_SECRET=
MEDIA_COMPANION_URL=https://companion.baoson.net
```
Expose `MEDIA_COMPANION_URL` cho JS qua config + data-attribute trên island (giống data-presign-url hiện tại).

## 5. Frontend (media-uppy.js mở rộng)
- Cài: `@uppy/dropbox @uppy/google-drive @uppy/google-photos @uppy/onedrive @uppy/box @uppy/unsplash @uppy/webcam`.
- Mỗi plugin: `uppy.use(Dropbox, { companionUrl, companionAllowedHosts })` — KHÔNG dùng UI Dashboard mặc định; render danh sách tệp qua provider views API vào UI thiết kế (xem README mục 5–7).
- Trạng thái "Đã kết nối" (chấm xanh trên tile nguồn): kiểm tra qua Companion session; "Ngắt kết nối" gọi `provider.logout()`.
- Tệp import từ cloud đi qua pipeline register hiện có → magic-byte sniff server-side vẫn là gate thật.

## 6. Bảo mật bắt buộc
- `COMPANION_CLIENT_ORIGINS` chỉ chứa origin admin; `COMPANION_UPLOAD_URLS` chỉ chứa endpoint upload của app (chống SSRF).
- Companion đứng sau HTTPS; secret ≥ 32 bytes ngẫu nhiên.
- Không log access token; Redis riêng DB index.
- Quyền admin: chỉ user có `media.upload` thấy tab Tải lên (gate sẵn có).
