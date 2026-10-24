## MODIFIED Requirements

### Requirement: Only user-driven automatic pricing interactions trigger rounding
Adding an automatically priced product or changing its quantity, row discount, tax, or automatic price context in Sales or POS SHALL recalculate and round the eligible commercial row. The same interactions in Purchase SHALL recalculate according to existing Purchase pricing authority without configured increment rounding. Merely loading an existing document, allocating its value internally, processing a non-POS return, or reading it for reporting or printing SHALL NOT initiate rounding. POS commercial cash-return valuation SHALL use its explicit cumulative return-rounding policy; source and remaining rows SHALL NOT be repriced.

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
- **WHEN** the system values a full or partial non-POS return from a persisted transaction row
- **THEN** it SHALL use the original persisted value and established remainder allocation
- **AND** SHALL NOT apply the business's current row-rounding setting again

### Requirement: Document-level and internal values are not independently rounded
The system SHALL NOT independently apply this capability to grand totals, global discounts, shipping, bundle component allocations, stock or serial allocations, owner-split fragments, internal return fragments, remaining sale balances, or other non-commercial internal rows. All downstream allocations SHALL reconcile exactly to their already-authoritative commercial row or document source.

#### Scenario: Global adjustment can produce a non-increment grand total
- **WHEN** a document applies a global discount or shipping after summing rounded commercial rows
- **THEN** the resulting grand total MAY be outside the configured increment
- **AND** the system SHALL NOT round it again

#### Scenario: POS owner fragments reconcile without rerounding
- **WHEN** an authoritative rounded POS row is split across multiple owners
- **THEN** the split planner SHALL allocate that amount deterministically
- **AND** the owner fragments SHALL sum exactly to the authoritative row total without independent increment rounding



#### Scenario: POS commercial return amount is rounded once
- **WHEN** a POS cash-return commercial amount is rounded under its cumulative return policy
- **THEN** owner and bundle allocations SHALL reconcile exactly to that amount
- **AND** the remaining balance SHALL be computed by subtraction without independent increment rounding
