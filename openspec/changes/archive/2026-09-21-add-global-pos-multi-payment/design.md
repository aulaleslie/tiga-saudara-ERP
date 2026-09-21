# Design

## Context

See `proposal.md` for motivation. POS finalization already posts ordinary Sales and Sale payments. Split checkout persists `pos_checkout_sales` mappings, while legacy/inline checkout can expose one Sale through `pos_checkouts.sale_id`. Global Sales Payment already provides canonical live-balance reconciliation, atomic multi-Sale payment creation, attachment replication, filters, tokenized search, exact barcode/serial lookup, and cross-setting read-only routes.

The missing layer is a POS-transaction projection. `pos_checkouts.paid_total` and checkout payment rows are immutable checkout-time tender facts; they are not lifetime collection totals. `pos_transactions` has lifecycle status but no payment status. Later payment therefore must settle generated Sales and project their current state back to one POS-facing row.

## Goals / Non-Goals

**Goals:**

- Establish one reusable, query-efficient definition of a valid completed POS financial transaction and its aggregate settlement state.
- Preserve one POS transaction as the pagination, summary, search, detail, and allocation unit even when checkout generated multiple Sales.
- Reuse the existing Sale ledger and POS ownership/payment priority with current-balance revalidation.
- Preserve reliable batch-to-POS-to-Sale-to-payment audit provenance.
- Keep cross-setting detail and receipt reprinting explicitly authorized and read-only.

**Non-Goals:**

- Replacing Global Sales Payment or the single-Sale payment workflow.
- Applying customer credit through the global POS form.
- Mutating historical checkout tender, checkout allocation, receipt, or POS-session cash records.
- Adding payment actions for draft, loaded, cancelled, failed, or structurally incomplete POS transactions.
- Running the complete application test suite as part of this change; verification is focused on affected behavior.

## Decisions

### Use a POS settlement projection over the Sale ledger

Create a shared projection/query service that resolves generated Sales through `pos_checkout_sales`, falling back to `pos_checkouts.sale_id` only when no split mappings exist. It will calculate effective paid, live due, payment status, earliest outstanding due date, overdue amount, and data-integrity state from canonical Sale settlement behavior.

This projection will power the list, cards, filters, candidate form, submission revalidation, and detail. Reusing one definition prevents split joins from producing different card and row totals. Persisting payment status on `pos_transactions` was rejected because payment invalidation, credit application, returns, and Sale monetary adjustment would make it stale.

### Treat completed posted transactions as the register base set

The register base query requires `COMPLETED`, a posted completed checkout, and at least one reachable generated Sale. It does not inspect `metadata.is_debt` or require an existing Sale payment. This includes ordinary paid transactions and zero-down Kas Bon while excluding non-financial lifecycle shells.

Malformed records will not be inferred as paid. The projection will identify missing or inconsistent mappings for diagnostics; they remain non-payable and outside the normal register unless an explicit diagnostic surface is added during implementation.

### Preserve checkout tender and separate later collection

Checkout `paid_total`, checkout payment rows and allocations, response payloads, receipt tender/change, and POS-session cash events remain immutable. Later collection creates new Sale payments. Global detail and receipt presentation will label checkout tender separately from current effective settlement.

Updating checkout totals was rejected because it would rewrite historical cashier/session facts and make receipt and reconciliation meaning ambiguous.

### Reuse POS priority as a pure remaining-balance planner

Extract or adapt the established POS ownership/payment priority into a planner that accepts reachable Sale groups, current live balances, and a requested POS amount. It fills current remaining balances in the same priority order, skips settled or ineligible groups, and emits a deterministic child-Sale allocation preview.

Calling checkout finalization directly was rejected because it assumes a new cart, inventory posting, and original balances. Due-date ordering was rejected because it would differ from checkout ownership priority.

### Persist workflow provenance without a second ledger

Add a global POS payment batch header and allocation rows. The header retains customer, shared date/reference/method/note, actor, and idempotency identity. Each allocation links the batch, POS transaction, generated Sale, created Sale payment, and amount. Sale payments remain the sole monetary ledger and canonical balance source.

Deriving all history later from shared references was rejected because references are not guaranteed relational identities and cannot reliably reconstruct split allocation origin, corrections, or attachment batches.

### Lock and validate the complete batch atomically

Normalize positive POS allocations and order identifiers before locking. In one database transaction, lock batch identity, POS records/checkouts, mappings, and Sales in deterministic order; verify exact customer, lifecycle, reachability, eligibility, and current live due; run the priority planner; create Sale payments and allocation links; reconcile Sales; and replicate attachments using existing cleanup behavior.

Any changed balance, mapping, customer, or attachment failure rejects the complete submission. This follows Global Sales Payment atomicity while adding POS aggregation.

### Keep the POS transaction as the outer query row

List and card queries will use correlated aggregates and `EXISTS` predicates rather than direct one-to-many joins. Counts use distinct POS transaction identity. Structured business/customer/date/status/cashier/terminal filters affect cards and rows; free-text search narrows the table without recalculating cards on each keystroke.

The selected starting transaction is injected first independently of ordinary candidate ordering so pagination or due-date sorting cannot displace it.

### Extend the established search contract for POS history

Ordinary text is whitespace-tokenized with AND across tokens and OR across fields per token. POS notes are the authoritative note field; generated Sale notes are not required because checkout already copies transaction context into split Sales. Product search includes immutable POS name/code snapshots and current linked identity, plus generated Sale/bundle provenance for components.

Barcode and serial branches match the entire trimmed input case-insensitively. Barcode search covers captured POS barcode plus current primary and conversion barcodes. Serial search uses generated Sale/dispatch provenance as primary truth and POS line/serial snapshots as legacy fallback.

### Use dedicated global routes and permissions

Create dedicated global list, detail, history, form, submission, and receipt routes that do not switch or depend on session setting. Access/create/history permissions are POS-payment specific. Receipt reprint additionally requires the existing receipt-reprint permission and records the actor through existing print logging.

Reusing the normal POS detail's `globalSalesSearch.access` bypass was rejected because search authority does not imply access to cross-business settlement, attachments, serials, and receipts.

### Keep original and current receipt facts visually separate

The receipt service remains authoritative for the original receipt. A global reprint uses the originating business and records a reprint event. If current settlement is displayed, it is a separate section and never replaces checkout methods, tender, change, or original outstanding debt.

## Risks / Trade-offs

- [Aggregate queries over Sales, payments, products, and serials can become expensive] → Use one projection/query layer, `EXISTS` predicates, eager loading for detail, appropriate indexes, and focused query-count or scale fixtures.
- [Historical split mappings may be incomplete] → Support the established inline fallback only when split mappings are absent, detect duplicate/inconsistent mappings, and block payment rather than guess.
- [Priority code may be coupled to checkout payloads] → Extract a small deterministic policy/planner and protect checkout behavior with existing plus focused regression tests.
- [Both Global Sales and Global POS can collect the same Sale] → Share canonical live balance, deterministic locking, and server-side revalidation so concurrent attempts cannot overpay.
- [Searching snapshots and current products can return a transaction under old and new names] → Treat this as intentional historical discoverability and document it in search tests.
- [Receipt reprint after settlement could be mistaken for a rewritten original] → Label original checkout facts and current settlement separately and retain print audit history.
- [A batch attachment copied to multiple payments can leave files after rollback] → Reuse the established attachment replicator and cleanup contract.

## Migration Plan

1. Add nullable-safe batch and allocation tables with foreign keys and lookup/idempotency indexes; do not backfill existing payments.
2. Deploy projection, permissions, routes, and read-only surfaces before enabling payment submission in navigation.
3. Enable submission after focused migration, authorization, projection, priority, atomicity, search, detail, and receipt tests pass.
4. Existing POS and Sale data remains unchanged. Rollback removes navigation and routes first; audit tables can remain harmlessly or be rolled back only after confirming no new batch records require retention.
