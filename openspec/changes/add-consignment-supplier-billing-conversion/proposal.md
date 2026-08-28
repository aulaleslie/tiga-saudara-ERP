## Why

Phase 2 ends with an approved, immutable supplier allocation that is ready for billing but cannot yet establish the supplier payable or enter the existing Purchase payment workflow. Phase 3 must convert that evidence into a financially active Purchase without receiving the same goods again or mutating inventory, serial, cost, sale, dispatch, or return state.

## What Changes

- Add supplier invoice capture for one approved Consignment Billing Confirmation, including invoice/reference dates, due date or payment term, tax reference, notes, and attachments where supported by the existing Purchase domain.
- Convert exactly one approved, billing-ready confirmation into exactly one source-typed Purchase and commercially precise Purchase details in one idempotent locked transaction.
- Preserve receipt-allocation granularity when quantities for the same product carry different supplier cost or tax snapshots, with durable links from generated Purchase details back to consignment allocation evidence.
- Mark the generated Purchase as physically complete without creating a Received Note or changing stock, serials, ProductPrice, last cost, average cost, Sales, POS, dispatch, or return records.
- Make the payable eligible for existing Purchase payment and supplier aging behavior while prohibiting ordinary receiving and full commercial edits that could invalidate consignment provenance.
- Add billing lifecycle evidence, dedicated permissions, tenant boundaries, source-aware Purchase presentation, and reconciliation fields for ready, billed, paid, and outstanding amounts.
- Keep post-billing returns, supplier credits/debits, invoice consolidation/splitting, automatic payments, agreements, and commission pricing outside Phase 3.

## Capabilities

### New Capabilities

- `consignment-supplier-billing`: Supplier invoice capture, atomic confirmation-to-Purchase conversion, immutable allocation-to-payable lineage, inventory-inert financial recognition, payment eligibility, and billing reconciliation.

### Modified Capabilities

- `consignment-sales-allocation`: Approved confirmations transition from billing-ready evidence to an idempotently linked billed state while retaining immutable allocation and audit evidence.

## Impact

- Adds Phase 3 billing metadata, lifecycle/audit fields, allocation-to-Purchase-detail lineage, permissions, services, controllers, views, and focused tests under `Modules/Consignment`.
- Extends `Modules/Purchase` with an explicit consignment-billing source classification and guards that prevent receiving or provenance-breaking edits while reusing reference allocation, payable totals, payments, and reporting.
- Reads Phase 1 receiving cost/tax provenance and Phase 2 approved confirmations; it does not alter their physical inventory or allocation quantities.
- Directly affects Purchase payment eligibility, supplier payable/aging presentation, Consignment reconciliation, navigation, and authorization. Verification remains limited to Phase 3 and directly affected Consignment, Purchase, payment, and reporting behavior rather than the full application suite.
