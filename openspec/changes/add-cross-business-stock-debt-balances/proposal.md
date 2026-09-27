# Proposal

## Why

Stock Transfer V3 records immutable cross-business movement provenance but does not show the resulting product quantities owed between businesses. Operators need an auditable balance view, while ordinary reverse-direction V3 transfers should automatically rebalance matching debt without introducing a separate return document or lifecycle.

## What Changes

- Add a `Saldo Utang Stok Antar Bisnis` submenu with one matrix row per product and one collapsible column per debtor business, following the existing Stok Lintas Bisnis interaction. A collapsed business cell shows how much of that product the business owes in total; expanding the business shows the creditor-business breakdown.
- Create an immutable debt-event ledger sourced only from completed cross-business V3 receipt allocations; same-business allocations have no debt effect.
- Rebalance an existing opposite-direction balance for the same product and condition when a V3 cross-business receipt completes, then create a reverse balance for any excess quantity.
- Show dispatched-but-unreceived quantities separately as informational projections for products that already have authoritative debt, without changing the debt balance. Products that exist only in transit and have no current debt are not listed.
- Add drill-down provenance from each balance to the V3 receipt allocations and transfers that changed it.
- Permit every Stock Transfer V3 entry path, including the standard create button and debt-dashboard prefill, to persist zero-quantity product rows in drafts. Submission for approval requires every retained row to have a positive valid quantity and exact serial count where applicable.
- Let V3 approvers use Simpan Progres as a truly incomplete workspace save: zero quantities, missing source or destination, mismatched totals, same-source-and-destination choices, and currently unfulfillable allocations may be retained without executable-plan validation or inventory effects. Setujui dan Kirim remains the strict boundary for completeness, route, quantity, serial, and live-stock validation.
- Show only products with at least one nonzero authoritative business debt. An authorized user may select any combination of product rows and open the existing Stock Transfer V3 create form with those products preloaded at quantity zero; no debtor, creditor, debt quantity, or stock condition is carried into the transfer.
- Rehydrate the ledger idempotently from existing completed V3 receipt allocations. The production-equivalent local snapshot currently contains no cross-business V3 receipts, so initial balances are expected to remain zero.
- Add a dedicated balance-view permission while preserving existing Stock Transfer action permissions and protected stock/tax visibility boundaries.
- Keep legacy workflow version 1/2 transfers, return obligations, statuses, and actions unchanged and outside this new ledger.
- Verify with focused automated tests only. Browser interaction and visual verification are explicitly assigned to a human developer; implementation agents must not run Chromium, browser automation, or attempt to reproduce UI behavior through automated browsers. No full-suite test run is planned.

## Capabilities

### New Capabilities

- `stock-transfer-cross-business-debt-balances`: Directional product-and-condition debt ledger, rebalancing rules, dashboard, drill-down, rehydration, in-transit projection, and authorization.

### Modified Capabilities

- `stock-transfer-inventory-movement`: Make successful V3 receipt the atomic and idempotent boundary that records cross-business debt effects alongside inventory completion.
- `stock-transfer-entry-scanning`: Permit zero-quantity rows in drafts from every V3 entry path, add validated debt-dashboard product prefill, and retain strict positive-quantity submission validation.
- `stock-transfer-approval-allocations`: Separate permissive, resumable approval-workspace saving from strict final approval validation.

## Impact

- `Modules/Adjustment` migrations, entities, services, controllers/routes, views, menu integration, permissions, V3 create-form initialization, and focused feature tests.
- `TransferV3ReceiptExecutor` gains locked, atomic debt-ledger posting for cross-business receipt allocations.
- Existing immutable `transfer_movement_allocations` remain the authoritative rehydration and provenance source.
- No external dependency, separate return workflow, automated browser tooling, or legacy-data rewrite is introduced.
