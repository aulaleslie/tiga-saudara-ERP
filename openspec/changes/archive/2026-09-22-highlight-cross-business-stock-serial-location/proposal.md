# Proposal

## Why

An exact serial-number search in Stok Persediaan Lintas Bisnis currently returns the owning product but does not show which visible business, location, or stock condition contains that serial. A lightweight stabilo-style marker will let users locate the relevant stock cell immediately without changing any existing report information or behavior.

## What Changes

- Resolve exact serial-number searches to the matching serial's current business, location, and operational Good/Bad condition.
- Add a soft yellow marker to the matching Good or Bad stock cell: the business subtotal cell while collapsed and the exact location cell while expanded.
- Remove the marker when the search changes, is cleared, or is not an exact serial-number match.
- Do not mark a stock cell for serials that are not currently in an operational Good or Bad inventory state.
- Preserve all existing values, filters, dialogs, pagination, and search-result behavior.
- Keep the marker as an on-screen aid only; Excel exports remain unchanged.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-stock-inventory`: Exact serial-number search will visually identify the matching operational stock cell while preserving the report's existing data and interactions.

## Impact

- Affected query/view-model logic: `app/Services/Reports/CrossBusinessStockInventoryQueryService.php`.
- Affected Livewire presentation: `resources/views/livewire/reports/cross-business-stock-inventory.blade.php` and, only if needed for state propagation, `app/Livewire/Reports/CrossBusinessStockInventory.php`.
- Affected focused verification: `tests/Feature/Reports/CrossBusinessStockInventoryFeatureTest.php`.
- No database migration, public API, permission, dependency, or Excel export changes.
