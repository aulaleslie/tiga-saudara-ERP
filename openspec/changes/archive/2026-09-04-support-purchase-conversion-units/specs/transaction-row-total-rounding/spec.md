## MODIFIED Requirements

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
- **WHEN** an eligible Sales or POS automatic row has a final tax-inclusive total of `78999.96` and the business increment is `100.00`
- **THEN** the authoritative row total SHALL be `79000.00`

#### Scenario: Automatic total rounds down
- **WHEN** an eligible Sales or POS automatic row has a final tax-inclusive total of `78949.00` and the business increment is `100.00`
- **THEN** the authoritative row total SHALL be `78900.00`

#### Scenario: Half increment rounds upward
- **WHEN** an eligible Sales or POS automatic row has a final tax-inclusive total of `78950.00` and the business increment is `100.00`
- **THEN** the authoritative row total SHALL be `79000.00`

#### Scenario: Rows round independently
- **WHEN** two eligible Sales or POS rows have raw tax-inclusive totals of `78949.00` and `12550.00` with an increment of `100.00`
- **THEN** their authoritative row totals SHALL be `78900.00` and `12600.00`
- **AND** document totals SHALL consume the sum of those independently rounded rows

#### Scenario: Purchase automatic total remains exact
- **WHEN** an automatic Purchase row has a calculated currency-precision total of `78949.37` and the business increment is `100.00`
- **THEN** the authoritative Purchase row total SHALL remain `78949.37`
- **AND** it MUST NOT be rounded to the configured increment

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

## ADDED Requirements

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

