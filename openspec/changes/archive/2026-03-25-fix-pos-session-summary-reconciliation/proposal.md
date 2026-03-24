## Why

POS session reconciliation is currently inaccurate for multi-payment checkouts because the change amount is incorrectly calculated as zero when multiple payment methods are combined. This leads to inflated "Expected Cash" (Ekspektasi Kas) totals and missing "CHANGE OUT" events in the session timeline, making it difficult for cashiers and managers to reconcile the drawer. Additionally, the session summary table only displays the first payment method, obscuring the full transaction context and complicating audit trails.

## What Changes

- **Correct `change_total` calculation**: Update `FinalizePosCheckoutService` to calculate change based on the total paid amount across all payment methods, not just the cash component.
- **Accurate cash event recording**: Ensure `EVENT_CHANGE_OUT` cash events are correctly recorded for all checkouts where change is given, specifically for multi-payment scenarios.
- **Enhanced session summary**: Update `PosSessionSummaryService` to aggregate and display all payment methods used in a checkout (e.g., "MANDIRI, CASH") instead of only the first one.
- **Expected cash synchronization**: Ensure the expected cash total in the POS session entity remains synchronized with the physically present cash by correctly deducting the change given.

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `pos-checkout-finalize-integration`: Requirement for `change_total` calculation and cash event emission must correctly account for multi-payment contexts.

## Impact

- **Affected Code**: `FinalizePosCheckoutService.php`, `PosSessionSummaryService.php`, and `PosSessionExpectedCashCalculator.php`.
- **Affected UI**: POS Session Summary page (`/pos/sessions/{id}/summary`), specifically the "Metode" column and the "Timeline Kas" history.
- **Affected Systems**: POS Cash Reconciliation and Audit Reporting.
