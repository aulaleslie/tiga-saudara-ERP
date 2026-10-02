# Spec Delta

## MODIFIED Requirements

### Requirement: Purchase by product filtering and sorting
The report SHALL support date range, period presets, supplier, tag, product category, and product filters, with configurable tag and category match logic, and sorting by product name, product code, purchase quantity, return quantity, purchase value, and average purchase value. Product suggestions SHALL search the global product catalog regardless of the selected report setting. Selecting a suggestion SHALL collapse the suggestion list while preserving the search term and matching suggestions for display when the search input regains focus. A product SHALL appear at most once in the selected filter.

#### Scenario: Supplier filter narrows rows
- **WHEN** the user selects one or more suppliers and applies filters
- **THEN** only purchases and lifecycle-valid returns for those suppliers are included

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with `Mencakup semua`
- **THEN** only purchases containing every selected tag are included

#### Scenario: Category any-match logic
- **WHEN** the user selects multiple product categories with `Salah satu`
- **THEN** product rows in at least one selected category are included

#### Scenario: Product filter narrows rows
- **WHEN** the user selects one or more products and applies filters
- **THEN** only rows for those selected products are included

#### Scenario: Product suggestions include products from other settings
- **WHEN** the user searches for a product whose legacy setting ID differs from the selected report setting
- **THEN** that product is available as a suggestion
- **AND** applying the filter still limits purchase data to the selected report setting

#### Scenario: Suggestions return on refocus after selection
- **WHEN** the user selects a product from search suggestions and later focuses the search input again
- **THEN** the previous search term and matching suggestions are visible again
- **AND** the selected product is identified as already selected and cannot be selected again

#### Scenario: Repeated product selection stays unique
- **WHEN** the same product is selected more than once through the filter interaction
- **THEN** only one selected entry remains for that product

#### Scenario: Sort by return quantity
- **WHEN** the user sorts by return quantity
- **THEN** rows are ordered by `Qty retur` in the selected direction with deterministic fallback ordering

#### Scenario: Period presets update date range
- **WHEN** the user selects a period preset such as current month or previous month
- **THEN** the pending `Tanggal awal` and `Tanggal akhir` values reflect that period before filters are applied
