## Context

`products.base_unit_id` is the accounting/stock unit. `product_unit_conversions` point a larger unit at that base, while `product_prices` hold per-setting base-unit sale, tier, last-purchase, and average-purchase prices. Purchase and receiving details persist quantities without a durable UOM snapshot; receipt approval creates a `BUY` transaction and increments both global product quantity and location stock.

The incident begins with `BOX` as the product base UOM. After receiving, the business discovers that it must stock and sell `PCS`, for example `1 BOX = 10 PCS`. It follows that all quantities and purchase-side unit costs must be rebased. Supplier document money must not change.

## Goals / Non-Goals

**Goals:**

- Correct one eligible non-serial, stock-managed product from its current base UOM to a searched target base UOM with one explicit positive factor.
- Preserve supplier monetary facts while rebasing selected receipt/purchase quantities, original `BUY` transaction facts, stock facts, and purchase cost indicators.
- Rebase every product-stock location consistently and preserve its location ownership.
- Present calculated results for acknowledgement; the operator enters only target unit, factor, and reason.
- Keep sales pricing and all historical sales/POS values unchanged, while explicitly warning the operator to review sales prices before selling.

**Non-Goals:**

- No automatic sale/tier/conversion-price repricing, historical sales/POS rewrite, sale-HPP replay, or compensating inventory movement.
- No serial-tracked products, chained factor inference, reverse/undo flow, or a hold/reservation mechanism.
- No implicit conversion of stock facts that cannot be traced safely to the correction scope.

## Decisions

### Base-UOM correction is distinct from ordinary conversion

The source is always the product's current base UOM. The target is a different Unit selected by searchable catalog lookup; it is not restricted to existing conversions. The operator states `1 source = factor target`. On successful execution, product base and primary/display UOM move to the target; the former base is retained/created as a direct conversion to the target with the supplied factor.

Existing conversion rows that use the old base are migrated only if each factor can be mechanically multiplied into the new base and no duplicate unit/base relationship or barcode conflict occurs. Their conversion selling prices remain prices for their own conversion units, and their barcode column/registry ownership is left untouched by the rebase.

**Barcode migration mechanics (authoritative registry-driven):** ownership is proven only via the `barcode_identities` registry, which enforces exactly one owner (`product_id` XOR `product_unit_conversion_id`) at the database level. If `products.barcode` is set and a matching `BarcodeIdentity` row with `product_id` exists, the barcode is treated as belonging to the former base unit and is migrated in the same transaction: the newly created former-base `ProductUnitConversion` row receives the barcode value, the `BarcodeIdentity` row is re-pointed to that conversion (`product_id` cleared, `product_unit_conversion_id` set) in a single atomic update, and `products.barcode` is cleared. If `products.barcode` is set but no matching registry row exists (unbackfilled legacy data), ownership cannot be proven and the correction is blocked before any mutation. A proactive uniqueness check against other conversions' legacy `barcode` values also blocks before mutation if the intended migration would collide.

### Product-wide, location-aware correction scope

The correction applies to one product across its permitted setting scope, not merely the Purchase page used to open the workflow. Product search returns preliminary eligible candidates; a selected product loads only its related receipt/purchase lines and a per-location inventory preview.

Every old-base purchase line must be selected and fully received, or void/cancelled without a stock effect. Every current global and per-location quantity/bucket must be explainable by selected receipt-created `BUY` rows. This prevents mixing `BOX` and `PCS` quantities. Opening/import stock, transfers, adjustments, breakage, returns, replacement dispatches, bundle component usage, or any unselected stock source block the initial release unless their correction semantics are explicitly implemented.

Completed/dispatched standard Sales and completed POS—including consumed bundle components—block execution. Sales/POS drafts, loaded carts, and non-dispatched Sales do not block, but are not rebased. The UI must warn that they may need review before they are later completed.

Because a product base UOM is global while product prices can exist per setting, a price-only footprint in another setting is safe and SHALL be rebased atomically: each setting's existing `last_purchase_price` and `average_purchase_price` is divided by the factor and captured in the batch audit. This preserves that setting's independent purchase-cost basis while changing its denomination from old base UOM to new base UOM. `sale_price`, tier prices, and conversion selling prices remain untouched in every setting.

Any other-setting stock, purchase/receipt history, inventory transaction, or other physical/history footprint remains a blocker. Those facts would otherwise retain old-base quantities, so the correction must name the setting(s) and refuse execution rather than partially normalize a global product.

### In-place correction and chronological ledger rebuild

Within one database transaction, lock the product, all in-scope purchase/receipt rows, matched `BUY` transactions, all product stock rows, conversions/prices, and relevant history. Revalidate all eligibility under lock.

Multiply selected purchase and approved receipt quantities, `BUY` quantities, tax/non-tax quantity buckets, and global/per-location good-stock quantity by the factor. Preserve document headers, line totals, taxes, discounts, payments, supplier identity, and receipt locations. Rebuild global and per-location transaction snapshots in chronological order; no correction transaction is created.

**Conservative broken-stock policy:** any non-zero `broken_quantity` (or its tax/non-tax buckets) on any `ProductStock` row for the product, in any location, blocks the entire correction before mutation. Broken/damaged stock is never rebased by this feature — there is no code path that multiplies broken quantities by the factor. This removes an earlier draft's assumption that broken buckets would be rebased alongside good stock.

The durable receipt-detail provenance is used when available. Legacy `BUY` rows must resolve uniquely from product, setting, location, reference, quantity, and chronology; zero or multiple candidates blocks the entire transaction.

### Purchase-cost-only rebase and acknowledgement

For each selected receipt, preserve its monetary value and divide its purchase unit cost by the factor. Replay corrected receipt cost history to calculate current average and last purchase price in the new base UOM. Use higher internal precision and show any displayed/storage rounding in the preview.

Do not update `sale_price`, tier prices, conversion sale prices, historical sale/POS monetary rows, or sale HPP snapshots. The preview and confirmation require the operator to acknowledge both the proposed inventory/purchase-cost change and the warning that sales prices must be reviewed before the product is sold or dispatched.

### Purchase-native searchable UI and immutable audit

Use the established project searchable controls for product and Unit catalog lookup. The screen shows a read-only source UOM, searchable target UOM, factor input, only the selected product's in-scope lines, per-location before/after quantities, derived purchase costs/HPP, conversion/barcode impacts, blockers, and acknowledgement checkboxes.

Persist immutable source/final snapshots: old/new primary and base UOM, factor, conversion/barcode changes, selected rows, matched transaction IDs, location values, cost results, untouched-sales-price warning acknowledgement, actor, time, and reason. Display audit history on every affected purchase.

## Risks / Trade-offs

- [Factor or unit is wrong] → Operator supplies only the factual relationship; calculated impact preview and mandatory acknowledgements make the irreversible decision visible.
- [Price division cannot be represented exactly] → Keep document totals authoritative, use higher internal precision, display effective rounded purchase costs, and record rounding in audit.
- [Draft commercial documents become semantically stale] → Do not mutate them; warn users to review drafts before completion. Completed outbound documents remain a hard gate.
- [Global base UOM with other-setting footprint] → Rebase purchase-cost-only `ProductPrice` rows in every setting; block stock, receipt, purchase-history, or transaction footprint in another setting rather than leaving old-base quantities.
- [Location stock is not receipt-explainable] → Block and name the location/source rather than producing mixed-unit inventory.

## Migration Plan

1. Extend/revise audit schema to capture base-UOM correction and sales-price-warning acknowledgement.
2. Preserve and continue writing receipt-to-`BUY` provenance.
3. Replace the current implementation's existing-conversion flow with searchable product/target-unit preview and strict scope validation.
4. Release only after full execution, rollback, precision, multi-location, barcode/conversion, and sales-price-nonmutation coverage passes.
5. Rollback disables the route/action but retains immutable completed correction facts; no destructive rollback of a completed correction.
