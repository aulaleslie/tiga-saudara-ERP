## ADDED Requirements

### Requirement: Supplier details tolerate an absent payment term
The system SHALL render the supplier detail page when the supplier has no resolvable payment-term relationship and SHALL display `-` as the payment-term value.

#### Scenario: Supplier has no payment term
- **WHEN** an authorized user opens the detail page for a supplier whose `payment_term_id` is null
- **THEN** the system renders the supplier detail page successfully
- **AND** the payment-term field displays `-`

#### Scenario: Supplier payment-term relation cannot be resolved
- **WHEN** an authorized user opens the detail page for a supplier whose payment-term relationship resolves to no record
- **THEN** the system renders the supplier detail page successfully
- **AND** the payment-term field displays `-`

### Requirement: Supplier details display the assigned payment term
The system SHALL display the related payment term's name when the supplier has a resolvable payment-term relationship.

#### Scenario: Supplier has a valid payment term
- **WHEN** an authorized user opens the detail page for a supplier assigned to an existing payment term
- **THEN** the system renders the supplier detail page successfully
- **AND** the payment-term field displays the related payment term's name
