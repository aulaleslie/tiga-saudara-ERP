# Proposal

## Why

An individual purchase payment currently accepts only one image through an upload path that resizes it, despite the form advertising PDF support. Payment histories link only the first attachment, so they cannot present a complete set of supporting documents.

## What Changes

- Allow multiple separate image and document attachments when creating an individual purchase payment, including common image, PDF, Word, Excel, and text formats.
- Give this flow its own upload processing and validation so the global purchase payment flow retains its current behavior.
- Attempt image compression once while preserving aspect ratio. Aim for less than 1 MB; accept the result or original even when it remains larger. Never ZIP attachments.
- Show every attachment as an individually named link opening in a new tab in both payment histories and the payment detail view.
- Keep attachment creation limited to payment creation; payment note editing does not mutate attachments.

## Capabilities

### New Capabilities

- `individual-purchase-payment-attachments`: Upload, store, and display multiple attachments for an individual purchase payment.

### Modified Capabilities

None.

## Impact

The individual payment form, controller, store service, payment media collection, shared payment DataTable renderer, and payment detail view are affected. A dedicated upload endpoint or handler is needed. Existing global payment upload and allocation behavior, existing payment records, and purchase document attachments remain in place. Deployment must configure HTTP and PHP request limits large enough for the desired upload sizes; the feature itself will not impose an original file size limit.
