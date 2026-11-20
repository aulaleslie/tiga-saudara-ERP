# Proposal

## Why

Products are shared across businesses, but category and brand selectors and uniqueness validation still use business ownership. The local database contains duplicate taxonomy records whose product references must be repaired before those copies can be removed.

## What Changes

- **BREAKING**: Remove `setting_id` columns and ownership foreign keys from product brands and categories, and remove their business filters and ownership writes throughout application and import paths.
- Define global brand identity by normalized name and category identity by normalized name within the canonical parent path. Normalize case and whitespace only; preserve spelling and punctuation differences.
- Provide a read-only reconciliation preview followed by explicit execution that keeps the lowest existing ID and its category code, remaps product and category-parent references, and removes redundant records atomically with an audit mapping.
- Enforce durable uniqueness and consistent resolution across forms, quick-add, and imports so concurrent creation cannot recreate duplicates.
- Verify the affected behavior with focused tests and an isolated MySQL reconciliation check.

## Capabilities

### New Capabilities

- `global-product-taxonomy`: Shared brand/category identity, selection, creation, hierarchy, and audited duplicate reconciliation.

### Modified Capabilities

None. Existing product canonical identity requirements concern products; this change introduces taxonomy identity without changing product matching.

## Impact

Affects `Modules/Product` entities, controllers, dropdowns, quick-add components, schema migrations, import jobs, factories/seeders, and focused tests. Expense categories remain separate. Product IDs, stock, prices, transaction records, and historical audit payloads are preserved. No new external dependency is required. Production cleanup requires a reviewed preview and a coordinated deployment; this proposal does not execute cleanup.
