## Why

Phase 1 can receive and trace supplier-owned stock, but selling that stock does not yet establish which supplier receipt funded the sale or reserve the quantity for later billing. Phase 2 is needed to turn persisted Sales and POS dispatch evidence into an auditable, concurrency-safe supplier allocation without creating Purchases, payables, or new inventory movements.

## What Changes

- Discover billable consignment quantities from approved `dispatch_details` at consignment locations for both ordinary Sales and posted POS checkouts, while leaving existing sale-location selection unchanged.
- Persist immutable sold-source snapshots so later POS return quantity adjustments, serial state changes, or configuration changes cannot rewrite the original event.
- Deduct physically effective pre-billing Sales Return and POS Return quantities from allocation eligibility.
- Resolve serialized ownership from approved consignment receiving lineage and prohibit supplier reassignment.
- Let authorized users manually allocate non-serialized sold quantities to approved supplier receipt lots at the same source location.
- Add one-supplier billing confirmations with draft, submission, approval, rejection, immutable audit evidence, and reservation release rules.
- Atomically prevent over-allocation of either sold quantities or supplier receipt pools under concurrent submissions and approvals.
- Extend consignment reconciliation to show received, sold, returned-before-billing, reserved, approved-allocation, and remaining quantities.
- Keep Phase 3 out of scope: approved confirmations do not create Purchases, payables, payments, supplier tax invoices, or post-billing credits.

## Capabilities

### New Capabilities

- `consignment-sales-allocation`: Detects consignment sales, preserves immutable sold evidence, resolves or captures supplier receipt allocation, governs confirmation lifecycle and reservations, accounts for pre-billing returns, and exposes allocation reconciliation.

### Modified Capabilities

None.

## Impact

- Adds Phase 2 tables, models, services, controllers, views, permissions, and focused tests under `Modules/Consignment`.
- Reads approved `Modules/Sale` dispatch details, Sales Return and POS Return lifecycle evidence, POS checkout-sale links, Phase 1 receiving details, and serial provenance.
- Adds read-only relationships and indexes where needed for source lookup, idempotency, reservations, and reconciliation; it does not alter Sales/POS sourcing or stock mutation behavior.
- Extends consignment navigation and reconciliation UI. Ordinary Purchase, Sales, POS, stock, payment, and non-consignment flows remain unchanged.
