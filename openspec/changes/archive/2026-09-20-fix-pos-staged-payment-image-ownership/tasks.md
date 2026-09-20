# Tasks

## 1. Client image ownership

- [x] 1.1 Separate non-destructive active-form image clearing from destructive pending-image removal in `public/js/pos-staged-payment.js`, and verify a successful stage transition clears the form without issuing individual deletion for the committed token.
- [x] 1.2 Capture and detach the targeted pending token before asynchronous deletion, and verify completion of an older deletion cannot clear a newer replacement upload.
- [x] 1.3 Preserve existing Cash behavior for an uncommitted image while ensuring Cash or Utang/Kas Bon selected after a committed transfer does not inherit or delete the transfer token; verify the staged payloads contain only their originating image association.

## 2. Server ownership boundary

- [x] 2.1 Detect whether an individually requested image token is referenced by the scoped cart payment chain and return a stable `409 PAYMENT_IMAGE_ALREADY_COMMITTED` response without deleting the record or file; verify a focused feature test covers the conflict.
- [x] 2.2 Preserve deletion of uncommitted scoped uploads and complete-chain reset cleanup, and verify their existing focused feature cases still pass.

## 3. Focused regression verification

- [x] 3.1 Add a focused staged-flow feature test for partial Transfer with image followed by Utang/Kas Bon, verifying successful finalization, the expected outstanding debt, one attachment on the transfer Sale Payment, and no image inheritance by the debt portion.
- [x] 3.2 Add or extend a focused Transfer-with-image followed by Cash case, verifying successful finalization and attachment isolation by payment stage.
- [x] 3.3 Run only the relevant `POSCheckoutNoteAndPaymentImageTest` cases (including committed deletion, pending deletion/reset, Transfer-to-Kas-Bon, Transfer-to-Cash, and retry behavior) and record that they pass; do not run or require the full test suite for this change.
