# POS Sell Cart Tax/Qty Adjustment Implementation Plan

Date: 2026-03-09  
Owner: POS Team

## Objective

Adjust `/pos/sell` behavior so:
1. Stock quantity is not shown in search result modal and cart item row.
2. Cart-time tax validation for serial assignment is removed.
3. Quantity editing is supported in cart item rows, but decreasing quantity below current value is not allowed.

## Scope

In scope:
1. POS sell UI rendering/interaction in `Modules/Pos/Resources/views/sell.blade.php`.
2. Cart service validation/rules in `Modules/Pos/Services/PosCartService.php`.
3. POS feature tests affected by cart quantity and serial assignment behavior.

Out of scope:
1. Final checkout tax split redesign (explicitly deferred).
2. Stock allocation strategy redesign in checkout posting flow.
3. Receipt/totals visual redesign.

## Current Baseline (Verified)

### UI/Frontend findings
1. Search modal explicitly renders stock quantity (`available_qty`) in result cards (`sell.blade.php` around `renderSearchResultsModal`, lines ~1595-1610).
2. Cart row explicitly renders stock text (`Stok: ${availableQty}`) (`sell.blade.php` around line ~1384).
3. Quantity input exists only for serial-tracked rows; non-serial rows are read-only badge values (`sell.blade.php` lines ~1333-1371).
4. Frontend currently blocks qty decrease (`newQty < prevQty`) with message `Jumlah qty tidak dapat dikurangi.` (`sell.blade.php` lines ~1971-1974).

### Backend findings
1. Serial assignment enforces tax match at cart time in `assignSerials()` (`PosCartService.php` lines ~527-534).
2. Serial append enforces tax match at cart time in `appendSerial()` (`PosCartService.php` lines ~733-740).
3. Backend `updateLine()` currently allows quantity decrease when valid (`PosCartService.php` line ~210 onward); no server-side guard for `newQty < currentQty`.

### Tinker verification snapshot (2026-03-09)
1. Runtime context is valid for POS cart tests: `setting_id=1`, `pos_session_id=1` (`OPEN`).
2. Search payload includes stock quantity field: `PosProductSearchService::search(1, "KERTAS", 3)` returns `available_qty`.
3. Cart line payload includes quantity and tax metadata: `PosCartService::addLine(1,1,2,1)` returns `qty`, `available_qty`, `tax_id`.
4. Quantity decrease is currently allowed at service level: add non-serial product qty 3, then `updateLine(..., ["qty" => 2])` succeeds.
5. Mixed tax/non-tax serial assignment is currently blocked: for product `id=1`, assigning serials `202602220002` (taxed) + `202602240001` (non-taxed) fails with `Serial number 202602220002 is taxed, but line is non-taxed.`

## Implementation Plan

### Phase 1 - Hide Stock Quantity from UI

Changes:
1. Remove stock quantity display block from search modal cards in `renderSearchResultsModal()`.
2. Remove cart row stock text `Stok: ...` in `buildLineRow()`.
3. Keep backend `available_qty` in payload unchanged for validation and future use.

Primary file:
1. `Modules/Pos/Resources/views/sell.blade.php`

Acceptance criteria:
1. Search result cards no longer display stock quantity.
2. Cart row metadata no longer displays stock quantity.
3. Add-to-cart and stock validation behavior remains unchanged.

### Phase 2 - Enable Qty Editing for All Cart Lines

Changes:
1. Render qty input (`.js-line-qty`) for both serial and non-serial lines.
2. Keep existing increase-only UX in frontend (`newQty < prevQty` blocked).
3. Preserve serial controls (`+ Serial` and chips) only for serial-tracked lines.

Primary file:
1. `Modules/Pos/Resources/views/sell.blade.php`

Acceptance criteria:
1. Non-serial lines are editable in cart.
2. Serial lines remain editable with serial controls.
3. Entering qty lower than current value is blocked in UI.

### Phase 3 - Enforce Increase-Only Rule in Backend

Changes:
1. Add server-side guard in `PosCartService::updateLine()` to reject requests where requested qty is lower than current line qty.
2. Return domain error aligned with cashier UX text for decrease attempts.
3. Adjust serial preservation logic to avoid clearing assigned serials on qty increase.
4. Keep existing guard that qty cannot go below assigned serial count.

Primary file:
1. `Modules/Pos/Services/PosCartService.php`

Acceptance criteria:
1. API `PATCH /pos/sell/cart/lines/{lineId}` rejects qty decrease with 422.
2. API and UI behavior are consistent (both increase-only).
3. Qty increase on serial lines keeps already-assigned serials intact.

### Phase 4 - Remove Cart-Time Serial Tax Match Validation

Changes:
1. Remove tax-match checks from `assignSerials()` (delete branches rejecting taxed serial on non-tax line and inverse).
2. Remove tax-match checks from `appendSerial()` with the same behavior.
3. Keep product-match validation.
4. Keep ACTIVE/unassigned serial validation.
5. Keep allowed-location validation.
6. Keep duplicate-serial prevention.

Primary file:
1. `Modules/Pos/Services/PosCartService.php`

Acceptance criteria:
1. Mixed taxed/non-taxed serials can be assigned in one cart line.
2. No cart-time rejection based solely on serial tax status.
3. Existing non-tax-related serial safeguards remain intact.

### Phase 5 - Tests and Regression Coverage

Changes:
1. Update affected test expecting qty decrease success: `Modules/Pos/Tests/Feature/POSCartTotalsDisplayTest.php` (`qty 2 -> 1`) should now expect 422.
2. Add feature test: non-serial line qty increase succeeds.
3. Add feature test: qty decrease is rejected by API.
4. Add feature test: mixed taxed/non-taxed serial assignment succeeds in cart.
5. Add optional manual UAT check: search modal no longer shows stock quantity.
6. Add optional manual UAT check: cart row no longer shows stock quantity.
7. Add optional manual UAT check: non-serial qty can be increased from UI.

Acceptance criteria:
1. Updated POS feature tests pass.
2. No regression on serial validation except removed tax-match rule.
3. UAT confirms all three requested behaviors.

## Risks and Mitigations

1. Risk: Backend increase-only rule can break existing API consumers relying on qty decrease.  
Mitigation: Align error message clearly and update tests/docs in same PR.
2. Risk: Removing cart-time tax match can expose checkout-time allocation mismatches in certain stock bucket setups.  
Mitigation: Keep change scoped to cart-time only, document checkout limitation, and schedule follow-up redesign.
3. Risk: Rendering qty input for all lines can introduce accidental rapid edits.  
Mitigation: Keep current change-event submit flow and success/error feedback unchanged.

## Delivery Sequence

1. PR-1: UI hiding of stock quantity + qty input for all lines.
2. PR-2: Backend increase-only enforcement + serial-preservation behavior.
3. PR-3: Remove cart-time serial tax checks + tests update/add.

## Definition of Done

1. No stock quantity shown in search result modal or cart rows.
2. Cart accepts serial assignment without tax-status matching.
3. Cart qty is editable for all lines but cannot be decreased (UI + backend).
4. POS feature tests are updated and passing.
