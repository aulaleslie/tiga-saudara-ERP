## 1. Sales Attachment Foundation

- [x] 1.1 Add Spatie Media Library support and register the `attachments` collection on the Sale entity.
- [x] 1.2 Add Sales attachment store and destroy routes using route-bound Sale and Media models.
- [x] 1.3 Implement Sales controller upload and deletion actions with single-file 10 MB validation, `sales.edit`, active-setting ownership, archive, collection, model type, and model ID guards.

## 2. Expense Attachment Endpoints

- [x] 2.1 Add Expense attachment store and destroy routes using route-bound Expense and Media models.
- [x] 2.2 Implement Expense controller upload and deletion actions with single-file 10 MB validation, `expenses.edit`, existing setting-ownership verification, archive, collection, model type, and model ID guards.

## 3. Detail Page Attachment Interfaces

- [x] 3.1 Add the always-visible Lampiran section to Sales detail with conditional one-file upload, empty state, original filename, size, Preview, Download, and conditional Delete controls.
- [x] 3.2 Upgrade the Expense detail Lampiran section to the same conditional upload and attachment-action behavior while preserving existing stored attachments.
- [x] 3.3 Add minimal file-input label behavior and responsive styling needed by both detail views, following the existing Purchase detail presentation.

## 4. Focused Verification

- [x] 4.1 Add Sales feature tests for detail display, valid direct upload, missing/oversized/multiple-file rejection, deletion, edit permission, active-setting isolation, archived-document blocking, and foreign-media rejection.
- [x] 4.2 Add Expense feature tests covering the equivalent display, upload, validation, deletion, permission, setting, archive, and foreign-media cases.
- [x] 4.3 Run the focused Sales and Expense attachment tests and confirm existing Purchase attachment behavior and files remain unchanged.
