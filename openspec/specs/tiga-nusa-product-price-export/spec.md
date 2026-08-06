## MODIFIED Requirements

### Requirement: The system exports a dual-company Excel price workbook
The system SHALL provide the existing Product-module console command that produces a native `.xlsx` workbook with a CV TIGA NUSA COMPUTER worksheet and a CV TOP IT INTERNUSA worksheet, resolving each setting by its exact company name.

#### Scenario: Default-path export succeeds
- **WHEN** exactly one setting exists for each required company and an operator runs the command without a path option
- **THEN** the system SHALL create `product_prices_tiga_nusa_export.xlsx` in `storage/app`
- **AND** the system SHALL report the destination path and number of exported product rows for each company

#### Scenario: Custom-path export succeeds
- **WHEN** an operator runs the command with a writable `--path` value and both required settings resolve uniquely
- **THEN** the system SHALL write the native Excel workbook to that path
- **AND** the system SHALL report the supplied destination path

### Requirement: Each worksheet is strictly scoped to its company prices
The system SHALL resolve CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA at command execution by exact company name and SHALL use only the `product_prices` row belonging to the worksheet's resolved setting for each selling, tier, last-purchase, and average-purchase price value.

#### Scenario: A product has prices in multiple settings
- **WHEN** a product has different prices for CV TIGA NUSA COMPUTER and CV TOP IT INTERNUSA
- **THEN** the CV TIGA NUSA COMPUTER worksheet SHALL contain only the CV TIGA NUSA COMPUTER price values
- **AND** the CV TOP IT INTERNUSA worksheet SHALL contain only the CV TOP IT INTERNUSA price values

#### Scenario: A target setting cannot be resolved uniquely
- **WHEN** zero or more than one setting has either required exact company name
- **THEN** the command SHALL fail with an actionable error identifying the unresolved company
- **AND** the command SHALL NOT create or overwrite an export file

### Requirement: The workbook includes every product in two simple price-list worksheets
The workbook SHALL contain CV TIGA NUSA COMPUTER as its first worksheet and CV TOP IT INTERNUSA as its second worksheet. Each worksheet SHALL include one row for every product, ordered ascending by product name, with exactly the columns `Nama Produk`, `Harga Jual`, `Harga Tier 1`, `Harga Tier 2`, `Harga Beli Terakhir`, and `Harga Beli Rata-rata`.

#### Scenario: A product has a company price row
- **WHEN** a product has a price row for the worksheet's company
- **THEN** its row SHALL show the stored sale price and Tier 1 and Tier 2 prices in numeric Excel cells
- **AND** its row SHALL show resolved last and average purchase prices in numeric Excel cells when available

#### Scenario: A product has no company price row
- **WHEN** a product has no price row for the worksheet's company
- **THEN** the product SHALL still appear in its alphabetical position
- **AND** its selling and tier price cells SHALL be blank

#### Scenario: An operator opens the generated workbook
- **WHEN** the workbook is opened in a spreadsheet application
- **THEN** each worksheet SHALL show its company name and a price-list title above the column headers
- **AND** all price columns SHALL use a numeric price format
- **AND** the column header row SHALL be frozen and filterable

## ADDED Requirements

### Requirement: Purchase-cost columns use the configured fallback chain
For each worksheet product row, the system SHALL use a positive company-scoped `last_purchase_price` for `Harga Beli Terakhir`; when it is null or zero, it SHALL use the product's positive `purchase_price`. The system SHALL use a positive company-scoped `average_purchase_price` for `Harga Beli Rata-rata`; when it is null or zero, it SHALL use the resolved `Harga Beli Terakhir` value. If no positive value is available from the applicable chain, the relevant purchase-cost cell SHALL be blank.

#### Scenario: Average purchase price falls back to last purchase price
- **WHEN** a company's product price row has a null or zero average purchase price and a positive last purchase price
- **THEN** the worksheet's `Harga Beli Rata-rata` cell SHALL equal that last purchase price

#### Scenario: Missing last purchase price falls back to product purchase price
- **WHEN** a company's product price row has a null or zero last purchase price and the product has a positive purchase price
- **THEN** the worksheet's `Harga Beli Terakhir` and `Harga Beli Rata-rata` cells SHALL equal the product purchase price

#### Scenario: No purchase-cost value is available
- **WHEN** the relevant company-scoped value and all of its fallbacks are null or zero
- **THEN** the affected purchase-cost cell SHALL be blank
