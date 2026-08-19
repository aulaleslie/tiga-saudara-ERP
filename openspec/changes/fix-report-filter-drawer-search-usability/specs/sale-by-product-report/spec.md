## MODIFIED Requirements

### Requirement: Report filtering and sorting
The report SHALL support date range, period presets, company scope, customer, tag, product category, and product filters, with configurable tag and category match logic, and sorting by product name, product code, sold quantity, return quantity, sales value, and average sales value.

Filter option lookups for products, product categories, and customers SHALL NOT be scoped by `setting_id`. Master data records `setting_id` as the setting in which the record was created, not the business that owns it, so scoping option lookups by it would offer a set of options that disagrees with the rows the report can return. Only transaction records (`sales`, `sale_returns`) SHALL be scoped by setting.

Filter option lookups SHALL match search input by splitting it on whitespace and requiring every resulting token to match, rather than matching the input as a single literal phrase. Each token MAY match any of the fields searched by that lookup. The product lookup SHALL search both `product_name` and `product_code`, and SHALL present the product code alongside the product name so that products with similar names remain distinguishable.

#### Scenario: Customer filter narrows rows
- **WHEN** the user selects one or more customers and applies filters
- **THEN** only sales and returns for those customers are included

#### Scenario: Tag all-match logic
- **WHEN** the user selects multiple tags with "Mencakup semua"
- **THEN** only sales containing every selected tag are included

#### Scenario: Category any-match logic
- **WHEN** the user selects multiple product categories with "Salah satu"
- **THEN** product rows in at least one selected category are included

#### Scenario: Product filter narrows rows
- **WHEN** the user selects one or more products and applies filters
- **THEN** only rows for those selected products are included

#### Scenario: Sort by return quantity
- **WHEN** the user sorts by return quantity
- **THEN** rows are ordered by `Kuantitas Retur` in the selected direction with deterministic fallback ordering

#### Scenario: Product search finds a product created in another setting
- **WHEN** a sale in the selected company scope references a product whose `products.setting_id` differs from that of the sale
- **AND** the user searches for that product by name in the product filter
- **THEN** the product SHALL be offered as a selectable option
- **AND** selecting it SHALL narrow the report to that product's rows

#### Scenario: Category search is not restricted by setting
- **WHEN** the user searches product categories in the filter panel
- **THEN** matching categories SHALL be offered regardless of the setting in which they were created

#### Scenario: Duplicate category names are listed separately
- **WHEN** more than one category record shares the same name across settings
- **THEN** each matching category SHALL be listed as its own option
- **AND** the options SHALL NOT be merged or deduplicated by name

#### Scenario: Customer search is not restricted by setting
- **WHEN** the user searches customers in the filter panel
- **THEN** matching customers SHALL be offered regardless of the setting recorded on the customer record

#### Scenario: Partial words across tokens match a product
- **WHEN** the user enters a search term whose whitespace-separated tokens each appear in a product's name but which does not appear as a single contiguous phrase
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Every token must match
- **WHEN** the user enters a search term in which one token matches no searched field of a product
- **THEN** that product SHALL NOT be offered as a selectable option

#### Scenario: Token order does not affect matching
- **WHEN** the user enters the same set of tokens in a different order
- **THEN** the same set of products SHALL be offered

#### Scenario: Product code is searchable
- **WHEN** the user enters a term matching a product's `product_code`
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Product options display the product code
- **WHEN** product options are presented in the filter panel
- **THEN** each option SHALL display the product code together with the product name

#### Scenario: Tokens may match across different searched fields
- **WHEN** the user enters a search term in which one token matches a product's name and another matches that product's code
- **THEN** that product SHALL be offered as a selectable option

#### Scenario: Minimum search length still applies
- **WHEN** the user enters a search term shorter than the minimum search length
- **THEN** no options SHALL be offered
