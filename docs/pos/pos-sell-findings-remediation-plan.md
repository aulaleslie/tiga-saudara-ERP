# POS Sell Findings Remediation Plan

Date: 2026-03-08  
Source findings: `docs/pos/pos-sell-findings.md` (POS-001, POS-002, POS-003)

## 1) Objectives

- Restore stable POS cart/search behavior for cashier flow.
- Enforce customer selection only at checkout finalization, not during cart/snapshot operations.
- Align Enter-key search behavior with product requirement and backend contracts.
- Add regression tests to prevent repeat breakage.

## 2) Dependency Order

1. Fix unresolved-customer snapshot contract (blocks POS-001 and POS-002, and causes noise in POS-003).
2. Fix Enter-key scan/search method mismatch and behavior contract (POS-003).
3. Add/repair automated tests and run focused regression suite.
4. QA pass with manual cashier scenarios.

## 3) Workstreams

## WS-A: Customer Resolution Contract Stabilization (POS-001, POS-002)

### Scope

- `Modules/Pos/Services/PosCheckoutCustomerResolverService.php`
- `Modules/Pos/Services/PosCartService.php`
- `Modules/Pos/Http/Controllers/PosSellController.php`
- `Modules/Pos/Resources/views/sell.blade.php` (status rendering already supports unresolved customer)

### Changes

1. Make unresolved customer (`selected_customer_id = null`) return non-fatal payload:
   - `selected_customer_id: null`
   - `selected_customer: null`
   - `resolved_customer_id: null`
   - `resolution_source: "none"`
   - `resolution_error: null` (or structured non-blocking warning if needed)
2. Keep strict customer validation in checkout finalize path only:
   - retain `CUSTOMER_UNRESOLVED` rejection on `POST /pos/sell/checkout/finalize`.
3. Remove write-then-error behavior in cart mutation responses by ensuring snapshot generation cannot throw for unresolved customer.
4. Normalize API behavior:
   - `GET /pos/sell/cart` returns `200` with unresolved customer payload.
   - `POST /pos/sell/cart/lines` returns `200` when product is valid, even without selected customer.
   - `PATCH /pos/sell/cart/customer` with `customer_id=null` returns `200`.

### Acceptance Criteria

- No `CUSTOMER_NOT_SELECTED` from cart/snapshot endpoints.
- No HTTP 500 from `GET /pos/sell/cart` in open session with empty customer selection.
- Customer-required error appears only on checkout finalize.

## WS-B: Enter-Key Search/Scan Contract Fix (POS-003)

### Scope

- `Modules/Pos/Resources/views/sell.blade.php`
- `Modules/Pos/Routes/web.php`
- `Modules/Pos/Http/Controllers/PosSellController.php`
- `Modules/Pos/Services/PosScanResolverService.php`

### Contract Decision (must be explicit before coding)

Choose one and implement consistently:

1. `scan-first`:
   - Enter calls scan resolver.
   - Exact match auto-adds.
   - Non-exact falls back to suggestion list/modal.
2. `search-first`:
   - Enter opens search-result UI.
   - Scanner flow handled by dedicated scanner UX path.

### Required Fixes Regardless of Option

1. Resolve HTTP method mismatch:
   - Either change frontend to `GET /pos/sell/search/resolve?q=...`, or add `POST` route support intentionally.
2. Remove unreachable frontend branches:
   - If resolver cannot return `ambiguous`, do not rely on it.
   - Or implement `ambiguous` in service.
3. Align UI expectation:
   - If requirement is “open result modal on Enter for non-exact,” implement actual modal flow.
   - Otherwise update requirement/docs to reflect inline suggestion behavior.

### Acceptance Criteria

- Pressing Enter no longer produces `405`.
- Enter behavior matches approved UX contract.
- No side-effect cart mutations happen from failed Enter-flow requests.

## WS-C: Regression Test Coverage

### Backend Feature Tests

1. `GET /pos/sell/cart` without selected customer returns `200` with `resolution_source=none`.
2. `PATCH /pos/sell/cart/customer` with `null` returns `200` with unresolved snapshot.
3. `POST /pos/sell/cart/lines` without selected customer returns `200` and adds/increments line.
4. `POST /pos/sell/checkout/finalize` without selected customer still returns `422` (`CUSTOMER_UNRESOLVED`).
5. Enter-path resolver endpoint method test:
   - method accepted by frontend must match route (no `405`).

### Frontend/Integration Checks

1. Manual: type partial name (`sam`) + Enter.
2. Manual: click suggestion path still works.
3. Manual: exact barcode/scanner Enter path still works.
4. Manual: serial exact scan path still works.

## WS-D: Rollout and Risk Controls

1. Deploy behind no feature flag (small targeted fixes), but with rollback-ready commit boundaries:
   - Commit A: customer resolution contract.
   - Commit B: Enter-key/scan contract.
   - Commit C: tests.
2. Monitor for 24h:
   - `CUSTOMER_NOT_SELECTED` occurrences in logs.
   - `405` on `/pos/sell/search/resolve`.
   - non-200 rate for `/pos/sell/cart` and `/pos/sell/cart/lines`.
3. Rollback trigger:
   - Any sustained increase in non-200 cart endpoints or checkout failure unrelated to customer validation.

## 4) Implementation Sequence (Execution Plan)

1. Implement WS-A and run targeted tests.
2. Validate with manual curl:
   - `GET /pos/sell/cart`
   - `POST /pos/sell/cart/lines`
   - `PATCH /pos/sell/cart/customer` with `null`
3. Implement WS-B after confirming intended Enter UX contract.
4. Execute WS-C tests and manual UI verification.
5. Ship in staged commits and monitor.

## 5) Definition of Done

- POS-001, POS-002, POS-003 marked `Resolved` in `docs/pos/pos-sell-findings.md`.
- Regression tests for these scenarios are present and passing.
- Manual cashier flow passes:
  - open POS sell page,
  - search and add product (click and Enter),
  - keep customer unselected while building cart,
  - select customer before checkout and complete transaction.
