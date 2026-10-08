# Design

## Context

See proposal.md for motivation. The global form currently renders one Dropzone below a paginated allocation table, posts one `attachment` filename, and `GlobalPurchasePaymentService` copies that file into every generated payment. The individual payment flow already stages multiple supported files with original-name metadata, validates them again before storage, and stores separate media items in the existing `attachments` collection. Global payment allocation and balance changes already run inside a locked database transaction.

## Goals / Non-Goals

**Goals:** Preserve the existing supplier, eligibility, amount-formatting, idempotency, and atomic settlement behavior while changing the file ownership to one purchase row. Keep attachment storage on `PurchasePayment` so current payment views can display both new and historical media.

**Non-Goals:** Introduce a global payment header or new attachment table, move historical media, change individual payment creation, or add post-creation attachment editing.

## Decisions

### Row-keyed staged attachment contract

Replace the top-level `attachment` input with `attachments[purchase_id][]` in the global create form and controller. The purchase ID is a key for a row's staged file names, not proof that the purchase is eligible. The service must validate that each key identifies an allowed candidate for the supplier and that each file belongs to exactly one submitted row. It must reject attachment-bearing zero allocations before creating any payment. Keep attachments optional for positive allocations.

This is preferable to a shared attachment input because it makes file ownership explicit and avoids copying an unrelated receipt to every payment. A separate global-payment header would require a new model and a different reading path for existing payment views.

### Per-row uploader within the paginated table

Render an attachment column beside the amount column, keeping the existing purchase context columns. Mount one multiple-file uploader per row with a stable purchase ID. Track staged names and in-flight uploads independently of DataTables' current page DOM; on submit, serialize all row-keyed file names into hidden inputs attached to the persistent form, including off-page rows. Remove a staged file through the individual payment deletion endpoint and remove only that row's reference. Block submit while any upload is pending or a row has files with zero amount. The server repeats the zero-amount and association checks so client manipulation cannot bypass them.

An alternative is keeping all uploaders in ordinary table cells and relying on DOM form serialization. DataTables detaches off-page rows, so that approach can silently omit attachments or allocations.

### Reuse individual file handling and payment media

Use the existing individual payment staging endpoint and its image/document validation, MIME checks, original-name metadata, and compression behavior. Before the global transaction, validate every staged name with the same multi-file validator used by individual payments, including duplicate detection across the entire submission. During the existing purchase/payment locked transaction, attach only each positive row's validated files to its new `PurchasePayment`. Preserve the original filename metadata and store one media item per file. Do not add an application-specific original-file size cap or ZIP files; transport limits still apply.

An alternative is extending the old global PDF/image validator. It would retain inconsistent accepted types and a 10 MB service cap while duplicating security rules.

### Atomicity and cleanup

Keep all payment rows, media records, and balance updates in the existing transaction. Track newly created media and remove physical files if any later attachment or balance operation fails; media files themselves are outside database rollback. Do not delete the staged source before successful storage. Preserve readable historical media without conversion or backfill.

## Risks / Trade-offs

- [Pagination can detach rows and lose input state] → Keep row attachment state outside the table DOM and verify submission from more than one page.
- [A filename can be reused across rows or tampered with] → Reject duplicate staged names globally and validate path, content, and row association on the server.
- [Filesystem writes cannot roll back with SQL] → Track created media and use compensating cleanup on failure; cover this with a focused test.
- [Large files or many rows can exceed transport or session capacity] → Reuse per-file staging and show upload errors before final submission.

## Migration Plan

Deploy the form, controller, and service change together. No schema migration is required. Historical global payments retain their existing media. Rolling back the new form and service leaves already-created row-specific media attached to their payments and readable through existing views.
