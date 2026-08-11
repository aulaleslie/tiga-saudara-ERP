## Why

Supplier invoices and commercial terms are often corrected only after goods have been received or dispatched. The current standard edit flows correctly protect executed inventory records, but they leave authorized owners without a direct way to correct the document's monetary values; the purchase-only correction workflow must remain available and unchanged.

## What Changes

- Add a privileged, monetary-only edit mode to the existing purchase and sale edit experience for `RECEIVED`, `RECEIVED PARTIALLY`, `DISPATCHED`, and `DISPATCHED PARTIALLY` documents.
- Keep the existing received-purchase correction workflow, permission, audit, payment-reconciliation, and optional cost-recalculation behavior unchanged.
- Allow approved purchase and sale documents that have not been received/dispatched to use the existing full edit behavior, including quantity changes, when the user has the corresponding approved-document permission.
- Introduce canonical, assignable permissions for approved purchase edits and post-fulfillment monetary edits, while retaining the existing `sales.approved.edit` permission.
- Require post-fulfillment edits to update existing document and line rows in place; prohibit product, quantity, row, bundle, receipt, dispatch, serial, location, stock, product-price, and sale-cost-snapshot changes.
- Recompute and persist only the affected document monetary values using existing normalization rules, without invoking receipt, dispatch, inventory-price, or HPP replay behavior.

## Capabilities

### New Capabilities

- `privileged-post-fulfillment-monetary-edits`: Permission-gated, in-place monetary editing of already received or dispatched purchase and sale documents while preserving executed inventory facts.

### Modified Capabilities

- `purchase-permission-normalization`: Add canonical purchase permissions required for approved-document and post-receipt monetary editing.
- `sales-permission-normalization`: Add canonical sales permissions required for post-dispatch monetary editing.

## Impact

- Affects purchase and sale edit controllers, Livewire edit components, cart/form controls, normalizers, authorization checks, and role-permission configuration/seeding.
- Requires an in-place persistence branch because the existing generic update paths delete and recreate detail rows; that behavior is unsafe once receiving or dispatch records exist.
- Does not add document override fields or alter the existing received-purchase correction schema/workflow.
- Requires focused feature and Livewire coverage for permissions, field locks, in-place row preservation, quantity editing before fulfillment, and protection of stock, product prices, and sales HPP snapshots.
