## 1. Barcode Identity and Audit Schema

- [x] 1.1 Add migrations for the barcode identity registry and product barcode assignment history with nullable audit-preserving foreign keys, ownership metadata, canonical key uniqueness, and MySQL/SQLite-compatible indexes.
- [x] 1.2 Add registry and assignment-history Eloquent models with product, conversion, and actor relationships plus initialization/replacement action constants or enum handling.
- [x] 1.3 Implement a deterministic barcode canonicalization utility that preserves stored string values and leading zeroes while producing consistent identity keys across MySQL and SQLite.
- [x] 1.4 Implement a read-only historical barcode preflight that detects duplicates within and across product and conversion-unit barcodes and reports every conflicting owner without modifying data.
- [x] 1.5 Implement safe registry backfill and per-table uniqueness migration behavior that aborts with actionable conflict details instead of rewriting historical barcodes.
- [x] 1.6 Add migration and unit tests for clean backfill, duplicate preflight failure, nullable values, rollback safety, and equivalent canonical collision behavior on SQLite.

## 2. Shared Barcode Integrity Services

- [x] 2.1 Implement a shared barcode identity reservation service that atomically reserves, replaces, and releases registry ownership and translates unique-key races into duplicate-domain results.
- [x] 2.2 Implement conflict lookup that resolves a duplicate identity to the owning product or product conversion and returns product code/name and conversion-unit context for user-facing errors.
- [x] 2.3 Implement the narrow product barcode assignment service with authorization, product row locking, stale-value comparison, cross-namespace validation, no-op detection, product-only mutation, and transactional audit history.
- [x] 2.4 Add service tests for initialization, replacement, no-op, leading-zero preservation, supported alphanumeric values, product conflicts, conversion conflicts, stale state, rollback, authorization, and concurrent reservation failure.

## 3. Existing Product Barcode Mutation Integration

- [x] 3.1 Update full product creation and update flows to validate and reserve base-unit barcodes through the shared identity service without changing unrelated price, tax, stock, media, or idempotency behavior.
- [x] 3.2 Update product quick-add barcode persistence to use the shared identity service while preserving its existing reset and product-selected behavior.
- [x] 3.3 Update product unit-conversion create, update, replacement, and deletion paths to reserve or release barcode identities through the shared service.
- [x] 3.4 Update Product-module barcode validation messages so cross-namespace conflicts identify the owning product and unit consistently across full forms, quick add, and the new workspace.
- [x] 3.5 Add regression tests covering product create/edit, quick add, conversion create/edit/delete, transaction rollback, and preservation of existing product price and unit-alignment behavior.

## 4. Authorization, Routing, and Navigation

- [x] 4.1 Register the `products.barcodes.manage` permission in the existing permission configuration and role/permission synchronization conventions without granting it implicitly through `products.edit` or `barcodes.print`.
- [x] 4.2 Add the `Modules/Product/Routes/web.php` route group `products.barcodes` mapping to `ProductBarcodeInitializationController` and protected by `products.barcodes.manage`.
- [x] 4.3 Modify the 'Produk' navigation menu (or the main layout sidebar) to conditionally render a 'Barcode Initialization' link when the active user possesses `products.barcodes.manage`.
- [x] 4.4 Add feature tests proving authorized access/save, unauthorized route and action rejection, menu visibility, and independence from product-edit and barcode-print permissions.

## 5. Livewire Barcode Initialization Workflow

- [x] 5.1 Create a new Livewire component `ProductBarcodeInitialization` that fetches all base products and unit conversions currently lacking a populated `barcode` attribute, grouping them cleanly for rapid entry.
- [x] 5.2 Implement `ProductBarcodeInitializationController` to present a workspace enumerating missing barcodes (base units and conversions) with fields for rapid entry, wrapping the bulk application in a transaction utilizing `BarcodeIdentityService`.
- [x] 5.3 Implement product selection and original-barcode snapshot handling, including browser events that highlight and focus the text-based barcode input.
- [x] 5.4 Implement scanner Enter capture and cancellation so candidate review never persists automatically, scanner framing is removed without numeric coercion, and cancellation refocuses the scan field.
- [x] 5.5 Generate a Code 128 SVG preview from a valid captured candidate using the installed Milon barcode library, display the complete authoritative value, and block confirmation when preview generation fails.
- [x] 5.6 Implement initialize and replacement confirmation actions through the assignment service, including old/new replacement context, no-op feedback, duplicate ownership messages, stale-state refresh, and corrective focus events.
- [x] 5.7 Track a bounded recent-success list and per-session saved count, then clear selected/candidate/search state and focus product search after each successful assignment.

## 6. Workspace User Interface

- [x] 6.1 Build the responsive Bootstrap/CoreUI workspace view with progress summary, search controls, result status badges, selected-product identity/base-unit card, and a prominent scanner-ready input state.
- [x] 6.2 Build the review panel with candidate text, safe barcode preview rendering, initialization confirmation, and an amber old-versus-new replacement warning with explicit replacement labeling.
- [x] 6.3 Add keyboard interactions for result selection, scan capture, deliberate confirmation, cancellation, and repeat-loop focus while preserving usable mouse and touch controls.
- [x] 6.4 Add Indonesian loading, empty, success, duplicate, invalid-preview, authorization-safe, stale-state, and unexpected-error messages consistent with existing localization conventions.
- [x] 6.5 Verify the interface remains usable on desktop scanner stations and tablet widths without introducing camera-scanner or POS workflow dependencies.

## 7. Workflow and Regression Verification

- [x] 7.1 Add Livewire tests for catalog search, uninitialized filtering, stockless visibility, result metadata, selection state, focus events, scanner capture without save, cancellation, and preview state.
- [x] 7.2 Add Livewire tests for confirmed initialization, confirmed replacement, no-op, duplicate product/conversion rejection, stale update rejection, retained correction context, recent activity, counter updates, and return-to-search focus.
- [x] 7.3 Add focused regression tests confirming existing POS exact-barcode resolution and Print Barcode rendering continue to consume stored product barcodes after assignments and replacements.
- [x] 7.4 Run focused Product and Livewire feature tests with `php artisan test` filters and resolve failures attributable to the change.
- [x] 7.5 Run `composer test:fresh-sqlite` to verify migrations, registry backfill, Product-module integrations, and broader regression safety.
- [x] 7.6 Perform scanner-station UAT for the repeated search-select-scan-preview-confirm loop, leading-zero barcode, alphanumeric barcode, duplicate feedback, replacement warning, keyboard-only use, and tablet layout.
