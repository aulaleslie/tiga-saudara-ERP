## Why

Products, customers, suppliers, taxes, and other transaction-linked master records can currently be deleted, which can remove or weaken the references needed to explain historical transactions and produce reliable reports. These records need a reversible inactive state that prevents new commercial use without hiding them from payments, returns, reports, and audit history.

## What Changes

- Replace destructive delete actions for transaction-linked master data with explicit deactivate and reactivate actions.
- Cover products, customers, suppliers, taxes, payment methods, payment terms, locations, units, and chart-of-account records that are referenced by transactional or accounting data.
- Keep inactive records visible in their administrative lists with a clear status and an active/inactive filter.
- Exclude inactive records from selection and validation when creating new sales, purchases, POS transactions, quotations, adjustments, transfers, expenses, and other new transaction documents.
- Preserve and resolve inactive records when viewing or editing an existing document that already references them, searching transaction history, settling an existing payable or receivable, producing reports, or creating returns and reversals from source documents.
- Reject stale or crafted requests that attempt to introduce an inactive master record into a new transaction.
- Preserve the existing product duplicate-merge lifecycle separately from ordinary product deactivation.
- Protect historical references against application-level deletion and unsafe database cascade behavior.
- Add focused tests for the touched lifecycle, selectors, transaction validation, and historical regression paths; do not require a full-suite test run.

## Capabilities

### New Capabilities

- `transaction-master-data-lifecycle`: Defines deactivation, reactivation, administrative visibility, new-transaction eligibility, historical resolution, and referential-integrity behavior for transaction-linked master data.

### Modified Capabilities

None. Existing capability behavior remains valid; this change adds a shared eligibility and retention policy across those workflows.

## Impact

- Affects master-data schemas, Eloquent models and queries, controllers/routes, permissions, list actions and status filters, Livewire/autocomplete selectors, request validation, imports, and POS staged state.
- Affects new-transaction entry points in Product, People, Setting, Sale, Purchase, POS, Quotation, Adjustment, Expense, return, payment, and accounting areas.
- Historical report, payment, return, reversal, audit, and document-detail queries must continue resolving inactive records.
- Existing foreign-key delete policies for covered master tables require review and hardening where deletion could null or cascade historical references.
- No historical transaction rewrite and no new external dependency are intended.
