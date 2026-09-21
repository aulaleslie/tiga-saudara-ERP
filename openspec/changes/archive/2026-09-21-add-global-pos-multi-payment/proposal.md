# Proposal

## Why

POS transactions can generate one or more owner-aligned Sales documents, but later collections are currently performed and inspected at the individual Sale level. Users need a permission-controlled global POS workspace that presents each completed POS transaction as the customer-facing unit while preserving the generated Sales and `SalePayment` records as the accounting source of truth.

## What Changes

- Add a global POS payment register containing every completed POS transaction with a posted checkout and at least one generated Sale, including fully paid, partially paid, and unpaid transactions regardless of whether checkout originally used Kas Bon.
- Derive each POS transaction's effective paid amount, live outstanding amount, payment status, due date, and overdue state from its reachable generated Sales and canonical active settlement data without rewriting checkout-time tender totals.
- Add outstanding, overdue, and paid-within-30-days summary cards and Sales-style cross-business, customer, date, status, cashier, and terminal filters.
- Add tokenized cross-field search over POS identifiers, customer identity, POS notes, Sale references, products, bundles, payment references, and business context, while keeping barcode and serial-number lookup exact and case-insensitive.
- Add a customer-level multi-POS payment form that keeps the selected POS transaction first and expands POS-level allocations across current child-Sale balances using the established POS ownership/payment priority.
- Create ordinary active `SalePayment` records atomically, retain lightweight POS batch/allocation audit links, replicate an optional attachment, and leave original checkout tender, receipt, and POS-session reconciliation facts unchanged.
- Add a dedicated read-only global POS transaction detail with generated Sales, products, bundles, serials, returns, effective settlement, payment history, and globally authorized receipt reprinting with existing print logging.
- Add dedicated global POS payment permissions while preserving the existing Global Sales Payment and setting-scoped POS workflows.
- Use focused feature and unit verification for the affected POS/global-payment behavior; no full-suite test run is planned by this change.

## Capabilities

### New Capabilities

- `global-pos-multi-payment`: Global POS transaction discovery, aggregate settlement projection, search and summaries, customer-level multi-transaction payment allocation, audit history, global detail, and receipt reprinting.

### Modified Capabilities

- `pos-debt-checkout`: Replace the earlier constraint that later debt collection has no POS collection UI with an authorized global POS collection workspace that still settles the generated Sales ledger.

## Impact

- Affected areas include `Modules/Pos` routes, controllers, Livewire components, services, entities, views, migrations, permissions, receipt projection, and focused tests.
- The existing Sales settlement model, `SalePayment`, canonical live-balance reconciliation, attachment handling, global filtering/search conventions, and POS owner-priority allocation rules will be reused or extracted for shared use.
- New persistence is limited to workflow/audit mapping between a global POS payment batch, selected POS transactions, generated Sales, and created Sale payments; it does not introduce a second financial ledger.
- Existing POS checkout payment rows, checkout `paid_total`, receipt tender facts, session cash reconciliation, normal POS transaction routes, and Global Sales Payment behavior remain compatible.
