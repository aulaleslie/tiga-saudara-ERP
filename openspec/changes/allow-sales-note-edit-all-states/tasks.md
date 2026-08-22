## 1. Sales Note Editor

- [x] 1.1 Create the sales Livewire note-editor component using archived-aware lookup, active-setting isolation, and `sales.edit` authorization at mount and mutation time.
- [x] 1.2 Implement note-only save behavior with `nullable|string|max:1000` validation, empty-string-to-null normalization, cancel restoration, and a success notification.
- [x] 1.3 Create the sales note-editor Blade view with read-only display plus privilege-gated edit, save, cancel, loading, and validation states.

## 2. Sales Detail Integration

- [x] 2.1 Replace the static note section on the normal setting-scoped sales detail page with the sales Livewire note editor.
- [x] 2.2 Confirm global and cross-business sales detail surfaces retain read-only note rendering and do not mount the editor.

## 3. Verification

- [x] 3.1 Add focused Livewire tests proving users with `sales.edit` can update notes in drafted, waiting-approval, approved, rejected, partially dispatched, dispatched, partially returned, and returned states without lifecycle-specific edit permissions.
- [x] 3.2 Add authorization and scope tests for users without `sales.edit`, archived sales, and sales outside the active setting.
- [x] 3.3 Add validation and interaction tests for a valid 1,000-character note, an oversized note, empty-note normalization, cancel restoration, and success notification behavior.
- [x] 3.4 Add regression assertions that a note save changes only `sales.note` and leaves status, customer, monetary fields, details, dispatches, payments, returns, and inventory-related records unchanged.
- [x] 3.5 Add or update detail-view tests verifying authorized controls, unauthorized read-only rendering, and read-only global/cross-business rendering.
- [x] 3.6 Run the focused sales note-editor and detail-view test suite, then run the broader relevant Laravel test set if focused verification passes.
