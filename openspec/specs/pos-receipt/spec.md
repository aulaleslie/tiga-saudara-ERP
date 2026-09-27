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

The POS receipt SHALL display each line item's total (subtotal) column in the same Rupiah unit used by the receipt grand total, payment values, and checkout totals. When a transaction snapshot stores a line total in minor units, the receipt data mapping MUST convert it to Rupiah exactly once before rendering. This applies uniformly to completed-checkout receipts, draft receipts, and loaded-transaction receipts.

#### Scenario: Single-unit line prints the checkout's Rupiah total

- **WHEN** a completed transaction line has quantity 1, unit price Rp45.000, and a snapshot line total of `4500000` minor units
- **THEN** the receipt line's total column displays Rp45.000
- **AND** it MUST NOT display Rp4.500.000

#### Scenario: Line total matches per-unit breakdown and grand total

- **WHEN** a receipt renders a line whose per-unit breakdown reads `1 PCS(S) @ Rp45.000` and whose only line is that item
- **THEN** the line total column equals the receipt grand total (Rp45.000)

#### Scenario: Draft and loaded-transaction receipts use the same Rupiah line total

- **WHEN** a draft or loaded transaction line snapshot stores its line total in minor units
- **THEN** its receipt preview displays the equivalent Rupiah value in the line total column using the same conversion as a completed receipt

#### Scenario: Packed breakdown per-unit price remains correctly scaled

- **WHEN** a packed line stores breakdown prices (`box_price_applied` / `loose_price_applied`) and its line total in minor units
- **THEN** the packed unit-breakdown prices and line total column are each converted to Rupiah exactly once
- **AND** the line total remains consistent with the receipt grand total

### Requirement: Receipt displays the POS transaction code prominently
The printed POS receipt SHALL display the POS transaction code in the receipt metadata block, with the same prominence previously given to the receipt number. The code SHALL be the same value recorded on the POS transaction and written into the generated Sale notes.

#### Scenario: Transaction code appears in the metadata block
- **WHEN** a POS receipt is rendered for a completed checkout
- **THEN** the metadata block displays a labelled POS transaction code row

#### Scenario: Receipt code matches the transaction record
- **WHEN** a POS receipt is rendered for a completed checkout
- **THEN** the displayed transaction code matches the code persisted on that checkout's POS transaction

#### Scenario: Checkout without a linked POS transaction
- **WHEN** a POS receipt is rendered for a checkout that has no linked POS transaction
- **THEN** the receipt renders without error and omits or neutrally fills the transaction code row

### Requirement: Receipt number is demoted to a de-emphasised footer
The printed POS receipt SHALL move the receipt number out of the metadata block into a small, visually de-emphasised element at the bottom of the receipt. The receipt number SHALL remain present and legible so that it can still be used to look up a return.

#### Scenario: Receipt number appears at the bottom
- **WHEN** a POS receipt is rendered
- **THEN** the receipt number appears in a de-emphasised element below the receipt footer text

#### Scenario: Receipt number no longer appears in the metadata block
- **WHEN** a POS receipt is rendered
- **THEN** the metadata block does not display the receipt number row

#### Scenario: Receipt number remains present
- **WHEN** a POS receipt is rendered for a checkout with a receipt number
- **THEN** the receipt number value is still printed on the receipt

### Requirement: Completed receipts SHALL identify bundle-component serials
A completed POS receipt and reprint SHALL render persisted component serial assignments beneath the bundle component to which they belong, without consulting the live bundle definition or current serial location/status.

#### Scenario: Receipt contains serialized parent and component
- **WHEN** a completed bundled transaction contains parent serials and component serials
- **THEN** the receipt SHALL distinguish parent serials from component serials
- **AND** each component serial SHALL appear with its persisted component name or code

#### Scenario: Historical bundle definition changes
- **WHEN** a completed receipt is reprinted after the live bundle or component changes or is deleted
- **THEN** the receipt SHALL retain the originally posted component-to-serial association

