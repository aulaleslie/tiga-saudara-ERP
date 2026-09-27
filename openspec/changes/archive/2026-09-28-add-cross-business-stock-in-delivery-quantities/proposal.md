# Proposal

## Why

The cross-business stock inventory report shows stock already held by each business but does not show approved purchase quantities that remain outstanding, forcing operators to inspect purchases separately before judging future availability. Its raw quantity presentation also does not consistently support fractional quantities or the product-unit conversion style already familiar from the Product list.

## What Changes

- Add a global `Dalam Pengiriman` quantity per product across the currently selected visible businesses.
- Add one `Dalam Pengiriman` quantity per selected business, without allocating or repeating it per location.
- Define in-delivery quantity as the non-negative outstanding quantity on non-archived purchases in `APPROVED` or `RECEIVED PARTIALLY` status after subtracting quantities from approved receiving notes.
- Add a report-wide quantity display selector covering every existing and new quantity cell: decimal display or largest-conversion-plus-base-remainder display consistent with the Product list convention.
- Format fractional decimal quantities with a comma separator and two decimal places, while displaying whole quantities as plain integers without `,00`.
- Apply the selected quantity display to the on-screen report and Excel export while preserving numeric values for decimal-mode Excel cells.
- Keep availability filtering based on actual stock; in-delivery quantities remain informational and are not stock on hand.
- Use bulk, page-scoped aggregation and eager-loaded unit metadata so query count does not grow per product, business, location, or cell; use bounded processing for exports.
- Verify the change with focused automated tests only; no full-suite run is planned.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cross-business-stock-inventory`: Extend the existing report and export with business-scoped outstanding purchase quantities, consistent quantity display modes, and performance requirements.

## Impact

- Cross-business stock inventory query service, row view model, Livewire state, Blade table, and Excel export.
- Purchase and approved receiving-note aggregation using existing purchase, purchase-detail, receiving-note, and receiving-note-detail data.
- Product base-unit and unit-conversion metadata used for display only.
- Focused feature/unit tests for quantity semantics, visibility scope, display formatting, export output, and bounded query behavior.
- No schema migration, stock mutation, purchase lifecycle change, new permission, or dependency is introduced.
