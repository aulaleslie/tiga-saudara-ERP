# Design

## Context

See proposal.md for the motivating failure. `PosSettlementProjectionService::project()` requires every reachable Sale to be unarchived and in an eligible lifecycle status. `GlobalPosMultiPaymentService` repeats that restriction in preview and locked submission before the existing planner can skip zero balances. Reachability already includes archived Sales and must retain them for diagnostics and history.

POS return line-effect execution corrects Sale/dispatch quantities and recalculates financial headers. Return lifecycle synchronization and later completion/archival are separate steps. Global collection must use the committed current Sale state rather than gate on the return header's completion status.

## Goals / Non-Goals

**Goals:** Centralize the child-Sale decision across projection and payment processing, preserve canonical balances and mapping diagnostics, and allow collection from eligible siblings immediately after committed corrections.

**Non-Goals:** Change return processing, archive timing, standalone global Sales payment, owner priority, authorization, schema, historical checkout values, or historical payment reconciliation. No full-suite verification is planned.

## Decisions

### 1. Use a narrow exception for fully returned, financially cleared Sales

A reachable Sale is exempt from blocking sibling payment when its status is `Sale::STATUS_RETURNED`, its total rounds to zero at existing currency precision, and its canonical live due is zero. Archival does not disqualify this exception. Fully returned status represents the existing return quantity lifecycle; do not treat any arbitrary archived zero-balance Sale as returned or infer full return from a zero bill alone.

Ordinary eligible lifecycle statuses retain their existing rules. All other ineligible reachable Sales continue to block the POS transaction, including fully returned Sales with residual charges or debt. The transaction still requires valid mapping, completed/posted checkout, and positive aggregate live due.

Alternative: ignore every zero-due Sale. Rejected because that would broaden payment eligibility to unrelated rejected or archived documents. Alternative: require completed returns. Rejected because it delays collection after valid corrections are committed.

### 2. Share eligibility and preserve reachability

Introduce a small shared POS child-Sale eligibility policy/helper, or an equivalent reusable method, consumed by projection, preview, and locked submission. Supply canonical current balance values consistently, retaining projection's eager-loaded payment/credit fast path. Preserve reachable Sale resolution, archived loading, mapping validation, aggregate financial history, and existing owner payment priority. Exempt Sales receive no positive allocation, SalePayment, or payment allocation audit row.

Alternative: filter returned Sales out of the resolver. Rejected because it hides document history and mapping anomalies. Alternative: duplicate the exception in three loops. Rejected because preview and save could diverge.

### 3. Revalidate under existing locks

Retain transaction and all reachable Sale locks, including exempt Sales, and recompute eligibility and balances before planning/writing. A Sale that no longer qualifies for the exception must trigger existing atomic rejection behavior. Use current committed state without a new return-completion gate. Keep existing overpayment and submitted-preview consistency checks.

### 4. Verify only the affected behavior

Extend existing projection, multi-payment service, and authorization/controller or Livewire test patterns. Cover unarchived and archived returned-zero siblings; correction committed before return completion; zero allocation and audit absence for exempt Sales; all-zero transaction; positive-balance ineligible Sale; unrelated archived/rejected zero-value Sale; broken mapping; and a relevant stale-preview rejection with no partial writes. Run targeted classes or filters using the existing SQLite-compatible test setup, plus syntax/style checks for changed PHP where available. No full application suite is required.

## Risks / Trade-offs

- [Different canonical balance calculations between eager-loaded projection and locked submission] → Use existing active payment/credit semantics and test agreement across entry points.
- [Financial zero incorrectly accepted as full return] → Require the existing fully returned lifecycle status as well as zero total and live due.
- [Return or payment changes after preview] → Retain locks, fresh checks, existing overpayment validation, and atomic rollback.
- [Additional per-Sale database queries in the register] → Keep eager-loaded settlement data and avoid per-row return queries for the exception.

## Migration Plan

Deploy ordinary application changes without migrations or backfills. Existing qualifying transactions immediately use the revised rule. Rollback restores the prior payment restriction; payments already created remain ordinary ledger entries with existing audit links.
