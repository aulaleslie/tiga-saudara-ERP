# purchase-conversion-unit-entry Specification

## Purpose
TBD - created by archiving change support-purchase-conversion-units. Update Purpose after archive.
## Requirements
### Requirement: Purchase lines accept product base and conversion units
The system SHALL let an authorized user create or edit a mutable Purchase line using the product's base unit or an active eligible conversion belonging to that product and enabled for purchases in the acting business. The system SHALL treat the base unit as factor `1` and SHALL reject a submitted conversion that is unrelated, invalid, inactive for new activity, or incompatible with the product's current base unit.

#### Scenario: User adds a conversion-unit line
- **WHEN** a user selects a product conversion where one BOX equals twelve PCS and enters two BOX
- **THEN** the Purchase cart SHALL retain two BOX as the entered representation
- **AND** it SHALL resolve the canonical quantity as twenty-four PCS

#### Scenario: User selects the base unit
- **WHEN** a user selects the product's base unit on a Purchase line
- **THEN** the system SHALL use an implicit conversion factor of `1`
- **AND** entered quantity SHALL equal canonical quantity

#### Scenario: Submitted conversion does not belong to product
- **WHEN** a client submits another product's conversion identity for a Purchase line
- **THEN** the server SHALL reject the line
- **AND** it MUST NOT trust a client-supplied conversion factor or canonical quantity

#### Scenario: Same product uses multiple units
- **WHEN** a Purchase contains the same product as two BOX and three PCS
- **THEN** the cart SHALL retain separate BOX and PCS lines
- **AND** adding the same product in the same selected unit SHALL increment only the matching line

#### Scenario: Purchase-disabled conversion is hidden and rejected
- **WHEN** a product has a purchase-disabled BOX conversion in the acting business
- **THEN** selecting that product SHALL omit BOX from new unit options and retain the base unit
- **AND** direct submission of BOX for a new or switched line SHALL be rejected by the server

#### Scenario: Sales flag does not restrict purchase eligibility
- **WHEN** BOX is sales-disabled but purchase-enabled and otherwise eligible
- **THEN** BOX SHALL remain available for new Purchase selection

#### Scenario: Duplicate excludes a now-disabled conversion
- **WHEN** a Purchase is duplicated and its original BOX conversion is now purchase-disabled
- **THEN** duplication SHALL use the existing canonical base-unit fallback and clear conversion intent
- **AND** it SHALL NOT introduce BOX through historical option fallback

#### Scenario: Persisted snapshots survive disablement
- **WHEN** a saved Purchase line references BOX and BOX is subsequently purchase-disabled
- **THEN** historical rendering, permitted unchanged-line edits, and receiving SHALL retain its original snapshots and existing restrictions
- **AND** snapshot fallback SHALL NOT authorize BOX for a new or switched line

### Requirement: Purchase lines persist canonical values and historical entry snapshots
The system SHALL store Purchase operational quantity in the product's base unit and SHALL retain the selected unit, entered quantity, entered-unit price, conversion identity, conversion-factor snapshot, and durable unit labels needed to interpret the line historically. The persisted factor snapshot SHALL remain authoritative if product conversion configuration later changes or becomes unavailable.

#### Scenario: Conversion line is persisted
- **WHEN** the user saves `2.500 BOX` at an entered price of `600000.00` and one BOX equals twelve PCS
- **THEN** the Purchase detail SHALL store entered quantity `2.500`, entered unit BOX, entered-unit price `600000.00`, and factor `12`
- **AND** its operational quantity SHALL be `30.000` PCS
- **AND** its normalized base-unit price SHALL represent `50000.00` per PCS

#### Scenario: Canonical unit price column precision
- **WHEN** a conversion line produces a repeating normalized base unit price (e.g. `100000.00 / 3 = 33333.333333`)
- **THEN** the system SHALL store `purchase_details.unit_price` and `price` as `decimal(15,6)` as an explicit capability delta to `currency-storage-convention/spec.md`
- **AND** it SHALL persist `entered_product_discount_amount` alongside `entered_quantity` and `entered_unit_price` to ensure recalculation and reload stability

#### Scenario: Configuration changes after save
- **WHEN** a persisted Purchase line snapshotted one BOX as twelve PCS
- **AND** the current product conversion is renamed, changed, deactivated, or unavailable
- **THEN** the Purchase SHALL continue to display and process the persisted line using its original snapshots

#### Scenario: Legacy line has no snapshots
- **WHEN** the system loads an existing Purchase detail without conversion snapshots
- **THEN** it SHALL interpret the existing quantity and unit price as base-unit values with factor `1`
- **AND** it MUST NOT rewrite the historical row merely to provide that fallback

### Requirement: Purchase conversion arithmetic preserves supported decimal quantities
The system SHALL parse and calculate entered, canonical, and received quantities using decimal-safe arithmetic with three fractional digits of supported quantity precision. It SHALL reject, rather than silently truncate or round, a value whose canonical result exceeds supported precision.

#### Scenario: Decimal conversion produces supported base quantity
- **WHEN** a user enters `0.500 BOX` for a factor-twelve conversion
- **THEN** the system SHALL accept and persist canonical quantity `6.000` PCS

#### Scenario: Canonical precision is unsupported
- **WHEN** entered quantity multiplied by the authoritative conversion factor produces more canonical fractional precision than the system supports
- **THEN** the system SHALL reject the value with an actionable validation error
- **AND** it MUST NOT silently round or truncate the quantity

#### Scenario: Quantity survives create and edit
- **WHEN** a valid fractional Purchase quantity is created, reloaded, and saved through an otherwise permitted edit
- **THEN** its entered and canonical quantities SHALL remain unchanged

### Requirement: Purchase monetary behavior remains authoritative in the entered unit
The system SHALL interpret an entered unit price as the price per selected Purchase unit while retaining the existing Purchase manual unit-price, manual line-total, discount, tax, shipping, and pricing-authority behavior. Conversion to a normalized base-unit price MUST NOT change the authoritative Purchase line total.

#### Scenario: Repeating normalized base price does not drift line total
- **WHEN** one BOX contains three PCS and the authoritative supplier price is `100000.00` per BOX
- **THEN** the system SHALL retain the existing Purchase-calculated line total from entered-unit values
- **AND** base-price normalization MUST NOT change that total through intermediate precision loss

#### Scenario: Manual line-total override is preserved
- **WHEN** a user explicitly commits a manual Purchase line total on a conversion-unit line
- **THEN** the system SHALL preserve the existing manual-line-total authority and recalculation behavior
- **AND** conversion normalization MUST NOT replace it with an independently calculated total

### Requirement: Receiving retains ordered-unit context and posts canonical quantities
The receiving flow SHALL default each Purchase line to its snapshotted ordered unit, SHALL allow entry in either that ordered unit or the product base unit, and SHALL display the canonical equivalent and remaining quantity. It SHALL persist received-note quantity, update Purchase completion state, and post inventory using base-unit quantities only.

#### Scenario: Receive in ordered conversion unit
- **WHEN** an order line contains two BOX at twelve PCS per BOX and the receiver enters one BOX
- **THEN** the pending receipt detail SHALL store canonical received quantity `12.000` PCS
- **AND** the remaining receivable quantity SHALL be `12.000` PCS

#### Scenario: Receive remaining loose base units
- **WHEN** the same order has twelve PCS remaining and the receiver selects PCS and enters seven
- **THEN** the receipt SHALL store `7.000` canonical PCS
- **AND** the line SHALL have `5.000` canonical PCS remaining

#### Scenario: Unrelated conversion is unavailable during receiving
- **WHEN** a product has another conversion that was not the ordered unit
- **THEN** receiving SHALL offer only the snapshotted ordered unit and base unit for that line

#### Scenario: Concurrent receipt would over-receive
- **WHEN** another approved receipt changes the remaining canonical quantity before approval
- **THEN** the approval path SHALL recheck the locked canonical totals
- **AND** it SHALL reject approval that would exceed the ordered canonical quantity

### Requirement: Serialized Purchase conversions require one serial per base unit
For a serial-tracked product, the system SHALL accept only Purchase and receiving conversions that resolve to a whole canonical base-unit quantity, and receiving SHALL require exactly one unique valid serial number for each received base unit.

#### Scenario: Fractional entered conversion resolves to whole base units
- **WHEN** a serial-tracked product uses factor twelve and the user purchases `0.500 BOX`
- **THEN** the line SHALL resolve to six base units
- **AND** receiving all of it SHALL require exactly six serial numbers

#### Scenario: Entered conversion resolves to fractional base unit
- **WHEN** a serial-tracked Purchase quantity resolves to `2.500` base units
- **THEN** the system SHALL reject the quantity
- **AND** it MUST NOT create or request a fractional serial allocation

### Requirement: Downstream Purchase behavior uses the correct quantity representation
Stock, transactions, receiving eligibility, Purchase returns, costing, normalization/replay, and inventory-facing reports SHALL use canonical base quantities. Purchase document screens and supplier-facing print/export surfaces SHALL show snapshotted entered quantity and unit when present and SHALL fall back to base-unit values for legacy rows.

#### Scenario: Approved receipt updates stock
- **WHEN** a receipt of one factor-twelve BOX is approved
- **THEN** product and location stock SHALL increase by twelve base units
- **AND** the inventory transaction SHALL record twelve base units

#### Scenario: Purchase return checks eligibility
- **WHEN** a return is created against a conversion-unit Purchase line
- **THEN** returned and remaining eligibility quantities SHALL be compared in canonical base units

#### Scenario: Supplier-facing document renders conversion snapshot
- **WHEN** a Purchase detail was entered as `2.500 BOX`
- **THEN** supplier-facing Purchase display and export SHALL identify `2.500 BOX`
- **AND** operational views MAY additionally show its canonical base-unit equivalent

### Requirement: Conversion-unit Purchase support preserves existing flows
The system SHALL preserve existing Purchase tax, discount, manual pricing, line-total override, business switching, create/edit authorization, receiving approval, return, import, costing, and reporting behavior except where this capability explicitly changes unit representation, decimal quantity handling, or Purchase increment rounding.

#### Scenario: Base-unit Purchase follows existing behavior
- **WHEN** a user creates and receives a Purchase using only base units
- **THEN** the workflow SHALL retain its existing behavior apart from decimal-safe handling and the separately specified removal of Purchase increment rounding

#### Scenario: Received Purchase retains edit restrictions
- **WHEN** a Purchase has receiving dependencies
- **THEN** conversion support SHALL NOT bypass its existing commercial or monetary-only edit restrictions

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


