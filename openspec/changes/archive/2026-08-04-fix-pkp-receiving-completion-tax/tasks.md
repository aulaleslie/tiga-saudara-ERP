## 1. Completion monetary reconstruction

- [x] 1.1 Add a shared retained-line input builder in `PurchaseReceivingCompletionService` that scales persisted subtotal, tax amount, and pre-tax subtotal by approved received quantity while retaining price, discount, and tax identity.
- [x] 1.2 Replace the duplicate preview and completion line-input construction with the shared builder before invoking `PurchaseNormalizer`.
- [x] 1.3 Verify the completion transaction persists the normalized proportional line and header tax values without changing existing locking, overpayment, audit, or non-PKP behavior.

## 2. Regression coverage

- [x] 2.1 Extend receiving-completion service tests with a PKP tax-exclusive shortfall case asserting matching preview and persisted line/header tax and total values.
- [x] 2.2 Add cases for a tax-included PKP line, mixed taxed/untaxed retained and removed lines, and a non-PKP shortfall completion.
- [x] 2.3 Add a rounding-sensitive proportional-tax case and assert finalized line/header reconciliation.

## 3. Verification

- [x] 3.1 Run the focused Purchase receiving-completion test suite and resolve failures.
- [x] 3.2 Run relevant Purchase normalization/non-PKP tax tests to confirm existing tax-stripping behavior remains intact.
