## Why

The POS cash pickup ("Pengambilan Kas") flow currently requires the cashier to manually type a supervisor's email and password for approval. This has two issues: (1) the cashier must know the exact supervisor email, and (2) the supervisor must share their password on a shared POS terminal, creating a credential exposure risk. Additionally, the expected cash amount displayed in the modal is stale — it's rendered server-side at page load and never refreshed, so after transactions occur the displayed number is wrong and the client-side validation cap is incorrect.

## What Changes

- **Replace email+password inputs** in the cash pickup modal Step 2 with a searchable supervisor dropdown and a 6-digit TOTP code input.
- **Supervisor dropdown** fetches eligible supervisors from a new API endpoint — only users with TOTP enabled, active status, correct permissions (`pos.safeDrops.approve` + `pos.supervisor.approval`), and same setting are listed.
- **Backend approval path** adds an OTP-based verification method to `PosSupervisorApprovalService` that looks up by user ID and verifies via `TwoFactorService::verifyCode()` instead of `Hash::check` on password.
- **Pickup endpoint contract** changes from `{supervisor_email, supervisor_password}` to `{supervisor_id, otp_code}`. **BREAKING** for any external consumers of `POST /pos/sessions/{session}/pickup`.
- **Live expected cash** — when the cash pickup modal opens, fetch the current expected cash from the existing session summary endpoint instead of reading the stale `data-expected-cash` DOM attribute.

## Capabilities

### New Capabilities
- `pos-supervisor-otp-approval`: Searchable supervisor dropdown with TOTP verification for POS cash pickup approval, replacing email+password inline authentication.
- `pos-cash-pickup-live-expected`: Live fetch of expected cash amount when the cash pickup modal opens, replacing stale page-load data.

### Modified Capabilities
- `pos-supervisor-cash-finalization`: The supervisor authentication mechanism changes from email+password to user-ID+OTP for the cash pickup (safe drop) flow.

## Impact

- **Backend**: `PosSupervisorApprovalService` gains a new OTP-based approval method. `PosSessionController::pickup()` accepts `supervisor_id` + `otp_code`. New controller/route for supervisor search endpoint.
- **Frontend**: Cash pickup modal Step 2 in `sell.blade.php` — HTML and JS rewritten for dropdown + OTP. Step 1 gains a loading state while fetching live expected cash.
- **API**: `POST /pos/sessions/{session}/pickup` request body changes (breaking). New `GET /pos/sell/supervisors/search` endpoint added.
- **Dependencies**: Relies on existing `TwoFactorService` (Google2FA) and `PosSessionSummaryService`. No new packages needed.
- **Other supervisor flows**: Price overrides, variance overrides still use email+password — noted for future migration.
