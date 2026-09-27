## Why

Multi-payment POS checkouts don't display change properly, have overly strict EDC reference validation, and fail to store multiple payment methods per sale — only the first payment method is recorded. This breaks reporting and cash reconciliation when customers pay with mixed tender (e.g., 40K non-cash + 50K cash).

## What Changes

- **Gratitude modal** wording updated to clearly show total change amount
- **EDC reference validation** simplified: only "not empty" check (removes strict alphanumeric format requirement)
- **Multiple SalePayment records** created per payment method per sale instead of collapsing all payments into a single record
- **Cash priority allocation** fixed to allocate cash to non-POS-setting products first, then overflow to terminal setting (correct implementation of ownership-priority logic)

## Capabilities

### New Capabilities

- `multi-payment-split-allocation`: POS checkout allocates multiple payment methods proportionally and by ownership priority to split sales. Each sale gets one `SalePayment` record per payment method used, enabling accurate cash reconciliation and payment method reporting.

### Modified Capabilities

- `pos-checkout-ui`: Gratitude modal now displays clear change message
- `edc-reference-validation`: Only enforces "not empty" validation, removes format restrictions

## Impact

**Code Files:**
- `public/js/pos-staged-payment.js` — gratitude modal text, EDC validation
- `Modules/Pos/Services/PosCheckoutOwnershipPriorityAllocationService.php` — payment allocation direction
- `Modules/Pos/Services/Adapters/SplitPosCheckoutPostingAdapter.php` — per-group payment slicing
- `Modules/Pos/Services/Adapters/InlinePosCheckoutPostingAdapter.php` — multi-SalePayment creation

**Database:**
- `SalePayment` table — will now have multiple records per sale when multi-payment checkout occurs

**Tests:**
- `Modules/Pos/Tests/Feature/POSCheckoutMultiPaymentFinalizeTest.php` — existing multi-payment tests should verify 2+ SalePayment records
- New tests for cash-priority allocation across split settings
