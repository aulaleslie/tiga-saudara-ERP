# tax-assignment Specification

## Purpose
This specification defines the requirements for manual tax assignment across purchase, sale, and product management workflows, ensuring tax application is an explicit user action rather than a system default.

## Requirements
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

### Requirement: Ignore Incoming Product Tax When Non-PKP
The system MUST actively ignore any pre-assigned or incoming `tax_id` from the Cartesian details when processing row inserts or updates if the `is_pkp` setting is FALSE. In this non-PKP context, the resulting saved row details MUST have `tax_id = null` and a calculated `product_tax_amount = 0`, overriding whatever the cart or product model initially supplied.

#### Scenario: Storing a purchase with a tax-assigned product when Non-PKP
- **WHEN** a user Submits a Purchase Cart (Store or Update) and `is_pkp` is false
- **AND** a Cartesian row has a `tax_id` populated (e.g., from the Product default)
- **THEN** the backend process SHALL intercept this and set `tax_id = null` 
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`.

#### Scenario: Storing a sale with a tax-assigned product when Non-PKP
- **WHEN** a user Submits a Sale Cart (Store or Update) and `is_pkp` is false
- **AND** a Cartesian row has a `tax_id` populated (e.g., from the Product default)
- **THEN** the backend process SHALL intercept this and set `tax_id = null`
- **AND** the backend process SHALL persist the row with `product_tax_amount = 0`.
