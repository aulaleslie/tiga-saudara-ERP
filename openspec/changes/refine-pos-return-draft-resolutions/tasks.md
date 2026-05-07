## 1. Schema And Models

- [x] 1.1 Add nullable draft-resolution columns to `pos_return_lines` for `resolution`, returned serial identity, replacement serial identity, source POS transaction line identity, and source-line grouping metadata.
- [x] 1.2 Add nullable rejected-delete audit fields to `pos_returns` if existing archive/delete fields cannot represent rejected soft deletion.
- [x] 1.3 Update `Modules/Pos/Entities/PosReturnLine.php` with resolution constants, serial/replacement fillable fields, casts, and relationships to returned and replacement serial records.
- [x] 1.4 Update `Modules/Pos/Entities/PosReturn.php` with helper methods or scopes for draft-editable, rejected-editable, hard-deletable, and rejected-soft-deletable states.

## 2. Source Snapshot Granularity

- [x] 2.1 Refactor `PosReturnSnapshotService` so returnable rows are keyed by source identity instead of consolidated by `product_id`.
- [x] 2.2 Include POS transaction line and POS transaction line serial context in the source snapshot for serialized POS lines.
- [x] 2.3 Build one serial-level snapshot row for each original sold serial, preserving sale, sale detail, dispatch detail, checkout sale, bundle, source setting, source location, and tax context.
- [x] 2.4 Build non-serial snapshot rows per original source sale/dispatch/component group without merging distinct source contexts.
- [x] 2.5 Include bundled component trace data under actionable-capable serialized bundle parent rows without creating executable component rows for `none` parent rows.
- [x] 2.6 Update snapshot hashing so the canonical hash reflects the new source identity rows and remains stable across equivalent payload ordering.

## 3. Draft Create Persistence

- [x] 3.1 Refactor `PosReturnSubmissionService::store()` to create `pos_returns` in `draft` status and `draft` approval status only.
- [x] 3.2 Remove Sales Return and Sale Return Detail creation from draft store behavior.
- [x] 3.3 Validate that at least one submitted line has `cash_return` or `product_replacement` before saving a draft.
- [x] 3.4 Persist serial-tracked source rows as one draft line per returned source serial with independent `none`, `cash_return`, or `product_replacement` resolution.
- [x] 3.5 Persist non-serial rows only when they have actionable resolution and positive quantity.
- [x] 3.6 Persist expected cash return amount for actionable `cash_return` lines using the original POS source monetary allocation.
- [x] 3.7 Persist bundled component trace rows or metadata only for actionable serialized bundled parent rows.
- [x] 3.8 Add guard assertions that draft store does not mutate `sale_returns`, `sale_return_details`, dispatch quantities, stock quantities, serial statuses, payments, or `transactions` rows.

## 4. Replacement Serial Validation

- [x] 4.1 Add a scanner/barcode-friendly replacement serial lookup path for serial-tracked draft lines.
- [x] 4.2 Validate replacement serials belong to the same product as the returned serial.
- [x] 4.3 Validate replacement serials are active/available at draft create and edit time.
- [x] 4.4 Validate replacement serials are not the same as the returned serial.
- [x] 4.5 Clear replacement serial selections when a line resolution changes from `product_replacement` to `none` or `cash_return`.
- [x] 4.6 Do not validate replacement serial origin owner, source setting, source location, or original dispatch location in this change.

## 5. Draft Edit And Delete

- [x] 5.1 Implement draft edit save by revalidating source snapshot freshness and rebuilding draft lines from submitted selections.
- [x] 5.2 Implement rejected edit save so edited rejected returns reset to `draft` status and draft approval status.
- [x] 5.3 Ensure edit save does not create Sales Return records or mutate stock, dispatch, payments, serial statuses, or transaction history.
- [x] 5.4 Implement hard delete for draft POS Returns and their draft lines.
- [x] 5.5 Implement audited soft-delete style behavior for rejected POS Returns, recording actor, timestamp, and reason or equivalent audit marker.
- [x] 5.6 Block direct draft/rejected delete behavior for approved or later lifecycle states.

## 6. Livewire And Blade UI

- [x] 6.1 Update the create form state from product-keyed quantities to source-line-keyed draft selections.
- [x] 6.2 Render serialized products as source serial rows grouped under their source POS line and bundle/non-bundle context.
- [x] 6.3 Default each serialized source row resolution to `none`.
- [x] 6.4 Provide per-serial controls for `none`, `product_replacement`, and `cash_return`.
- [x] 6.5 Show replacement serial scanner/input controls only when a serial row uses `product_replacement`.
- [x] 6.6 Show expected cash amount for actionable `cash_return` rows.
- [x] 6.7 Show bundled component trace rows only beneath actionable serialized bundled parent rows.
- [x] 6.8 Update the edit form to load existing draft/rejected line resolutions and replacement serials using the same source-line-keyed state as create.

## 7. Tests And Verification

- [x] 7.1 Add a focused feature or Livewire test for transaction `TNC-TXN-2026-05-0001`-style data where the same serialized SKU appears in bundled and non-bundled POS lines.
- [x] 7.2 Test that the snapshot exposes two bundled Samsung serial rows and one non-bundled Samsung serial row without product-level merging.
- [x] 7.3 Test that saving a valid draft creates only POS Return records and no Sales Return records.
- [x] 7.4 Test that saving all `none` lines is rejected.
- [x] 7.5 Test mixed serial resolutions in one POS Return document.
- [x] 7.6 Test replacement serial validation for same product, active/available status, and returned-serial mismatch.
- [x] 7.7 Test that replacement serial origin owner/location is not required.
- [x] 7.8 Test that draft edit rebuilds lines without execution mutations.
- [x] 7.9 Test that rejected edit resets the document to draft.
- [x] 7.10 Test draft hard delete and rejected audited soft delete behavior.
- [x] 7.11 Run focused POS return tests with `php artisan test --filter=PosReturn`.
