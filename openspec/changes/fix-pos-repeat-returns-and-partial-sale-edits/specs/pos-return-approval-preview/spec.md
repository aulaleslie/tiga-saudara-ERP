## ADDED Requirements

### Requirement: Repeated POS returns depend on remaining eligible products
The system SHALL allow any number of return processes for one POS transaction while eligible quantity or customer-held serialized units remain. Unrelated completed returns or monetary edits MUST NOT block another valid return. The system MUST validate affected source identities, quantities, serial occurrences and financial evidence at approval and MUST prevent overlapping claims from executing.

#### Scenario: Quantity return after unrelated serial replacement
- **GIVEN** a POS transaction contains a non-serial item of 5 pcs and a serialized item of 1 pc
- **AND** the serialized item has completed replacement A to B
- **WHEN** a return requests 1 pc of the non-serial item
- **THEN** approval SHALL be allowed subject to normal validation
- **AND** the remaining non-serial quantity SHALL be 4 and the customer-held serial SHALL remain B

#### Scenario: Successive partial returns exhaust available quantity
- **WHEN** successive returns consume the remaining quantity of an item
- **THEN** each request within available quantity SHALL be eligible regardless of previous return count
- **AND** a request beyond remaining quantity SHALL be blocked without execution effects
- **AND** completed reductions SHALL not be counted twice

#### Scenario: Independent pending requests coexist
- **WHEN** separate pending returns request distinct available items from the same POS transaction
- **THEN** both SHALL remain eligible independently
- **AND** completing one SHALL not invalidate the other's unaffected items

#### Scenario: Competing requests cannot consume the same entitlement
- **WHEN** competing requests overlap in quantity or the same held serial occurrence
- **THEN** overlapping claims SHALL be blocked before competing execution effects commit
- **AND** stale approval submission SHALL not exceed remaining entitlement

#### Scenario: Genuine source mismatch remains blocked
- **WHEN** the affected source identity or execution evidence is missing or inconsistent
- **THEN** approval SHALL be blocked with an actionable explanation
- **AND** no return execution effects SHALL be committed

### Requirement: Pending returns require renewed review for affected monetary changes
The system SHALL require explicit renewed review at the updated value when a monetary edit changes a pending return's financial basis, including relevant shared header allocations. Unrelated edits that do not change that basis MUST NOT block approval. Preview SHALL remain read-only, and final approval MUST validate the latest reviewed value.

#### Scenario: Requested item price changes
- **WHEN** an item's price changes while its return is pending
- **THEN** the return SHALL require renewed review showing the updated value
- **AND** stale approval confirmation SHALL not execute

#### Scenario: Updated value is explicitly reviewed
- **WHEN** an authorized user explicitly accepts the updated pending return value
- **THEN** approval SHALL become available if all existing approval checks pass
- **AND** original audit evidence SHALL remain preserved

#### Scenario: Unrelated item edit leaves approval valid
- **WHEN** another item's price changes without changing the pending return's financial basis
- **THEN** the pending return SHALL remain eligible without renewed monetary review

#### Scenario: Shared header change affects return allocation
- **WHEN** a header monetary adjustment changes a pending return's value
- **THEN** the return SHALL require renewed review of that updated value
