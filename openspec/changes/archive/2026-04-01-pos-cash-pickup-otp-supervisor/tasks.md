## 1. Supervisor Search Endpoint (Backend)

- [x] 1.1 Create `PosSellSupervisorController` with a `search` method that queries eligible supervisors (active, same setting, TOTP enabled+confirmed, has `pos.safeDrops.approve` and `pos.supervisor.approval` permissions). Accept `q` and `limit` query params, return `{results: [{id, name, email}]}`.
- [x] 1.2 Register route `GET /pos/sell/supervisors/search` in `Modules/Pos/Routes/web.php` with `auth`, `pos.enabled`, `can:pos.access` middleware. Name: `pos.sell.supervisors.search`.
- [x] 1.3 Write Feature test for supervisor search: verify only TOTP-enabled users with correct permissions are returned, verify setting scoping, verify `q` filter works on name and email, verify empty results.

## 2. OTP-Based Supervisor Approval (Backend)

- [x] 2.1 Add `approveSafeDropWithOtp(int $supervisorId, string $otpCode, PosSession $session, float $amount, int $cashierId)` method to `PosSupervisorApprovalService`. Look up by ID, run eligibility checks (active, setting, permissions, TOTP enabled), verify OTP via `TwoFactorService::verifyCode()`, record `PosSupervisorApproval`.
- [x] 2.2 Modify `PosSessionController::pickup()` to accept `supervisor_id` + `otp_code` instead of `supervisor_email` + `supervisor_password`. Update validation rules. Call new `approveSafeDropWithOtp()` method.
- [x] 2.3 Write Feature test for OTP-based pickup: valid OTP succeeds, invalid OTP returns 422, supervisor without TOTP returns 422, non-existent supervisor returns 422, amount exceeding expected cash returns 422.

## 3. Live Expected Cash Fetch (Frontend)

- [x] 3.1 Modify the pickup button click handler in `sell.blade.php` to fetch `GET /pos/sessions/{id}/summary` (Accept: application/json) when the modal opens, instead of reading `data-expected-cash` from DOM.
- [x] 3.2 Add loading state to expected cash display: show spinner while fetching, disable amount input and "Lanjut" button until fetch completes.
- [x] 3.3 Handle fetch failure: show error message "Gagal memuat data kas. Coba lagi." with a retry mechanism, keep amount input disabled on failure.

## 4. Supervisor Dropdown + OTP UI (Frontend)

- [x] 4.1 Replace Step 2 HTML in cash pickup modal: remove email+password inputs, add supervisor search input with results container, selected supervisor display area with clear button, and 6-digit OTP code input. Wire JS route URL for supervisor search endpoint.
- [x] 4.2 Implement supervisor search JS: debounced input (250ms) → fetch to supervisor search endpoint → render results as `list-group-item` buttons (following POS customer search pattern). Track `latestSupervisorRequestId` for stale response handling.
- [x] 4.3 Implement supervisor select/clear: click result → store `selectedSupervisorId` and show name with clear button, hide search input. Clear button → reset state, show search input. Focus OTP input after selection.
- [x] 4.4 Implement "Konfirmasi Pengambilan" button validation: disabled until both a supervisor is selected AND a 6-digit OTP code is entered. Submit sends `{amount, supervisor_id, otp_code}` to pickup endpoint.
- [x] 4.5 Handle empty supervisor results: display "Tidak ada supervisor dengan OTP aktif." message in results area.

## 5. Integration Testing

- [x] 5.1 End-to-end manual test: open POS, complete a sale, open cash pickup modal → verify expected cash is live (not stale), search for supervisor, select, enter OTP, confirm pickup succeeds.
- [x] 5.2 Verify modal reset: close and reopen modal → supervisor selection cleared, OTP cleared, expected cash re-fetched.
