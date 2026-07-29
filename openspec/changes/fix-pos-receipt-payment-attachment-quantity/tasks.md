## 1. Receipt amount normalization

- [ ] 1.1 Trace completed and draft/loaded POS receipt line-total sources and normalize snapshot minor-unit values to Rupiah once in the receipt data mapping.
- [ ] 1.2 Keep Sale-detail fallback totals and packed unit-breakdown conversion correct while ensuring the rendered product-row total matches checkout grand total.
- [ ] 1.3 Update receipt regression tests for a Rp45.000 row stored as `4500000` minor units, covering completed, draft/loaded, and packed/conversion receipts.

## 2. Staged multi-payment attachment lifecycle

- [ ] 2.1 Separate active-form attachment state from an attachment token belonging to a successfully committed payment stage.
- [ ] 2.2 Prevent stage-form reset and selection of Cash from deleting a committed non-cash stage attachment; retain explicit pre-stage removal and full-chain-reset cleanup.
- [ ] 2.3 Verify finalization maps the preserved non-cash attachment only to its originating Sale Payment and never to a Cash Sale Payment.
- [ ] 2.4 Add a staged-flow regression test for Transfer with an attachment followed by Cash, asserting successful finalization, correct attachment ownership, and no Cash attachment.

## 3. Raw POS quantity display

- [ ] 3.1 Replace POS receipt, transaction-detail, bundle, return, and item-sales-report quantity formatters with raw normalized quantity rendering.
- [ ] 3.2 Add focused rendering tests that assert integer quantity `1` remains `1` and a meaningful fractional quantity remains unpadded.

## 4. Verification

- [ ] 4.1 Run the focused POS receipt, staged-payment, attachment, transaction/return display, and report tests.
- [ ] 4.2 Run the relevant broader POS test suite or `composer test:fresh-sqlite` if the focused suite passes.
