## Purpose

POS receipts SHALL display transaction details and product serial numbers for customer and accounting records.
## Requirements
### Requirement: Print Assigned Serial Numbers on POS Receipt
The system SHALL display assigned serial numbers directly on the POS receipt for all products that require serial number tracking to ensure accurate warranty tracking and customer transparency.

#### Scenario: Transaction includes serialized product
- **WHEN** a transaction is completed and a receipt is generated
- **AND** the transaction includes a product line with assigned serial numbers
- **THEN** the receipt line item should display the serial numbers (e.g., "SN: XXXXXX")
- **AND** the serial numbers should be formatted neatly below the product name

### Requirement: Robust Customer Identity Display
The POS receipt SHALL display the customer's identity by intelligently combining `contact_name` and `company_name` (or `customer_name`) to provide maximum context. It MUST safely ignore empty strings (`""`) to ensure a blank name is not printed when one of the fields is empty but the other is present.

#### Scenario: Customer has both contact and company name
- **WHEN** a POS receipt is rendered
- **AND** the customer has both `contact_name` and `company_name` (or `customer_name`) defined as non-empty strings
- **THEN** the receipt SHALL display the customer as "Contact Name - Company Name"

#### Scenario: Customer has only company name
- **WHEN** a POS receipt is rendered
- **AND** the customer has an empty `contact_name` but a defined `company_name` (or `customer_name`)
- **THEN** the receipt SHALL display the customer as just "Company Name"

#### Scenario: Customer has only contact name
- **WHEN** a POS receipt is rendered
- **AND** the customer has a defined `contact_name` but empty `company_name` and `customer_name`
- **THEN** the receipt SHALL display the customer as just "Contact Name"

#### Scenario: Customer has no defined names
- **WHEN** a POS receipt is rendered
- **AND** the customer has empty strings for `contact_name`, `company_name`, and `customer_name`
- **THEN** the receipt SHALL display the customer as "-"

### Requirement: Receipt expresses packing split for conversion lines
The POS receipt SHALL express a packed line's unit breakdown as its packing split (number of boxes plus loose base units) rather than a single conversion-unit price, so the printed line reflects how the quantity was decomposed.

#### Scenario: Receipt shows box plus loose base unit split
- **WHEN** a packed line has quantity 6 decomposed into 1 box (factor 5) plus 1 loose base unit
- **THEN** the receipt line's unit breakdown reads as "1 box + 1 ream" (or the product's box and base unit names)

#### Scenario: Pure loose line shows base units only
- **WHEN** a packed line has quantity 3 with no full box group
- **THEN** the receipt line's unit breakdown shows only loose base units and no box

