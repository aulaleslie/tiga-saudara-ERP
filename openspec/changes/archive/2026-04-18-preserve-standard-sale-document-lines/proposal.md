## Why

Standard Sales currently allows users to build separate cart rows for the same product, but create/update collapses matching rows into one `sale_details` record before saving. That makes the persisted sales document differ from the rows the user entered and blocks future row-level document semantics such as per-line descriptions.

## What Changes

- Standard Sales product selection on create/edit will continue to create a new cart row every time a product is selected, including when the product, tax, and bundle match an existing row.
- Standard Sales create/update will preserve each cart row as a distinct `sale_details` row.
- Standard Sales create/update will stop aggregating document rows by `product_id`, `tax_id`, and `bundle_id` during save.
- Dispatch behavior will remain fulfillment-oriented: dispatch screens and validation may aggregate saved sale details by product/tax/bundle to determine dispatchable demand.
- POS, sales import, and per-line note/description fields are out of scope.

## Capabilities

### New Capabilities
- `standard-sale-document-lines`: Defines standard Sales cart row creation and persistence semantics for preserving document lines while allowing dispatch aggregation.

### Modified Capabilities

## Impact

- Affected code:
  - `app/Livewire/Sale/ProductCart.php`
  - `app/Livewire/Sale/EditForm.php`
  - `Modules/Sale/Services/SaleService.php`
  - `Modules/Sale/Services/SaleNormalizer.php`
  - `Modules/Sale/Services/SaleCartAggregator.php`
  - `Modules/Sale/Http/Controllers/SaleController.php`
- Affected data:
  - Existing schema can remain unchanged.
  - Future standard Sales documents may contain multiple `sale_details` rows with the same product/tax/bundle.
- Tests should cover duplicate row selection, create persistence, update persistence, bundle parent/child persistence, and dispatch aggregation from preserved rows.
