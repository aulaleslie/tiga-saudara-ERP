# Proposal

## Why

Operators can currently bill and pay a supplier only after consignment goods sell. They need to buy all or selected remaining goods before sale, optionally settle sold-but-unbilled quantities on the same invoice, and transfer unsold stock into company ownership without duplicate receiving or supplier charges.

## What Changes

- Add a dedicated buyout document for one supplier and business, supporting multiple receipts, explicit partial quantities or all eligible quantities, exact serial selection, and separate sold/unbilled and unsold allocations.
- Keep existing sold-only confirmations and their versioned historical evidence intact; share receipt, sold-source, and serial capacity checks across both workflows.
- Require outstanding non-serialized sales in an affected product/location pool to be allocated to supplier receipts before buying its unsold stock; do not guess supplier ownership or apply FIFO/LIFO.
- Prepare invoice terms and destinations in a permissioned draft; approval reserves resources without moving stock. Reject/cancel releases reservations; approved reservations do not expire automatically.
- Finalize once in one transaction: create a protected consignment Purchase/payable, move unsold goods into same-business standard locations, preserve provenance, and adjust setting-scoped average cost without adding physical quantity. Sold allocations cause no inventory or historical sales-cost change.
- Use existing full/partial Purchase payments separately from finalization. Preserve editable administrative metadata and block ordinary receiving, commercial edits, deletion, and supplier returns.
- Keep later customer returns company-owned and prevent rebilling. Support a dedicated full audited reversal only before any payment or downstream dependency; defer supplier credits and post-payment returns.
- Extend reconciliation and the consignment baseline with end-to-end, compatibility, costing, rollback, and isolated MySQL race coverage.

## Capabilities

### New Capabilities

- `consignment-stock-buyout`: Receipt-backed selection, approval, reservations, ownership acquisition, pricing, atomic financial/stock posting, customer-return ownership, and constrained reversal.

### Modified Capabilities

- `consignment-sales-allocation`: Share sold/receipt capacity with buyouts and report reservations and consumed quantities without duplicate supplier allocation.
- `consignment-supplier-billing`: Distinguish inventory-inert legacy conversion from dedicated buyout finalization; extend protected Purchase lifecycle and payment eligibility to the new source.
- `consignment-custody-receiving`: Recognize buyout reservations and ownership-acquisition evidence as receiving-reversal dependencies while preserving custody-only receiving.

## Impact

Changes primarily affect `Modules/Consignment` models, migrations, services, controllers, views, routes, permissions, and tests. Integration touches `Modules/Purchase` source guards/payment presentation/cost replay, Sales/POS and stock-operation availability, Sales Return/POS Return receiving, serial history, and inventory report projections. Reuse Laravel 10, Livewire 3, Eloquent, existing pricing/payment/sequence conventions, and the locked SQLite baseline; add no external dependency.

This explicitly introduces ownership acquisition outside the historical Phase 1/Phase 3 boundaries, without expanding those legacy operations. No historical snapshots are rewritten, no cross-business acquisition is supported, and no unrelated repository test backlog is included. MySQL concurrency remains unverified until the isolated race tests execute successfully.
