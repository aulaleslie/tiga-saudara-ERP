## 1. Eligibility service edits

- [x] 1.1 In `Modules/Product/Services/SerialConversionEligibilityService.php`, remove `PurchaseReturn::STATUS_DRAFT` from the `$activeReturnStatuses` array (~line 171).
- [x] 1.2 Remove `Transfer::STATUS_DRAFT` from the `$activeTransferStatuses` array (~line 206).
- [x] 1.3 Remove `Sale::STATUS_DRAFTED` from the active-sales `whereIn` (~line 264).
- [x] 1.4 Remove `'draft'` and `'DRAFT'` from the Adjustment `whereIn(['pending', 'PENDING', 'draft', 'DRAFT'])` (~line 291).
- [x] 1.5 Remove `'Draft'` and `'DRAFT'` from the SaleReturn header `status` `whereIn` (~line 316), leaving `approval_status` and `settlementItems` sub-query conditions untouched.

## 2. Test updates (focused, not full suite)

- [x] 2.1 Locate existing test cases asserting a DRAFT document (PurchaseReturn, Transfer, Sale, Adjustment, SaleReturn) blocks conversion; update each to assert the product IS eligible when only a draft document exists.
- [x] 2.2 Add/confirm one regression case per document type asserting a non-draft active status (e.g. `PENDING_APPROVAL` for PurchaseReturn, `WAITING_APPROVAL` for Sale, `PENDING` for Adjustment/Transfer, `AWAITING_SETTLEMENT` for SaleReturn) still blocks conversion.
- [x] 2.3 Add a case confirming a SaleReturn with a settlement item in DRAFT status still blocks conversion (header status active/non-draft, but settlement item draft).
- [x] 2.4 Run focused verification only: `php artisan test --filter=SerialConversion` (or the specific test file names covering eligibility). Do not run the full test suite for this change.

## 3. Spec sync check

- [x] 3.1 Confirm the updated spec delta in `specs/existing-stock-serialization-conversion/spec.md` accurately reflects the final implemented behavior before archiving.
