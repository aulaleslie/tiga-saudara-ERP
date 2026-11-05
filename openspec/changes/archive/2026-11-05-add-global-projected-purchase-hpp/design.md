# Design

## Context

See proposal.md for motivation. `CrossBusinessPriceController::edit` already resolves current global HPP using the active business's existing price row, then the first existing row, then zero. The view has a discrepancy warning for divergent averages. Receipt approval in `PurchaseController` uses global `product_quantity`, tax-exclusive discounted line cost from `PurchaseCostHelper`, and `ProductAveragePriceSynchronizer` to propagate the result to all settings.

`CrossBusinessInDeliveryStockService` already defines ordered-minus-approved-received eligibility and per-detail clamping. `PurchaseTable` is shared by ordinary lists and the global payment workspace. Its global query uses `globalPaymentEligible()`, which excludes APPROVED purchases; `GlobalPurchasePaymentController::show` has the same restriction. The product link therefore needs an explicit projection context, not just a search string or existing summary-card filter.

## Goals / Non-Goals

**Goals:** One reusable source definition for projection and drilldown; bounded database aggregation; no persistence from viewing; focused PHP verification.

**Non-Goals:** Changing actual receipt costing, recomputing historical HPP, forecasting sales before arrival, allocating header freight/discount, adding migrations, changing payment candidate eligibility, or automated browser testing.

## Decisions

### 1. Compute a page-load global estimate

Add a small projection service used by the cross-business price controller. Reuse its resolved `currentGlobalHpp` baseline rather than resolving HPP independently per business. Aggregate incoming quantity R and value V, then compute `(product_quantity × currentGlobalHpp + V) / (product_quantity + R)`. If R is zero return the baseline. If R is positive but the resulting denominator is nonpositive, return V/R as a deterministic incoming-cost fallback. Preserve fractional base quantities and cost precision; round only for display using the existing whole-Rupiah convention.

The existing active-row/first-row fallback is a compatibility assumption for divergent historical averages; preserve the discrepancy warning. Do not silently synchronize stored values. For stockless products show `Tidak berlaku`. Recompute on page load or redirect; no polling or client-side recalculation from editable commercial prices. Incoming-only and per-business averages were rejected because the agreed goal is projected global HPP after receipt.

### 2. Share outstanding eligibility without materializing all purchase lines

Extract or reuse the approved-receipt aggregate from `CrossBusinessInDeliveryStockService` through a focused shared query helper. Match purchase status APPROVED/RECEIVED PARTIALLY, null archived_at, and selected product. Subtract only approved receipt quantities by purchase-detail identity and clamp each remainder before summation. Canonical detail quantities are already base-unit quantities.

Value each remainder with `PurchaseCostHelper::calculateUnitCost(sub_total, product_tax_amount, product_discount_amount, quantity)`. An SQL-equivalent expression can aggregate the same formula with a positive ordered-quantity guard; verify it against the helper in focused tests. Discounts are already in subtotal. Header discount/shipping are excluded because receipt approval excludes them. Use aggregate queries for R/V and correlated existence for source purchases rather than loading historical notes and details into PHP. If the inventory service is refactored, run its focused regression test.

### 3. Make projection display informational

Add the column beside current average price in `products/cross-business-prices.blade.php`, repeating the same value for every business, including missing price rows. Render text with existing currency conventions, not a saveable input. Preserve base/conversion/bundle edits, copy behavior, global-HPP dialog, snapshots and save validation. The exact tooltip is specified in the delta. Place a single permission-gated source link beside the heading using a new-tab target and noopener semantics.

### 4. Add explicit projection context to the existing global workspace

Use a validated positive product identifier such as `projection_product_id` in the standalone global workspace URL. Controller entry validates existence and passes trusted context to a locked Livewire product property; browser refresh reconstructs it from the URL. Pass this context through the workspace partial with no context on supplier embeds. The existing global access permission remains mandatory at controller and Livewire entry points.

Only when this context is present, replace normal payment-list eligibility with the shared positive-outstanding product query. Use an existence predicate to avoid duplicate purchase headers. Keep global business/date filters, search, sorting and pagination. Initial links omit all additional filters. Retain product scope through subsequent requests and reset actions; reset clears optional filters, not the product identity. Invalid product context fails closed. Hide payment summary cards in this mode rather than mixing payment totals with incoming-goods scope, and label it as the selected product's approved outstanding purchases.

For source document navigation, pass product projection context to the global detail route. Its read query must validate the same source eligibility before admitting an otherwise payment-ineligible APPROVED purchase. Existing normal detail behavior remains unchanged without context. Payment buttons and direct payment endpoints continue to require normal eligibility; projection context cannot change those rules. A purchase that ceases contributing between list and detail can return not found and the user can refresh.

A separate new global purchase product was rejected because it duplicates the existing workspace; blindly broadening `globalPaymentEligible()` was rejected because it would change payment behavior.

### 5. Focused verification and human browser review

Follow nearby tests in `Modules/Product/Tests/Feature/CrossBusinessPriceBackendTest.php`, `CrossBusinessPriceAccessTest.php`, `tests/Feature/Reports/CrossBusinessInDeliveryStockServiceTest.php`, and purchase Livewire/global filter tests. Run focused `php artisan test --filter=...` selections for changed calculations, rendering, permissions, filters and eligibility regressions. No full-suite requirement and no automated browser tools or browser tests. A human reviews column placement, tooltip, edit/cancel display, new-tab navigation and source-list readability; record handoff separately from automated completion.

## Risks / Trade-offs

- [Estimate changes as stock, approvals or receipts change] → Explain the receive-now assumption; read fresh data on reload without writing or long-lived cache.
- [Divergent stored HPP] → Reuse existing baseline and warning; document that this is an estimate rather than repair historical data.
- [Zero/negative stock] → Specify deterministic denominator behavior and cover it in focused tests.
- [Global payment scope accidentally broadened] → Gate projection mode explicitly, scope detail requests to source membership, retain payment service eligibility, and regression-test normal and embedded views.
- [Source list differs from projection] → Share the line eligibility predicate; no initial payment/date restrictions; acknowledge normal changes between page loads.

## Migration Plan

No database migration or backfill. Deploy code and views together after focused PHP checks. Human performs browser review. Rollback removes the projection presentation/context while leaving all stored purchase, stock and price data intact.
