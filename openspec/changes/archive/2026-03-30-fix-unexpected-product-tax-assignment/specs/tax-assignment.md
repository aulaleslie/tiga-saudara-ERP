## ADDED Requirements

### Requirement: Manual Tax Assignment in Purchase/Sale Carts
The system MUST NOT automatically assign a default or latest tax to cart items in PKP mode when no explicit tax is provided. Tax assignment SHALL be an explicit user action.

#### Scenario: Adding a taxless product to a PKP Purchase Cart
- **WHEN** a product without an assigned tax is added to a Purchase Cart
- **AND** the system setting `is_pkp` is TRUE
- **THEN** the cart line `tax_id` SHALL remain NULL
- **AND** the line SHALL be displayed as "Non Pajak" (or equivalent placeholder) until a tax is selected.

#### Scenario: Adding a taxless product to a PKP Sale Cart
- **WHEN** a product without an assigned tax is added to a Sale Cart
- **AND** the system setting `is_pkp` is TRUE
- **THEN** the cart line `tax_id` SHALL remain NULL
- **AND** the line SHALL be displayed as "Non Pajak" (or equivalent placeholder) until a tax is selected.

### Requirement: Explicit Tax Assignment in Product Imports
The product import process MUST NOT assign default taxes to products if the import source (e.g., CSV) does not specify them.

#### Scenario: Importing products without tax columns
- **WHEN** a CSV file is uploaded for product import
- **AND** the file does not contain `purchase_tax` or `sale_tax` data
- **THEN** the imported product records SHALL have NULL tax IDs
- **AND** the system MUST NOT fallback to hardcoded defaults (e.g., PPN 11%).
