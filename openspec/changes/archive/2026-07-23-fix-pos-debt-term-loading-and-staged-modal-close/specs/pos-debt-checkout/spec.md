## ADDED Requirements

### Requirement: Debt checkout SHALL expose existing payment terms reliably
When a named customer enables the debt checkout sub-flow, the POS SHALL load every existing `payment_terms` row available to the application and present the returned terms for selection. The POS MUST NOT treat a failed or invalid payment-term response as a successful empty result.

#### Scenario: Existing payment terms populate the debt selector
- **WHEN** an authorized cashier enables debt checkout and one or more payment terms exist
- **THEN** the POS payment-term endpoint MUST return the existing terms with their identifiers, names, and longevity values
- **AND** the staged-payment modal MUST present those terms for selection

#### Scenario: No payment terms exist
- **WHEN** an authorized cashier enables debt checkout and the payment-term endpoint succeeds with no existing terms
- **THEN** the modal MUST state that no payment terms are available
- **AND** debt checkout continuation MUST remain disabled

#### Scenario: Payment-term loading fails
- **WHEN** the payment-term request returns a non-success response, an invalid payload, or a network failure
- **THEN** the staged-payment modal MUST display an actionable payment-term loading error
- **AND** debt checkout continuation MUST remain disabled
- **AND** the cashier MUST be able to retry loading terms without reloading the entire POS page
