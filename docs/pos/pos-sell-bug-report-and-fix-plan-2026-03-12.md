# POS Sell Investigation Report and Fix Plan

Date: 2026-03-12  
Scope: investigation only, no code implementation

## Issue A - Serial Product Button Not Displayed Properly

Context:
- Page: `http://localhost:8000/pos/sell`
- Symptom: serial action button on serial-required cart row looks broken/blank.

Findings:
- Serial action button is icon-only and uses Font Awesome class:
  - `Modules/Pos/Resources/views/sell.blade.php:1556-1558`
  - `<i class="fas fa-barcode"></i>`
- Main layout includes Bootstrap Icons only, not Font Awesome:
  - `resources/views/includes/main-css.blade.php:7-8`
- Result: icon-only button can render as visually empty if Font Awesome is not loaded.

Related UI defects found in same serial flow:
- Product name lookup for serial modal uses `.product-name`, but cart row renders `.name`:
  - Rendered class: `Modules/Pos/Resources/views/sell.blade.php:1587`
  - Queried class: `Modules/Pos/Resources/views/sell.blade.php:2369`
- Serial remove button inside modal uses `.js-serial-remove`, but click handler is bound to cart table body and requires `tr[data-line-id]`, so modal remove click path returns early:
  - Modal remove button render: `Modules/Pos/Resources/views/sell.blade.php:1715-1717`
  - Handler: `Modules/Pos/Resources/views/sell.blade.php:2363-2383`

## Issue B - Checkout Fails With `STOCK_UNAVAILABLE` on Mixed-Business Cart

Symptom:
- On `Konfirmasi Pembayaran`, checkout fails with:
  - `One or more items in the cart are no longer available in stock across allowed locations.`

Where error is thrown:
- `Modules/Pos/Services/FinalizePosCheckoutService.php:473-478`

### Tinker Verification

1) Failed checkout records:
- `pos_checkouts` latest rows show failures:
  - `id=1`, `status=FAILED`, `failure_code=STOCK_UNAVAILABLE`, `setting_id=1`, `pos_session_id=1`
  - `id=2`, `status=FAILED`, `failure_code=STOCK_UNAVAILABLE`, `setting_id=1`, `pos_session_id=1`

2) Cart loaded from session file:
- Session cart (`setting=1`, `session=1`) has 2 lines:
  - Line 1: product `SAMSUNG GALAXY Z FOLD 6` (serial required), qty 1, `tax_id=null`, assigned serial `202603120001`
  - Line 2: product `KERTAS SINAR DUNIA A4 70 GSM 1 RIM 500 SHEET` (non-serial), qty 1, `tax_id=null`

3) Resolver result for current cart lines:
- Input lines to resolver:
  - `[{"product_id":1,"qty":1,"tax_id":null},{"product_id":2,"qty":1,"tax_id":null}]`
- Output:
  - `unfulfilled_lines: [0]`
  - Line index 0 is unfulfilled.

4) Stock snapshot for failing line:
- Product 1 stock in allowed locations:
  - `location_id=1`, owner setting `1` (PKP), `quantity=10`, `quantity_non_tax=0`, `quantity_tax=10`
- Assigned serial `202603120001`:
  - `ACTIVE`, `location_id=1`, `tax_id=1`

5) Control check:
- If same resolver call is forced with `tax_id=1` for line 0, `unfulfilled_lines` becomes empty.

### Root Cause

Primary cause:
- Stock resolver determines taxable strategy only from `line.tax_id`:
  - `Modules/Pos/Services/ResolvePosStockAllocationsService.php:43-67`
- For `tax_id=null`, resolver uses non-tax-only bucket (`quantity_non_tax`) even for serial-required lines:
  - `Modules/Pos/Services/ResolvePosStockAllocationsService.php:230-286`
- The failing serial line has only taxed stock (`quantity_tax=10`, `quantity_non_tax=0`), so it is falsely flagged unfulfilled.

Why this is a false negative:
- Serial line already has concrete assigned serial (`ACTIVE`, valid, in allowed location).
- Posting path is serial-aware and reconstructs allocations from actual serial records, but checkout is blocked before posting.

Secondary behavior mismatch to expected split:
- Runtime currently uses inline posting adapter, not split posting:
  - Bound by feature flag in `Modules/Pos/Providers/PosServiceProvider.php:32-39`
  - Default config is disabled: `Modules/Pos/Config/config.php:8`
- So expected split-per-business posting will not happen until split-posting is enabled.

Secondary tax-split risk (serial):
- Split planner marks line taxable by `line.tax_id` only:
  - `Modules/Pos/Services/PosCheckoutSplitPlannerService.php:66-69`
  - `resolveEffectiveTax` hard-gates on `lineTaxable`:
  - `Modules/Pos/Services/PosCheckoutSplitPlannerService.php:404-407`
- For serial lines with `line.tax_id=null` but serial tax present, planner can classify chunk as `NON_TAX`.

## Fix Plan (No Implementation Yet)

1. Improve checkout diagnostics first
- Include unfulfilled line indices and product identifiers in `STOCK_UNAVAILABLE` error payload/log.
- Add metadata for resolver input/output during failed checkout to speed future triage.

2. Fix serial-aware stock pre-check
- Preferred: make `ResolvePosStockAllocationsService` serial-aware.
- Add line metadata input (`serial_number_required`, `assigned_serials`) and allocation path that validates serial records and maps chunk availability by serial location/tax.
- Ensure serial lines are not forced through non-tax-only path when `line.tax_id` is null.

3. Align finalize pre-check contract
- Update `FinalizePosCheckoutService` to pass resolver the data it needs for serial lines (not only `product_id/qty/tax_id`).
- Keep `unfulfilled_lines` semantics stable for non-serial lines.

4. Ensure split behavior matches expectation
- Enable split posting in runtime (`POS_CHECKOUT_SPLIT_POSTING_ENABLED=true`) where required.
- Verify container binding resolves `SplitPosCheckoutPostingAdapter`.
- Validate proportional payment and per-business split documents on mixed-business cart.

5. Correct serial UI rendering
- Replace icon-only Font Awesome usage with Bootstrap Icons or add visible text fallback on serial action button.
- Fix selector mismatch (`.product-name` vs rendered class `.name`).
- Add dedicated event handling for serial remove actions inside serial modal.

6. Add/extend regression tests
- Feature test: mixed-business cart with serial taxed line + non-serial non-tax line must pass checkout.
- Feature test: error payload includes actionable failing line detail.
- UI test/manual checklist: serial button visibility, serial modal product name, modal remove serial action.

## Suggested Validation Checklist After Fix

- `/pos/sell` serial action button is clearly visible and actionable.
- Cart with:
  - 1 serial product sourced from PKP business
  - 1 non-serial product sourced from non-PKP business
  - successfully finalizes checkout.
- Checkout response includes split groups and proportional payment allocations (when split posting enabled).
- No generic stock error for valid serial-assigned lines.

---

## Issue A Implementation Update (2026-03-12)

Scope implemented in this update:
- `Modules/Pos/Resources/views/sell.blade.php`
  - Serial action button changed to Bootstrap Icons-compatible markup with visible `Serial` label fallback.
  - Stable product context contract added via `data-product-name` on `.js-serial-add`.
  - Serial delete logic centralized into shared helper `removeSerialFromLine(lineId, serialNumber, source)`.
  - Added modal-scoped `.js-serial-remove` handler using `currentSerialLineId` (no dependency on `tr[data-line-id]`).
- `Modules/Pos/Tests/Feature/POSShellSessionGuardTest.php`
  - Added shell-level regression test to assert serial UI hooks are present for:
    - visible serial action control markup
    - stable product-name source
    - shared serial remove helper
    - modal remove event wiring

Regression checks executed:
1. `php artisan test Modules/Pos/Tests/Feature/POSShellSessionGuardTest.php`
   - Result: PASS (6 tests, 34 assertions)
2. `php artisan test Modules/Pos/Tests/Feature/POSSerialIncrementalAssignmentTest.php --filter test_can_remove_assigned_serials`
   - Result: PASS (1 test, 7 assertions)

Notes:
- Automated coverage confirms the serial UI wiring and existing serial remove API flow remain green.
- Manual browser validation on `/pos/sell` should still be performed to visually confirm button rendering and modal interaction in the target environment.

---

## Issue B Implementation Update (2026-03-12)

Scope implemented in this update:
- `Modules/Pos/Services/ResolvePosStockAllocationsService.php`
  - Added serial-aware stock pre-check path using assigned serial records (`status`, `location`, and effective tax context).
  - Kept existing non-serial allocation behavior unchanged.
  - Added structured diagnostics output: `unfulfilled_details[]` with `line_index`, `product_id`, `reason_code`, and qty context.
- `Modules/Pos/Services/FinalizePosCheckoutService.php`
  - Normalized resolver input lines to include serial metadata (`serial_number_required`, `assigned_serials`).
  - Added `STOCK_UNAVAILABLE` response details payload (`details.unfulfilled_lines[]`) with actionable line diagnostics.
  - Persisted validation failure details into checkout failure metadata for triage.
- `Modules/Pos/Services/Exceptions/PosCheckoutValidationException.php`
  - Added optional structured `details` payload support.
- `Modules/Pos/Http/Controllers/PosSellController.php`
  - Exposed validation exception details in `checkoutFinalize` 422 JSON responses when available.
- `Modules/Pos/Services/PosCheckoutSplitPlannerService.php`
  - Updated serial tax resolution precedence so serial-assigned lines with null `line.tax_id` use explicit line tax first, then serial tax context, before fallback.
- `Modules/Pos/Tests/Unit/PosCheckoutSplitPlannerServiceTest.php`
  - Added regression test for serial-tax bucket resolution when line tax is null.
- `Modules/Pos/Tests/Feature/POSStockAllocationResolverTest.php`
  - Added resolver regression tests for serial taxed-line fulfillment and location-not-allowed diagnostic reason.
- `Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`
  - Added feature regression test for mixed-business cart (taxed serial + non-serial non-tax) successful finalize.
  - Added feature regression test asserting `STOCK_UNAVAILABLE` includes actionable failing-line details.

Regression checks executed:
1. `php artisan test Modules/Pos/Tests/Feature/POSStockAllocationResolverTest.php`
   - Result: PASS (7 tests, 22 assertions)
2. `php artisan test Modules/Pos/Tests/Unit/PosCheckoutSplitPlannerServiceTest.php`
   - Result: PASS (2 tests, 13 assertions)
3. `php artisan test Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`
   - Result: PASS (11 tests, 84 assertions)

Notes:
- Checkout now keeps backward-compatible `STOCK_UNAVAILABLE` code while returning actionable `details.unfulfilled_lines[]`.
- Mixed-business checkout path with serial-taxed line is now covered by automated regression.
