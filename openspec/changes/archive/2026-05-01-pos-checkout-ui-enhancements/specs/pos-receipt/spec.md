## ADDED Requirements

### Requirement: Print Assigned Serial Numbers on POS Receipt
To ensure accurate warranty tracking and customer transparency, any product that requires serial number tracking must have its assigned serial numbers printed directly on the POS receipt.

#### Scenario: Transaction includes serialized product
- **WHEN** a transaction is completed and a receipt is generated
- **AND** the transaction includes a product line with assigned serial numbers
- **THEN** the receipt line item should display the serial numbers (e.g., "SN: XXXXXX")
- **AND** the serial numbers should be formatted neatly below the product name
