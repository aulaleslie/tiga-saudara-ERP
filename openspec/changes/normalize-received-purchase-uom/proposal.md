## Why

Purchases can be received before the product's packaging conversion is configured, causing a supplier quantity such as `10 BOX` to be recorded as `10 PCS`. This understates stock and makes the product's current HPP incorrect even though the supplier invoice amount is correct. The business needs a safe, auditable way to normalize all explicitly selected, fully received erroneous purchase lines to the product's existing smallest UOM before any stock-affecting sale occurs.

## What Changes

- Add a privileged, product-level received-purchase UOM normalization workflow that lets an operator select related fully received purchase lines for one non-serial, stock-managed product and one direct source-UOM-to-base-UOM conversion.
- Provide a preview that shows each selected purchase and receipt line's source quantity, normalized base quantity, preserved supplier monetary amount, transaction match, location effect, and projected current HPP.
- Update selected existing purchase-detail and approved receiving-detail quantities in place, preserve supplier financial totals and payment state, and recompute the product's current purchase-cost indicators from the normalized receipt history.
- Update the original linked `BUY` inventory-transaction rows in place, including their quantity and running quantity/bucket snapshots, rather than adding compensating correction movements.
- Add a durable receiving-detail-to-inventory-transaction link for newly approved receipts. For legacy receipts, resolve a unique existing `BUY` transaction from evidence and refuse to normalize when the match is absent or ambiguous.
- Enforce execution-time safety checks: all selected lines are fully received; the product has no stock-affecting dispatched Sale or completed POS checkout; the product has no disallowed later inventory movement; the product is not serial-tracked; and the selected rows have not already been normalized.
- Store immutable normalization audit data, including input conversion snapshot, selected rows, matched transaction IDs, before/after values, actor, reason, timestamps, and recalculation outcome.
- Add project-native Purchase UI entry points, preview, confirmation, eligibility feedback, and read-only audit visibility on affected purchase records.

## Capabilities

### New Capabilities

- `received-purchase-uom-normalization`: Safely normalize explicitly selected received purchase and receiving quantities into a product's base UOM, reconcile their original inventory transactions and current HPP, and retain complete audit evidence.

### Modified Capabilities

- `privileged-received-purchase-corrections`: Distinguish the new inventory/UOM normalization authority and workflow from existing received-purchase monetary corrections.
- `purchase-receiving-notes`: Persist the durable link between an approved receiving detail and its generated inventory transaction.

## Impact

- Affects `Modules/Purchase` purchase detail, receiving, correction, and cost-replay flows; `Modules/Product` conversion, stock, price, and transaction data; and normal Sale/POS history eligibility checks.
- Requires additive migrations for normalization audit/case records and transaction-to-receiving-detail linkage, plus a new permission and Purchase routes/controllers/views or Livewire components following existing Bootstrap/CoreUI conventions.
- Does not change supplier invoice totals, tax, discounts, payments, due amounts, normal Sales draft behavior, or POS draft/loaded-cart behavior.
