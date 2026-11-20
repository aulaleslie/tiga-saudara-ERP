# Design

## Context

See proposal.md for motivation. The application uses a centralized `app/Config/Permissions.php` registry, Spatie authorization, `PermissionHelper` role forms, and `PosPermissionMatrix` bundles/clusters. `permissions:sync` creates permission records and synchronizes Admin to all permissions; Super Admin bypasses Gate checks.

Cash Pickup currently fetches session summary JSON, uses `expected_cash_total` as an input cap, embeds cash in nav attributes, and displays `expected_cash_after` on success. Both `PosSessionController` and `PosSafeDropService` reject pickups above cached expected cash. `PosSessionExpectedCashCalculator` already sums signed ledger movements without a zero floor. Session summaries expose cached and calculated balances, cash events, and reconstructable components. Session lists and finalization also disclose expected cash and variance.

## Goals / Non-Goals

**Goals:** Separate disclosure from operational authorization; apply one permission consistently at response boundaries; retain authoritative accounting and usable blind cash operations; record signed balances accurately.

**Non-Goals:** Change pickup eligibility, OTP authentication, session lifecycle, close/finalization variance approval thresholds, historical ledger events, ordinary sale/checkout workflows, or existing Admin synchronization policy. This permission cannot erase amounts a user already saw or independently recorded; protection targets POS disclosures and reconciliation data.

## Decisions

### 1. Integrate with unified assignment

Add `pos.sessions.viewExpectedCash` with label `Lihat Ekspektasi Kas` in the POS registry group and the matrix's explicit exceptions cluster. Do not add it to manager, cashier, or floor-staff bundles, and do not infer it from session ownership, role names, reporting, or supervisor permissions. Use Gate-based checks to retain Super Admin bypass. Existing permission sync grants Admin the permission as designed. A separate registry or custom role-name guard would bypass established management conventions.

### 2. Keep internal calculations complete; project disclosure per requester

Use a shared permission-aware presentation/projection helper at POS HTTP/view boundaries. Keep ledger calculators, approval services, and audit persistence complete. For users without visibility, omit protected keys instead of replacing real balances with zero; expose a non-monetary visibility flag where scripts need it. Recheck permission on each request. Avoid mutating shared service data in a way that breaks internal finalization or other callers.

Protect calculated/cached/after-pickup balances, variance, aggregate cash components, reconstructable cash-event amounts and metadata, and threshold information that discloses balance. Apply the same projection to nested session models and serialized payloads. Existing business/session authorization must run independently. Audit writes remain complete; audit values must not be reflected back to unauthorized browsers.

Cover sell/nav data attributes, pickup modal and toast, session index/detail/summary HTML and JSON, close/finalize responses and modal initialization, POS reports/reconciliation and exports if exposed, and cash-event timelines. Preserve ordinary transaction entry and counting inputs; withhold the reconciliation datasets that reconstruct the session balance. CSS hiding alone leaves data available through network responses and is insufficient.

### 3. Decouple pickup entry from expected cash

For authorized viewers, retain the live summary fetch, loading/error/retry behavior, and signed balance display. Remove balance-derived maximums and comparison checks in all cases. For users without visibility, enable positive amount entry without a balance fetch dependency; OTP flow remains unchanged. Missing redacted keys must not become zero-balance rejection or a disabled button. Successful responses/toasts contain no remaining balance for these users.

Remove the cap in both controller and safe-drop service. Retain positive numeric amount validation, denomination consistency, session/business/status checks, supervisor approval, row locks, and atomic event/cache updates. No overdraft permission, extra warning confirmation, or added approval step is introduced. A positive pickup may deepen an already negative balance. Preserve the ledger calculator as the signed source of truth; check persistence and formatting for sign loss rather than introduce a new calculation.

### 4. Preserve blind counting and variance approval

Users with existing action permissions can enter actual cash and submit closing/finalization without seeing expected cash or numeric variance. Server calculations and existing approval thresholds remain authoritative. Return an approval-required outcome when necessary without the sensitive amounts. Supervisor credentials authorize the action, not disclosure to the requesting browser. Authorized viewers retain the detailed existing reconciliation experience.

### 5. Focus verification on changed contracts

Extend existing permission helper/matrix tests, `PosSafeDropOtpPickupTest`, `POSSafeDropWorkflowTest`, `POSSessionSummaryViewTest`, `POSReconciliationViewTest`, and relevant finalization tests. Exercise users with/without the new permission, session owners and supervisors, Admin sync and Super Admin bypass, and denied business/session access. Assert absence in actual HTML/JSON including nested metadata, not merely hidden labels.

Use a focused `php artisan test --filter=...` selection with the repository's SQLite testing setup, or the corresponding focused invocation of `composer test:fresh-sqlite` where supported. Browser-smoke the pickup and finalization branches because button enablement and fetch assumptions live in inline JavaScript. Do not require the full application suite unless focused failures show a broader affected dependency. Planning validates artifacts only; implementation tests run during apply.

## Risks / Trade-offs

- Hidden nested values or alternate POS responses could leak balances -> project complete response data and assert absence in representative surfaces, including event metadata and action responses.
- Existing scripts assume exact balances are always present -> branch on visibility and preserve count/approval usability in focused browser checks.
- Managers lose default disclosure -> provide the explicit unified checkbox and document assignment before rollout; retain Admin sync behavior.
- Negative balances reveal physical/ledger discrepancies -> record the actual pickup and signed result with unchanged audit/approval controls, as requested.
- Existing specs contain unconditional disclosure and pickup-limit language -> deltas narrow affected requirements; shared visibility rules apply across POS surfaces without rewriting unrelated historical specifications.

## Migration Plan

Deploy registry and runtime changes together, run the existing permission synchronization, clear the relevant application caches through the normal release process, and explicitly assign visibility to selected ordinary roles. Do not run synchronization during planning. Verify the existing schema stores signed expected-cash values before rollout; no schema change is expected. Do not rewrite closed-session history. Rollback code if necessary without clamping or reversing any legitimately recorded negative balance; rolling back the cap may restrict subsequent pickups until the discrepancy is resolved through existing operations.

## Existing specification prerequisite

Strict change validation passes, but archive preflight reports that the existing main `pos-supervisor-cash-finalization/spec.md` begins with a delta-only `## MODIFIED Requirements` header and has no valid main requirements section. Before archive, repair only its document structure (main title, Purpose if needed, and `## Requirements`), preserving behavior and scenarios. This is tracked in tasks; planning leaves that existing main spec untouched.
