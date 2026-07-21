## 1. Packing pricing engine

- [x] 1.1 Create `Modules/Pos/Services/PackedLinePricingService.php` with a pure `price(int $qty, string $tier, array $pricingBasis): array` returning `{ line_total_minor, blended_unit_price, breakdown }`
- [x] 1.2 Implement per-group logic: `box_count = floor(qty/factor)`, `remainder = qty % factor`; each box group = `min(box_price, factor × tier_base_price)`; remainder = `remainder × tier_base_price`; all in integer minor units
- [x] 1.3 Resolve tier base price from `pricing_basis` (base_price / tier_1_price / tier_2_price) with the same fallback semantics as `resolveLinePrice`
- [x] 1.4 Build `breakdown` payload: packing split (boxes + loose), both price ways compared with winner flag, tier label
- [x] 1.5 Add `Modules/Pos/Tests/Unit/PackedLinePricingServiceTest.php` covering qty 3/5/6 for non-tier and reseller (expect 135000 / 210000 / 255000 and 210000 / 252000)

## 2. Pricing basis capture (add/scan, DB touched once)

- [x] 2.1 In `PosCartService::addLine`, when the product has a box conversion, build and cache `pricing_basis` = { factor, box_price, base_price, tier_1_price, tier_2_price, tax_id, tax_name, tax_rate } from a single set of queries
- [x] 2.2 Seed initial quantity to `factor` when the line is added via box barcode scan; otherwise keep the requested base-unit quantity
- [x] 2.3 Populate `pricing_basis` for product-search adds too (any product with a box conversion), so packing applies regardless of entry path
- [x] 2.4 Compute the initial packed price via `PackedLinePricingService` and store `line_total`, blended `unit_price`, `breakdown`, `price_source = PACKED`
- [x] 2.5 Preserve existing bundle branch unchanged; collapse the base vs conversion branches into the single packed path

## 3. Reprice triggers (zero-DB updates)

- [x] 3.1 In `PosCartService::updateLine`, re-pack the line's total quantity from cached `pricing_basis` on quantity change (no DB queries) and refresh `line_total` / `unit_price` / `breakdown`
- [x] 3.2 In `PosCartService::updateCustomerSelection`, re-pack packed lines using cached `pricing_basis` and the new tier — no per-line pricing query
- [x] 3.3 Assert (test) that quantity and customer updates on a packed line issue zero pricing/conversion/tax queries

## 4. Merge key and re-pack semantics

- [x] 4.1 Update `PosCartService::buildMergeKey` so packed lines key on product + tax + conversion + tier + PACKED marker, excluding blended price
- [x] 4.2 Ensure repeated box scans coalesce and re-pack the combined total quantity (never price an increment in isolation)
- [x] 4.3 Mirror the packed merge-key shape in `PosTransactionSnapshotMapper::computeMergeKey` (extract a shared helper if practical) and add a reload-parity test

## 5. Authoritative line total in totals calculator

- [x] 5.1 Extend `PosCartTotalsCalculator` to use a line's authoritative `line_total` (when present) as `_line_gross_minor` instead of `qty × unit_price`
- [x] 5.2 Verify percentage line discounts, bill-discount proration, and PKP tax extraction operate on the authoritative gross
- [x] 5.3 Add totals tests for blended lines that do not divide evenly (e.g. line_total 100000, qty 3 → subtotal exactly 100000)
- [x] 5.4 Confirm existing `PosCartTotalsCalculatorTest` non-packed cases still pass unchanged

## 6. Read-only breakdown panel (sell UI)

- [x] 6.1 Surface `breakdown` on each line in the cart snapshot (`buildSnapshot`)
- [x] 6.2 Render a read-only breakdown panel in `Modules/Pos/Resources/views/sell.blade.php`: packing split, both price ways compared with winner marked, tier badge
- [ ] 6.3 Nice-to-have: show a customer-tier badge on customer selection

## 7. Receipt packing split

- [x] 7.1 In `PosReceiptService`, build `unit_breakdown` from the packing split (boxes + loose base units) for packed lines
- [x] 7.2 Confirm `receipt.blade.php` renders the packing-split breakdown; adjust wording if needed
- [x] 7.3 Update/extend `POSReceiptGenerationTest` for the packing-split breakdown

## 8. Verification

- [x] 8.1 Run focused POS pricing tests via `php artisan test` (packing, totals, cart, receipt) and fix failures
- [ ] 8.2 Manual/regression check: quantity 6 for Kertas A4 yields 255000 (non-tier) and 252000 (reseller); price updates live on quantity and customer change
