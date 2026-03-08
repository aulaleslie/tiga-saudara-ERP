# POS Sell V2 Final Implementation Plan

Date: 2026-03-07  
Source of truth decisions: `docs/pos/pos-sell-brainstorm-decision-questions.md`

## 1) Locked Scope (Implemented Behavior)

- Search bar:
  - Remove `Siap Pindai` button.
  - Product add paths:
    - click suggestion,
    - enter key: exact unique match => immediate add, ambiguous => open result modal,
    - barcode scanner (sends Enter) => immediate add for exact code,
    - serial scanner => immediate add/assign serial.
- Cart:
  - Non-serial: barcode scan increments existing row qty or creates new row.
  - Serial: single product row supports qty + incremental serial filling; checkout blocked if assigned serial count != qty.
  - Duplicate serial in cart rejected immediately.
- Customer:
  - Bigger suggestion UI and prominent selected customer display.
  - Tier pricing auto-reprice on customer update.
  - No default walk-in fallback; base price applies for non-tier/no customer.
  - Checkout still requires explicitly selected customer.
- Payment method:
  - Source from `payment_methods` table.
  - Searchable dropdown.
  - Transitional compatibility for old `method_code` payload.
- Stock allocation:
  - For non-serial taxable lines: prioritize non-tax bucket first.
  - Global bucket-first order (`Q18=B`): non-tax pass (all configured locations by priority), then tax pass.
  - Serial lines follow scanned serial location/tax bucket (resolver logic for non-serial focus).

## 2) Current Codebase Findings (Anchors)

- POS sell shell is monolithic view+JS: `Modules/Pos/Resources/views/sell.blade.php`.
- Cart line model is product-keyed (`line_id = product_id`) and qty-centric in `Modules/Pos/Services/PosCartService.php`.
- Serial assignment endpoint currently requires full exact list count==qty (`assignSerials`), not incremental.
- Customer resolver currently falls back to setting walk-in mapping: `Modules/Pos/Services/PosCheckoutCustomerResolverService.php`.
- Checkout request/service currently hardcoded to `method_code in [cash,transfer,qris]`:
  - `Modules/Pos/Http/Requests/StorePosCheckoutFinalizeRequest.php`
  - `Modules/Pos/Services/FinalizePosCheckoutService.php`
- Posting adapter maps method code by name matching in payment method table:
  - `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
- Allocation currently location-first and tax-bucket selected directly by line tax request:
  - `Modules/Pos/Services/ResolvePosStockAllocationsService.php`
- Reporting/reconciliation currently classify cash vs non-cash via string comparison on `payment_method_code`:
  - `Modules/Pos/Services/PosReportingService.php`
  - `Modules/Pos/Services/PosReconciliationService.php`

## 3) High-Level Design

### 3.1 Session Cart V2 Model (in session only)

Keep session-backed cart but introduce stable `line_id` and explicit merge metadata.

Proposed per-line shape:

```php
[
  'line_id' => int,
  'product_id' => int,
  'product_name' => string,
  'serial_number_required' => bool,
  'assigned_serials' => string[],
  'qty' => int,
  'unit_price' => float,
  'tax_id' => ?int,
  'tax_name' => ?string,
  'tax_rate' => float,
  'merge_key' => string,
  'price_source' => 'BASE'|'TIER'|'OVERRIDE',
  'price_valid' => bool,
  'price_error' => ?string,
]
```

Cart-level metadata:

```php
[
  'next_line_id' => int,
  'selected_customer_id' => ?int,
]
```

### 3.2 Merge Rules

- Non-serial merge key: `product_id + effective_unit_price + tax_id` (CQ5=B).
- Serial merge key: `product_id + serial-tax-profile`.
  - This avoids mixed-tax serials inside one row and keeps estimated totals coherent.

### 3.3 Scan Resolution Contract

Add a dedicated scan resolver endpoint to avoid overloading suggestion search behavior:

- Input: `q`.
- Output categories:
  - `type=product_exact` (barcode/SKU exact unique),
  - `type=serial_exact` (available serial exact),
  - `type=ambiguous` (open modal),
  - `type=none`.

This lets Enter/scanner flow be deterministic.

## 4) Backend Work Plan

### 4.1 Database & Model Updates

1. Add migration: `payment_methods.requires_reference` (bool, default false).
2. Add migration: `pos_checkouts.payment_method_id` (nullable FK to `payment_methods`).
3. Add backfill migration:
   - map existing `pos_checkouts.payment_method_code` to best-match `payment_methods.id`.
4. Update model fillables/casts:
   - `Modules/Setting/Entities/PaymentMethod.php`: include `is_available_in_pos`, `requires_reference`.
   - `Modules/Pos/Entities/PosCheckout.php`: include `payment_method_id` relation.

### 4.2 POS Payment Method Domain

1. Add service: `PosPaymentMethodSearchService`.
   - filter `is_available_in_pos=true`.
   - return `{id,name,is_cash,requires_reference}`.
2. Add controller endpoint in `PosSellController`:
   - `GET /pos/sell/payment-methods/search`.
3. Update `StorePosCheckoutFinalizeRequest` for transitional payload:
   - accept either:
     - new: `payment.payment_method_id`,
     - legacy: `payment.method_code`.
4. Update `FinalizePosCheckoutService` normalization:
   - resolve PaymentMethod first,
   - enforce amount rules using `is_cash`,
   - enforce reference using `requires_reference`.
5. Update `InlinePosCheckoutPostingAdapter`:
   - stop fuzzy name matching as primary path,
   - consume resolved `payment_method_id` directly.

### 4.3 Customer Resolution & Tier Pricing

1. Update `PosCheckoutCustomerResolverService`:
   - remove default walk-in fallback behavior from checkout resolution path.
2. Update `PosCartService::updateCustomerSelection()`:
   - after customer change, reprice all non-overridden lines.
3. Add strict active-setting price resolver in cart service:
   - read from `product_prices` for current active setting only,
   - no fallback to `products.product_price` for cart pricing (Q11=B),
   - for no customer/non-tier use `sale_price` from active setting price row.
4. Add invalid-price line state (`price_valid=false`) when strict price row missing during reprice.

### 4.4 Cart & Serial Mechanics

1. Refactor cart storage in `PosCartSessionStore` + `PosCartService` to use stable `line_id`.
2. Keep backward compatibility for `lineId` routes:
   - resolve by new line id first,
   - fallback by product_id when unambiguous.
3. Serial assignment changes:
   - keep existing full replace endpoint,
   - allow incremental serial count (< qty) for in-progress filling,
   - add append/remove serial endpoints for UI ergonomics:
     - `POST /pos/sell/cart/lines/{lineId}/serials/append`
     - `DELETE /pos/sell/cart/lines/{lineId}/serials/{serial}`
4. Qty rules:
   - non-serial: no manual qty edit from UI.
   - serial: qty editable; reduction blocked if target qty < assigned serial count (CQ1=A).
5. Implement `+serial` action semantics:
   - increments qty by 1 and opens serial entry flow (CQ2=A).
6. Serial scan semantics:
   - fill unfilled slot first,
   - if no slot, auto-increment qty then append (CQ3=A).
7. Serial tax semantics:
   - serial record tax is source of truth (CQ4=A),
   - route serial to line with matching serial-tax profile; create line if needed.

### 4.5 Search / Scan APIs

1. Extend `PosProductSearchService` or add `PosScanResolverService`:
   - exact product barcode/SKU detection,
   - exact serial detection on available serials in allowed locations.
2. Add controller endpoint:
   - `GET /pos/sell/search/resolve` (or similar) used by Enter/scanner flow.
3. Keep existing `/products/search` for suggestion list rendering.

### 4.6 Stock Allocation (Non-Serial)

Update `ResolvePosStockAllocationsService`:

- For non-serial taxable lines:
  1. Pass 1: non-tax bucket across all configured locations by priority.
  2. Pass 2: tax bucket across same ordered locations.
- For non-tax lines: only non-tax bucket.
- For serial lines: resolver only ensures overall feasibility; final per-serial chunking remains in posting adapter.

### 4.7 Reporting / Reconciliation / Receipt Consistency

1. `PosReportingService` and `PosReconciliationService`:
   - classify cash/non-cash using joined `payment_methods.is_cash` where available,
   - fallback to legacy `payment_method_code='cash'` for old rows.
2. `PosReceiptService`:
   - display payment method label from related `payment_method` (fallback to code).

## 5) Frontend Work Plan (`sell.blade.php`)

### 5.1 Search UX

- Remove `Siap Pindai` button and handlers.
- Add Enter handler:
  - call scan resolver endpoint,
  - `product_exact` => add line,
  - `serial_exact` => serial append flow,
  - `ambiguous` => open search modal,
  - `none` => show not-found status.
- Keep debounce suggestions for click selection.

### 5.2 Cart UX

- Non-serial row:
  - show qty as read-only badge/text.
- Serial row:
  - qty control,
  - `+ Serial` small action,
  - assigned serial chips/list with remove action,
  - status text `assigned/qty`.
- Checkout button disabled when:
  - cart empty,
  - customer not selected,
  - invalid price lines exist,
  - any serial-required line has assigned != qty.

### 5.3 Customer UX

- Increase suggestion panel item height and font.
- Move selected customer display to bottom of card as prominent read-only text.
- Keep phone number in smaller text.
- On customer update, trigger cart reprice refresh and show status.

### 5.4 Payment UX

- Replace static cash/transfer/qris buttons with searchable dropdown.
- Load method list from payment-method search endpoint.
- Use method metadata:
  - `is_cash` to toggle change/preset behavior,
  - `requires_reference` to require/show reference input.

## 6) Route and Request Changes

Update `Modules/Pos/Routes/web.php`:

- add:
  - `GET /pos/sell/search/resolve`
  - `GET /pos/sell/payment-methods/search`
  - `POST /pos/sell/cart/lines/{lineId}/serials/append`
  - `DELETE /pos/sell/cart/lines/{lineId}/serials/{serial}`

Keep existing routes for compatibility.

## 7) File-Level Change List (Execution Checklist)

### POS Module

- `Modules/Pos/Routes/web.php`
- `Modules/Pos/Http/Controllers/PosSellController.php`
- `Modules/Pos/Http/Requests/StorePosCheckoutFinalizeRequest.php`
- `Modules/Pos/Http/Requests/UpdatePosCartLineRequest.php`
- `Modules/Pos/Http/Requests/StorePosCartSerialAssignmentRequest.php`
- `Modules/Pos/Services/PosCartSessionStore.php`
- `Modules/Pos/Services/PosCartService.php`
- `Modules/Pos/Services/PosProductSearchService.php` and/or new `PosScanResolverService.php`
- `Modules/Pos/Services/PosCheckoutCustomerResolverService.php`
- `Modules/Pos/Services/FinalizePosCheckoutService.php`
- `Modules/Pos/Services/ResolvePosStockAllocationsService.php`
- `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php`
- `Modules/Pos/Services/PosReportingService.php`
- `Modules/Pos/Services/PosReconciliationService.php`
- `Modules/Pos/Services/PosReceiptService.php`
- `Modules/Pos/Resources/views/sell.blade.php`
- `Modules/Pos/Entities/PosCheckout.php`
- new migration(s) under `Modules/Pos/Database/Migrations/`

### Setting Module

- `Modules/Setting/Entities/PaymentMethod.php`
- `Modules/Setting/Http/Controllers/PaymentMethodController.php`
- `Modules/Setting/Resources/views/payment_methods/index.blade.php`
- `Modules/Setting/Resources/views/payment_methods/create.blade.php`
- `Modules/Setting/Resources/views/payment_methods/edit.blade.php`
- new migration(s) under `Modules/Setting/Database/Migrations/`

## 8) Test Plan (Must Update/Add)

### Existing Feature Tests to Update

- `Modules/Pos/Tests/Feature/POSWalkInCustomerSelectionTest.php`
  - remove default walk-in fallback expectations.
- `Modules/Pos/Tests/Feature/POSCartTotalsDisplayTest.php`
  - adjust qty editing expectations for non-serial rows.
- `Modules/Pos/Tests/Feature/POSCheckoutFinalizeIdempotencyTest.php`
- `Modules/Pos/Tests/Feature/POSPaymentValidationRulesTest.php`
- `Modules/Pos/Tests/Feature/POSReceiptGenerationTest.php`
- `Modules/Pos/Tests/Feature/POSReportingPackTest.php`
- `Modules/Pos/Tests/Feature/POSReconciliationViewTest.php`
  - adapt from `method_code` to payment method id + metadata behavior.
- `Modules/Pos/Tests/Feature/POSSerialValidationCheckoutTest.php`
  - incremental serial assignment and qty interplay.
- `Modules/Pos/Tests/Feature/POSStockAllocationResolverTest.php`
- `Modules/Pos/Tests/Feature/POSTaxBySourceSnapshotTest.php`
  - expected allocation/tax chunk behavior under bucket-first non-serial rules.

### New Tests to Add

- `POSScanResolveEndpointTest` (exact product / exact serial / ambiguous / none).
- `POSCustomerTierRepricingTest` (reprice on customer switch, strict missing price row behavior).
- `POSPaymentMethodSearchTest` (`is_available_in_pos`, `requires_reference`).
- `POSSerialIncrementalAssignmentTest` (`+serial` semantics, qty-reduction guard).
- `POSNonSerialMergeKeyTest` (merge by product+price+tax).
- `POSCheckoutSelectedCustomerRequiredTest` (no default fallback + checkout blocked).

### Execution Commands (targeted)

```bash
php artisan test --filter=POSProductSearchScanTest
php artisan test --filter=POSSerialValidationCheckoutTest
php artisan test --filter=POSStockAllocationResolverTest
php artisan test --filter=POSPaymentValidationRulesTest
php artisan test --filter=POSCheckoutFinalizeIdempotencyTest
php artisan test --filter=POSWalkInCustomerSelectionTest
php artisan test --filter=POSTaxBySourceSnapshotTest
php artisan test --filter=POSReportingPackTest
php artisan test --filter=POSReconciliationViewTest
```

## 9) Rollout Strategy (Phased, Compatibility First)

### Phase 1: Schema + Compatibility Layer

- Add migrations (`payment_methods`, `pos_checkouts`).
- Keep old payload accepted.
- Keep old report queries with fallback condition.

### Phase 2: Backend Domain Changes

- Cart/serial/tier/scan resolver service changes.
- API endpoints for scan resolve and payment method search.

### Phase 3: Frontend Sell UI V2

- Replace cart/search/payment behavior in `sell.blade.php`.
- Keep route contracts backward-compatible.

### Phase 4: Test Stabilization + UAT

- Update existing tests and add new tests.
- UAT checklist on real scanner and serial-heavy flows.

### Phase 5: Cleanup (post-stabilization)

- Remove legacy `method_code` client usage.
- Optionally remove legacy fallback logic after stable period.

## 10) Risks and Mitigations

- Risk: No methods marked `is_available_in_pos` => payment dropdown empty.  
  Mitigation: migration/backfill + UI fallback warning + block checkout.
- Risk: Cart line id migration in session may orphan existing sessions.  
  Mitigation: runtime normalizer that upgrades old session cart shape on read.
- Risk: Allocation behavior changes tax totals in mixed-stock scenarios.  
  Mitigation: explicit regression tests in `POSTaxBySourceSnapshotTest` and resolver tests.
- Risk: Strict active-setting price rows may block previously valid carts.  
  Mitigation: surface explicit line-level error and admin remediation guidance.

## 11) Definition of Done

- All locked Q/CQ decisions implemented.
- POS sell UI behavior matches scanner + serial + payment + customer requirements.
- Checkout blocked on serial mismatch and missing selected customer.
- Dynamic payment methods fully used by frontend and backend.
- Non-serial stock allocation follows global bucket-first rule.
- POS feature tests updated/passing; new tests added for changed domain rules.
