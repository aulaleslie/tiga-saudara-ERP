## ADDED Requirements

### Requirement: System SHALL gate the cross-business stock inventory menu on the `inventory.view_remaining_stock` permission
Only users holding `inventory.view_remaining_stock` SHALL be able to access the menu entry and its underlying report route/component.

#### Scenario: Permitted user accesses the menu
- **WHEN** a user holding `inventory.view_remaining_stock` navigates to the cross-business stock inventory menu
- **THEN** the report loads and renders

#### Scenario: Unpermitted user is denied
- **WHEN** a user lacking `inventory.view_remaining_stock` attempts to access the report route directly
- **THEN** the system responds with a 403 and does not render any stock data

### Requirement: System SHALL scope visible businesses to the acting user's assigned businesses, except for Super Admin
Business options in the filter dropdown and business columns in the table SHALL be restricted to businesses the acting user is assigned to via the `user_setting` pivot (`User::settings()`), except a user holding the `Super Admin` role SHALL see all businesses unconditionally regardless of assignment.

#### Scenario: Non-Super-Admin user sees only assigned businesses
- **WHEN** a user assigned to 2 of 7 businesses loads the report
- **THEN** the business filter dropdown lists only those 2 businesses, and the table shows columns only for those 2 businesses

#### Scenario: Super Admin sees all businesses regardless of assignment
- **WHEN** a user holding the `Super Admin` role loads the report
- **THEN** the business filter dropdown lists all businesses in the system, and the table shows columns for all businesses, regardless of any `user_setting` assignment

#### Scenario: Business filter defaults to all assigned businesses
- **WHEN** a user with any set of assigned businesses loads the report with no prior filter selection
- **THEN** all businesses visible to that user are selected by default

### Requirement: System SHALL display one row per product with Good and Bad stock quantities per business
For each product row, the system SHALL show, per visible business, a Good quantity (`SUM(quantity_tax) + SUM(quantity_non_tax)` across the business's locations) and a Bad quantity (`SUM(broken_quantity_tax) + SUM(broken_quantity_non_tax)` across the business's locations), sourced from `product_stocks` joined through `locations.setting_id`.

#### Scenario: Product with stock in multiple businesses
- **WHEN** a product has stock recorded in `product_stocks` across 3 of the user's visible businesses
- **THEN** the product's row shows a Good and Bad value for each of those 3 businesses

#### Scenario: Product with no stock in a visible business
- **WHEN** a product has no `product_stocks` rows for a given visible business
- **THEN** that business's Good and Bad columns show zero for that product's row

### Requirement: System SHALL allow each business's columns to collapse to a subtotal or expand to per-location detail
By default, a business's Good/Bad columns SHALL show the aggregated subtotal across all of its locations. The user SHALL be able to toggle that business's column group to show Good/Bad broken out per individual `location_id` under that business's `setting_id`, and toggle back to the collapsed subtotal.

#### Scenario: Business with a single location
- **WHEN** a business has exactly one location
- **THEN** its collapsed and expanded views show identical Good/Bad values for that one location

#### Scenario: Expanding a multi-location business
- **WHEN** a user toggles expand on a business with 3 locations
- **THEN** that business's column group is replaced with 3 sets of Good/Bad columns, one per location, and the values sum to the previously shown collapsed subtotal

#### Scenario: Collapsing back
- **WHEN** a user toggles collapse on a previously expanded business
- **THEN** that business's columns return to the single aggregated Good/Bad subtotal

### Requirement: System SHALL surface tax/non-tax composition as an informational tooltip against the business's PKP status
When a business's `is_pkp` flag is true and any portion of the displayed Good or Bad quantity for that business (or, when expanded, that location) originates from `quantity_non_tax`/`broken_quantity_non_tax`, a tooltip SHALL show that non-tax quantity. When `is_pkp` is false and any portion originates from `quantity_tax`/`broken_quantity_tax`, a tooltip SHALL show that tax quantity. This is informational only and SHALL NOT block, alter, or validate the displayed totals.

#### Scenario: PKP business with non-tax stock, collapsed view
- **WHEN** a business with `is_pkp = true` has an aggregated Good quantity where part of the sum comes from `quantity_non_tax`
- **THEN** the collapsed cell shows a tooltip indicator stating the aggregated non-tax quantity across that business's locations

#### Scenario: PKP business with non-tax stock, expanded view
- **WHEN** the same business is expanded to per-location columns
- **THEN** each location's cell shows a tooltip indicator stating that specific location's non-tax quantity, independently of the other locations

#### Scenario: Non-PKP business with tax stock
- **WHEN** a business with `is_pkp = false` has a Good or Bad quantity where part of the sum comes from `quantity_tax` or `broken_quantity_tax`
- **THEN** a tooltip indicator states that tax quantity

#### Scenario: No mismatch present
- **WHEN** a business's displayed quantity has no portion in the "unexpected" tax bucket for its PKP status
- **THEN** no tooltip indicator is shown for that cell

### Requirement: System SHALL provide a serial number lookup dialog for serialized products
For any product where `products.serial_number_required` is true, each non-zero Good/Bad cell SHALL show a button that opens a dialog listing serial numbers scoped to that exact business, location (or all of the business's locations when the business is collapsed), and condition (Good or Bad).

#### Scenario: Opening the dialog from a Good cell
- **WHEN** a user clicks the serial button on a Good cell for a serialized product
- **THEN** the dialog opens listing that product's serials for that business/location scope, filtered to the sellable state: not broken, not in return process, not dispatched, not returned

#### Scenario: Opening the dialog from a Bad cell
- **WHEN** a user clicks the serial button on a Bad cell for a serialized product
- **THEN** the dialog opens listing that product's serials for that business/location scope, filtered to `is_broken = true`

#### Scenario: Non-serialized product has no serial button
- **WHEN** a product has `serial_number_required = false`
- **THEN** no serial button is shown on any of its cells, regardless of quantity

#### Scenario: Zero-quantity cell has no serial button
- **WHEN** a serialized product's Good or Bad quantity for a given business/location is zero
- **THEN** no serial button is shown for that specific cell

### Requirement: System SHALL support a single search box combining product-identity search and exact barcode/serial lookup
The search input SHALL apply two independent match paths, combined with OR: (1) the existing multi-token, order-independent search across product name, product code, barcode, category name, and brand name; (2) an exact-match lookup against `products.barcode` and `product_serial_numbers.serial_number`. A serial number match SHALL resolve to its owning product's row in the table.

#### Scenario: Multi-word product identity search
- **WHEN** a user searches "acer 8 core i3"
- **THEN** products whose name contains all of "acer", "8", "core", and "i3" as substrings, in any order, are returned

#### Scenario: Exact barcode match
- **WHEN** a user searches a value that exactly matches a product's `barcode`
- **THEN** that product's row is returned

#### Scenario: Partial barcode does not match
- **WHEN** a user searches a value that is a substring or prefix of a product's `barcode` but not the full value
- **THEN** that product is not returned via the barcode path (it may still be returned via the product-identity path if the fragment matches name/code/category/brand)

#### Scenario: Exact serial number match resolves to the owning product
- **WHEN** a user searches a value that exactly matches a `product_serial_numbers.serial_number`
- **THEN** the table shows the row for the product that owns that serial number, without automatically opening the serial dialog or highlighting the specific serial

### Requirement: System SHALL support category, brand, and availability filters alongside pagination
The report SHALL provide a category filter (live-search multi-select with implicit OR semantics) and a brand filter (live-search), plus a three-state availability filter (all / available / non-available) and pagination of results.

#### Scenario: Filtering by category
- **WHEN** a user selects 2 categories
- **THEN** products belonging to at least one of the 2 selected categories are shown

#### Scenario: Filtering to available stock only
- **WHEN** a user selects the "available" availability filter
- **THEN** only products with a nonzero Good or Bad quantity in at least one visible business are shown

#### Scenario: Filtering to non-available stock only
- **WHEN** a user selects the "non-available" availability filter
- **THEN** only products with zero Good and Bad quantity across all visible businesses are shown

### Requirement: System SHALL export the report to Excel, always fully expanded to per-location detail
The Excel export SHALL mirror the on-screen table layout (product rows, business/location grouped columns, Good/Bad values) but SHALL always show per-location detail for every business, regardless of whether any business is collapsed or expanded on screen at export time. The export SHALL respect the currently applied search/filter criteria and the user's business-visibility scope.

#### Scenario: Exporting while a business is collapsed on screen
- **WHEN** a user with one business collapsed on screen triggers the Excel export
- **THEN** the exported file shows that business broken out per location, not as a collapsed subtotal

#### Scenario: Export respects business-visibility scope
- **WHEN** a non-Super-Admin user assigned to 2 businesses exports the report
- **THEN** the exported file contains columns only for those 2 businesses' locations

#### Scenario: Export respects applied filters
- **WHEN** a user has applied a category filter and a search term before exporting
- **THEN** the exported rows match exactly the filtered result set shown on screen (before pagination)
