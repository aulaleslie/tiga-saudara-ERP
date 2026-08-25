## Why

Bundle creation already produces one setting-owned copy for every existing business, but those copies have no shared creation identity and their prices can only be maintained one business at a time. Administrators need an explicit, opt-in way to apply an edited bundle sale price to every copy originating from the same future creation operation, without changing historical data or synchronizing other bundle fields.

## What Changes

- Assign one shared replica-group identity to every per-business bundle copy produced by a new bundle creation operation.
- Add an Indonesian edit-form checkbox, `Terapkan harga ke semua bisnis`, next to `Harga Jual Paket` for grouped bundles.
- When the checkbox is selected, atomically update `bundle_sale_price` on every existing bundle copy with the same replica-group identity.
- Preserve the existing setting-local update behavior when the checkbox is not selected.
- Keep name, description, dates, enabled state, composition, and informational component prices setting-local regardless of the checkbox.
- Leave existing bundle records ungrouped and do not backfill them; they cannot use cross-business price synchronization.
- Continue excluding settings created after the original bundle creation from automatic copy creation or later synchronization.

## Capabilities

### New Capabilities

<!-- None. This change refines the existing setting-scope contract. -->

### Modified Capabilities

- `bundle-setting-scope`: Introduce future-only replica-group identity and an explicit exception to per-setting independence for synchronized bundle sale-price edits.

## Impact

- Product Bundle schema and model gain nullable replica-group identity storage.
- Product Bundle creation, edit rendering, validation, and transactional update behavior change.
- The bundle edit Blade view gains an opt-in Indonesian checkbox and legacy-record guidance.
- Product Bundle feature tests must cover lineage assignment, scoped and propagated updates, atomic rollback, authorization boundaries, and ungrouped historical records.
- Sales, POS, historical transaction snapshots, component composition, and product prices are not changed.
