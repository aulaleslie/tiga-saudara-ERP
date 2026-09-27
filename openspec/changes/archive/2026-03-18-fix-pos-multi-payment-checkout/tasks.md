## 1. UI & Validation Fixes (Frontend)

- [x] 1.1 Update gratitude modal text: change `"Kembalian: ..."` to `"Total Kembalian ..."` in `pos-staged-payment.js` line 642
- [x] 1.2 Remove EDC reference regex validation block from `validateBeforeSubmit()` (lines 517–522 in `pos-staged-payment.js`)
- [x] 1.3 Simplify `validateEdcReferenceRealtime()` to only check "not empty" instead of calling API (lines 241–264 in `pos-staged-payment.js`)
- [x] 1.4 Verify frontend validation changes in browser: test EDC field accepts spaces/special chars, rejects only empty (Fixed bug: EDC reference input now properly triggers button validation)

## 2. Payment Allocation Direction Fix (Service)

- [x] 2.1 In `PosCheckoutOwnershipPriorityAllocationService`, refactor `allocateToTerminalOwned()` → `allocateToGroups(array $groupKeys, ...)` to be reusable for both terminal-owned and non-terminal-owned allocations
- [x] 2.2 Swap cash allocation logic: cash now goes to non-terminal-owned groups first (line ~79), then proportional overflow
- [x] 2.3 Verify non-cash allocation unchanged: non-cash goes to terminal-owned first, then proportional overflow
- [x] 2.4 Add unit test: multi-setting scenario where Setting B (non-POS) gets cash first, Setting A (POS) gets non-cash (Updated existing tests in POSCheckoutOwnershipPriorityAllocationTest)

## 3. Multi-Payment Slicing in Split Adapter

- [x] 3.1 Create helper method in `SplitPosCheckoutPostingAdapter::slicePaymentsPerGroup()` that takes the full allocation matrix from `allocateMultiPayment()` and returns `array<split_key, array<payment_entries>>`
- [x] 3.2 In `SplitPosCheckoutPostingAdapter::post()` loop (around line 72–103), before calling `inlinePostingAdapter->post()`, inject the per-group payment slices into `$groupContext['payment']['payments']` (only when `is_multi_payment` is true)
- [x] 3.3 Preserve required fields in `$groupContext['payment']`: `is_multi_payment`, `amount_paid`, `total_cash_minor_units`, `payment_method_id` (from first slice), `reference` (from first slice), `is_cash` (from first slice)
- [x] 3.4 Add validation: skip creating SalePayment for any payment entry with amount ≤ 0

## 4. Multiple SalePayment Creation in Inline Adapter

- [x] 4.1 In `InlinePosCheckoutPostingAdapter::post()`, locate the SalePayment creation block (lines 364–372)
- [x] 4.2 When `is_multi_payment` is true, loop over `$payment['payments']` and create one `SalePayment` per entry:
  ```
  - amount: payment_entry['amount_minor_units'] / 100
  - payment_method_id: payment_entry['payment_method_id']
  - note: payment_entry['reference'] ?? null
  - payment_method: payment method name
  ```
- [x] 4.3 For single-payment checkouts, keep existing logic unchanged (one SalePayment per sale)
- [x] 4.4 Return last created `$salePayment->id` in the response for backward compatibility with caller code

## 5. Testing & Verification

- [ ] 5.1 Run existing test: `php artisan test --filter=POSCheckoutMultiPaymentFinalizeTest` — verify all tests pass, especially the 2-SalePayment assertions
- [ ] 5.2 Manual test single setting, mixed payment: 60K total, Non-Cash 40K + Cash 50K → verify 2 SalePayment records (40K + 20K), change = 30K
- [ ] 5.3 Manual test multi-setting, mixed payment: 100K Setting A + 100K Setting B, Non-Cash 150K + Cash 50K → verify Setting B has 2 SalePayment records (50K cash + 50K non-cash), Setting A has 1 (100K non-cash)
- [ ] 5.4 Manual test EDC validation: enter reference with spaces, dashes, numbers → verify accepts without format error
- [ ] 5.5 Manual test gratitude modal: complete a mixed-payment checkout → verify modal shows "Total Kembalian Rp X.XXX"
- [ ] 5.6 Run full POS test suite: `php artisan test Modules/Pos/Tests/Feature/` to ensure no regressions in other areas
- [ ] 5.7 Query database post-checkout: verify SalePayment count, amounts, and payment_method_id values match expectations

## 6. Code Review & Cleanup

- [x] 6.1 Code review: check for any remaining references to "proportional" cash allocation in comments (should be "non-terminal-owned first")
- [x] 6.2 Remove any old fallback/commented-out allocation logic (removed old allocateMultiPayment method from split adapter)
- [x] 6.3 Update class docblocks in allocation service to document cash-priority direction
- [x] 6.4 Verify no debug logging left in payment slicing code
