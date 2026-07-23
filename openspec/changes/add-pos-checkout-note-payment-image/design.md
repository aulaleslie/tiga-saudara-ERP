## Context

The POS cart is currently held in `PosCartSessionStore`, can be persisted as a `PosTransaction` draft, and is finalized through `FinalizePosCheckoutService`. Checkout posting can split one customer-facing POS transaction into multiple owner-aligned Sales. The inline posting adapter currently writes a fixed provenance value to `Sale.note`, while neither the cart nor POS transaction header has a dedicated cashier note.

Staged payments are stored in the Laravel session under a cart token before a `PosCheckout` or `SalePayment` exists. Each stage carries a method, amount, order, and optional EDC/reference. During split posting, one stage can produce a `SalePayment` in more than one generated Sale. `SalePayment` already implements Spatie Media Library and defines a single-file `attachments` collection.

The note and image are both optional. EDC/reference behavior remains controlled by the existing payment-method `requires_reference` flag and is not changed by this design.

## Goals / Non-Goals

**Goals:**

- Capture an optional POS transaction note at the requested position and preserve it across reloads, saved drafts, checkout, and generated Sales.
- Allow one optional JPEG or PNG image on each non-cash staged payment.
- Retain an uploaded image safely between payment staging and finalization.
- Map each staged payment to every `SalePayment` it generates and copy its image to all of those records.
- Preserve stage isolation, split-owner behavior, idempotent finalization, and reset/expiry cleanup.

**Non-Goals:**

- Requiring either a transaction note or a payment image.
- Changing which payment methods require an EDC/reference.
- Accepting images for cash payment stages.
- Printing the transaction note or payment image on the customer receipt.
- Adding a permanent attachment to `pos_checkouts` or `pos_checkout_payments`.
- Supporting multiple images or non-image documents for one payment stage.

## Decisions

### Store the note explicitly through the POS lifecycle

Add a nullable `note` text column to `pos_transactions` and `pos_checkouts`, and add `note` to the cart-session shape and cart snapshot. A focused cart-note mutation endpoint will trim the value, treat an empty string as null, and enforce a 1,000-character limit. The UI will save on blur or with a short debounce and render server validation without blocking unrelated cart activity.

Saved-draft mapping will persist and hydrate the note, and the transaction snapshot hash will include it so draft drift detection covers header content as well as lines and totals. Completion will copy the final cart note to both the completed POS transaction and checkout ledger.

Explicit columns are preferred over generic metadata because the note is user-authored business data that must be queryable and reliably round-trip through drafts and completed transactions.

### Combine the cashier note with POS provenance on every generated Sale

Posting context will carry the normalized transaction note into both inline and split posting. Each generated `Sale.note` will contain the cashier note when present plus the existing `POS checkout #<id>` provenance on a separate line. When the cashier note is absent, current provenance-only behavior remains.

Copying the note into every generated Sale is preferred over storing it only on the POS checkout because Sales users work from owner-specific documents and split posting can generate more than one of them.

### Use a dedicated temporary POS payment-image upload record

Introduce a temporary upload record bound to setting, POS session, cashier, cart token, and an opaque upload token. It will retain the storage path, original name, MIME type, size, expiry, and consumption state. A POS-authenticated multipart endpoint will accept JPEG and PNG images up to 5 MB and return only the opaque token and display metadata.

The staged-payment request will accept a nullable image token. The backend will reject a supplied token for a cash method and, for a non-cash method, verify ownership, cart-token scope, expiry, MIME type, and unconsumed state before recording it in the payment chain. Omitting the token remains valid.

A dedicated scoped record is preferred over putting an `UploadedFile`, raw path, or binary data in the Laravel session. It also avoids trusting the unscoped filename returned by the existing generic Dropzone upload endpoint.

### Preserve a stable one-based stage order through split posting

The staged chain's existing one-based `stage_order` will remain the correlation key. Payment normalization and split slicing will preserve that order. Every generated `SalePayment` will store the originating value in its existing `stage_order` column.

The inline posting adapter will return generated-payment mappings containing `stage_order`, payment method ID, Sale ID, and Sale Payment ID. The split adapter will aggregate these mappings across all generated Sales. This is preferred over correlating only by payment-method ID because the same non-cash method may occur in multiple stages.

### Attach only to generated Sale Payments and duplicate per originating stage

After Sale Payments are created during finalization, the finalization service will group their returned mappings by stage order. If that stage has an image token, it will use the existing `attachments` single-file media collection to create an independent attachment for every mapped `SalePayment`. A stage without an image performs no media action.

Images are not stored permanently on POS checkout/payment models. For a split checkout, duplicating the file is intentional because each owner-specific Sale Payment must remain self-contained. An image from one stage must never be copied to another stage, including another stage using the same payment method.

The temporary source is retained until the checkout transaction commits successfully. After commit it is marked consumed and its temporary file is removed; cleanup failure does not invalidate the posted checkout and is handled by scheduled cleanup.

### Keep retry and cleanup behavior deterministic

The image token and stage order participate in the canonical payment/finalization payload hash so replaying an idempotency key with different evidence is a conflict. The posted-checkout replay path does not add media again. The single-file media collection prevents more than one attachment on a generated payment, while explicit mapping checks prevent duplicate copy attempts within the first finalization.

Resetting the whole payment chain will delete unconsumed temporary images associated with that cart token. A scheduled cleanup will remove expired or consumed leftovers. Failed finalization leaves unconsumed sources available for a bounded retry window.

## Risks / Trade-offs

- [Filesystem writes are not transactionally rolled back with database changes] → Keep the temporary source until commit, perform database/media association inside the guarded finalization flow, and clean orphaned media files with a scheduled repair/cleanup path.
- [Split payment slicing can lose the original stage identity] → Preserve one-based `stage_order` explicitly through normalization, allocation, adapter creation, and returned mappings; cover repeated-method stages in tests.
- [Duplicating evidence increases storage usage] → Limit each stage to one 5 MB image and duplicate only to Sale Payments actually generated from that stage.
- [Debounced note writes can race with checkout] → Flush a pending note update before opening payment and use the server cart snapshot as finalization input.
- [Temporary uploads can be guessed or reused] → Use opaque tokens and validate setting, session, cashier, cart token, expiry, and consumption status on every use.
- [A reset or expired session can leave temporary files] → Delete scoped uploads on reset and run periodic expiry cleanup.

## Migration Plan

1. Add nullable note columns and the temporary POS payment-image upload table without changing existing rows.
2. Deploy backend note and image endpoints, persistence, correlation mappings, and cleanup support.
3. Deploy the POS UI and staged-payment JavaScript after the backend accepts the new optional fields.
4. Verify legacy carts, drafts, and staged chains without the new fields continue to normalize to null.
5. Rollback disables the UI/endpoints first, then removes only the additive note columns and temporary-upload table; existing Sale Payment media attached during successful checkouts remains valid business evidence.

## Open Questions

- None. Both fields are optional; images are limited to one JPEG/PNG per non-cash stage and are duplicated only to Sale Payments originating from that stage.
