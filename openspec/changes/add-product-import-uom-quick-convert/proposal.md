## Why

Several thousand products carry stock that originated from a "SALES PRICE & STOCK SNAPSHOT IMPORT" adjustment (`transactions.type = ADJ`) rather than from a received Purchase — their stock has no `ReceivedNoteDetail` → `BUY` lineage at all. When such a product's unit of measure is wrong (e.g. base unit stored as `BKS` but the business needs to stock and sell it as `PCS`), the existing "Normalisasi UOM Penerimaan" tool cannot help: it is deliberately built to trust only receipt-traceable `BUY` history and hard-blocks with "Hanya produk dengan sumber dari Pembelian (BUY) yang dapat dinormalisasi" whenever it finds import/adjustment-sourced stock. Re-importing or fabricating fake receiving records to satisfy that tool would fight the data model and touches production data unnecessarily. A narrower, honest correction path is needed so an operator can fix these products' UOM in place without another import cycle.

## What Changes

- Add a new privileged artisan command, `product:convert-uom {product_id} {target_unit} {factor} [--reason=] [--dry-run]`, that rebases a single product's base unit of measure directly from its current live stock, for products whose stock has no dispatch/sale history.
- The command multiplies `products.product_quantity`, every `product_stocks` quantity bucket, and the originating stock-adjustment `transactions` row's own quantity fields by `factor`; divides purchase-cost basis fields (`average_purchase_price`, `last_purchase_price`) by `factor`; and updates `products.unit_id`/`base_unit_id` to the target unit. `purchase_details` rows are never read or written — they are historical/reference only and are confirmed disconnected from live stock for import-origin products.
- Adds hard-blocking eligibility checks before any mutation: transaction-ledger self-consistency (no untracked stock drift), and zero dispatch/fulfillment history for the product (any `DISPATCH` transaction, or any Sale with `status` in `DISPATCHED`/`RETURNED`/`RETURNED PARTIALLY`, or any Sale/POS document with `paid_amount > 0`, blocks the command entirely).
- Adds an auto-cleanup step: any POS draft (`pos_transactions` status `DRAFT`/`LOADED`) or standard Sale with no dispatch and no payment that references the product is force-deleted in the same run (not just blocked), because those documents' persisted quantities are never re-resolved against a changed base unit at checkout and would silently dispatch the wrong quantity afterward. The command reports every removed document (reference, status, payment amount, owner/customer, created_at) in its output.
- Writes an immutable audit record of the correction (product, old/new unit, factor, before/after quantities, reason, actor, timestamp, and the list of documents removed).
- **BREAKING**: none — this is a new, narrowly-scoped operator tool; it does not change existing UI, APIs, or the existing Purchase UOM Normalization workflow.

## Capabilities

### New Capabilities
- `product-import-origin-uom-correction`: Defines eligibility rules, mutation scope, and audit requirements for correcting the base unit of measure of a product whose stock originated from a stock-snapshot import/adjustment rather than a receipt, including the mandatory removal of undispatched/unpaid draft documents that reference the product.

### Modified Capabilities
(none — this does not change requirements of `received-purchase-uom-normalization`, `pos-draft-stock-management-preservation`, or any other existing spec; it is a distinct, additive workflow for a distinct class of product.)

## Impact

- New artisan command in `Modules/Product` (or a suitable module) plus a service class implementing eligibility checks and the mutation transaction.
- Reads/writes: `products`, `product_stocks`, `transactions`, `product_prices` (per-setting cost fields), `product_unit_conversions` (if any exist for the product).
- Reads/deletes: `pos_transactions` + `pos_transaction_lines` (DRAFT/LOADED, undispatched/unpaid), `sales` + `sale_details` (undispatched/unpaid).
- New audit table (or extension of an existing audit pattern) to persist the correction record.
- No changes to existing Purchase, POS, or Sales UI/controllers; this ships as a CLI-only tool for privileged operators, run against production data in place.
