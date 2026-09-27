# product-ean13-barcode-generation Specification

## Purpose
TBD - created by archiving change generate-product-ean13-barcodes. Update Purpose after archive.
## Requirements
### Requirement: Product barcode normalization command is available
The Product module SHALL register an Artisan command named `product:generate-ean13-barcodes` that evaluates every existing product base-unit barcode, without filtering by stock-management state or setting.

#### Scenario: Command evaluates the complete catalog
- **WHEN** the command runs against products from multiple settings and with mixed stock-management states
- **THEN** it SHALL evaluate every product exactly once
- **AND** it SHALL NOT modify any product unit-conversion barcode

### Requirement: Existing valid EAN-13 barcodes are preserved
The command SHALL regard a barcode as valid EAN-13 only when it consists of exactly 13 decimal digits and its final digit satisfies the EAN-13 modulo-10 check digit rule. It SHALL preserve the value of a valid EAN-13 barcode and set that product's `product_barcode_symbology` to `EAN13`.

#### Scenario: Valid EAN-13 barcode is retained
- **WHEN** a product holds a 13-digit barcode with a correct EAN-13 check digit
- **THEN** the command SHALL leave the barcode value unchanged
- **AND** it SHALL persist `EAN13` as the product barcode symbology

#### Scenario: Thirteen digits with an invalid check digit are not retained
- **WHEN** a product holds 13 decimal digits whose final digit fails the EAN-13 check digit rule
- **THEN** the command SHALL treat the barcode as invalid
- **AND** it SHALL generate a replacement EAN-13 barcode for that product

### Requirement: Invalid product barcodes are replaced with internal EAN-13 values
For a blank, non-numeric, incorrectly sized, or checksum-invalid product barcode, the command SHALL generate a 13-digit EAN-13 value. Its first three digits SHALL be from `200` through `299`, its next nine digits SHALL be randomized, and its final digit SHALL be the calculated EAN-13 check digit. The command SHALL save `EAN13` as the product barcode symbology with the replacement value.

#### Scenario: Blank barcode is initialized
- **WHEN** a product barcode is null, empty, or whitespace-only
- **THEN** the command SHALL replace it with a valid EAN-13 barcode beginning with a value from `200` through `299`
- **AND** it SHALL set the product barcode symbology to `EAN13`

#### Scenario: Legacy arbitrary barcode is replaced
- **WHEN** a product barcode includes letters, punctuation, or an incorrect number of digits
- **THEN** the command SHALL replace it with a valid internal EAN-13 barcode
- **AND** it SHALL not retain the legacy barcode value

### Requirement: Generated and preserved identities remain unique and consistent
The command SHALL ensure each generated barcode is unique across both product base-unit and product unit-conversion barcode identities. For every successful generated replacement or valid-EAN-13 reconciliation, it SHALL keep `products.barcode`, `products.product_barcode_symbology`, and the product-owned `barcode_identities` row consistent within one database transaction.

When replacing an invalid product barcode, the command SHALL remove that product's identity for the replaced value and create the identity for the generated value in the same transaction.

#### Scenario: Generated candidate collides with a conversion barcode
- **WHEN** a generated candidate already belongs to a product unit conversion
- **THEN** the command SHALL not assign that candidate to the product
- **AND** it SHALL generate and validate another candidate before updating the product

#### Scenario: Legacy valid EAN-13 lacks an identity row
- **WHEN** a product holds a valid EAN-13 barcode and no matching product-owned barcode identity exists
- **THEN** the command SHALL create the matching identity row while preserving the barcode value
- **AND** it SHALL set the symbology to `EAN13` in the same transaction

#### Scenario: Replacing a legacy barcode replaces its identity
- **WHEN** a product with an invalid barcode has a product-owned identity for that legacy value
- **THEN** the command SHALL remove the legacy identity and create a product-owned identity for the generated EAN-13 value in the same transaction
- **AND** no identity for the legacy value SHALL remain owned by that product

#### Scenario: Identity persistence fails for a product
- **WHEN** reserving or reconciling a product barcode identity fails
- **THEN** the command SHALL roll back all barcode and symbology changes for that product
- **AND** it SHALL continue evaluating subsequent products

### Requirement: Normalization command supports safe preview and operational reporting
The command SHALL accept a `--dry-run` option that performs equivalent eligibility, generation, uniqueness, and outcome evaluation without changing product or barcode-identity data. On completion, it SHALL report outcome counts, including preserved valid barcodes, generated replacements, registry repairs, conflicts/errors, and whether the run was a dry run.

#### Scenario: Dry run makes no persistence changes
- **WHEN** the command runs with `--dry-run`
- **THEN** it SHALL NOT modify a product barcode or barcode symbology
- **AND** it SHALL NOT create, replace, or delete any barcode identity row

#### Scenario: Repeated successful execution is idempotent
- **WHEN** the command completes successfully and runs again without changes to the catalog
- **THEN** it SHALL preserve each product's existing valid EAN-13 barcode
- **AND** it SHALL not generate a different barcode for those products

