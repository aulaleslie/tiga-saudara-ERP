# Tasks

## 1. Notification Destinations

- [x] 1.1 Add a shared same-application notification action normalizer that accepts valid origin-relative paths, preserves query/fragment components, and rejects protocol-relative, malformed, and unrecognized external destinations; verify with focused unit tests for accepted and rejected inputs
- [x] 1.2 Update document and stock notification producers to persist relative route paths in HTTP and console contexts; verify with focused notification service tests that generated `action_url` values contain no scheme or host
- [x] 1.3 Harden the notification read controller to redirect only to normalized internal paths while preserving authorization, setting selection, and mark-as-read behavior; verify with focused feature tests using LAN and Cloudflare request hosts plus an unsafe stored destination

## 2. Legacy Notification Repair

- [x] 2.1 Implement an idempotent notification action URL repair command with explicit allowed-origin inputs and preview/dry-run reporting; verify focused command tests cover LAN URLs, Cloudflare URLs, queries/fragments, relative rows, and unrecognized origins
- [x] 2.2 Verify the repair changes only `action_url` and preserves notification read, resolved, fingerprint, recipient, setting, and timestamp state in the focused command tests

## 3. Images and Files

- [x] 3.1 Configure the public filesystem disk to use a dedicated origin-relative `/storage` URL without changing its root, visibility, symlink, or the global default disk; verify a focused filesystem test produces `/storage/...` for the public disk under both request origins
- [x] 3.2 Verify representative Spatie Media Library product images, avatars, document attachments, payment attachments, POS payment images, and return attachments continue using the existing public disk while generating origin-relative URLs in focused tests
- [x] 3.3 Trace settlement proof file write locations and make their read-side disk explicit only where needed; verify focused tests show existing proof paths resolve through a valid origin-relative public URL without relocating files

## 4. Focused Verification and Deployment Handoff

- [x] 4.1 Run only the focused notification, repair-command, filesystem, media, and proof-link test files or filters introduced/affected by this change and record the passing commands; do not run or require the full application test suite
- [x] 4.2 Create a human-developer browser verification checklist covering `/notifications`, a product image, an avatar, a general attachment, a POS payment image, and a return/settlement proof through both the LAN and Cloudflare origins; verify the checklist also includes config-cache clearing, worker restart, repair preview, and repair execution steps
