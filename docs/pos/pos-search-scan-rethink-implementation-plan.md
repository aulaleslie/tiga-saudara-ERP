# POS Search/Scan Rethink Implementation Plan

Date: 2026-03-08  
Owner: POS Team

## Objective

Restructure POS product entry flow so:
1. Main input is scanner-first (barcode/conversion barcode/serial number only).
2. Product add from scanner is processed on `Enter` event from scanner.
3. Manual product discovery is moved to a dedicated `Cari Produk` modal with keyword search and click-to-add behavior.

## Current Baseline (Code Snapshot)

- Scanner + keyword search are mixed in one field in `Modules/Pos/Resources/views/sell.blade.php`.
- Main field currently runs:
1. Debounced keyword search on input.
2. Scan resolver on `Enter`.
- Product search modal exists but currently only renders result list (no in-modal keyword input/submit control).
- Scan resolver service still accepts exact SKU (`product_code`) in addition to barcode and serial.

## Scope

- In scope:
1. POS Sell UI behavior split (scanner input vs manual search modal).
2. JS event flow updates.
3. Scan resolver rule tightening.
4. Feature tests update/addition for new contract.
- Out of scope:
1. Cart pricing logic.
2. Checkout flow logic.
3. Customer selection flow.

## Decision Questions (Aligned)

Locked decisions based on alignment on 2026-03-08:

1. **Scanner field accepted inputs**
1. Barcode.
2. Conversion barcode (package barcode).
3. Serial number.
Selected: **1 (scanner-only; no SKU in scanner field)**.

2. **Behavior when scanner resolve returns `type=none`**
1. Do nothing (no fallback action, no auto-open modal).
Selected: **1**.
Notes:
1. User can continue typing/correcting input in scanner field and press `Enter` again.

3. **Scanner/typed code trigger behavior**
1. Capture `Enter` event.
2. If input resolves, add item to cart.
Selected: **1 + 2**.
Notes:
1. Hardware scanner suffix `Enter` is the primary trigger.
2. Manual typing barcode/serial/conversion barcode + `Enter` is also supported.

4. **Modal search trigger**
1. Press `Enter` in modal search input.
2. Click `Cari` button.
Selected: **support both 1 and 2**.

5. **After clicking product result in modal**
1. Add to cart, close modal, refocus scanner field.
Selected: **1**.

6. **Modal keyword search scope**
1. Product name.
2. SKU.
Selected: **product name + SKU**.

7. **Default quantity when adding from modal**
1. Always add qty `1`.
Selected: **1**.

8. **Rollout strategy**
1. Direct replace.
Selected: **1**.

9. **Modal result display style**
1. Card grid layout (no image for now; image-ready structure for future).
Selected: **1**.

## Phased Plan

### Phase 1 - UI Contract Split (Scanner Field + `Cari Produk` Button)

Deliverables:
1. Rename scanner label and placeholder to scanner-only wording.
2. Add `Cari Produk` button beside scanner field.
3. Remove inline dropdown search result container under scanner input.
4. Keep status area for scan feedback.

Primary files:
1. `Modules/Pos/Resources/views/sell.blade.php` (layout, styles, search section markup).

Acceptance criteria:
1. Scanner field no longer suggests name/SKU typing.
2. `Cari Produk` button is visible and responsive on desktop/mobile layout.

### Phase 2 - Scanner Input Behavior Hardening

Deliverables:
1. Remove scanner field debounced keyword search handler.
2. Keep `Enter` keydown handler for scan resolve.
3. On resolve success (`product_exact` / `serial_exact`), add to cart.
4. On `none`, do nothing (no modal auto-open, no fallback search execution).

Primary files:
1. `Modules/Pos/Resources/views/sell.blade.php` (script section).

Acceptance criteria:
1. Typing in scanner field does not trigger keyword result fetching.
2. Only `Enter` triggers scanner resolution.
3. Scanner field supports both hardware scanner input and manual typed scan codes.

### Phase 3 - Dedicated `Cari Produk` Modal Search UX

Deliverables:
1. Add modal input + submit controls for keyword search.
2. Support both `Enter` key and `Cari` button to trigger modal search.
3. Wire modal submit to product search endpoint.
4. Restrict modal search scope to product name + SKU.
5. Render result list in card-grid style (image placeholder not required in this phase).
6. Click result adds item to cart and closes modal.

Primary files:
1. `Modules/Pos/Resources/views/sell.blade.php` (modal markup + JS handlers).
2. Optional extraction to dedicated JS asset if script size is too large.

Acceptance criteria:
1. Cashier can open modal from `Cari Produk`, search by keyword, and add product by click.
2. Added product appears in cart with existing add-line behavior.
3. Modal search renders product cards (grid) without image dependency.
4. Modal search is executable by both keyboard (`Enter`) and mouse (`Cari` click).

### Phase 4 - Backend Scan Rule Tightening

Deliverables:
1. Remove SKU exact matching from scan resolver service.
2. Keep scan resolver exact-matching for:
1. Product barcode.
2. Conversion barcode.
3. Serial number.
3. Update service comments/docblock to match real behavior.

Primary files:
1. `Modules/Pos/Services/PosScanResolverService.php`.

Acceptance criteria:
1. Scan resolve with SKU returns `type=none`.
2. Barcode/conversion/serial resolve remains unchanged.

### Phase 5 - Tests, Regression, and UAT

Deliverables:
1. Update scan resolver tests for SKU behavior change.
2. Add/adjust UI-flow test coverage where possible for modal search action.
3. Keep product keyword search endpoint tests as manual-search contract.
4. Execute targeted POS test suite and manual hardware scan pass.

Primary files:
1. `Modules/Pos/Tests/Feature/POSScanResolveEndpointTest.php`.
2. `Modules/Pos/Tests/Feature/POSProductSearchScanTest.php` (as needed for expectation alignment).

Acceptance criteria:
1. Existing critical POS tests pass after expectation updates.
2. Manual UAT confirms scanner and modal flows are clearly separated.

## Risks and Mitigations

1. Risk: Removing inline result list may break current JS guard clauses.  
Mitigation: Update JS element presence checks to avoid early script return.
2. Risk: Cashiers accustomed to typing SKU in scanner field may face transition friction.  
Mitigation: Add clear status/help text near scanner field and SOP update.
3. Risk: Large inline script changes increase regression chance.  
Mitigation: Keep changes phase-scoped and test after each phase, not in one big commit.

## Suggested Delivery Sequence

1. Implement Phase 1 + 2 in one PR (UI split + scanner hardening).
2. Implement Phase 3 in next PR (modal search UX).
3. Implement Phase 4 + 5 in final PR (backend rule lock + test updates).

## Definition of Done

1. Scanner field is strictly scan-only by behavior and wording.
2. `Cari Produk` modal handles manual keyword search and add-to-cart.
3. SKU no longer resolves through scan endpoint.
4. POS tests are updated and passing for the new contract.
5. Modal search result UI is card-grid based and ready for future image support.
