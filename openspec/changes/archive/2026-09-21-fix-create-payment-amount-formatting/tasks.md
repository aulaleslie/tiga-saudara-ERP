# Tasks

## 1. Shared payment amount behavior

- [x] 1.1 Implement a reusable payment-amount input helper that keeps full canonical decimal state, renders Indonesian thousand separators with a two-decimal rounded display on blur, reveals the full raw number on focus, and visibly rejects invalid non-empty edits; verify focused JavaScript tests cover whole, fractional, arbitrary-precision, empty, invalid, and restored values. The field tracks focused/blurred representation state and retains the accepted canonical value separately only after successful parsing, so display rounding never changes calculations or submission and invalid edits never fall back to stale data. Covered end-to-end by `tests/js/payment-amount-input.test.cjs` using `tests/js/lib/mini-jquery.cjs`.
- [x] 1.2 Add the explicit payment-amount field marker and decimal input mode required by the helper, and verify unrelated monetary inputs are not automatically enhanced.

## 2. Single-document payment creation

- [x] 2.1 Replace the duplicated Sales payment `amount` formatter with the shared behavior while preserving canonical submission and existing customer-credit behavior; verify the focused Sales payment creation/submission tests pass.
- [x] 2.2 Replace the duplicated Purchase payment `amount` formatter with the shared behavior while preserving canonical submission; verify the focused Purchase payment creation/submission tests pass.

## 3. Global payment allocation creation

- [x] 3.1 Integrate the shared behavior into global Sales allocation inputs and calculate totals and submissions from canonical values; verify focused global Sales payment view and store tests pass for whole and decimal allocations.
- [x] 3.2 Integrate the shared behavior into global Purchase allocation inputs, preserving maximum clamping, all-page DataTables traversal, hidden-field synchronization, and canonical totals; verify focused global Purchase payment view and store tests pass, including a non-visible paginated row where practical.
- [x] 3.3 Integrate the shared behavior into global POS allocation inputs and use canonical values for totals, preview requests, validation, and submission; verify focused global POS payment authorization/view and service or controller tests pass for whole and decimal allocations.

## 4. Focused regression verification

- [x] 4.1 Ran `php artisan test --filter="SalePaymentDataTableAndDeletion|SalePaymentInvalidation|SalePaymentMaintenance|SalePaymentCreditLocking|GlobalSalePayment|PurchasePaymentDataTableAndDeletion|PurchasePaymentMaintenance|GlobalPurchasePayment|GlobalPosPayment"`: 272 passed, 9 failed. Confirmed via `git stash` that the same 9 failures (missing `purchases.due-date.override` permission seed; unrelated `GlobalPosPaymen...` migration `QueryException`s) exist identically on the pre-change tree — pre-existing environmental issues, not caused by this change. No full-suite run performed.
- [x] 4.2 `tests/js/payment-amount-input.test.cjs` exercises the real focus/blur/input handlers and DOM-visible state via `tests/js/lib/mini-jquery.cjs`; `node tests/js/payment-amount-input.test.cjs` passes 44/44 assertions. Coverage includes whole/fractional focus-blur round trips, arbitrary fractional precision (`1000.999`), two-decimal rounded display with full canonical restoration and submission, fractional values that round to zero cents (`1.005` -> `1,00` display while retaining `1.005`), invalid-text preservation, negative rejection, `validateScope()`, and full-precision `setCanonicalValue()` behavior. Live in-browser inspection of the five pages was not performed in this session.
