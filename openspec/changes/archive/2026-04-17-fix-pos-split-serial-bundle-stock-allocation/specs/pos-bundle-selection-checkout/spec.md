## ADDED Requirements

### Requirement: POS bundled checkout SHALL deduct child stock once per sold bundle unit
When POS checkout finalizes a selected bundle with stock-managed child products, the system SHALL deduct bundle child stock according to the sold parent bundle quantity. Split posting MUST NOT cause bundle child stock to be deducted more times than the number of sold bundle units requires.

#### Scenario: Single bundled serial parent deducts one child unit
- **WHEN** POS checkout finalizes one stock-managed serial-tracked parent product with one selected bundle child quantity of one
- **THEN** the parent product stock is deducted by one
- **AND** the bundle child product stock is deducted by one

#### Scenario: Two bundled serial parents split by source deduct two child units total
- **WHEN** POS checkout finalizes two serial-tracked parent units in one bundled cart line and the assigned parent serials resolve into two split groups
- **THEN** the parent product stock is deducted by two across the parent source groups
- **AND** the bundle child product stock is deducted by two total across all posted groups
- **AND** the bundle child product stock MUST NOT be deducted once per split group using the full original child quantity

### Requirement: POS bundled checkout SHALL retain child source allocation ownership
When bundle child stock is allocated from a source location, the final stock movement for that child product SHALL use the source location, source setting, and tax bucket selected by the stock resolver.

#### Scenario: Child stock source remains resolver-selected during split posting
- **WHEN** a bundled checkout line is split by the parent product source but the bundle child product is allocated from a separate source location
- **THEN** the child product stock movement uses the child allocation source location and source setting
- **AND** the child product stock bucket decremented matches the child allocation `tax_bucket_used` value
