## ADDED Requirements

### Requirement: The system exports a CV TIGA NUSA COMPUTER Excel price list
The system SHALL provide a Product-module console command that produces a native `.xlsx` workbook containing the product price list for the setting whose company name is exactly `CV TIGA NUSA COMPUTER`.

#### Scenario: Default-path export succeeds
- **WHEN** exactly one CV TIGA NUSA COMPUTER setting exists and an operator runs the command without a path option
- **THEN** the system SHALL create `product_prices_tiga_nusa_export.xlsx` in `storage/app`
- **AND** the system SHALL report the destination path and number of exported product rows

#### Scenario: Custom-path export succeeds
- **WHEN** an operator runs the command with a writable `--path` value
- **THEN** the system SHALL write the native Excel workbook to that path
- **AND** the system SHALL report the supplied destination path

### Requirement: The export is strictly scoped to CV TIGA NUSA COMPUTER prices
The system SHALL resolve the target setting at command execution by its exact company name and SHALL use only the `product_prices` row belonging to that resolved setting for every exported price value.

#### Scenario: A product has prices in multiple settings
- **WHEN** a product has different sale, Tier 1, or Tier 2 prices for CV TIGA NUSA COMPUTER and another setting
- **THEN** the workbook SHALL contain only the CV TIGA NUSA COMPUTER price values
- **AND** the workbook SHALL NOT disclose price values from the other setting

#### Scenario: The target setting cannot be resolved uniquely
- **WHEN** zero or more than one setting has the exact company name CV TIGA NUSA COMPUTER
- **THEN** the command SHALL fail with an actionable error
- **AND** the command SHALL NOT create or overwrite an export file

### Requirement: The workbook includes every product in a simple price-list layout
The workbook SHALL include one row for every product, ordered ascending by product name, with exactly the columns `Nama Produk`, `Harga Jual`, `Harga Tier 1`, and `Harga Tier 2`.

#### Scenario: A product has a CV TIGA NUSA COMPUTER price row
- **WHEN** a product has a price row for CV TIGA NUSA COMPUTER
- **THEN** its row SHALL show the stored sale price, Tier 1 price, and Tier 2 price in numeric Excel cells

#### Scenario: A product has no CV TIGA NUSA COMPUTER price row
- **WHEN** a product has no price row for CV TIGA NUSA COMPUTER
- **THEN** the product SHALL still appear in its alphabetical position
- **AND** its three price cells SHALL be blank

#### Scenario: An operator opens the generated workbook
- **WHEN** the workbook is opened in a spreadsheet application
- **THEN** it SHALL show CV TIGA NUSA COMPUTER and a price-list title above the column headers
- **AND** the price columns SHALL use a numeric price format
- **AND** the column header row SHALL be frozen and filterable

### Requirement: The command prevents accidental overwrite
The command SHALL follow the Product barcode exporter's overwrite behavior by requiring confirmation before replacing an existing destination file, unless `--force` is supplied.

#### Scenario: An existing file is not forced
- **WHEN** the destination file already exists and the operator declines overwrite confirmation
- **THEN** the command SHALL leave the existing file unchanged
- **AND** the command SHALL report that the export was cancelled

#### Scenario: An existing file is forced
- **WHEN** the destination file already exists and the operator supplies `--force`
- **THEN** the command SHALL replace the file with the current CV TIGA NUSA COMPUTER workbook
