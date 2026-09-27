# terminal-harga-product-search Specification

## Purpose
TBD - created by archiving change terminal-harga-free-text-search-parity. Update Purpose after archive.
## Requirements
### Requirement: Terminal Harga SHALL tokenize free-text product search like Product List
Terminal Harga SHALL treat free-text product search input as whitespace-separated tokens, require every token to match, and allow each token to match product name, product code, category name, or brand name.

#### Scenario: Multi-word partial product name search
- **WHEN** a Terminal Harga user searches for `SAM GAL FO`
- **THEN** Terminal Harga SHALL include a product named `SAMSUNG GALAXY FOLD` when that product has an active-setting product price row

#### Scenario: Tokens may match different descriptive fields
- **WHEN** a Terminal Harga user enters multiple free-text tokens where one token matches product name and another token matches brand or category
- **THEN** Terminal Harga SHALL include products where every token matches at least one of product name, product code, category name, or brand name

### Requirement: Terminal Harga SHALL preserve scanner-code product search
Terminal Harga SHALL continue matching product barcode, unit conversion barcode, and product serial number using the trimmed whole search input rather than tokenizing those scanner-code fields.

#### Scenario: Product barcode scan still finds product
- **WHEN** a Terminal Harga user scans or submits a complete product barcode
- **THEN** Terminal Harga SHALL include the matching product when that product has an active-setting product price row

#### Scenario: Conversion barcode scan still finds product
- **WHEN** a Terminal Harga user scans or submits a complete unit conversion barcode
- **THEN** Terminal Harga SHALL include the parent product when that product has an active-setting product price row

#### Scenario: Serial number scan still finds product
- **WHEN** a Terminal Harga user scans or submits a complete product serial number
- **THEN** Terminal Harga SHALL include the serialized product when that product has an active-setting product price row

### Requirement: Terminal Harga SHALL preserve existing browsing constraints
Terminal Harga product search SHALL preserve active-setting product price filtering, customer-tier contextual price display, pagination reset on product search changes, scanner submit handling, and search-input refocus behavior.

#### Scenario: Product without active-setting price remains hidden
- **WHEN** a Terminal Harga user searches for a product whose descriptive fields or scanner-code fields match the search input
- **AND** the product does not have a product price row for the active setting
- **THEN** Terminal Harga SHALL NOT include that product in the results

#### Scenario: Search submit remains scanner-friendly
- **WHEN** a Terminal Harga user submits a product search
- **THEN** Terminal Harga SHALL reset product pagination to the first page
- **AND** Terminal Harga SHALL refocus the product search input after the search is processed

