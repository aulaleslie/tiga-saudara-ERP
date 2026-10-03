# Design

## Context

See proposal.md for motivation. The individual payment form currently stages a single `attachment` through `dropzone.upload`; that endpoint accepts images only and fits them to 400 × 400. `PurchasePaymentStoreService` validates one staged filename and stores one media item. `PurchasePaymentsDataTable` is used by both the purchase detail payment table and separate payment list, but displays only the first media item. The payment detail view also displays only the first. The global payment form has its own single attachment validation and copy logic. Purchase document attachments use a different service that may ZIP files.

## Goals / Non-Goals

**Goals:** Keep the individual payment upload isolated, preserve existing financial transaction and rollback behavior, and render all existing and new payment attachments consistently.

**Non-Goals:** Change global payment allocations or attachment upload, alter purchase document attachments, add post-creation attachment editing, or introduce new storage tables.

## Decisions

### Dedicated staging route and handler

Add a dedicated authenticated upload endpoint for individual purchase payment files. Reuse the existing temporary Dropzone directory convention but do not modify `dropzone.upload`, `dropzone.upload.documents`, or the purchase document attachment service. The form submits an `attachments[]` list of opaque staged names, removes only its own hidden input when a staged file is removed, and blocks final submission until pending uploads complete. The handler preserves original filenames as metadata while using generated safe storage names.

An alternative is to extend the shared Dropzone endpoint. That risks changing product and global payment uploads, so this change uses a separate path. The existing single `attachment` contract in global payment code remains intact.

### Type handling and one image pass

Validate allowed extension and detected content type on upload and again when consuming a staged file. Include PDF, DOC, DOCX, XLS, XLSX, TXT, and recognized image types; reject active or unrecognized content rather than trusting the extension alone. Enlarge the payment media collection's MIME acceptance for the approved types. For image formats supported by the image processor, encode once at a chosen quality without cropping or changing dimensions; use that result only if it is valid and smaller. Preserve original bytes for unsupported formats or failed/no-benefit compression. Do not add an application size cap, a repeated quality loop, or ZIP fallback.

An alternative is adapting `PurchaseAttachmentService`, but its 1 MB hard limit and ZIP fallback conflict with this workflow. Very large uploads depend on PHP and web-server body limits; implementation should document/configure those limits for deployment, without advertising an unlimited network transfer guarantee.

### Payment storage and failure cleanup

The individual controller validates an array of staged names. Add a dedicated multi-attachment store method or companion service rather than changing the existing single-file store contract used by other callers. Validate every staged path within `temp/dropzone` before the financial transaction. Under the existing purchase/payment locks, create one media row per file and reconcile balances as today. Track every created media path for compensating filesystem cleanup if payment creation or reconciliation fails. After success, remove or move staged files according to media-library behavior; an invalid member must not leave a partial payment. Treat duplicate staged names as invalid to avoid moving the same file twice.

An alternative is converting the existing `store()` parameter to an array union. A new path avoids changing existing callers and unit tests for single-file behavior.

### Shared rendering

Update `PurchasePaymentsDataTable` to eager-load payment media and render escaped labels and URLs for every attachment. This one renderer serves both payment tables. Update the payment detail view to iterate the same collection. Keep links opening in a new tab with `rel="noopener noreferrer"`. Use a compact stacked list in the table so multiple names remain readable. Legacy payments with one attachment continue to render one link.

## Risks / Trade-offs

- [Large uploads can be rejected before Laravel receives them] → Document required PHP and proxy body limits and show an actionable upload failure in the form.
- [Unsupported image codecs cannot be compressed] → Preserve the valid original image rather than rejecting it for compression failure.
- [Media files are not transactional with the database] → Track all created files and clean them on failure; verify this with a focused rollback test.
- [Many media lookups can slow a table] → Eager-load media in the DataTable query.
- [Browser behavior differs for Office and text files] → Keep `target="_blank"`; browsers may download types they cannot render.

## Migration Plan

No database migration is required because the media collection already supports multiple rows. Deploy the dedicated endpoint and form together. Existing single-file payments remain readable. A rollback can restore the previous form and renderer; newly stored media remains associated with its payment, though the old renderer would show only its first file.
