# Design

## Context

See proposal.md for the motivation. This Laravel 10 modular ERP already has GlobalPosMultiPaymentService, canonical generated-Sale resolution and live settlement, ownership-priority allocation, and a current monetary receipt projection. Global collection currently has one payment method per batch and no receiving session; the requested mixed-method cashier flow therefore needs orchestration beyond a button invoking that service repeatedly.

PosSessionExpectedCashCalculator locks the session and derives expected cash exclusively from directional PosSessionCashEvent rows. PosSafeDropService uses expected cash for pickup limits. PosSessionSummaryService and PosReconciliationService currently aggregate checkout-oriented activity and need collection-specific presentation. Existing specs contain older summary formulas involving non-cash; collection must follow the later physical-cash ledger rules and must not add non-cash to drawer cash. Terminal-less sessions are already supported for authorized users, not only super admins.

## Goals / Non-Goals

**Goals:** Reuse canonical Sales settlement, expose current bill facts in cashier terms, and make payment, audit, and receiving-session accounting consistent under retries and concurrency.

**Non-Goals:** Multi-transaction payment, price editing in collection, a parallel receivables ledger, changes to global payment access, historical backfill, and payment invalidation/refund implementation. Verification is focused; full-suite testing is neither a task nor a release gate for this change.

## Decisions

### 1. Dedicated session-bound entry point and permission

Introduce `pos.kasBon.collect` using existing permission registration, matrix, and role-bundle patterns; include it in cashier and manager bundles. Require POS access and this permission on every discovery, review, and submit endpoint. Resolve the authenticated actor's current OPEN session in the active setting server-side; verify session ownership and optional terminal consistency. Never infer a receiving session from the original transaction or accept arbitrary client session IDs. Existing Super Admin conventions still apply without bypassing the open-session requirement.

Alternative: reuse global create permission. Rejected because counter collection should be independently grantable without global cross-business payment authority.

### 2. Reuse projection and eligibility within a business-scoped Kas Bon query

Query completed transactions with posted checkout, original authoritative debt intent (checkout metadata), and positive canonical live due. Scope the POS header to active business while resolving all mapped child Sales, including owner businesses, through existing diagnostics and return-aware eligibility. Do not filter individual child Sales to the active business. Reuse current settlement projection rather than checkout paid snapshots. Paginate server-side, with eager/batched resolution. Search visible text fields and monetary fields using normalized displayed and canonical representations; amounts remain numeric for sorting and totals.

Review uses proven current Sale-to-POS-line mapping, including current quantities, prices, totals, and adjustments. Reuse PosReceiptReprintProjectionService where suitable, extracting shared read-only projection logic if necessary. Preserve original checkout total separately. Return a review fingerprint for current bill, child mappings, settlement, and eligibility; block ambiguous mappings with an actionable explanation instead of historical-price substitution.

Alternative: reuse global register unfiltered. Rejected because it includes ordinary POS, paid transactions, and other businesses. Reusing original snapshots alone would collect the wrong bill after modifications.

### 3. Single-transaction orchestration with ordinary Sale settlement

Create a session-aware collection service that accepts one POS ID, review fingerprint, amount to pay, ordered method entries (amount, optional description/note, optional reference, optional evidence), and idempotency key. In the payment modal, the payment amount is derived from the sum of amounts entered across payment methods (Jumlah) rather than a separate target input, with real-time display of Total Pembayaran and Sisa Hutang Setelah Pembayaran. In the Keterangan column, every method row accepts optional free text without monetary formatting or tender calculations (e.g. accepting "Cicilan kedua" for Cash). For Transfer and non-cash methods, a separate Nomor Referensi text input is provided, required according to payment method rules, while preserving evidence upload. Keterangan and transfer reference are saved separately without substitution. Uang Tunai Diterima and Kembalian Tunai are removed from this flow; entered cash Jumlah is treated as cash received and applied, with zero change. Adding or removing payment methods preserves all entered amounts, descriptions, references, and evidence across existing rows. Use existing checkout input formatting and payment/evidence rules, while isolating collection state from cart and checkout payment-chain state so opening/cancelling collection preserves an in-progress cart. No new checkout, stock posting, dispatch, or Kas Bon debt is created.

Refactor reusable allocation/payment primitives from global collection if appropriate; do not relax its authorization or add implicit session cash to its existing route. Allocate each payment method in displayed order against remaining locked child balances using established ownership/method priority, accounting for allocations already planned in the same collection. Settle only amount to pay with zero change.

Persist a collection header (transaction, receiving setting/session, nullable terminal, actor, reference, server timestamp, idempotency key and payload hash, amount, cash tender/change totals, review and receipt snapshots), method rows (method, settlement amount, tender, reference, evidence context, notes), and allocation links (method row, Sale, SalePayment, amount). These are audit records, not a new financial balance ledger. Ordinary active SalePayment records remain authoritative for debt. Collection time is server time; backdating is not offered.

Alternative: call global payment submission once per method without a shared transaction. Rejected because a later method failure could leave earlier payment and cash posted.

### 4. Atomic accounting, deterministic locking, and durable retries

Lock the receiving session first, then transaction/checkout and child Sales in deterministic order consistent with existing checkout/session workflows; inspect other settlement locks during implementation to avoid inversion. Recompute fingerprint and eligibility under locks. In one database transaction, create collection/method/allocation audit records, ordinary Sale payments and media links, reconcile affected Sales, append cash events, and refresh expected cash. Attachment failure rolls back financial records and cleans any copied media using established cleanup patterns.

Enforce unique idempotency identity in the database, bound to actor, receiving context, and request hash. A retry of committed identical input returns its stored result even if the session has since closed; a changed payload is rejected. Check replay before requiring a fresh OPEN session for a new write. Rollback leaves no cash, Sale payment, or partial collection. Concurrent requests and session-close races are verified with focused cases.

### 5. Physical cash entries and separate session reporting

Add a distinct `KAS_BON_COLLECTION_IN` event for actual cash tender and use a collection-referenced change outflow exactly once. Both reference the collection header, never each child Sale. Non-cash is recorded in collection methods without a cash event. Existing directional cash calculation then feeds expected balance, threshold and pickup calculations. Update session summaries, closing/reconciliation presentations and applicable collection-inclusive totals to expose net cash and non-cash collections separately from checkout sales. Preserve original checkout sales/payment comparisons; report collection audit-to-Sale-payment consistency separately so collections do not create misleading new-sale mismatches.

Alternative: reuse CASH_SALE_IN. Rejected because it would classify debt collection as a new cash sale. Altering checkout snapshots would also corrupt original history.

### 6. Collection receipt and invalidation boundary

Render a dedicated receipt from immutable collection snapshots: collection/original POS references, customer, actor/session, time, method amounts, tender/change, amount applied and remaining debt immediately after collection. Initial printing uses collection authorization; later reprint also follows existing receipt-reprint permission conventions and print audit patterns. Reprinting never triggers settlement or changes receipt values after later bill edits.

Sale payment invalidation stays in Sales. Canonical debt can reopen, while immutable collection facts and cash events remain. No observer may silently reverse collection cash on SalePayment invalidation. This is equally true for open and closed receiving sessions; money correction requires its own separate workflow.

## Risks / Trade-offs

- [Current line mapping can be ambiguous] → Fail review clearly; preserve access to original historical receipt and avoid invented current item values.
- [Mixed methods and split owners can duplicate money] → Single atomic orchestration, per-method allocation audit, and one collection-level cash movement with focused invariants.
- [Session close can race with collection] → Lock/revalidate the same session and reject new collection once closing starts.
- [Sale invalidation reopens collectible debt while cash remains] → Preserve physical cash history and expose ordinary payment status; reversal/refund design remains separate.
- [Search over computed current amounts is expensive] → Reuse the existing optimized global projection/query patterns, paginate, and inspect focused query behavior rather than running a full benchmark project.
- [Non-terminal summary paths currently use transaction activity] → Explicitly add receiving-session collections for both terminal and terminal-less views.

## Migration Plan

Add collection header, method, allocation tables and uniqueness/indexes via Modules/Pos migrations compatible with production MySQL/MariaDB and focused SQLite tests. Register permission and role mappings through existing mechanisms. No old collections or cash events are backfilled. Deploy schema before enabling routes/UI. Rollback hides/disables the entry point and preserves committed collection audit and cash records; do not drop populated history tables as an automatic rollback step.

Focused verification covers new service/controller/Livewire interactions, permissions, query scope/search, current-bill projection, split and mixed payments, tender/change, retries/rollback, stale review/session state, pickup/close/reconciliation, terminal-less sessions, receipt stability, and invalidation preserving cash. Run targeted PHPUnit filters and selected neighboring global-payment/session tests only.
