## Why

Supplier invoices can contain corrected prices, discounts, shipping charges, or payment information after goods have been received and the purchase has been fully paid. The current hard block protects inventory history, but leaves authorized finance or administrative users unable to correct a legitimate supplier error without unsafe database intervention.

## What Changes

- Add a privileged, auditable correction workflow for received or partially received purchases, available to Super Admin and users explicitly granted the correction permission.
- Permit corrections to received purchase line prices, global discount, shipping, and other supported monetary header values without changing received quantities, products, supplier, receipt location, or historical receipt/serial links.
- Preserve original purchase-detail identities and record mandatory correction reason plus field-level before/after values.
- Recalculate purchase document totals and active-payment-derived paid, due, and payment status values atomically.
- When one active purchase payment exists, update it as part of the correction; when multiple active payments exist, require the authorized user to choose the payment to change and review before/after values before saving.
- Keep purchase correction separate from inventory-cost recalculation. After a correction, provide an explicit authorized recalculation action that can replay affected purchase cost and, when chosen, downstream sale HPP snapshots from the applicable received date.
- Allocate supported header-level global discount and shipping amounts deterministically across affected purchase lines for cost replay, while excluding input tax from HPP.

## Capabilities

### New Capabilities

- `privileged-received-purchase-corrections`: Authorized correction, audit, payment-adjustment, and explicit cost-recalculation workflow for completed purchase documents.

### Modified Capabilities

- `purchase-permission-normalization`: Add and consistently enforce a canonical permission for correcting received purchases.
- `sales-cost-snapshots`: Permit an explicit, auditable correction-triggered replay to recompute affected downstream sale cost snapshots while preserving normal backfill semantics.
- `product-purchase-price-normalization`: Ensure corrected received purchase costs, including allocated header adjustments, are the authoritative inputs to purchase-price normalization.

## Impact

- Affected code: `Modules/Purchase` purchase controller/forms, purchase detail and payment models, receiving-note relationships, `Modules/Product` price normalization, `Modules/Sale` cost replay/snapshots, centralized permission configuration, and role-management UI.
- Data: new immutable purchase-correction audit/history storage; no destructive rewrite of receipts, serial links, stock transaction quantities, or payment invalidation history.
- Reports: purchase/payable figures reflect corrected document and active payment values; sales HPP/profit reports change only after the explicit downstream recalculation is run.
