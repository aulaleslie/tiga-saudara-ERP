## 1. Schema and Model Integrity

- [x] 1.1 Add a Product module migration that creates non-null `product_bundles.is_active` with a true default and a unique constraint on `product_bundle_items(bundle_id, product_id)`, with reversible MySQL/MariaDB and SQLite-compatible rollback behavior.
- [x] 1.2 In a Product module migration, replace the product-to-bundle-header and product-to-bundle-item cascade delete actions with restrictive foreign keys while preserving cascade deletion from a bundle header to its component rows.
- [x] 1.3 Add the boolean `is_active` cast to `ProductBundle` and confirm the parent/component relationships used by deletion guards remain available.

## 2. Bundle Authoring and Replication

- [x] 2.1 Extend bundle create/update validation to reject duplicate `items.*.product_id` values while continuing to allow the parent product and bundle-capable products as direct components.
- [x] 2.2 Refactor bundle creation to resolve every available setting and atomically create an independent enabled bundle header plus identical component rows for each setting, rolling back every copy on any failure.
- [x] 2.3 Preserve per-setting independence by keeping update, enabled-state changes, and deletion targeted only at the active-setting copy, without adding replica grouping or propagation.
- [x] 2.4 Add `is_active` controls and status display to Product Bundle administration while keeping disabled bundles visible and manageable on the active setting's Product page.
- [x] 2.5 Align nested-route authorization so edit, update, and delete return 404 before mutation unless both the route product and active setting own the bundle.

## 3. Product Deletion Protection

- [x] 3.1 Add an application-level Product deletion guard for both owned bundle headers and component references, with clear administrator feedback and no partial mutation.
- [x] 3.2 Verify product deletion succeeds after every parent/component bundle reference is explicitly removed and that deleting one bundle copy still cascades only its own component rows.

## 4. Definition and Setting-Scope Tests

- [x] 4.1 Add feature tests proving creation produces one identical, enabled copy per existing setting and that a failure in any copy rolls back all headers and items.
- [x] 4.2 Add create/update tests for distinct components, duplicate rejection with prior-state preservation, and database enforcement of unique bundle/component identity.
- [x] 4.3 Add route-tampering tests proving update rejects a bundle belonging to another product or setting without mutating its header or items.
- [x] 4.4 Add tests proving edit, enable/disable, and delete affect only the selected setting copy and that settings added later receive no automatic copy.
- [x] 4.5 Add deletion tests for parent and component products at both application and database constraint levels, plus successful deletion after references are removed.

## 5. One-Level and Self-Component Regression Coverage

- [x] 5.1 Add authoring tests proving a parent product can be its own component and a component product may own bundles without validation failure.
- [x] 5.2 Add focused normal Sales coverage proving only direct items expand and a stock-managed self-component with quantity one produces parent plus component demand for two units per bundled parent sold.
- [x] 5.3 Add focused POS coverage proving component-owned bundles are not recursively fetched and stock-managed self-component demand deducts the parent and direct component quantities exactly once each.

## 6. Verification

- [x] 6.1 Run focused Product Bundle, Product deletion, normal Sales bundle, and POS bundle tests and resolve regressions without introducing Sequence 2 runtime lifecycle filtering.
- [x] 6.2 Run `composer test:fresh-sqlite` to verify migrations, foreign-key behavior, and the broader Laravel test suite.
- [x] 6.3 Run `openspec validate harden-product-bundle-definitions` and confirm the change remains apply-ready.
