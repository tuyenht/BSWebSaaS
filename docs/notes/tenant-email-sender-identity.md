# Tenant email sender identity (from-name + reply-to)

**Status:** implemented 2026-07-01 (Option A). Applies to every admin/system email a tenant sends
(login OTP, password reset, email verification, notifications).

## Why

A tenant that has NOT configured its own SMTP sends through the **shared platform SMTP**
(`info@baoson.net`). Before this, the recipient saw the platform's identity ("BSWebSaaS") and any
reply landed in the platform inbox, not the customer's. We separate **transport** from **identity**
so a shared-SMTP tenant still sends *as themselves* and replies reach *them*.

## Model — transport vs identity

| Concern | Source | Notes |
|---|---|---|
| **Transport** (which SMTP actually sends) | tenant's own SMTP/OAuth if fully configured, else the platform default | resolved by `TenantMailer::resolve()` |
| **From address** | the transport's own from-address (e.g. `info@baoson.net` on shared SMTP) | **must stay aligned with the sending domain** for SPF/DKIM/DMARC — never the customer's address, or the mail is spam/rejected |
| **From name** | tenant's `from_name`, else the **site name** (tenant brand) | `TenantMailer::applyTenantIdentity()` — the recipient sees "Cơ Khí An Vinh", not "BSWebSaaS" |
| **Reply-To** | the tenant's **`reply_to`** setting | `SetTenantReplyTo` listener stamps it at send time → a reply reaches the customer |

## Requirements — a tenant's email settings (admin → Settings → Email)

The customer configures their sender identity here; **SMTP is optional** (leave it blank to send via
the system's shared server with just their identity):

- **Tên hiển thị người gửi** (`from_name`) — the brand shown as the sender. Defaults to the site name.
- **Email nhận phản hồi** (`reply_to`) — **the customer's real business email**; recipient replies go here.
- **Email gửi hiển thị** (`from_address`) — only meaningful when the tenant runs their own SMTP.
- **SMTP server** (host/port/encryption/user/password) — optional; all-or-nothing. Blank ⇒ shared transport.

The platform default (super-admin scope) still **requires** a real, working SMTP server.

## Deliverability rule (do not regress)

Never set the From address to the customer's own domain while sending through the shared
`baoson.net` SMTP — SPF/DKIM/DMARC align to `baoson.net`, so a mismatched From is spam/rejected
(Gmail/Yahoo enforce this). Route replies with **Reply-To**, keep **From** on the sending domain.

## Implementation

- `App\Modules\Admin\Support\TenantMailer::applyTenantIdentity()` — sets the From-name (Octane-safe, per apply()).
- `App\Modules\Admin\Listeners\SetTenantReplyTo` — on `MessageSending`, stamps `reply_to` (stateless; no Octane leak; never overrides an explicit Reply-To).
- `App\Modules\Admin\Livewire\EmailSettings` — the `replyTo` field + optional-SMTP save.

## Operational caveats (do not trip these)

- **Send must stay synchronous.** The identity mechanism relies on two request-scoped facts:
  `TenantMailer::apply()` mutates the live mail config *in the web request*, and `SetTenantReplyTo`
  reads `CurrentSite` *at send time*. Every auth mail today is synchronous (no notification implements
  `ShouldQueue`), so both hold. **If you ever mark one `ShouldQueue`**, the Horizon worker has neither
  the applied config nor the tenant's `CurrentSite` → From-name reverts to the platform brand and
  Reply-To is dropped. To queue safely you must re-enter the site *and* call `apply()` inside the job.
- **Partial SMTP silently falls back to shared.** `TenantMailer::usable()` requires host **+**
  username **+** password together; a tenant who fills host/username but leaves the password blank is
  treated as "not configured" and sent through the shared transport. "Send test" then reports success
  (the shared transport works), so the tenant may believe their own SMTP is live when it isn't. If this
  causes confusion, surface a "SMTP incomplete — using the shared server" notice on the settings page.
- **Per-IP OTP cap depends on the real client IP — handled at the edge.** `EmailOtp` caps 20 sends/hr
  per source IP via `request()->ip()`. For Cloudflare-proxied (orange) hosts the app would otherwise
  see a Cloudflare edge IP, not the visitor. The edge Caddy resolves the true visitor from the
  authoritative `CF-Connecting-IP` header — trusted only from Cloudflare's published ranges
  (`docker/caddy/Caddyfile`) — and forwards it in `X-Forwarded-For`, so `request()->ip()` is correct
  behind Cloudflare with no app change. Gray (DNS-only) hosts like av1 keep resolving the direct peer.
  Refresh the Cloudflare ranges from `cloudflare.com/ips` if lockouts on legitimate visitors appear.
  (The per-account cap of 6/hr, keyed by user id, is exact regardless and is the primary protection.)

## Follow-ups

- Reconcile `docs/design.md` mail section with the transport-vs-identity split (COUNCIL, propose-first).
- Optional: per-site brand/logo in transactional email templates.
- Optional: an integration test that sends real mail within a tenant (array transport, which fires
  `MessageSending`) and asserts the captured Reply-To — proves the `Event::listen` wiring, not just the
  listener logic the unit test already covers.
