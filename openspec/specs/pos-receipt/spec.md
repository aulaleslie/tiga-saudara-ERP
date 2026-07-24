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

### Requirement: Packed receipt prices use correct Rupiah values and snapshotted unit labels
The POS receipt SHALL express packed line prices in Rupiah using snapshotted conversion and base unit labels without placeholder initials like `[K]` or `[P]`.

#### Scenario: Receipt shows box plus loose base-unit split with correct prices
- **WHEN** a packed line has quantity 6 decomposed into 1 DUS at Rp210,000 plus 1 RIM at Rp45,000
- **THEN** the receipt displays `1 DUS @ Rp210.000` and `1 RIM @ Rp45.000` using the snapshotted unit labels

#### Scenario: Pure packed line displays the configured conversion unit
- **WHEN** a packed line consists only of one or more full conversion groups
- **THEN** the receipt displays the configured conversion-unit label and does not display `[K]` or a hardcoded generic box label

#### Scenario: Pure loose line shows the configured base unit
- **WHEN** a packed line contains only loose base units
- **THEN** the receipt displays the full configured base-unit label and does not display a first-letter placeholder

#### Scenario: Packed price remains in Rupiah on completed receipt
- **WHEN** a completed transaction snapshot stores `box_price_applied = 21000000` minor units
- **THEN** the completed receipt displays Rp210,000 and MUST NOT display Rp21,000,000 for that breakdown price

#### Scenario: Packed price remains in Rupiah on draft receipt
- **WHEN** a draft or loaded transaction snapshot stores packed breakdown prices in minor units
- **THEN** its receipt preview applies the same unit labels and minor-to-Rupiah conversion as a completed receipt

#### Scenario: Historical packed snapshot lacks unit labels
- **WHEN** an older packed transaction snapshot does not contain the new unit-label fields
- **THEN** the receipt SHALL resolve the best available configured conversion and base-unit names without emitting placeholder initials or changing persisted historical data

### Requirement: Receipt line total column reflects Rupiah line total

The POS receipt SHALL display each line item's total (subtotal) column as the line's Rupiah total value. The receipt MUST NOT divide the persisted line total by 100 when the snapshot already stores it in Rupiah. This applies uniformly to completed-checkout receipts, draft receipts, and loaded-transaction receipts.

#### Scenario: Single-unit line prints full Rupiah total

- **WHEN** a completed transaction line has quantity 1 and unit price Rp335.000 and its snapshot stores the line total as `335000` Rupiah
- **THEN** the receipt line's total column displays Rp335.000
- **AND** the receipt MUST NOT display Rp3.350 for that line

#### Scenario: Line total matches per-unit breakdown and grand total

- **WHEN** a receipt renders a line whose per-unit breakdown reads `1 PCS(S) @ Rp335.000` and whose only line is that item
- **THEN** the line total column equals the receipt grand total (Rp335.000)

#### Scenario: Draft and loaded-transaction receipts use the same Rupiah line total

- **WHEN** a draft or loaded transaction line snapshot stores the line total in Rupiah
- **THEN** its receipt preview displays that Rupiah value in the line total column using the same conversion as a completed receipt

#### Scenario: Packed breakdown per-unit price remains correctly scaled

- **WHEN** a packed line stores breakdown prices (`box_price_applied` / `loose_price_applied`) in minor units
- **THEN** the packed unit-breakdown per-unit prices continue to be converted from minor units to Rupiah
- **AND** the line total column still displays the Rupiah line total without an additional division by 100

