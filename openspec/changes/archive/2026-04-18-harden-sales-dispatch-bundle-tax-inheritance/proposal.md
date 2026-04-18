## Why

Sales dispatch currently derives bundle component tax context from `sale_bundle_items`, but those records do not persist a `tax_id`. In practice this causes taxed parent lines to produce non-tax bundle dispatch keys, so non-serial bundle components can display or validate against the wrong stock bucket.

## What Changes

- Enforce a single rule for sales dispatch bundle components: tax context must inherit from the parent `sale_details` row.
- Update dispatch aggregation and validation key construction so bundle component keys use inherited parent tax context instead of nullable bundle-item tax context.
- Ensure non-serial bundle stock display and server-side stock validation are aligned to the inherited tax bucket (`quantity_tax` for taxed parents, `quantity_non_tax` for non-tax parents).
- Add regression coverage for both taxed and non-taxed parent bundle dispatch scenarios.

## Capabilities

### New Capabilities
- `sales-dispatch-bundle-tax-inheritance`: Sales dispatch must inherit bundle component tax context from the parent sale line so stock bucket resolution is deterministic and consistent.

### Modified Capabilities
- `sale-tax-assignment`: Dispatch-time tax bucket selection must preserve parent sale-line tax intent for bundle components.

## Impact

- Affected code:
  - `Modules/Sale/Http/Controllers/SaleController.php` (bundle aggregation + validation stock path)
  - `app/Livewire/Sale/DispatchSaleTable.php` (displayed stock coherence with dispatch key tax context)
  - Sales dispatch feature tests under `Modules/Sale/Tests/Feature/`
- No external API contract changes.
- No new infrastructure dependencies.
