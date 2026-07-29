## 1. Receipt amount normalization

- [x] 1.1 Trace completed and draft/loaded POS receipt line-total sources and normalize snapshot minor-unit values to Rupiah once in the receipt data mapping.
- [x] 1.2 Keep Sale-detail fallback totals and packed unit-breakdown conversion correct while ensuring the rendered product-row total matches checkout grand total.
- [x] 1.3 Update receipt regression tests for a Rp45.000 row stored as `4500000` minor units, covering completed, draft/loaded, and packed/conversion receipts.

## 2. Staged multi-payment attachment lifecycle

- [x] 2.1 Separate active-form attachment state from an attachment token belonging to a successfully committed payment stage.
- [x] 2.2 Prevent stage-form reset and selection of Cash from deleting a committed non-cash stage attachment; retain explicit pre-stage removal and full-chain-reset cleanup.
- [x] 2.3 Verify finalization maps the preserved non-cash attachment only to its originating Sale Payment and never to a Cash Sale Payment.
- [x] 2.4 Add a staged-flow regression test for Transfer with an attachment followed by Cash, asserting successful finalization, correct attachment ownership, and no Cash attachment.

## 3. Raw POS quantity display

- [x] 3.1 Replace POS receipt, transaction-detail, bundle, return, and item-sales-report quantity formatters with raw normalized quantity rendering.
- [x] 3.2 Add focused rendering tests that assert integer quantity `1` remains `1` and a meaningful fractional quantity remains unpadded.

## 4. Verification

- [x] 4.1 Run the focused POS receipt, staged-payment, attachment, transaction/return display, and report tests.
- [x] 4.2 Run the relevant broader POS test suite or `composer test:fresh-sqlite` if the focused suite passes.

## 5. Legacy packed receipt compatibility

- [x] 5.1 When receipt metadata has price_source = PACKED and no line_total_minor, treat legacy line_total as minor units and divide by 100 exactly once.
- [x] 5.2 Add end-to-end loaded packed-draft regression test with real product pricing, conversion, customer tier switching, and receipt verification.
- [x] 5.3 Correct unit-test comments/fixtures to clarify packed line_total = 3,520,000 represents minor units, not Rupiah.
