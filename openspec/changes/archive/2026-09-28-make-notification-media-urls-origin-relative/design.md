# Design

## Context

See `proposal.md` for motivation. Production intentionally supports two browser origins: an on-premise LAN address and a Cloudflare Tunnel domain. `APP_URL` remains useful as a fallback for processes without an HTTP request, but it is currently also embedded in persisted notification actions and public-disk media URLs.

Notification list links first reach `/notifications/{notification}/read` on the current origin, then redirect to a persisted `action_url`. Document and stock notification producers currently persist absolute `route()` results. Spatie Media Library uses the `public` filesystem disk, whose configured URL is `APP_URL/storage`. A smaller set of settlement proof views uses `Storage::url()` and requires confirmation that the selected disk matches the file's actual public location.

## Goals / Non-Goals

**Goals:**

- Establish relative paths as the invariant for same-application notification destinations.
- Keep notification clicks on the origin used to open `/notifications`.
- Make existing public Media Library URLs work unchanged from both supported origins.
- Repair legacy notification origins through an explicit, repeatable operator action.
- Verify behavior with focused automated tests and a documented human browser check.

**Non-Goals:**

- Do not relocate files, change media associations, or migrate storage providers.
- Do not change email URLs, PDF filesystem paths, Cloudflare routing, or general route generation.
- Do not make private files public or redesign file authorization.
- Do not require or plan a full application test-suite run.
- Do not automate browser testing; a human developer performs the browser verification.

## Decisions

### 1. Store notification actions as relative application paths

Notification producers will request non-absolute route output. A shared notification action normalizer will enforce a leading-slash application path, preserve query strings and fragments, and reject protocol-relative or unrecognized external destinations before persistence or redirect.

This is preferred over switching `APP_URL` to the Cloudflare domain because either fixed origin breaks the other supported access mode. Resolving a stored path in the active request also works for notifications created by queues or Artisan commands.

### 2. Keep `/notifications/{notification}/read` as the controlled redirect boundary

The controller will continue to authorize the notification, establish its setting context, and mark it read before redirecting. It will redirect only to a validated internal relative path; invalid or unsafe legacy values will fall back to the notification index rather than leave the application.

This preserves current read-state semantics while preventing stored absolute values from acting as open redirects.

### 3. Repair legacy rows with a dedicated idempotent command

An operator command will accept an explicit allowlist of legacy origins, support a report/dry-run mode, and normalize only URLs matching those origins. It will retain path, query, and fragment components and update only `action_url`.

An explicit command is preferred over a schema migration because the production origins are deployment data, not schema knowledge, and the operator should be able to preview affected and unrecognized rows. Re-running the command must be safe.

### 4. Configure the public filesystem URL as an origin-relative storage prefix

The `public` disk URL will use a dedicated setting with `/storage` as its default rather than concatenating `APP_URL`. Spatie Media Library can then continue using the existing public disk and public symlink while emitting `/storage/...` URLs.

`ASSET_URL` will not be introduced or forced. Static `asset()` calls should continue following the active request, and `APP_URL` remains the fallback origin for unrelated background behavior.

### 5. Audit direct storage links without changing the global default disk

The implementation will trace where settlement proof paths are written and make their read-side disk explicit if necessary. It will not change `FILESYSTEM_DRIVER=local` globally, because that could alter unrelated upload destinations. Existing files must remain at their current paths.

### 6. Use focused verification only

Automated verification will target notification URL normalization, redirect safety, the legacy repair command, public-disk URL generation, and any adjusted direct proof-link disk behavior. No task will run or require the full suite.

A human developer will verify representative notification, product image, avatar, attachment, POS payment image, and proof links once through the LAN origin and once through the Cloudflare origin.

## Risks / Trade-offs

- **[Risk] Some stored notification actions may use an unexpected historical origin.** → The repair command reports but does not rewrite unrecognized origins; the operator can review and rerun with an additional explicit origin.
- **[Risk] A relative public-disk URL could expose an incorrect symlink/path assumption.** → Confirm `public/storage` and representative existing media before deployment, then use focused URL and file-existence checks.
- **[Risk] Direct `Storage::url()` proof paths may have been written to a disk different from the view's implicit default.** → Trace the write path and specify the correct disk locally instead of changing the global default.
- **[Risk] Queue workers retain old configuration.** → Clear/cache configuration and restart long-lived workers during deployment.
- **[Trade-off] Relative links are unsuitable when copied into an external channel without an application origin.** → This change is limited to in-browser notification and file surfaces; external email links remain out of scope.

## Migration Plan

1. Deploy notification and filesystem URL changes.
2. Clear and rebuild Laravel configuration cache, then restart long-lived queue workers.
3. Run focused automated tests only.
4. Preview the legacy notification repair using the production LAN and Cloudflare origins.
5. Apply the repair and retain its reported counts for deployment evidence.
6. Have a human developer perform the documented browser checks from both origins.

Rollback consists of restoring the previous code/configuration. Already normalized notification paths remain compatible because relative internal redirects are valid under both the old and new request flows; no file rollback or database restoration is required.
