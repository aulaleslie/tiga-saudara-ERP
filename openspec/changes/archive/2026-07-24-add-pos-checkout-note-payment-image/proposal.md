## Why

POS cashiers currently cannot record an optional transaction note that follows the checkout into the generated Sales documents. Non-cash payment stages also cannot accept an optional proof image, leaving generated Sale Payment records without supporting evidence when the checkout is posted, including when one POS payment is split across multiple Sales.

## What Changes

- Add an optional POS transaction note below customer selection and above the checkout action.
- Preserve the optional note through the active cart, saved POS drafts, completed POS transaction, checkout ledger, and every generated Sale document.
- Add an optional image input for each non-cash staged payment; cash payment stages do not display or accept an image.
- Temporarily retain an uploaded image until checkout finalization without making the image mandatory.
- Attach the image to the generated Sale Payment for its originating payment stage.
- When split posting creates multiple Sale Payment records from one POS payment stage, duplicate that stage's image to every corresponding generated Sale Payment.
- Keep payment images isolated by stage so an image from one payment method is not attached to payments generated from another stage.
- Preserve existing EDC/reference-number requirements independently from the optional image.

## Capabilities

### New Capabilities

- `pos-checkout-note`: Optional transaction-note capture, draft persistence, checkout propagation, and generated Sale document behavior.
- `pos-non-cash-payment-image`: Optional per-stage non-cash image upload and attachment duplication across generated Sale Payment records.

### Modified Capabilities

- None.

## Impact

- POS sell layout and staged-payment JavaScript.
- POS cart session state, cart mutation API, saved transaction snapshots, checkout ledger, and transaction detail data.
- POS staged-payment and finalization endpoints, temporary upload lifecycle, and idempotency handling.
- Split and inline checkout posting results so generated Sale Payments can be mapped back to their originating POS payment stage.
- Existing `SalePayment` media collection and attachment access.
- POS feature tests covering optional values, draft round trips, multi-payment isolation, split-owner duplication, retries, reset cleanup, and validation.
