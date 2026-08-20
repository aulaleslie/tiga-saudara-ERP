## Context

The product-bundle HPP change has 31/31 tasks checked and passes strict OpenSpec validation. Its final focused run reported one known failure, while earlier baseline comparisons identified three Sale serial-badge failures and one standard Sales Return archival failure. Independent reproduction established three distinct causes:

- `SaleShowSerialBadgeTest` creates only `sales.show`; rendering the Sale page evaluates `SalePolicy::overrideReportingDate()`, and Spatie throws because `sales.reporting-date.override` does not exist in that refreshed test database. Production permission configuration already declares it.
- `POSReturnCrossOwnerReplacementTest` uses `assertSame()` on two separately hydrated Carbon date objects. The replacement code copies the original date, but equal Carbon values are not the same object instance.
- `SaleReturnReceiveSerialStatusTest` reaches completed settlement and `RETURNED` status but not archival. Standard receiving restores stock and records effective return quantity without reducing `sale_details.quantity` or `dispatch_details.dispatched_quantity`; the archival helper requires both mutable quantities to be zero, a condition currently reachable only through POS correction paths.

The first two are test defects. The third is a lifecycle defect/undefined standard-return boundary relevant to safe bundle return enablement. A separate change keeps these corrections out of the completed HPP scope while producing a trustworthy release gate.

## Goals / Non-Goals

**Goals:**

- Make the known red tests evaluate their intended behavior.
- Define and implement full standard Sales Return archival from effective cumulative coverage.
- Preserve single inventory movement at receiving and idempotent settlement completion.
- Establish a focused, explainable bundle release gate spanning high-risk integrations.
- Allow the completed HPP OpenSpec to be wrapped independently without treating these pre-existing failures as HPP regressions.

**Non-Goals:**

- Redesign Sales Return settlement methods, POS Return approval, or bundle composition.
- Convert standard Sales Returns to POS-style destructive Sale-detail/dispatch correction.
- Fix unrelated warehouse, Livewire, legacy scaling, or broad POS suite failures.
- Require the complete Sale, POS, Reports, SalesReturn, or application test suite.
- Enable bundles in production automatically; operational rollout remains a separate decision after the gate passes.

## Decisions

### 1. Repair fixture completeness, not authorization behavior

`SaleShowSerialBadgeTest` will create the `sales.reporting-date.override` permission record for guard `web` but will not grant it to the test user unless a scenario needs the control. This lets the view policy safely return false while the user retains only `sales.show` authority.

Alternative considered: make `SalePolicy` silently return false when a configured permission row is missing. Rejected because production permission synchronization is an operational invariant and globally weakening policy behavior to accommodate one incomplete test fixture could conceal deployment errors.

### 2. Compare Sale dates by normalized value

The cross-owner test will compare `toDateString()` or raw persisted `Y-m-d` values. A separate scenario will use different execution and original dates to prove the generated replacement Sale/payment preserve the original calendar date.

Alternative considered: remove the date assertion. Rejected because date preservation affects reporting periods and should remain covered.

### 3. Archive standard fully returned Sales from effective coverage

Keep receiving as the only inventory mutation point. Extend `SaleReturnLifecycleSyncService` with one shared coverage calculation:

```text
effective returned quantity
= sale_return_details.quantity
   whose parent return is AWAITING SETTLEMENT or COMPLETED

full standard return
= source Sale is RETURNED
   and effective returned quantity >= current persisted dispatched quantity
```

The existing zero-active-Sale/zero-active-dispatch condition remains sufficient for POS-corrected Sales. The effective-coverage condition is the standard-return fallback when quantities intentionally remain historical. Partial coverage remains unarchived. Only completion/settlement calls archival, so merely receiving a full return may mark the Sale `RETURNED` but does not archive until settlement completes.

The comparison must be performed per physical dispatch detail/product lineage where possible, not only as one header sum, so an over-return on one line cannot hide an under-return on another. Legacy details without dispatch identity use a conservative grouped fallback and cannot cause archival when coverage is ambiguous.

Alternative considered: reduce standard Sale and dispatch quantities during receiving. Rejected because it would expand this change into commercial correction, affect historical reports, and risk double-applying the POS/HPP return rules.

Alternative considered: archive every Sale whose status is `RETURNED`. Rejected because imported or manually corrected legacy status may not prove effective received and settled coverage.

### 4. Keep archival and inventory effects separated

Archival writes only Sale lifecycle/audit fields. It does not update Product, ProductStock, serial status, transactions, Sale Return payments, Sale details, or dispatch details. Existing transaction and row locks remain the idempotency boundary. Focused tests assert transaction count and stock/serial values before and after settlement.

### 5. Define a focused bundle release manifest

Add a documented command/checklist or test-group manifest containing only the high-risk test files introduced or directly relied upon by bundle hardening:

- definition/lifecycle and captured pricing;
- Normal Sales and POS split persistence;
- dispatch, serial, and receipt identity;
- owner-aware HPP, reports, return reversal, and replacement HPP;
- standard and POS full/partial return lifecycle;
- idempotent finalization and replacement;
- additive migration up/down or fresh SQLite compatibility for the touched migrations.

Every failure gets an explicit classification. Confirmed flaky or unrelated failures may be recorded outside the gate but cannot be silently ignored within it.

## Risks / Trade-offs

- [Header-level quantity comparison could archive a multi-line Sale incorrectly] → Calculate coverage by dispatch lineage and require every dispatched line to be fully covered.
- [POS-corrected quantities and return-detail coverage represent the same return] → Retain the existing zero-active-quantity fast path and use coverage only as an alternative sufficiency condition, never add the two quantities together.
- [Completed legacy returns lack dispatch references] → Treat ambiguous lineage conservatively and leave the Sale unarchived with diagnostic evidence.
- [Permission fixture repair could accidentally grant extra access] → Create the permission record but do not assign it to the user; assert the intended serial output rather than override-date UI.
- [A focused gate can miss unrelated application defects] → Scope it explicitly to bundle release risk and keep broader suites as optional diagnostics, not release evidence.

## Migration Plan

1. No production schema migration is expected for this follow-up.
2. Repair the two test defects and prove their entire focused files pass.
3. Implement standard-return coverage and archival under existing settlement transaction/locks.
4. Run focused standard/POS full and partial return tests to guard against cross-path regressions and duplicate stock movement.
5. Run the documented bundle release manifest and record exact results.
6. Enable bundles only after this change is complete and the controlled rollout prerequisites are accepted.

Rollback reverts the lifecycle coverage fallback and test/manifest changes. No data migration is required; Sales archived by the new valid full-return rule remain auditable and should not be automatically unarchived during rollback.

## Open Questions

- None blocking implementation. Ambiguous legacy return lineage remains deliberately non-archiving and reportable rather than guessed.
