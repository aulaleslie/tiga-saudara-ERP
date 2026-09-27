## MODIFIED Requirements

### Requirement: Completion keeps financial and audit data consistent
The system SHALL recalculate line and header monetary values using the purchase normalization rules, derive paid amount, due amount, and payment status from active payments, and persist an immutable completion audit record in the same database transaction. For every retained PKP purchase-detail row, the system SHALL preserve its persisted tax identity and proportionally recalculate its persisted subtotal, pre-tax subtotal, and product tax amount according to the approved received quantity over the original ordered quantity. The completion preview SHALL use the same final monetary reconstruction as the completion transaction. A non-PKP purchase SHALL continue to persist no tax data after normalization.

#### Scenario: Final document total reflects only accepted goods
- **WHEN** a partial purchase is completed after its lines are normalized
- **THEN** its line values, tax, discount, shipping, total amount, and due amount SHALL be recalculated from the retained final lines
- **AND** the global purchase payment workflow SHALL treat it as an eligible exact-`RECEIVED` purchase when it has a positive live balance

#### Scenario: PKP retained line preserves proportional tax
- **WHEN** a PKP purchase detail with ordered quantity 10, persisted subtotal 11100, persisted product tax amount 1100, and a tax ID has approved received quantity 5
- **THEN** the final retained detail SHALL have quantity 5, the same tax ID, subtotal 5550, and product tax amount 550
- **AND** the completed purchase header tax amount SHALL include 550 for that detail

#### Scenario: PKP preview matches persisted completion amounts
- **WHEN** an authorized user previews and then completes an eligible PKP supplier-shortfall purchase without a source-document change
- **THEN** the preview tax and total amounts SHALL equal the resulting persisted purchase header amounts
- **AND** each retained line's persisted tax amount SHALL equal its previewed proportional result

#### Scenario: Tax-included PKP line retains its original tax composition
- **WHEN** a partially received PKP purchase has a tax-included retained line with persisted subtotal and product tax amount
- **THEN** completion SHALL proportionally retain that stored tax composition for the approved quantity
- **AND** it SHALL NOT reprice the line or resolve tax using a current tax-master value

#### Scenario: Non-PKP completion remains untaxed
- **WHEN** an eligible partially received purchase belongs to a non-PKP setting
- **THEN** completion SHALL persist null line/header tax IDs and zero line/header tax amounts

#### Scenario: Existing payment overage blocks completion
- **WHEN** active purchase payments exceed the normalized document total
- **THEN** the system SHALL reject completion
- **AND** it SHALL preserve all purchase, payment, receipt, and audit data

#### Scenario: Audit records the finalization decision
- **WHEN** completion succeeds
- **THEN** the system SHALL store the purchase and setting, actor, timestamp, required reason, source line quantities, approved receipt totals, final line outcomes, and financial before/after values
