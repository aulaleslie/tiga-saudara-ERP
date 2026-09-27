# Spec Delta

## MODIFIED Requirements

### Requirement: Debt checkout SHALL require a named customer
The debt path SHALL be blocked unless the cart resolves to an active customer. An explicitly selected customer and the configured default walk-in customer SHALL both be eligible for debt checkout. An unresolved or inactive customer MUST NOT be allowed to complete as debt.

#### Scenario: Debt allowed for default walk-in customer
- **WHEN** a cashier attempts finish-as-debt while the cart resolves to the configured active default walk-in customer
- **THEN** the system MUST allow the debt sub-flow to proceed

#### Scenario: Debt allowed for named customer
- **WHEN** a cashier attempts finish-as-debt while the cart resolves to an explicitly selected active customer
- **THEN** the system MUST allow the debt sub-flow to proceed

#### Scenario: Debt blocked for guest customer
- **WHEN** a cashier attempts finish-as-debt while the cart has no resolved customer
- **THEN** the system MUST reject the debt checkout and MUST prompt the cashier to select or configure a customer

#### Scenario: Debt blocked for inactive customer
- **WHEN** a cashier attempts finish-as-debt while the resolved customer is inactive
- **THEN** the system MUST reject the debt checkout as having an invalid customer
