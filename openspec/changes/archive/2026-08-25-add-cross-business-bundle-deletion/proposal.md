## Why

Bundle deletion currently relies on a native browser confirmation dialog and can remove only the active-business copy, even when the bundle belongs to a future-created replica group. Administrators need an application-native confirmation experience with an explicit, safe option to remove the same bundle creation group from every business.

## What Changes

- Replace the Product Bundle native `confirm()` deletion prompt with an Indonesian Bootstrap confirmation modal.
- For grouped bundles, add an unchecked `Hapus paket ini dari semua bisnis` checkbox to the modal.
- Keep deletion local to the active-business bundle when the checkbox is not selected.
- When selected, atomically delete every existing bundle copy carrying the route bundle's persisted non-null replica-group identity.
- For historical ungrouped bundles, omit the actionable cross-business checkbox and explain that deletion affects only the current business.
- Preserve existing delete permission, nested product ownership, and active-setting authorization checks.
- Do not create, backfill, infer, or repair replica groups as part of deletion.

## Capabilities

### New Capabilities

<!-- None. This change extends the established bundle setting-scope contract. -->

### Modified Capabilities

- `bundle-setting-scope`: Add explicit, optional cross-business deletion for grouped bundle copies while preserving local deletion as the default.

## Impact

- Product detail bundle deletion controls and JavaScript/modal behavior change.
- Product Bundle deletion request validation and controller transaction behavior change.
- Focused Product Bundle feature coverage expands to modal rendering, local/group deletion, lineage security, authorization, and rollback.
- No schema change, historical-data rewrite, Sales/POS behavior change, or full-suite verification is required.
