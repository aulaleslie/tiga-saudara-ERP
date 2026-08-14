## Why

Sales and Expense detail pages do not provide the same attachment-management experience available on Purchase details. Users need a quick, consistent way to add and manage supporting documents directly from these detail pages without reopening the document edit workflow.

## What Changes

- Add sale-level attachment upload, listing, preview/download, and deletion to the Sales detail page.
- Upgrade the Expense detail attachment section with upload, explicit preview/download, deletion, and an empty state.
- Store uploaded files unchanged in each document's Spatie Media Library `attachments` collection; image compression and ZIP conversion are explicitly out of scope.
- Restrict attachment mutations to users with the document module's existing edit permission, enforce active-setting ownership, and prohibit mutation of archived documents.
- Validate that an attachment belongs to the route-bound document before deletion.

## Capabilities

### New Capabilities
- `sales-expense-detail-attachments`: Attachment viewing and management behavior on Sales and Expense detail pages.

### Modified Capabilities

None.

## Impact

- Sales and Expense entities, controllers, routes, and detail Blade views.
- Spatie Media Library usage for the shared `attachments` collection.
- Existing `sales.edit` and `expenses.edit` authorization policies and active-setting ownership checks.
- Focused feature tests for attachment display, upload, deletion, authorization, and document ownership.
- Purchase attachment behavior remains unchanged, and no new compression dependency or background processing is introduced.
