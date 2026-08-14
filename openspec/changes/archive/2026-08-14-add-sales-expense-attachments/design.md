## Context

Expense already implements Spatie Media Library and stores files in an `attachments` collection during create/edit, but its detail page is read-only and omits an empty state and explicit actions. Purchase detail provides the desired interaction pattern, including detail-page upload and deletion. Sale currently has neither the `HasMedia` contract nor sale-level attachment routes and UI; its existing attachment behavior is limited to payment records.

The requested delivery is intentionally narrow and time-sensitive. Files must be stored directly, without adopting Purchase's image compression and ZIP conversion behavior. Existing Purchase behavior must remain untouched.

## Goals / Non-Goals

**Goals:**

- Provide consistent document-level attachment controls on Sales and Expense detail pages.
- Reuse the existing Spatie `attachments` collection convention.
- Enforce edit permissions, active-setting isolation, archived-document immutability, and strict media ownership checks.
- Preserve uploaded files and their original client-visible names.
- Cover the security-sensitive paths with focused feature tests.

**Non-Goals:**

- Changing Purchase attachments or `PurchaseAttachmentService`.
- Compressing images, resizing images, creating ZIP archives, or adding asynchronous processing.
- Changing payment-level attachments, create/edit form attachments, document approval rules, or archive workflows.
- Supporting bulk upload; each detail-page request accepts one file.
- Introducing new permissions or a new attachment database table.

## Decisions

### Use the existing Spatie Media Library collection

Sale will adopt `HasMedia` and `InteractsWithMedia` and register the same `attachments` collection already used by Purchase and Expense. Expense keeps its current model integration. This avoids schema changes and keeps storage lifecycle cleanup in Media Library.

Alternative considered: create dedicated sale and expense attachment tables. Rejected because it duplicates existing media infrastructure and would increase migration and delivery effort.

### Store uploads directly with standard validation

Each endpoint will validate a single required file with a 10 MB maximum and add it directly to the route-bound document's `attachments` collection. The media name/file metadata will preserve the original uploaded filename. There will be no call to `PurchaseAttachmentService`, because that service is Purchase-typed and its core responsibility includes the explicitly excluded compression/ZIP behavior.

Alternative considered: generalize `PurchaseAttachmentService`. Rejected for this change because it risks altering established Purchase behavior and expands a quick UI capability into a cross-module processing refactor.

### Use module-specific controller endpoints

Sales and Expense will each receive store and destroy routes and controller actions, following the established Purchase route shape. Small duplication is accepted to keep authorization and setting-ownership checks explicit within each module and to minimize cross-module coupling.

Alternative considered: a polymorphic shared attachment controller. Rejected because accepting model types dynamically enlarges the authorization surface and is unnecessary for two modules.

### Match Purchase detail presentation without copying processing behavior

Both detail views will always render a Lampiran section. The section will include a one-file uploader when mutation is allowed, a list with filename and human-readable size, Preview and Download actions, Delete controls, and an empty state. Images can open in a new tab; non-image preview actions can use the browser/download behavior supported by the stored MIME type.

### Enforce mutation rules independently of document editing workflow

Attachment mutation requires `sales.edit` or `expenses.edit`, active-setting ownership, and a non-archived document. It does not require the document itself to be in a fully editable lifecycle status. This matches Purchase's detail-page attachment model and allows supporting evidence to be added after ordinary field editing is locked. Existing view permissions continue to govern access to the detail page.

Deletion additionally compares collection name, model type, and model ID against the route-bound document before deleting. Route model binding alone is insufficient because the media ID is independently supplied by the client.

## Risks / Trade-offs

- [Attachments can be changed after approval] → Require edit permission and block archived documents; broader attachment auditing is explicitly deferred.
- [Direct uploads can consume more storage than compressed Purchase attachments] → Enforce a 10 MB per-file limit and one file per request.
- [Browser preview differs by file type] → Always provide an explicit Download action and use new-tab preview only where supported.
- [Duplicated controller logic can drift] → Keep actions small and parallel, with equivalent feature-test matrices for both modules.
- [Original filenames can contain unsafe characters] → Let Media Library generate/manage stored paths while retaining the original name as display metadata; escape all names through Blade.

## Migration Plan

1. Add Media Library support to the Sale model; no database migration is required because the shared media table already exists.
2. Add Sales and Expense attachment endpoints and controller guards.
3. Add attachment controls to both detail views.
4. Deploy with focused feature tests. Existing Expense media remains visible because the collection name is unchanged; existing Sales records require no backfill.

Rollback consists of reverting the routes, controller actions, Sale media integration, and Blade sections. Existing uploaded media records can remain safely in the shared media table or be removed separately under an explicit data-retention decision.

## Open Questions

None. The change uses the existing module edit permissions, a 10 MB limit, one file per request, and non-archived documents as the agreed mutation boundary.
