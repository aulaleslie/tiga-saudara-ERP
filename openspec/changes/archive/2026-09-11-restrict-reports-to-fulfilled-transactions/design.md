## Context

Report query services currently encode lifecycle rules independently. Primary lists accept user-selected lifecycle statuses, product reports union origin details with return details, receivable/payable reports use their own status sets, and the tax report includes approved-or-later documents. Return settlement is treated by this change as modifying the selected sale or purchase header and details; those persisted values are therefore the reporting source of truth. The reports already share snapshot-based export patterns and setting scoping, but not a shared eligibility implementation.

## Goals / Non-Goals

**Goals:**

- Apply one reusable fulfilled-transaction eligibility definition across sales- and purchase-derived reports.
- Prevent recognition of unfulfilled and partially fulfilled documents.
- Preserve remaining value for partially returned documents and exclude completed fully returned documents.
- Read current persisted origin/target values without subtracting return records again.
- Keep UI, totals, local/global scope, and exports query-equivalent.

**Non-Goals:**

- Hardening or redesigning sales-return or purchase-return settlement mutation.
- Creating historical value snapshots; past-period reports intentionally reflect current modified origin values.
- Changing delivery-event reports, order-completion reports, return workflow screens, permissions, or database schema.
- Running the entire application test suite as part of this focused report change.

## Decisions

### Centralize eligibility as query scopes/concerns

Introduce reusable report query helpers for qualified sales and purchases rather than duplicating status arrays. A qualified sale has status `DISPATCHED` or `RETURNED PARTIALLY`. A qualified purchase has status `RECEIVED` or `RETURNED PARTIALLY`. A temporarily `RETURNED` document may remain reportable while its full-return settlement is unfinished; completed fully returned sources are excluded through the established archive/completion signal.

Alternative considered: filter only by current status. This was rejected because return receipt can change a sale to `RETURNED` before settlement approval and archival, producing premature disappearance.

### Persisted origin/target rows are authoritative

Header reports read current sale/purchase monetary fields and detail/product/tax reports read current detail fields. Product reports stop unioning and subtracting return-detail aggregates. Receivable/payable reports retain active-payment-ledger calculations while applying the shared document eligibility gate.

Alternative considered: continue calculating net values from origins minus returns. This was rejected because settlement already modifies the selected document and separate subtraction can double count.

### Preserve report-specific date semantics

Eligibility changes do not alter effective reporting dates, as-of payment cutoffs, delivery-event dates, or return-report dates. Because origin rows are mutable by contract, historical-period results intentionally reflect their current modified values.

### Apply eligibility at the base query

Each affected report applies eligibility before grouping, pagination, totals, and export mapping. Snapshot services continue to capture applied filters; validators and UI status options accept only report-eligible statuses. This prevents screen/export drift and avoids post-query filtering.

### Keep report exemptions explicit

Sales Delivery and Purchase Delivery continue to use approved dispatch/receiving notes. Sales Order Completion and Purchase Order Completion continue to show their intended pre-fulfillment stages. Dedicated return reports remain governed by return lifecycle rules.

## Risks / Trade-offs

- [Risk] Existing historical data may use `RETURNED` without a reliable completion/archive marker. → Mitigation: cover known lifecycle combinations with focused tests and use the established archival/completion relation rather than guessing from return amount.
- [Risk] A settlement path may not honor the assumed complete header/detail mutation contract. → Mitigation: document the assumption, add report tests against already-mutated fixtures, and leave mutation hardening to a separate change.
- [Risk] Removing return unions changes by-product return columns. → Mitigation: redefine these reports around net persisted origin values and update labels/columns only where necessary to avoid presenting a second deduction.
- [Risk] Status filtering changes saved filter state. → Mitigation: normalize stale/ineligible values through existing validators and require users to reapply filters before export.
- [Trade-off] Past reports change after later returns. → Accepted as the requested current-authoritative-value behavior.

## Migration Plan

No data migration is required. Deploy query/helper, validator/UI, and export changes together. Rollback restores the previous report queries and status options; no persisted data needs reversal.

## Open Questions

None blocking. Complete sale/purchase mutation at settlement approval is an explicit assumption for this change and will be revisited in separate hardening work.
