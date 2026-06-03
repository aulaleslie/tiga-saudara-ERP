## 1. Test Coverage

- [x] 1.1 Add focused purchase import tests proving a fully paid future import creates exactly one active `purchase_payments` row with cash method, purchase date, ERP purchase reference, and reconciled header balances.
- [x] 1.2 Add focused purchase import tests proving a partially paid future import creates exactly one active `purchase_payments` row, leaves positive due amount, and marks the purchase as partially paid.
- [x] 1.3 Add focused purchase import tests proving a fully unpaid future import creates no `purchase_payments` row and keeps paid amount at zero.
- [x] 1.4 Add focused sales import tests proving a fully paid future import creates exactly one active `sale_payments` row with cash method, sale date, ERP sale reference, and reconciled header balances.
- [x] 1.5 Add focused sales import tests proving a partially paid future import creates exactly one active `sale_payments` row, leaves positive due amount, and marks the sale as partially paid.
- [x] 1.6 Add focused sales import tests proving a fully unpaid future import creates no `sale_payments` row and keeps paid amount at zero.
- [x] 1.7 Add purchase and sales import tests proving `Pembayaran` is preferred, blank or missing `Pembayaran` falls back to calculated total minus preferred outstanding balance, and `Sisa Tagihan Hari Ini` is preferred over `Sisa Tagihan`.
- [x] 1.8 Add purchase and sales import tests proving conflicting repeated payment fields or non-reconciling payment totals invalidate the whole invoice group without creating documents or payment rows.
- [x] 1.9 Add purchase and sales import tests proving a missing cash payment method fails paid invoice groups but does not block unpaid invoice groups.

## 2. Shared Payment Resolution

- [x] 2.1 Add a small import payment summary resolver or equivalent duplicated helpers that parse money fields, resolve preferred outstanding balance, resolve paid amount, and validate reconciliation with a monetary tolerance.
- [x] 2.2 Extend purchase import row mapping to distinguish `Sisa Tagihan Hari Ini`, `Sisa Tagihan`, `Pembayaran`, and source `Total` values without breaking existing templates.
- [x] 2.3 Extend sales import row mapping to distinguish `Sisa Tagihan Hari Ini`, `Sisa Tagihan`, `Pembayaran`, and source `Total` values without breaking existing templates.
- [x] 2.4 Add cash payment method resolution that prefers `payment_methods.is_cash = true` and falls back to a case-insensitive `CASH` name only when needed.

## 3. Purchase Import Implementation

- [x] 3.1 Validate purchase invoice-group payment fields before creating the purchase document and mark every row in the group invalid on mismatch.
- [x] 3.2 Use the resolved purchase paid amount and preferred outstanding balance when setting purchase `paid_amount`, `due_amount`, and `payment_status`.
- [x] 3.3 Create one active `PurchasePayment` inside the existing purchase invoice-group transaction when resolved paid amount is greater than zero.
- [x] 3.4 Ensure duplicate-skipped purchase imports do not create or backfill payment rows.

## 4. Sales Import Implementation

- [x] 4.1 Validate sales invoice-group payment fields before creating the sale document and mark every row in the group invalid on mismatch.
- [x] 4.2 Use the resolved sale paid amount and preferred outstanding balance when setting sale `paid_amount`, `due_amount`, and `payment_status`.
- [x] 4.3 Create one active `SalePayment` inside the existing sales invoice-group transaction when resolved paid amount is greater than zero.
- [x] 4.4 Ensure duplicate-skipped sales imports do not create or backfill payment rows.

## 5. Verification

- [x] 5.1 Run the focused purchase import payment tests.
- [x] 5.2 Run the focused sales import payment tests.
- [x] 5.3 Run the existing focused purchase import and sales import ownership/price-sync tests to guard against regressions.
- [x] 5.4 Run `php artisan test` or `composer test:fresh-sqlite` when practical for broader confidence.
