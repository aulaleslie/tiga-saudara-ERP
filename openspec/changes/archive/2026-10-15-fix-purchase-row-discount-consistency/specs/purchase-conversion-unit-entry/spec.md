# Spec Delta

## ADDED Requirements

### Requirement: Purchase row discounts retain their entered and canonical meanings
The system SHALL interpret a fixed discount as currency per entered Purchase unit and a percentage discount as a percentage of entered unit price. It SHALL persist the entered discount input and canonical currency discount consistently, converting fixed currency by the authoritative factor exactly once. The same interpretation SHALL apply to base units, conversion units, and all supported Purchase tax modes.

#### Scenario: Fixed discount survives converted-unit save and reopen
- **WHEN** a user saves one BOX of twelve PCS at Rp12,000 with fixed discount Rp1,200 per BOX and reopens the Purchase
- **THEN** the entered discount SHALL remain Rp1,200 per BOX
- **AND** the canonical currency discount SHALL remain Rp100 per PCS
- **AND** saving the unchanged row SHALL preserve its stored subtotal and tax

#### Scenario: Percentage input survives save and reopen
- **WHEN** a user saves a base-unit or converted-unit Purchase row with a 10% discount and reopens it
- **THEN** the editable discount SHALL remain 10%
- **AND** its monetary equivalents SHALL represent 10% of the corresponding entered and canonical prices

#### Scenario: Changed discount replaces its prior snapshot
- **WHEN** a user changes a saved row discount, including changing it to zero, and saves the Purchase
- **THEN** the entered discount and canonical currency discount SHALL reflect the new input
- **AND** an older snapshot SHALL NOT override the change

#### Scenario: Discount recalculation uses the correct entered-unit amount
- **WHEN** an eligible saved converted-unit row is recalculated after a quantity, price, discount, unit, or tax change
- **THEN** the recalculation SHALL use currency discount per entered unit
- **AND** it SHALL NOT apply canonical discount directly against entered-unit price or divide the discount by the factor twice

#### Scenario: Legacy discount has no entered snapshot
- **WHEN** a Purchase row lacks an entered discount snapshot
- **THEN** its fixed entered-unit discount SHALL be derived from canonical currency discount and its authoritative factor
- **AND** its percentage input SHALL be derived from canonical currency discount and canonical price when price is positive, otherwise zero
- **AND** rendering SHALL NOT rewrite the historical row

### Requirement: Purchase discount calculations preserve supported fractional quantities
Purchase subtotal and tax calculations SHALL use the full supported entered quantity without integer truncation or coercion to one. Existing quantity validation and manual-total authority SHALL remain in force.

#### Scenario: Half a discounted box
- **WHEN** a user purchases 0.500 BOX at Rp12,000 per BOX with Rp1,200 fixed discount and factor twelve, without tax
- **THEN** the net line subtotal SHALL be Rp5,400
- **AND** the canonical quantity SHALL be 6.000 PCS
- **AND** save, reload, and quantity recalculation SHALL retain consistent discounted values

#### Scenario: Manual total remains authoritative
- **WHEN** a discounted row with an eligible committed manual total is saved and reopened without a pricing change
- **THEN** its committed subtotal and reconciled tax allocation SHALL remain unchanged

### Requirement: Purchase details and print distinguish percentage and currency discounts
Purchase details and print SHALL display row discounts according to their type and entered unit. Percentage input SHALL appear as a percentage and monetary discount SHALL represent currency per entered unit. Fixed discounts SHALL identify their currency amount and entered-unit context. Taxed row DPP SHALL equal the stored subtotal minus stored row tax, with no additional row-discount subtraction.

#### Scenario: Percentage discount renders correctly
- **WHEN** a saved row has entered-unit price Rp10,000 and a 10% discount
- **THEN** details and print SHALL identify a 10% discount and a Rp1,000 currency equivalent per entered unit
- **AND** they SHALL NOT display Rp10 as the monetary discount or derive 0.1% from the percentage input

#### Scenario: Fixed conversion discount renders correctly
- **WHEN** a saved row has a fixed Rp1,200 discount per BOX and factor twelve
- **THEN** details and print SHALL show Rp1,200 in the entered-unit context
- **AND** any displayed canonical discount SHALL identify Rp100 per PCS

#### Scenario: Explicit zero remains zero
- **WHEN** a saved row has an explicit entered discount of zero
- **THEN** its entered discount display SHALL remain zero
- **AND** display SHALL NOT silently repair contradictory historical fields

#### Scenario: Taxed DPP includes the discount once
- **WHEN** a discounted Purchase row has subtotal Rp11,988 and stored tax Rp1,188
- **THEN** its displayed row DPP SHALL equal Rp10,800

### Requirement: Discounted purchase HPP reconciles across tax modes
Receiving approval SHALL derive discounted unit HPP from stored row DPP divided by canonical quantity. Row discount SHALL be included exactly once in subtotal and SHALL NOT be deducted again by HPP calculation. Existing receiving authorization and lifecycle guards SHALL remain in force.

#### Scenario: Non-PKP approval uses discounted cost
- **WHEN** one BOX of twelve PCS priced Rp12,000 with Rp1,200 fixed discount is saved, reloaded, recalculated, and received without tax
- **THEN** subtotal SHALL equal Rp10,800 and tax SHALL equal zero
- **AND** approval SHALL use HPP Rp900 per PCS

#### Scenario: Tax-excluded approval uses discounted DPP
- **WHEN** the same line is saved and received with 11% tax excluded
- **THEN** subtotal SHALL equal Rp11,988 and tax SHALL equal Rp1,188
- **AND** approval SHALL use HPP Rp900 per PCS

#### Scenario: Tax-included approval removes tax from discounted total
- **WHEN** the same line is saved and received with 11% tax included
- **THEN** subtotal SHALL equal Rp10,800 and tax SHALL equal Rp1,070.27
- **AND** approval SHALL derive HPP from Rp9,729.73 divided by twelve, within existing cost storage precision
