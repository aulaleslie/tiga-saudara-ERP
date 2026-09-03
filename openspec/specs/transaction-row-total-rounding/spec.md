# transaction-row-total-rounding Specification

## Purpose
TBD - created by archiving change configurable-row-total-rounding. Update Purpose after archive.
## Requirements
### Requirement: Businesses configure automatic row-total rounding
Each business SHALL store a non-negative monetary row-total rounding increment. New and existing businesses SHALL default to `100.00`, and an increment of zero SHALL disable automatic row-total rounding for that business's eligible Sales and POS rows. Purchase calculations SHALL ignore this setting.

#### Scenario: Existing business receives the default
- **WHEN** the rounding configuration migration is applied to an existing business
- **THEN** that business's row-total rounding increment SHALL be `100.00`

#### Scenario: Business disables rounding
- **WHEN** an authorized user saves a rounding increment of zero on the Business Configuration page
- **THEN** eligible automatic Sales and POS row totals for that business SHALL retain their unrounded two-decimal value

#### Scenario: Business configurations remain isolated
- **WHEN** two businesses configure different rounding increments
- **THEN** each Sales or POS interaction SHALL use the effective document business's increment
- **AND** Purchase interactions in both businesses SHALL ignore the increments

### Requirement: Eligible automatic rows round their final tax-inclusive total
When a user interaction calculates or recalculates an automatically priced commercial row in Sales or POS, the system SHALL round that row's final tax-inclusive total independently to the nearest configured increment using half-up midpoint behavior. The system SHALL apply tax before rounding and SHALL NOT round the resulting document grand total again. Purchase rows SHALL NOT be eligible for configured increment rounding.

#### Scenario: Automatic total rounds across a decimal boundary
- **WHEN** an eligible automatic row has a final tax-inclusive total of `78999.96` and the business increment is `100.00`
- **THEN** the authoritative row total SHALL be `79000.00`

#### Scenario: Automatic total rounds down
- **WHEN** an eligible automatic row has a final tax-inclusive total of `78949.00` and the business increment is `100.00`
- **THEN** the authoritative row total SHALL be `78900.00`

#### Scenario: Half increment rounds upward
- **WHEN** an eligible automatic row has a final tax-inclusive total of `78950.00` and the business increment is `100.00`
- **THEN** the authoritative row total SHALL be `79000.00`

#### Scenario: Rows round independently
- **WHEN** two eligible Sales or POS rows have raw tax-inclusive totals of `78949.00` and `12550.00` with an increment of `100.00`
- **THEN** their authoritative row totals SHALL be `78900.00` and `12600.00`
- **AND** document totals SHALL consume the sum of those independently rounded rows

#### Scenario: Purchase automatic total remains exact
- **WHEN** an automatic Purchase row has a calculated currency-precision total of `78949.37` and the business increment is `100.00`
- **THEN** the authoritative Purchase row total SHALL remain `78949.37`
- **AND** it MUST NOT be rounded to the configured increment

### Requirement: Rounded taxable rows reconcile tax exactly
For an eligible taxable automatic row, the rounded tax-inclusive row total SHALL be authoritative. The system SHALL derive the row pre-tax subtotal and tax amount from that rounded total so their two-decimal sum equals the rounded row total in both tax-included and tax-exclusive entry modes.

#### Scenario: Tax-included row rounds before allocation
- **WHEN** an automatic taxable row has a raw tax-inclusive total of `78999.96`, an applicable tax, and an increment of `100.00`
- **THEN** the row total SHALL be `79000.00`
- **AND** the derived pre-tax subtotal plus tax amount SHALL equal `79000.00` exactly

#### Scenario: Tax-exclusive entry rounds the final amount
- **WHEN** an automatically priced tax-exclusive row receives applicable tax and its resulting tax-inclusive total is eligible for rounding
- **THEN** the system SHALL round only the resulting tax-inclusive row total
- **AND** SHALL derive pre-tax subtotal and tax from that authoritative rounded total

### Requirement: Manual pricing remains exact and bypasses automatic rounding
A row whose unit price or final row total is explicitly committed by a user SHALL preserve that pricing authority and SHALL bypass automatic row-total rounding. Sales, Purchase, and POS SHALL durably distinguish automatic pricing from manual unit-price and manual line-total pricing as applicable.

#### Scenario: Manual unit price produces an unrounded total
- **WHEN** a user explicitly commits a unit price and the resulting tax-inclusive row total is `78999.96`
- **THEN** the authoritative row total SHALL remain `78999.96`
- **AND** the row SHALL remain manually priced through later quantity, row-discount, or tax recalculation

#### Scenario: Manual line total remains exact
- **WHEN** a user explicitly commits `78949.00` as a row total
- **THEN** the authoritative row total SHALL remain `78949.00`
- **AND** applicable pre-tax and tax values SHALL be derived from that exact total

#### Scenario: POS approved override bypasses rounding
- **WHEN** a POS unit-price or line-total override is approved and applied
- **THEN** POS SHALL preserve the approved authoritative value through cart totals, snapshots, checkout, payment, receipt, and posting without automatic row rounding

### Requirement: Only user-driven automatic pricing interactions trigger rounding
Adding an automatically priced product or changing its quantity, row discount, tax, or automatic price context in Sales or POS SHALL recalculate and round the eligible commercial row. The same interactions in Purchase SHALL recalculate according to existing Purchase pricing authority without configured increment rounding. Merely loading an existing document, allocating its value internally, processing a return, or reading it for reporting or printing SHALL NOT initiate rounding.

#### Scenario: Existing document load is stable
- **WHEN** a user opens an existing Sales, Purchase, or POS draft without performing an automatic pricing interaction
- **THEN** persisted row totals SHALL remain unchanged

#### Scenario: Sales or POS quantity change recalculates an automatic row
- **WHEN** a user changes the quantity of an eligible automatic Sales or POS row
- **THEN** the system SHALL calculate tax and the raw row total from the current automatic pricing inputs
- **AND** SHALL apply the effective business's rounding increment

#### Scenario: Purchase quantity change recalculates without increment rounding
- **WHEN** a user changes the quantity of an automatic Purchase row
- **THEN** the system SHALL retain the existing Purchase tax, discount, and pricing-authority calculation behavior
- **AND** the calculated result SHALL NOT be rounded to the business's configured increment

#### Scenario: Return preserves original valuation
- **WHEN** the system values a full or partial return from a persisted transaction row
- **THEN** it SHALL use the original persisted value and established remainder allocation
- **AND** SHALL NOT apply the business's current row-rounding setting again

### Requirement: Purchase manual pricing remains unchanged when increment rounding is removed
Purchase SHALL preserve its existing manual unit-price and manual line-total override authority, persistence, and recalculation rules. Removing configured increment rounding MUST NOT redesign, disable, or reinterpret those manual behaviors.

#### Scenario: Manual Purchase unit price is committed
- **WHEN** a user explicitly commits a Purchase unit price
- **THEN** the existing manual-unit-price behavior SHALL remain authoritative
- **AND** the resulting total SHALL NOT receive configured increment rounding

#### Scenario: Manual Purchase line total is committed
- **WHEN** a user explicitly commits a Purchase line total
- **THEN** the existing manual-line-total behavior SHALL remain authoritative through supported subsequent interactions
- **AND** the total SHALL NOT receive configured increment rounding

### Requirement: Sales and POS rounding remain unchanged
Removing configured increment rounding from Purchase MUST NOT change the rounding eligibility, calculation, authorization, persistence, or downstream reconciliation behavior of Sales or POS.

#### Scenario: Purchase rounding is removed
- **WHEN** the same business performs Purchase, Sales, and POS calculations with a positive rounding increment
- **THEN** Purchase SHALL ignore the increment
- **AND** eligible Sales and POS rows SHALL continue applying it according to their existing contracts

### Requirement: Document-level and internal values are not independently rounded
The system SHALL NOT independently apply this capability to grand totals, global discounts, shipping, bundle component allocations, stock or serial allocations, owner-split fragments, return fragments, or other non-commercial internal rows. All downstream allocations SHALL reconcile exactly to their already-authoritative commercial row or document source.

#### Scenario: Global adjustment can produce a non-increment grand total
- **WHEN** a document applies a global discount or shipping after summing rounded commercial rows
- **THEN** the resulting grand total MAY be outside the configured increment
- **AND** the system SHALL NOT round it again

#### Scenario: POS owner fragments reconcile without rerounding
- **WHEN** an authoritative rounded POS row is split across multiple owners
- **THEN** the split planner SHALL allocate that amount deterministically
- **AND** the owner fragments SHALL sum exactly to the authoritative row total without independent increment rounding

