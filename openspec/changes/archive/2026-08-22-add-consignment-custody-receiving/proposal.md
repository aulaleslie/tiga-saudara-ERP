## Why

The company urgently needs to receive supplier-owned consignment goods into physical stock without creating an ordinary supplier bill until the goods are sold. The current Purchase receiving path cannot represent custody-only inventory because approval also establishes purchase-cost, payable, and receiving semantics that would contaminate ordinary flows.

## What Changes

- Add an explicit consignment classification to locations and prevent incompatible ordinary/consignment receiving from mixing stock provenance.
- Add a dedicated Consignment Receival document with draft, submission, approval, rejection, and resubmission behavior.
- Add a single full-quantity Consignment Receiving Note with independent pending, approval, and rejection behavior.
- On receiving approval, atomically add physical stock, tax/non-tax bucket quantity, inventory transactions, supplier receipt provenance, and serial lineage without creating a Purchase or payable.
- Snapshot supplier unit cost and setting-driven tax context, and update only the receiving setting's operational weighted-average product cost while leaving last purchase price unchanged.
- Add a controlled full reversal for an approved consignment receipt only before any downstream consumption or dependency exists.
- Exclude or distinguish consignment-location stock from company-owned inventory valuation while preserving physical stock visibility.
- Add dedicated permissions, tenant guards, notifications, audit evidence, and focused regression protection for ordinary Purchase receiving.

## Capabilities

### New Capabilities

- `consignment-custody-receiving`: Location governance, Consignment Receival and Receiving lifecycles, custody-only inventory mutation, supplier quantity provenance, serial lineage, operational average cost, and safe full reversal.

### Modified Capabilities

- `purchase-receiving-per-setting-quantity`: Ordinary Purchase receiving must reject consignment-classified locations while preserving existing setting-scoped transaction quantities elsewhere.
- `inventory-valuation-report`: Company-owned inventory valuation must not count supplier-owned consignment stock as owned inventory value.
- `warehouse-stock-valuation-report`: Warehouse valuation must identify consignment locations and prevent their stock value from inflating company-owned totals.

## Impact

- New consignment module/domain tables, models, services, routes, permissions, UI, notifications, and tests.
- Additive location, inventory-transaction provenance, and serial-lineage schema changes.
- Focused changes to Location administration, ordinary Purchase receiving validation, setting-scoped ProductPrice average-cost updates, inventory reports/exports, and stock/serial audit presentation.
- No historical stock rewrite, no ordinary Purchase/payable creation during consignment receiving, and no Phase 1 changes to Sales, POS, billing confirmation, supplier payment, transfer, bundle, or import behavior.
