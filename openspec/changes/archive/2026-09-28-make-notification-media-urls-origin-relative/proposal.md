# Proposal

## Why

The production ERP is intentionally reachable through both its on-premise LAN address and a Cloudflare Tunnel domain, but persisted notification destinations and Media Library URLs currently embed `APP_URL`, causing users who enter through one origin to be redirected or served files through the other. These browser-facing links need to remain on the origin the user is actively using without changing where files are stored.

## What Changes

- Persist same-application notification destinations as origin-relative paths so `/notifications` redirects remain on the current LAN or Cloudflare origin.
- Normalize existing notification destinations that use a recognized LAN or Cloudflare application origin, while preserving notification history and state.
- Prevent notification redirects from treating arbitrary external absolute URLs as normal internal destinations.
- Generate public Media Library image and attachment URLs as origin-relative `/storage/...` paths without moving or rewriting stored media.
- Verify direct `Storage::url()` proof links use the correct public disk/path and remain origin-relative.
- Add focused automated coverage only; full-suite execution is outside this change, and browser checks across LAN and Cloudflare origins are performed manually by a human developer.

## Capabilities

### New Capabilities

- `dual-origin-file-access`: Browser-facing product images, avatars, attachments, payment evidence, and return proof files remain accessible through the current LAN or Cloudflare origin.

### Modified Capabilities

- `notifications`: Notification action destinations and redirects become origin-relative, safe for dual-origin access, and repairable for existing persisted rows.

## Impact

- Affected notification code includes `DocumentNotificationService`, `StockNotificationService`, `NotificationService`, `NotificationController`, and the notification repair/sync tooling or a dedicated repair command.
- Affected file URL behavior includes the Laravel public filesystem disk, Spatie Media Library consumers, and settlement proof links using `Storage::url()`.
- No file relocation, media-record rewrite, route/API redesign, email-link change, or Cloudflare topology change is intended.
- Deployment requires a focused legacy-notification repair and human verification from both the LAN address and Cloudflare domain.
