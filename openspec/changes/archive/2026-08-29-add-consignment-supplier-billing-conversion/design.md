## Context

Phase 1 records supplier-owned physical custody and immutable receipt-lot/serial provenance without a payable. Phase 2 assigns sold quantities to those receipt lots, and approval produces one-supplier immutable confirmation evidence with `is_ready_for_billing = true` and an unused `purchase_id` link. The existing Purchase domain already owns references, payable balances, payment allocation, attachments, supplier aging, and paid/partial/unpaid reconciliation, but its normal lifecycle assumes that an approved Purchase is later received into inventory.

Phase 3 crosses Consignment and Purchase. It must reuse existing financial behavior while making it impossible to receive the same goods twice or edit a generated payable away from its approved allocation evidence. The primary stakeholders are consignment billing operators, approvers, accounts payable users, auditors, and inventory controllers.

## Goals / Non-Goals

**Goals:**

- Convert one approved confirmation into one auditable Purchase exactly once.
- Preserve exact receipt-allocation cost, quantity, tax, supplier, product, and serial lineage.
- Establish a payable compatible with existing payment and reporting behavior.
- Keep conversion completely inert with respect to inventory, serial, operational cost, Sales/POS, dispatch, returns, and allocation capacity.
- Prevent ordinary Purchase receiving and provenance-breaking mutations for generated Purchases.
- Enforce tenant, permission, stale-state, and concurrency safeguards at the domain boundary.
- Verify Phase 3 and only directly affected Consignment, Purchase, payment, and report behavior.

**Non-Goals:**

- Post-billing returns, Purchase Returns, debit/credit notes, refunds, payment reallocation, invoice cancellation, consolidation, splitting, automatic payment, supplier agreements, commissions, ownership conversion, or tax-platform submission.
- Recomputing Phase 1 cost or Phase 2 allocation evidence.
- Changing standard Purchase behavior or introducing a separate accounts-payable ledger.

## Decisions

### 1. One approved confirmation creates one Purchase

Use the existing nullable `consignment_billing_confirmations.purchase_id` as the durable one-to-one result link and add database uniqueness where required. Conversion locks the confirmation and returns the existing linked Purchase or rejects repeated conversion rather than creating another document.

Consolidating confirmations or splitting one confirmation was rejected because it complicates idempotency, invoice reconciliation, returns, and audit lineage before the basic payable workflow is established.

### 2. Add explicit Purchase source classification

Add a constrained, indexed Purchase source/type field with an ordinary default and a `CONSIGNMENT_BILLING` value, plus a source relationship or durable confirmation link. Generated Purchases use `STATUS_RECEIVED` solely to participate in current payment eligibility and payable reporting; the source classification, not status alone, prevents all receiving entry points and full commercial mutation.

Creating a new parallel payable model was rejected because it would duplicate payments, balances, aging, references, attachments, and reporting. Treating the document as an indistinguishable received Purchase was rejected because ordinary controllers could create a second receipt or permit incompatible corrections.

### 3. Capture invoice metadata before the atomic conversion

Present a conversion preview derived from approved allocations and accept supplier invoice number, invoice/reporting/due dates, payment term, tax reference, notes, and attachments. On confirmation, validate the submitted metadata again against the locked confirmation, supplier, setting, and current master-data rules. Preview remains read-only and creates no reservation or financial state.

Editing the generated Purchase afterward was rejected because supplier, lines, cost, tax, and totals must stay aligned with immutable Phase 2 evidence. Later correction and credit workflows belong to Phase 4.

### 4. Preserve commercial allocation granularity

Generate Purchase details at the smallest distinct receipt-lot commercial snapshot needed to preserve quantity, unit cost, tax, product, and supplier provenance. Non-serialized receipt allocations map directly. Serialized allocations may be grouped only when they share the same receiving detail and identical commercial snapshot, while a new lineage table maps every contributing receipt or serialized allocation and quantity to its generated Purchase detail.

Aggregating only by product was rejected because receipt lots can have different supplier cost or tax snapshots. Creating an unrelated Purchase line without durable source links was rejected because later reconciliation and credits could not prove what was billed.

### 5. Use Purchase monetary conventions without recalculating commercial evidence

Line base quantities and unit commercial snapshots come from approved allocation records. Header/detail monetary values use the existing Purchase tax-inclusion and rounding conventions, with deterministic reconciliation asserting that detail quantities and totals equal the immutable billing preview. Set `paid_amount = 0`, `due_amount = total_amount`, and unpaid status on creation.

Current ProductPrice or tax configuration SHALL NOT replace stored Phase 1/Phase 2 snapshots. Any irreconcilable legacy evidence becomes a conversion blocker.

### 6. Conversion uses a single locked transaction and stable ordering

Lock the confirmation header first as the idempotency guard, then its allocation evidence in ID order, supplier/setting authority, Purchase sequence authority, and any target lineage rows required for creation. Revalidate `APPROVED`, `is_ready_for_billing`, tenant, supplier, source hashes, quantities, lineage, and absence of `purchase_id`. Create Purchase, details, lineage, audit, attachment associations, and the confirmation link in one transaction.

No stock, ProductPrice, receiving, serial, Sales/POS, dispatch, or return row is locked for mutation because conversion is financially inert; immutable source consistency is checked from Phase 2 snapshots and links. Database uniqueness remains the final defense against concurrent duplicate conversion.

### 7. Generated Purchases are immutable except for settlement state

Centralize a source-aware guard used by Purchase edit, receiving, correction, deletion/archive, and return entry points. Generated commercial fields and details are read-only. Existing authorized PurchasePayment creation/invalidation and canonical balance reconciliation remain allowed, as do read-only reports and attachments governed by the chosen invoice policy.

Scattered UI-only guards were rejected because forged routes, global payment flows, and future services must observe the same source boundary.

### 8. Extend reconciliation from allocation to settlement

Consignment reconciliation reads the linked Purchase and active payments to show ready/billed state, Purchase and supplier invoice references, total billed, paid, and live outstanding amounts. Allocation quantities remain derived from Phase 2 evidence and are not duplicated as new quantity movements.

## Risks / Trade-offs

- [Using `STATUS_RECEIVED` may trigger legacy receiving/edit assumptions] → Require an explicit source classification and centralized domain guards at every incompatible Purchase entry point.
- [Receipt lots for one product have different tax or cost] → Preserve distinct commercial snapshots and lossless lineage rather than product-only aggregation.
- [Concurrent conversion creates duplicate payables] → Lock the confirmation, enforce a unique result link, allocate the Purchase reference transactionally, and recheck before commit.
- [Purchase integer monetary columns can conflict with fractional calculations] → Follow existing Purchase currency rounding rules and fail preview/conversion when immutable evidence cannot reconcile exactly.
- [Existing global payment crosses settings] → Reuse its supplier and eligibility locks while ensuring generated Purchase setting/source visibility and permission behavior remain explicit.
- [Attachments interact with filesystem state outside database rollback] → Stage and validate uploads before conversion, attach after durable document creation using the established recoverable media pattern, and clean failed staging safely.
- [No Phase 3 correction path] → Make preview explicit and generated commercial evidence immutable; defer audited credits, returns, and corrections to Phase 4.

## Migration Plan

1. Add source classification, billing audit metadata, one-to-one confirmation/Purchase protection, and allocation-to-Purchase-detail lineage with additive foreign keys and indexes.
2. Add source-aware Purchase model relationships and centralized incompatible-operation guards while preserving the ordinary default for all historical Purchases.
3. Add billing preview/conversion services, dedicated permissions, routes, UI, audit, and reconciliation presentation.
4. Add focused conversion, concurrency, tenant, monetary-lineage, inventory-inertness, receiving-guard, payment, and standard-Purchase regression tests.
5. Run only Phase 3 and directly affected Consignment allocation, Purchase receiving/payment, supplier balance, and reconciliation tests plus syntax, migration, diff, and strict OpenSpec validation.

Rollback first revokes billing permissions. Code rollback may disable new conversions, but generated Purchases, lineage, audit, and confirmation links must remain to preserve financial history. Schema rollback is permitted only when no Phase 3 billing records exist and must never delete or reinterpret billed evidence.

## Open Questions

- Confirm whether invoice attachments become immutable at conversion or may be appended later without changing commercial evidence; the conservative default is immutable conversion attachments.
- Confirm the canonical existing Purchase tax-inclusion rule for mixed allocation tax snapshots; the default is lossless detail-level tax with deterministic header reconciliation.
