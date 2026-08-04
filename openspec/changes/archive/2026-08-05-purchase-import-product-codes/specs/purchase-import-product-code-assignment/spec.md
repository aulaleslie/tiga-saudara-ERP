## ADDED Requirements

### Requirement: Purchase imports accept optional product codes
The purchase CSV importer SHALL recognize `Kode Produk` as an optional product-code column, retain its value in the staged import row, and make it available when resolving or creating the row product. The importer SHALL continue accepting rows when the column is absent or its value is blank.

#### Scenario: Indonesian product-code header is supplied
- **WHEN** a purchase CSV contains a `Kode Produk` column with a product row
- **THEN** the staged row SHALL retain that row's product-code value for purchase processing

#### Scenario: Product-code column is absent
- **WHEN** a purchase CSV omits the optional product-code column
- **THEN** the purchase import SHALL remain valid and process products using the existing generated-code fallback behavior

### Requirement: Normalized product name is authoritative
The purchase importer SHALL resolve a product by the marker-normalized, case-insensitive product name before assigning or considering an imported product code. When multiple existing products match that normalized name, it SHALL select the product with the earliest creation identity and SHALL reuse it without changing its product code.

#### Scenario: Marker variants resolve to the first existing product
- **WHEN** an import row's product name becomes equal to an existing product name after removing the supported purchase marker
- **AND** the row supplies a different nonblank product code
- **THEN** the importer SHALL reuse the earliest matching product
- **AND** the importer SHALL NOT update that product's product code
- **AND** the importer SHALL NOT create another product for the row

#### Scenario: Legacy normalized-name duplicates exist
- **WHEN** more than one existing product matches an import row's marker-normalized, case-insensitive name
- **THEN** the importer SHALL use the matching product with the lowest product ID
- **AND** the importer SHALL preserve that selected product's code

### Requirement: New products retain safe imported codes
The purchase importer SHALL create a product with a trimmed imported product code when its normalized product name has no existing match and that code is not already assigned to another product. A blank code SHALL be treated as unavailable.

#### Scenario: A new product has an unused imported code
- **WHEN** a purchase import row resolves to no existing normalized-name product
- **AND** its trimmed imported product code is nonblank and unused
- **THEN** the created product SHALL retain the imported code

#### Scenario: A new product has a blank imported code
- **WHEN** a purchase import row resolves to no existing normalized-name product
- **AND** its imported product code is blank or absent
- **THEN** the importer SHALL create the product with its normal generated SKU

### Requirement: Reused imported codes do not merge distinct products
The purchase importer SHALL not use an imported product code to merge distinct normalized product names. When a new normalized product name presents a code already assigned to another product, the importer SHALL create the new product with a generated SKU.

#### Scenario: Code conflict occurs in the same import batch
- **WHEN** an earlier row creates a product using a nonblank imported code
- **AND** a later row has a different marker-normalized product name and the same trimmed code
- **THEN** the later row SHALL create a distinct product
- **AND** the later product SHALL receive a generated SKU
- **AND** the earlier product's code SHALL remain unchanged

#### Scenario: Code conflict exists before import
- **WHEN** a purchase import row has a new marker-normalized product name
- **AND** its trimmed imported code is already assigned to an existing different product
- **THEN** the importer SHALL create a distinct product with a generated SKU
- **AND** it SHALL not update or merge the pre-existing product
