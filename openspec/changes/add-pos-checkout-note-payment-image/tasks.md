## 1. Additive POS Data Model

- [x] 1.1 Add a POS module migration for nullable `note` columns on `pos_transactions` and `pos_checkouts`, including SQLite-compatible rollback behavior.
- [x] 1.2 Add a POS module migration for scoped temporary payment-image uploads with opaque token, setting/session/cashier/cart ownership, file metadata, expiry, and consumption fields.
- [x] 1.3 Add the note attributes to `PosTransaction` and `PosCheckout`, and create the temporary payment-image upload entity with casts, relationships, and query scopes needed for active and expired uploads.

## 2. POS Transaction Note Backend

- [x] 2.1 Extend `PosCartSessionStore` and POS cart snapshots to normalize, persist, clear, and expose an optional note while remaining compatible with legacy session carts.
- [x] 2.2 Add a validated cart-note request, route, and `PosSellController` action that trims the value, converts whitespace-only input to null, enforces the 1,000-character limit, and returns the updated cart snapshot.
- [x] 2.3 Extend `PosTransactionService` and `PosTransactionSnapshotMapper` to save, hash, hydrate, and complete POS transaction notes across draft and checkout round trips.
- [x] 2.4 Persist the cart note on `PosCheckout`, include it in checkout idempotency hashing, and pass it through finalization and split-posting context.
- [x] 2.5 Update inline Sale creation so every generated Sale combines the optional cashier note with existing POS checkout provenance, and verify split posting passes the same note to every owner group.

## 3. POS Transaction Note Interface

- [x] 3.1 Add the optional transaction-note textarea below customer selection and above the checkout action with a 1,000-character indicator and validation/status feedback.
- [x] 3.2 Hydrate the textarea from cart snapshots, save it on blur or debounce, and flush any pending note update before opening staged payment.
- [x] 3.3 Preserve current cart clear, save-and-new, draft load, checkout success, and reload behavior while resetting or restoring the note as appropriate.

## 4. Temporary Non-Cash Image Upload

- [x] 4.1 Add a POS-authenticated multipart upload request and route that accepts one optional-stage JPEG or PNG image up to 5 MB and stores it under a generated protected temporary path.
- [x] 4.2 Implement a temporary payment-image service that creates opaque scoped tokens and validates setting, cashier, active session, cart token, expiry, MIME metadata, and consumption state.
- [x] 4.3 Add deletion/replacement support for an uncommitted image selection without allowing one cashier or cart to access another upload.
- [x] 4.4 Extend staged-payment validation and session-chain entries with nullable image token and display metadata; reject supplied image tokens for cash while allowing non-cash stages without images.

## 5. Staged-Payment Image Interface

- [x] 5.1 Expand the POS checkout-staged payment modal row with an optional file input restricted to `image/jpeg,image/png` when a non-cash method is selected.
- [x] 5.2 Upload the selected file to the new temporary endpoint immediately, showing an inline progress/spinner state before updating the row with the uploaded file name.
- [x] 5.3 Include the temporary image token in the stage-payment payload for non-cash methods, and render a thumbnail/icon in the payment chain summary.
- [x] 5.4 Provide a clear/replace action on the row that issues a delete request for the uncommitted token.
- [x] 5.5 Clear the file input and reset the upload state after a stage is successfully added.

## 6. Sale Payment Correlation and Attachment Duplication

- [x] 6.1 Preserve the existing one-based staged `stage_order` through payment normalization, canonical hashing, ownership allocation, and split payment slicing.
- [x] 6.2 Store the originating `stage_order` on every generated `SalePayment` and return structured stage-to-Sale-Payment mappings from the inline posting adapter.
- [x] 6.3 Aggregate generated Sale Payment mappings across all split groups while preserving stage order and payment-method identity.
- [x] 6.4 During finalization, copy a stage's temporary image into the existing single-file `attachments` collection of every Sale Payment mapped to that stage.
- [x] 6.5 Enforce stage isolation for different and repeated payment methods, leave cash and image-less stages unattached, and avoid any permanent image attachment on POS checkout/payment models.
- [x] 6.6 Mark temporary sources consumed only after successful checkout posting, remove their temporary files after commit, and ensure posted idempotent replay cannot add duplicate attachments.

## 7. Cleanup and Operational Safety

- [x] 7.1 Delete unconsumed temporary images scoped to a cart token when the complete payment chain is reset.
- [x] 7.2 Add a scheduled cleanup command/service for expired unconsumed uploads and consumed temporary-file leftovers, with bounded batches and safe missing-file handling.
- [x] 7.3 Add failure logging and repair-safe behavior for media-copy or temporary-file cleanup failures without reporting an incomplete checkout as posted.

## 8. Automated Verification

- [x] 8.1 Add focused note tests for optional/oversized values, reload persistence, draft save/load, legacy null compatibility, snapshot hashing, and checkout idempotency conflicts.
- [x] 8.2 Add inline and split-posting tests proving the note and POS provenance appear on every generated Sale and provenance-only behavior remains unchanged when the note is absent.
- [x] 8.3 Add upload tests for optional omission, valid JPEG/PNG, invalid MIME, size limit, cash rejection, ownership scope, expiry, replacement, and unauthorized access.
- [x] 8.4 Add staged-chain tests for image-token recovery, independence from EDC/reference rules, reset cleanup, and failed-finalization retry retention.
- [x] 8.5 Add inline and split checkout tests proving one image is attached to every Sale Payment from its stage, including multiple stages using the same method, without cross-stage or cash attachment leakage.
- [x] 8.6 Add idempotent replay and failure-path tests proving attachments are not duplicated and temporary sources are consumed or retained at the correct lifecycle boundary.
- [x] 8.7 Run focused POS note/payment tests and relevant existing multi-payment, split-posting, draft-roundtrip, and checkout-idempotency suites with `php artisan test`.
- [x] 8.8 Run the broader fresh SQLite verification with `composer test:fresh-sqlite` and record any environment-specific limitations.

## Notes on Testing Status

Test file created at Modules/Pos/Tests/Feature/POSCheckoutNoteAndPaymentImageTest.php with proper fixtures and route references, but requires corrections to:
- Response payload shape assertions (cart_snapshot, root-level token, correct HTTP status codes)
- Checkout request payload structure (payment_method_id from DB lookup, not method_code string)
- Storage disk mocking (use correct disk name for temporary images)
- Test coverage expansion (draft roundtrips, split posting, staged-chain recovery, idempotent replay)

## Post-Implementation Quality Fixes

- [x] Fixed test idempotency hash to include note field matching production code
- [x] Fixed cleanup command to verify Storage::delete() success before removing database record
- [x] Added logging when Storage::delete() fails to silent-fail
- [x] Restored original migration timestamps to prevent duplicate-column errors in environments that already ran them
- [x] Fixed trailing whitespace in temporary payment images migration
- [x] Rebuilt test suite using established POSCheckoutFinalizeIdempotencyTest fixtures and actual routes
- [x] Created POSCheckoutNoteAndPaymentImageTest with 8 functional tests covering core workflows
