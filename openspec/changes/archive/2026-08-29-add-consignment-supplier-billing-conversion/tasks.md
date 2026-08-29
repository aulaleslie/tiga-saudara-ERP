## 1. Schema and source identity

- [x] 1.1 Add focused migration tests for ordinary Purchase defaults, unique confirmation-to-Purchase conversion, and restrictive allocation-to-Purchase-detail lineage.
- [x] 1.2 Add an explicit indexed Purchase source classification with an ordinary historical default and `CONSIGNMENT_BILLING` value.
- [x] 1.3 Add confirmation billing actor/time and supplier invoice snapshot fields needed beyond the existing `purchase_id` and readiness flag.
- [x] 1.4 Add a lineage table linking generated Purchase details to contributing non-serialized receipt allocations or serialized allocations with billed base quantity and immutable commercial snapshots.
- [x] 1.5 Add database uniqueness and foreign-key constraints that prevent duplicate confirmation conversion and duplicate allocation billing without deleting financial history on parent removal.
- [x] 1.6 Add Eloquent casts, constants, relationships, scopes, and source helpers for confirmation, Purchase, PurchaseDetail, and billing lineage models.

## 2. Billing preview and monetary construction

- [x] 2.1 Add focused service tests for invoice metadata validation, tenant/supplier boundaries, due-date/payment-term handling, and read-only preview behavior.
- [x] 2.2 Implement a billing preview service that loads only approved billing-ready confirmations and derives rows from immutable receipt and serialized allocation evidence.
- [x] 2.3 Build deterministic commercial grouping that preserves distinct receiving-detail cost and tax snapshots and maps every contributing allocation to one preview line.
- [x] 2.4 Reconcile line quantities against approved confirmation quantities and reject missing, duplicated, cross-supplier, cross-setting, product, location, or lineage evidence.
- [x] 2.5 Calculate Purchase detail and header subtotal, tax, total, paid, and due values using existing Purchase tax-inclusion and currency-rounding conventions without reading mutable current costs.
- [x] 2.6 Add preview blockers for monetary values that cannot be represented or reconciled exactly by the current Purchase schema.

## 3. Atomic confirmation-to-Purchase conversion

- [x] 3.1 Add focused lifecycle tests for successful conversion, repeated conversion, concurrent/stale invocation, later-line rollback, and sequence idempotency.
- [x] 3.2 Implement a conversion service that locks the confirmation and allocation evidence in deterministic order and revalidates approval, readiness, tenant, supplier, snapshots, quantities, and absence of a Purchase link.
- [x] 3.3 Allocate the Purchase reference transactionally and create a `CONSIGNMENT_BILLING` Purchase in the physically complete, unpaid state with canonical supplier invoice metadata.
- [x] 3.4 Create Purchase details and lossless lineage rows from the validated preview, including serialized provenance where applicable.
- [x] 3.5 Link the Purchase to the confirmation, clear further billing readiness, persist billing actor/time, and append an immutable full conversion audit payload in the same transaction.
- [x] 3.6 Stage, validate, attach, and safely clean supplier invoice attachments using the established media workflow without leaving orphaned files after failure.
- [x] 3.7 Assert conversion creates no Received Note and does not mutate stock buckets, ProductPrice averages or last costs, serials, receiving provenance, Sales/POS, dispatch, returns, or Phase 2 allocation quantities.

## 4. Purchase lifecycle and payment integration

- [x] 4.1 Add focused regression tests proving every Purchase receiving entry point rejects `CONSIGNMENT_BILLING` sources while ordinary Purchase receiving remains unchanged.
- [x] 4.2 Implement a centralized source-aware Purchase lifecycle guard for receiving, full commercial edit, detail mutation, deletion/archive, correction, and return entry points.
- [x] 4.3 Make generated Purchase commercial fields and details read-only while allowing authorized read surfaces, active payment creation/invalidation, and canonical balance reconciliation.
- [x] 4.4 Add focused individual and global Purchase payment tests proving partial/full settlement works for generated payables with tenant, supplier, due-amount, and locking safeguards.
- [x] 4.5 Add source labels and immutable-lineage presentation to Purchase detail and payment views without changing standard Purchase behavior.

## 5. Consignment billing UI, authorization, and reconciliation

- [x] 5.1 Add dedicated consignment billing access and conversion permissions, strict routes, navigation, and controller gates with no legacy permission fallbacks.
- [x] 5.2 Add a setting-scoped ready-for-billing list and conversion form with supplier invoice metadata, allocation-level preview, totals, blockers, confirmation action, and supported attachment capture.
- [x] 5.3 Add billed confirmation presentation linking to the generated Purchase and showing immutable invoice, conversion actor/time, lineage, audit, and settlement balances.
- [x] 5.4 Extend Consignment reconciliation with ready/billed filters and Purchase reference, supplier invoice, billed, paid, and live outstanding fields without duplicating allocation quantities.
- [x] 5.5 Add focused feature tests for permissions, foreign-setting denial, form validation, duplicate submission behavior, escaped output, and standard-only isolation.

## 6. Focused verification and release readiness

- [x] 6.1 Run Phase 3 billing conversion unit and feature tests, including non-serialized, serialized, mixed-cost/tax, rollback, and idempotency cases.
- [x] 6.2 Run directly affected Phase 2 confirmation/allocation tests and Phase 1 receiving provenance tests.
- [x] 6.3 Run directly affected Purchase reference, receiving guard, individual/global payment, balance reconciliation, supplier aging, and reporting tests.
- [x] 6.4 Run PHP syntax checks for touched files, migration up/down checks on fresh SQLite, `git diff --check`, and strict OpenSpec validation; resolve only issues introduced by this change.
