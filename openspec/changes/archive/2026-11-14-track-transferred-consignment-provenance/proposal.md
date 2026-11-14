# Proposal

## Why

Consignment goods can be transferred and sold by another business, but current reconciliation assumes the selling location is the original receipt location. Proven TOP IT-to-PERDANA transfers therefore block buyout and leave remote sales outside TOP IT's supplier allocation, even though CANDRA must continue dealing only with TOP IT.

## What Changes

- Preserve original supplier and receipt-business responsibility separately from current custody, inventory ownership after acquisition, and the actual selling business.
- Keep discovery manual. Discover remote approved dispatch evidence into consignment reconciliation without changing POS checkout, cashier steps, configured location order, tax rules, revenue, customer payments, or historical transactions.
- Track serialized movements through exact serial/receipt/transfer lineage; track non-serialized quantities through explicit receipt-lot transfer allocations. A sole eligible lot is proposed for transfer-operator confirmation; multiple eligible lots require explicit choice.
- Keep non-serialized sale attribution in back-office reconciliation after manual discovery. Ambiguous attribution blocks billing/buyout, never cashier checkout, and never silently applies FIFO/LIFO.
- Reconcile local custody, remote custody, transit, net sales, acquisitions, reservations, and supplier financial consumption separately; completed transfers do not create a supplier bill.
- Let the original receipt business bill/pay the supplier for remote sales and buy out eligible stock at local or remote consignment locations. Unsold bought-out stock becomes owned by the holding business, through a standard destination in that business, while the supplier Purchase stays with the original receipt business.
- Preserve receipt provenance, existing billed evidence, versioned sold-source hashes, return/inspection policies, claim safeguards, and exact once-only supplier consumption across businesses. Record internal provenance only: create no inter-business payable, invoice, debt settlement, or commission.
- Add preview-first, audited historical reconstruction for proven transfers, including the AXIOO example; unresolved evidence remains an actionable blocker. Do not edit live quantities or rewrite old sales to make balances agree.

## Capabilities

### New Capabilities

- `consignment-transfer-provenance`: Receipt-backed custody/transit tracking, original billing responsibility, remote buyout ownership, return routing, historical reconstruction, and consignment-specific access boundaries.

### Modified Capabilities

- `consignment-sales-allocation`: Manual remote discovery, version-compatible sold evidence, transfer-aware receipt attribution, shared capacity, and reconciliation under the original receipt business.
- `consignment-supplier-billing`: Original-business supplier liability and supplier-facing identity with internally traceable remote sales and custody.
- `stock-transfer-inventory-movement`: Consignment-only receipt-lot provenance posting alongside existing transfer dispatch, receipt, cancellation, and return effects; standard transfers and tax policy remain unchanged.

## Impact

Primarily `Modules/Consignment` services, schema, controllers, reconciliation/buyout screens, permissions, and tests, plus narrow consignment-only hooks in `Modules/Adjustment` transfer preparation/posting. Integrate existing Purchase source protections, serial history, return ownership routing, cost-ledger events, and inventory projections where remote acquisition requires them. No external dependency.

Build on the implemented but unarchived `buy-out-unsold-consignment-stock` change; retain its outstanding human UAT/exposure gates. Its same-business receipt/source assumption is deliberately extended here, not silently removed from its historical artifacts. The separate `add-cross-business-stock-debt-balances` change is not a dependency and receives no new financial/stock-debt posting from this change.

**POS boundary:** no production edits to POS checkout/cart/allocation/posting code, transaction schemas, location selection, taxation, receipts, or customer-payment behavior. Existing POS dispatches are read-only inputs to consignment discovery. Add integration tests that prove this boundary. Ordinary transfers between consignment and standard locations remain prohibited; dedicated buyout is the controlled ownership-acquisition path.
