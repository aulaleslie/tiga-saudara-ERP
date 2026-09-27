## Context

`FinalizePosCheckoutService` currently calculates the change amount for multi-payment checkouts by subtracting the grand total from the cash component only (`totalCash - grandTotal`). This logic fails when multiple payment methods are combined (e.g., MANDIRI 10M + CASH 3M for a 12M total). In this scenario, the total paid is 13M, resulting in 1M change, but the current logic calculates `3M - 12M = -9M` (clamped to 0), causing the system to record zero change. This error cascades into missing `EVENT_CHANGE_OUT` events and an inflated "Ekspektasi Kas" in the POS session entity.

## Goals / Non-Goals

**Goals:**
- Correct the `change_total` calculation and persistence for all payment types (single/multi-payment).
- Ensure `EVENT_CHANGE_OUT` events are accurately recorded for multi-payment checkouts to maintain cash drawer integrity.
- Aggregated display of multiple payment methods in the POS Session Summary table.

**Non-Goals:**
- Changing the receipt generation logic (already handles change display).
- Modifying the cash drawer opening trigger logic.

## Decisions

### 1. Corrected Change Calculation Logic
In `FinalizePosCheckoutService`, the `actualChangeTotal` will be derived from the total paid across all methods (`$paidTotal - $actualGrandTotal`). This value accurately represents the excess cash to be returned, provided the checkout is finalized with a cash component.

### 2. Payment Method Data Aggregation in Summary
`PosSessionSummaryService` will be updated to load `payments.paymentMethod` relationships for checkouts. Unique payment method names will be joined into a string (e.g., "MANDIRI, CASH") to provide full context in the session summary "Metode" column.

### 3. Automated Regression Testing
Extend `POSCheckoutMultiPaymentFinalizeTest.php` to include specific assertions for `change_total` and the presence of `EVENT_CHANGE_OUT` in mixed-payment overpayment scenarios.

## Risks / Trade-offs

- **[Risk]** Over-calculating change if non-cash methods are recorded with excess amounts.
- **[Mitigation]** The system enforces validations ensuring non-cash payments do not exceed the grand total, and cash is always the final (and thus over-payable) method.
