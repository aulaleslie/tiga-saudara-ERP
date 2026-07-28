## Context

The existing sale-payment flow is setting-scoped and processes one sale per submission. Its controller can combine a monetary payment with customer credit, updates stored sale totals directly, and uses the normal sale detail route whose ownership and company presentation depend on the active session setting.

The archived `global-purchase-multi-payment` change provides the closest brownfield pattern: a permission-protected global workspace, a Livewire table and summary cards in global mode, dedicated cross-setting detail/history routes, a supplier allocation form, and a transaction service that locks and revalidates every purchase before creating existing payment rows.

Sales introduce three material differences:

- Eligible sales start at `APPROVED` and include `DISPATCHED PARTIALLY` and `DISPATCHED`.
- POS Kas Bon checkouts post ordinary `Sale` records with `DISPATCHED` status and `UNPAID` or `PARTIAL` balances, sometimes split into multiple owner-aligned sales.
- Existing sale payments can apply customer credits, but this global workflow is intentionally monetary-payment-only.

## Goals / Non-Goals

**Goals:**

- Provide one cross-setting workspace for collecting a customer payment against multiple outstanding sales.
- Include both ordinary sales and POS Kas Bon sales without creating a parallel POS settlement ledger.
- Preserve active-payment, invalidation, decimal, media, status, setting, customer, and permission conventions.
- Make list eligibility and submission validation use one canonical live-balance calculation.
- Keep all positive allocations and attachment replication atomic.
- Keep normal sales routes setting-scoped and global routes explicitly authorized and read-only except for payment creation.

**Non-Goals:**

- Applying `CustomerCredit` or creating `SalePaymentCreditApplication` records.
- Paying invoices belonging to different customer IDs in one submission.
- Changing the existing single-sale customer-credit workflow.
- Combining owner-split POS sales into one artificial invoice or payment row.
- Introducing a new global-payment header or payment ledger table.
- Allowing draft, waiting-approval, rejected, returned, archived, or fully paid sales.

## Decisions

### Reuse existing sale and payment records

The workflow will create one active `SalePayment` per positive allocation and update the corresponding `Sale`. Shared payment fields will be copied to each row, and one uploaded attachment will be independently attached to every created payment.

This matches the purchase implementation and keeps operational reports, payment history, invalidation, and POS balance visibility on the existing ledger. A new batch header was considered but rejected because the current domain does not require batch-level editing or reversal and the shared reference is sufficient for operator traceability.

### Group candidates by exact customer identity

The starting sale determines the exact `customer_id`. Candidate sales can belong to any setting but must have that same customer ID.

Name or contact matching is rejected because it can combine distinct customer records. Cross-customer allocation is rejected because it weakens authorization, reconciliation, and tamper validation.

### Define “approved up” explicitly

Eligible statuses are exactly:

- `Sale::STATUS_APPROVED`
- `Sale::STATUS_DISPATCHED_PARTIALLY`
- `Sale::STATUS_DISPATCHED`

Every candidate must also be non-archived and have a positive live outstanding balance. This permits approved sales to receive deposits and includes POS Kas Bon, whose generated sales are dispatched. Earlier lifecycle states are excluded because they are not approved receivables.

### Add canonical live sales balance behavior

The `Sale` domain will expose a canonical active monetary-payment total and live outstanding balance, with reusable query behavior equivalent to the purchase-side live-balance pattern. Global list, summary, candidate selection, starting-sale validation, locked submission validation, and post-payment reconciliation will use this behavior rather than trusting a rendered or stored `due_amount`.

For this change, the live monetary paid amount is derived from active `SalePayment.amount` values. Existing customer-credit applications remain part of the stored sale settlement history but cannot be newly applied through global payment. Implementation must preserve any already-applied credit when reconciling the header; it must not erase or double-count it. Focused tests will establish reconciliation behavior for sales that already contain credit applications even though they cannot receive new global credit.

Using only stored `due_amount` was rejected because it can be stale after invalidation, imports, concurrent payment, or historical correction.

### Use a dedicated global controller and service

`GlobalSalePaymentController` will own index, cross-setting detail, history, create, and store endpoints. `GlobalSalePaymentService` will own atomic allocation.

The service will:

1. Normalize and sort allocation IDs.
2. Prevalidate the optional temporary attachment.
3. Begin one database transaction.
4. Lock every positive-allocation sale in deterministic order.
5. Revalidate customer, status, archive state, and live balance.
6. Resolve the selected payment method once and create one active `SalePayment` per allocation.
7. Reconcile affected sale headers from canonical settlement totals.
8. Replicate attachment media while preserving atomic failure behavior.

Controller-only implementation was rejected because the concurrency and media logic deserves isolated feature tests and reuse.

### Extend existing Livewire sales surfaces with locked global mode

`SaleTable` and `SaleSummaryCards` will accept a locked `globalMode` flag. Normal mode keeps the active `setting_id` restriction and existing actions. Global mode removes that restriction, enforces the global permission, applies eligible statuses and live-positive-balance rules, and renders payment-only actions.

Search will retain existing sale reference, imported reference, tax reference, customer, product, tag, POS receipt, and POS transaction matching. A separate table implementation was rejected because it would duplicate the established sales list and drift over time.

### Provide dedicated cross-setting read-only presentation

Global detail and history routes will fetch eligible sales without using the normal active-setting ownership path. They will load the sale’s actual `Setting` for company presentation and expose no edit, archive, approval, dispatch, attachment-management, or other operational controls. Payment creation appears only with `salePayments.create` and positive live due.

Normal detail and payment routes retain their existing setting behavior. The unrelated global-sales-search permission will not substitute for `salePayments.global.access`.

### Keep customer credit out of the global contract

The global form will contain one monetary allocation per candidate and will not query, display, accept, or mutate customer credits. Server validation will reject or ignore no credit-shaped input; the controller will whitelist only defined shared fields and monetary allocations.

This prevents a first implementation from combining two independently locked allocation ledgers. Customer-credit multi-allocation can be proposed separately if needed.

### Treat POS Kas Bon as ordinary eligible sales

No POS-specific payment record is created. A POS-originated sale participates when it satisfies the same customer, status, archive, and live-balance rules. The list and allocation form will display available POS receipt or transaction identifiers for operator recognition. Split-owner checkout sales remain distinct rows and are settled independently.

## Risks / Trade-offs

- **[Historical sale headers may not reconcile cleanly with active payments and existing credits]** → Add focused characterization tests and centralize the calculation before enabling global submission.
- **[Concurrent operators can over-allocate the same invoice]** → Lock candidates in deterministic ID order and revalidate live balances inside one transaction.
- **[One shared attachment copied to many payments increases storage]** → Preserve the purchase workflow’s independent-copy behavior because each payment history must remain self-contained.
- **[Cross-setting detail can show the wrong company identity]** → Pass and render the sale’s actual setting instead of using only the active-session `settings()` helper.
- **[POS split sales can look like duplicates]** → Display sale reference plus receipt/transaction identifiers and setting/company context; do not merge owner-aligned sales.
- **[Global mode could leak ordinary mutation controls]** → Use dedicated actions and global-aware views, and verify hidden controls and forbidden direct routes in feature tests.
- **[Extending shared Livewire components could regress normal lists]** → Lock global mode, default it to false, and run both normal and global component tests.

## Migration Plan

1. Add and seed `salePayments.global.access` through the project’s permission conventions.
2. Add canonical Sale live-balance behavior with characterization tests.
3. Add the global service, controller, routes, Livewire modes, views, actions, and navigation.
4. Add focused feature tests for authorization, cross-setting queries, atomic allocation, attachment replication, live reconciliation, and POS Kas Bon.
5. Deploy additively; no historical row rewrite is required.

Rollback removes the global routes/menu/permission exposure and application classes. Existing `SalePayment` rows created through the workspace remain valid ordinary payment history and require no data rollback.

## Open Questions

None. Eligibility, customer-credit exclusion, and POS Kas Bon inclusion are resolved.
