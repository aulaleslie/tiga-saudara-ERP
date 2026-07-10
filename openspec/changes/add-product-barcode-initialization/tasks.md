## 1. Barcode Identity and Audit Schema

- [ ] 1.1 Add migrations for the barcode identity registry and product barcode assignment history with nullable audit-preserving foreign keys, ownership metadata, canonical key uniqueness, and MySQL/SQLite-compatible indexes.
- [ ] 1.2 Add registry and assignment-history Eloquent models with product, conversion, and actor relationships plus initialization/replacement action constants or enum handling.
- [ ] 1.3 Implement a deterministic barcode canonicalization utility that preserves stored string values and leading zeroes while producing consistent identity keys across MySQL and SQLite.
- [ ] 1.4 Implement a read-only historical barcode preflight that detects duplicates within and across product and conversion-unit barcodes and reports every conflicting owner without modifying data.
- [ ] 1.5 Implement safe registry backfill and per-table uniqueness migration behavior that aborts with actionable conflict details instead of rewriting historical barcodes.
- [ ] 1.6 Add migration and unit tests for clean backfill, duplicate preflight failure, nullable values, rollback safety, and equivalent canonical collision behavior on SQLite.

## 2. Shared Barcode Integrity Services

- [ ] 2.1 Implement a shared barcode identity reservation service that atomically reserves, replaces, and releases registry ownership and translates unique-key races into duplicate-domain results.
- [ ] 2.2 Implement conflict lookup that resolves a duplicate identity to the owning product or product conversion and returns product code/name and conversion-unit context for user-facing errors.
- [ ] 2.3 Implement the narrow product barcode assignment service with authorization, product row locking, stale-value comparison, cross-namespace validation, no-op detection, product-only mutation, and transactional audit history.
- [ ] 2.4 Add service tests for initialization, replacement, no-op, leading-zero preservation, supported alphanumeric values, product conflicts, conversion conflicts, stale state, rollback, authorization, and concurrent reservation failure.

## 3. Existing Product Barcode Mutation Integration

- [ ] 3.1 Update full product creation and update flows to validate and reserve base-unit barcodes through the shared identity service without changing unrelated price, tax, stock, media, or idempotency behavior.
- [ ] 3.2 Update product quick-add barcode persistence to use the shared identity service while preserving its existing reset and product-selected behavior.
- [ ] 3.3 Update product unit-conversion create, update, replacement, and deletion paths to reserve or release barcode identities through the shared service.
- [ ] 3.4 Update Product-module barcode validation messages so cross-namespace conflicts identify the owning product and unit consistently across full forms, quick add, and the new workspace.
- [ ] 3.5 Add regression tests covering product create/edit, quick add, conversion create/edit/delete, transaction rollback, and preservation of existing product price and unit-alignment behavior.

## 4. Authorization, Routing, and Navigation

- [ ] 4.1 Register the `products.barcodes.manage` permission in the existing permission configuration and role/permission synchronization conventions without granting it implicitly through `products.edit` or `barcodes.print`.
- [ ] 4.2 Add a Product-module barcode initialization route and page shell protected by authentication, setting context, and the new permission.
- [ ] 4.3 Add a conditional navigation entry for the workspace using existing CoreUI menu and active-route conventions.
- [ ] 4.4 Add feature tests proving authorized access/save, unauthorized route and action rejection, menu visibility, and independence from product-edit and barcode-print permissions.

## 5. Livewire Barcode Initialization Workflow

- [ ] 5.1 Create the Livewire 3 workspace component with explicit searching, ready-to-scan, review-initialize, review-replace, saving, success, and recoverable-error states.
- [ ] 5.2 Implement debounced product-name/product-code search under existing product visibility rules, including stock-managed and stockless products, current barcode/base-unit metadata, missing-barcode prioritization, and an uninitialized-only filter.
- [ ] 5.3 Implement product selection and original-barcode snapshot handling, including browser events that highlight and focus the text-based barcode input.
- [ ] 5.4 Implement scanner Enter capture and cancellation so candidate review never persists automatically, scanner framing is removed without numeric coercion, and cancellation refocuses the scan field.
- [ ] 5.5 Generate a Code 128 SVG preview from a valid captured candidate using the installed Milon barcode library, display the complete authoritative value, and block confirmation when preview generation fails.
- [ ] 5.6 Implement initialize and replacement confirmation actions through the assignment service, including old/new replacement context, no-op feedback, duplicate ownership messages, stale-state refresh, and corrective focus events.
- [ ] 5.7 Track a bounded recent-success list and per-session saved count, then clear selected/candidate/search state and focus product search after each successful assignment.

## 6. Workspace User Interface

- [ ] 6.1 Build the responsive Bootstrap/CoreUI workspace view with progress summary, search controls, result status badges, selected-product identity/base-unit card, and a prominent scanner-ready input state.
- [ ] 6.2 Build the review panel with candidate text, safe barcode preview rendering, initialization confirmation, and an amber old-versus-new replacement warning with explicit replacement labeling.
- [ ] 6.3 Add keyboard interactions for result selection, scan capture, deliberate confirmation, cancellation, and repeat-loop focus while preserving usable mouse and touch controls.
- [ ] 6.4 Add Indonesian loading, empty, success, duplicate, invalid-preview, authorization-safe, stale-state, and unexpected-error messages consistent with existing localization conventions.
- [ ] 6.5 Verify the interface remains usable on desktop scanner stations and tablet widths without introducing camera-scanner or POS workflow dependencies.

## 7. Workflow and Regression Verification

- [ ] 7.1 Add Livewire tests for catalog search, uninitialized filtering, stockless visibility, result metadata, selection state, focus events, scanner capture without save, cancellation, and preview state.
- [ ] 7.2 Add Livewire tests for confirmed initialization, confirmed replacement, no-op, duplicate product/conversion rejection, stale update rejection, retained correction context, recent activity, counter updates, and return-to-search focus.
- [ ] 7.3 Add focused regression tests confirming existing POS exact-barcode resolution and Print Barcode rendering continue to consume stored product barcodes after assignments and replacements.
- [ ] 7.4 Run focused Product and Livewire feature tests with `php artisan test` filters and resolve failures attributable to the change.
- [ ] 7.5 Run `composer test:fresh-sqlite` to verify migrations, registry backfill, Product-module integrations, and broader regression safety.
- [ ] 7.6 Perform scanner-station UAT for the repeated search-select-scan-preview-confirm loop, leading-zero barcode, alphanumeric barcode, duplicate feedback, replacement warning, keyboard-only use, and tablet layout.
