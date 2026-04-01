## Context

The POS cash pickup ("Pengambilan Kas") modal currently uses a two-step flow: Step 1 collects the pickup amount, Step 2 collects supervisor email + password for approval. The supervisor is authenticated via `PosSupervisorApprovalService::approveSessionAction()` which looks up users by email and verifies via `Hash::check` against their password.

This creates two problems:
1. **Credential exposure** — the supervisor must type their password on a shared POS terminal, or the cashier must know it.
2. **Stale expected cash** — the `data-expected-cash` attribute is rendered at page load and never refreshed, so the amount shown after transactions is outdated.

The codebase already has a TOTP infrastructure (`TwoFactorService` using `PragmaRX\Google2FA`) and the POS customer search follows a vanilla JS pattern (debounced fetch + DOM rendering) that we can replicate for supervisors.

## Goals / Non-Goals

**Goals:**
- Replace email+password supervisor auth in cash pickup with a searchable dropdown of eligible supervisors + TOTP code input.
- Fetch live expected cash from the server when the cash pickup modal opens, eliminating the stale data issue.
- Add a new API endpoint to search for TOTP-enabled supervisors eligible for safe drop approval.
- Add an OTP-based approval path in `PosSupervisorApprovalService` for cash pickup.

**Non-Goals:**
- Migrating other supervisor flows (price overrides, variance overrides) to OTP — noted for future work, same pattern applies.
- Adding TOTP replay protection (`verifyKeyNewer` timestamp tracking) — pre-existing gap, out of scope.
- Creating a Livewire or Alpine.js component — POS sell page is vanilla JS throughout.
- Fallback to password for supervisors without TOTP — Option A: enforce TOTP, non-TOTP supervisors simply don't appear.

## Decisions

### 1. Supervisor search endpoint location

**Decision**: New route `GET /pos/sell/supervisors/search` handled by a new method on an existing POS controller (or a small dedicated `PosSellSupervisorController`).

**Rationale**: Follows the existing pattern of `/pos/sell/customers/search` and `/pos/sell/products/search`. Keeps supervisor search scoped to the POS sell context.

**Alternatives considered**:
- Reusing the async approval queue's supervisor list — too coupled to the approval request flow; that system lists all supervisors, not filtered by TOTP status.

### 2. Supervisor eligibility criteria

**Decision**: The search endpoint returns users matching ALL of:
- `is_active = true`
- Same `setting_id` as current user (Super Admin bypasses)
- `two_factor_secret IS NOT NULL` AND `two_factor_confirmed_at IS NOT NULL`
- Has permissions: `pos.safeDrops.approve` AND `pos.supervisor.approval`

**Rationale**: Mirrors the existing checks in `PosSupervisorApprovalService::approveSessionAction()` plus the TOTP enrollment requirement (Option A — enforce TOTP).

### 3. OTP approval method — new method, not modifying old one

**Decision**: Add `approveSafeDropWithOtp(int $supervisorId, string $otpCode, PosSession $session, float $amount)` as a new method in `PosSupervisorApprovalService`. The existing `approveSafeDrop()` (email+password) remains untouched.

**Rationale**: Other flows (price override, variance override) still use email+password. Keeping both paths avoids breaking those. The pickup controller switches to the new method.

### 4. Frontend pattern — vanilla JS mirroring POS customer search

**Decision**: Implement the supervisor dropdown using the same vanilla JS pattern as the POS customer search: text input with debounced fetch, results rendered as `list-group-item` buttons, click-to-select with selected state display and clear button.

**Rationale**: Consistency with the existing POS page. No new JS libraries or framework components needed. The POS page does not use Alpine or Livewire for interactive elements.

### 5. Live expected cash — fetch from existing session summary endpoint

**Decision**: When the pickup button is clicked, show a loading state and `GET /pos/sessions/{id}/summary` (with `Accept: application/json`) to get the live `expected_cash_total`. The session ID comes from the stable `data-session-id` DOM attribute.

**Rationale**: The endpoint already exists (`pos.sessions.summary`) and returns the calculated expected cash via `PosSessionExpectedCashCalculator`. No new backend work needed for this part.

### 6. Pickup endpoint contract change

**Decision**: `POST /pos/sessions/{session}/pickup` changes from `{amount, supervisor_email, supervisor_password}` to `{amount, supervisor_id, otp_code}`. This is a breaking change.

**Rationale**: Clean break — no dual-mode parsing. The endpoint is only consumed by the POS sell page JS (no external API consumers known). The old fields are removed in one step.

## Risks / Trade-offs

- **[No TOTP-enrolled supervisors]** → If no supervisor has TOTP enabled, the dropdown will be empty and cash pickup is blocked. **Mitigation**: UI shows clear message "Tidak ada supervisor dengan OTP aktif. Hubungi admin." Deployment checklist must ensure at least one supervisor has TOTP set up.
- **[Breaking API contract]** → The pickup endpoint request body changes. **Mitigation**: Only consumed by the POS sell page JS (same deploy artifact). No known external consumers.
- **[TOTP replay within window]** → The existing `TwoFactorService::verifyCode()` does not track last-used timestamps, so the same code could theoretically be reused within the 90-second window. **Mitigation**: Low risk for cash pickup (not a high-frequency action). Tracked as a separate hardening item.
- **[Session summary fetch latency]** → Fetching session summary on modal open adds a network round trip. **Mitigation**: Show a spinner/loading state; the endpoint is lightweight (single DB query with lock). Expected <200ms.
