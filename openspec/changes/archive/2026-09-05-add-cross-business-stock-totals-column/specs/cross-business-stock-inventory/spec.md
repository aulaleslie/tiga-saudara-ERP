## ADDED Requirements

### Requirement: System SHALL display a cross-business total Good and Bad quantity per product row
Immediately after the "Merek" (Brand) column, the system SHALL display two additional columns, "Total Bagus" and "Total Rusak", showing each product's Good and Bad quantity summed across all currently selected/visible businesses. These totals SHALL remain unaffected by whether any individual business's columns are collapsed to a subtotal or expanded to per-location detail. The Excel export SHALL mirror these two columns in the same position, vertically merged across all header tiers, consistent with the existing Produk/Kategori/Merek columns.

#### Scenario: Totals sum across multiple selected businesses
- **WHEN** a product has Good quantity of 5 in Business A and 3 in Business B, both currently selected
- **THEN** the "Total Bagus" column for that product's row shows 8

#### Scenario: Totals unaffected by expand/collapse toggle
- **WHEN** a user toggles a business's columns from collapsed to expanded (or back)
- **THEN** the "Total Bagus" and "Total Rusak" values for every product row remain unchanged

#### Scenario: Totals reflect only currently selected businesses
- **WHEN** a user deselects a business from the business filter
- **THEN** the "Total Bagus" and "Total Rusak" columns recalculate to exclude that business's quantities

#### Scenario: Excel export includes the same total columns
- **WHEN** a user exports the report to Excel
- **THEN** the exported file contains "Total Bagus" and "Total Rusak" columns positioned immediately after "Merek", with values matching the on-screen totals for the applied filters
