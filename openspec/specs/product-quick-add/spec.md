## ADDED Requirements

### Requirement: Full Modal State Reset
The Product Quick Add Modal must clear all input fields completely when closed or after a successful product creation, preventing data leakage between consecutive additions.

#### Scenario: Re-opening modal after saving product with stock alerts and serial number requirements
- **GIVEN** the user has successfully added a product with `serial_number_required` checked, `product_stock_alert` set, and a `barcode` entered.
- **WHEN** the user reopens the product quick add modal to add another product
- **THEN** the `serial_number_required` checkbox must be unchecked
- **AND** the `product_stock_alert` input must be empty
- **AND** the `barcode` input must be empty
