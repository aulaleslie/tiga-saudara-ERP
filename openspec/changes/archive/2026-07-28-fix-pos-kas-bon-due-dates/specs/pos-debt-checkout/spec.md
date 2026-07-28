## MODIFIED Requirements

### Requirement: Debt checkout SHALL require a payment term and compute the due date
The debt path SHALL require the cashier to select a payment term from the existing `payment_terms`. The selected term SHALL remain part of the authoritative checkout context through staged payment and finalization. Every `Sale` document generated from that Kas Bon checkout, including each owner-specific Sale created by split posting, SHALL set `payment_term_id` to the selected term and set `due_date` to the checkout posting date plus the selected term's `longevity` in days.

#### Scenario: Term is required
- **WHEN** a cashier submits a debt checkout without selecting a payment term
- **THEN** the system MUST reject the checkout and MUST prompt for a payment term

#### Scenario: Due date derived from term longevity
- **WHEN** a cashier selects a term with `longevity = 30` and completes a debt checkout on a given date
- **THEN** the posted sale MUST set `payment_term_id` to that term and `due_date` to the checkout date plus 30 days

#### Scenario: Staged Kas Bon preserves its selected term
- **WHEN** a cashier selects a payment term in the staged Kas Bon flow and completes checkout through the payment-chain finalization request
- **THEN** the generated Sale documents MUST retain that selected `payment_term_id`
- **AND** each generated Sale document MUST use the due date calculated from that term's longevity

#### Scenario: Split Kas Bon documents share the selected term and due date
- **WHEN** a Kas Bon checkout with a selected payment term creates Sales documents for multiple source-owner groups
- **THEN** every generated Sale MUST use the selected `payment_term_id`
- **AND** every generated Sale MUST have the same due date, calculated from the checkout posting date plus the selected term's longevity
