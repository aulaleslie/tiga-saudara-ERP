## Context

Product bundles currently use a setting-owned header and first-level component rows. Creation writes only the active setting, update replaces every component row, component identity is not unique within a bundle, and the update action checks setting ownership without checking the route product. Product foreign keys currently cascade bundle definitions away when a referenced product is deleted.

Production has no bundle definitions, so schema constraints can be hardened without a data reconciliation or bundle backfill. Bundle administration is performed by a small coordinated administrator group, making existing last-write-wins editing acceptable. Sales and POS already treat bundle items as first-level snapshots and must retain that model.

## Goals / Non-Goals

**Goals:**

- Make newly authored definitions available as independent copies in every current setting.
- Enforce distinct first-level component identity while explicitly supporting self-components.
- Add a per-setting administrative enabled state.
- Close nested-route ownership and destructive product-deletion integrity gaps.
- Preserve existing Sales/POS one-level bundle behavior and historical snapshots.

**Non-Goals:**

- Runtime filtering by `is_active`, `active_from`, or `active_to` in Sales, POS, drafts, approval, checkout, receipts, returns, or reports; that belongs to Sequence 2.
- Shared replica identity, synchronized cross-setting edits, or coordinated group actions.
- Automatic bundle backfill for settings created later.
- Existing-data bundle migration, because no bundle definitions exist in production.
- Optimistic locking or other concurrent edit protection.
- Recursive/nested bundle expansion.

## Decisions

### Create independent copies in one transaction

The store action will resolve every row in `settings`, then create one header and an identical set of component rows for each setting inside the existing database transaction. All settings are targets; there is no active-setting filter and no setting-selection UI. Any failure rolls back the entire operation.

The copies will have independent primary keys and no group UUID. After creation, existing setting-scoped edit and delete behavior remains local to one copy. A future backfill or group-management capability can introduce explicit lineage if it becomes necessary.

Alternative considered: create only the active-setting copy and lazily clone later. Rejected because current businesses must receive the definition together and partial availability is undesirable.

### Store an administrative `is_active` flag on each header

Add a non-null boolean `is_active` column with a database default of true and a boolean model cast. Creation writes the same enabled default to every copy. The setting-scoped edit surface may change only its own copy.

The Product administration list will show the status so disabled definitions remain discoverable and manageable. Product administration will not hide disabled bundles. Sales/POS runtime resolvers will not be changed in this sequence; consistent lifecycle enforcement will be designed in Sequence 2.

Alternative considered: use only `active_from` and `active_to` as a disable mechanism. Rejected because operational disablement should not require changing scheduled dates and must be reversible without losing the intended period.

### Enforce one component row per product

Controller validation will reject repeated `items.*.product_id` values, and the database will add a unique constraint on `(bundle_id, product_id)`. Quantity remains the sole way to express multiple units of the same component. The transaction preserves the previous composition if update validation or persistence fails.

Alternative considered: merge duplicate submitted rows by summing quantities. Rejected because silently combining potentially different informational prices is ambiguous.

### Preserve first-level composition and allow self-components

No validation will reject a component because it equals `parent_product_id` or because it owns bundles. Sales and POS continue iterating only the selected header's direct `items`; they will not invoke bundle resolution for component products.

A self-component therefore produces separate parent and component demand. With parent quantity one and self-component quantity one, stock processing deducts two units. Focused regression coverage will protect this deliberate behavior.

Alternative considered: prohibit self-components and bundle-capable components. Rejected because the supported domain treats component rows as direct first-level demand regardless of other bundle definitions.

### Enforce both nested-route identities before mutation

Edit, update, and delete must require both `parent_product_id === route product id` and `setting_id === active setting id`, returning 404 before mutation when either identity differs. The update action will be aligned with the checks already present on edit and delete.

Alternative considered: rely on implicit route binding. Rejected because current independent bindings do not scope `{bundle}` through `{product}`.

### Replace destructive product cascades with deletion restrictions

Foreign keys from `product_bundles.parent_product_id` and `product_bundle_items.product_id` will restrict product deletion rather than cascading definitions away. The Product deletion action will also detect the two relationships before deletion and return clear administrator feedback. Bundle deletion will continue cascading its own component rows.

Database restrictions provide integrity for deletion paths outside the controller, while the application guard provides an actionable message. Migration implementation must preserve MySQL/MariaDB production behavior and fresh SQLite test compatibility.

Alternative considered: controller-only protection. Rejected because other application or maintenance paths could still delete a referenced product and silently alter definitions.

### Preserve last-write-wins updates

The existing transactional header update followed by delete-and-recreate of component rows will remain. No version token, conflict response, or row-level edit lock will be introduced because the small administrator group coordinates bundle maintenance.

## Risks / Trade-offs

- [Writing into settings the actor does not normally administer] → Treat bundle replication as one authorized system-managed create operation; subsequent administration remains permission- and setting-scoped.
- [A large number of settings increases create work] → Use one bounded transaction; the current number of settings is small and bundle creation is infrequent.
- [Disabled state exists before runtime enforcement] → Keep disabled bundles visible in administration and explicitly defer Sales/POS eligibility behavior to Sequence 2.
- [Self-components can surprise inventory operators] → State the additive demand contract in the spec and cover both Sales and POS inventory behavior with focused tests.
- [Foreign-key alteration differs across database engines] → Verify focused migrations/tests with SQLite and run the project's fresh SQLite suite before completion.
- [Independent copies can drift] → Drift is intentional; group identity and synchronized management are documented future work.

## Migration Plan

1. Add `product_bundles.is_active` as non-null with default true.
2. Add the unique `(bundle_id, product_id)` constraint to `product_bundle_items`.
3. Replace product-reference cascade actions with restrictive foreign keys while preserving bundle-to-item cascade deletion.
4. Deploy controller, model, administration UI, and tests with the schema changes.
5. Rollback restores prior foreign-key actions, removes the uniqueness constraint, and removes `is_active`; no bundle data backfill is required.

## Open Questions

None. Runtime lifecycle behavior and future replica grouping/backfill are intentionally assigned to later changes.
