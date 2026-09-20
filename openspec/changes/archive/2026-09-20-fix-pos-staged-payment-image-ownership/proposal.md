# Proposal

## Why

After a cashier commits a partial non-cash payment with image evidence, the staged-payment UI deletes that image while resetting the active form for the next stage. Finalizing the remainder as Utang/Kas Bon then fails because the committed payment chain still references the deleted token, forcing cashiers to proceed without evidence despite the existing image-lifecycle requirement.

## What Changes

- Separate clearing active-form image state from deleting a pending temporary upload.
- Transfer image ownership from the active form to the committed payment chain immediately after a payment stage succeeds, including before a later Cash or Utang/Kas Bon transition.
- Reject individual deletion of an image token already referenced by a committed payment stage while retaining explicit removal of uncommitted uploads and complete-chain reset cleanup.
- Make asynchronous pending-image removal safe against clearing or deleting a newer upload.
- Add focused regression coverage for Transfer-with-image followed by Utang/Kas Bon, committed-token deletion protection, pending-image removal, and existing chain-reset behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-non-cash-payment-image`: Clarify that committed image ownership also covers Utang/Kas Bon finalization, individual deletion must reject chain-owned tokens, and asynchronous pending-image cleanup must not affect a newer upload.

## Impact

- Affects the staged checkout image state machine in `public/js/pos-staged-payment.js`.
- Affects the scoped payment-image deletion boundary in `Modules/Pos/Http/Controllers/PosPaymentImageController.php` and potentially its request/service helpers.
- Extends focused POS payment-image feature tests; no database migration or dependency change is required.
- The individual payment-image deletion API gains a conflict response when the requested token is already owned by the committed payment chain.
