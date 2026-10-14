# Spec Delta

## ADDED Requirements

### Requirement: Fully returned zero-value split Sales do not block sibling collection
The system SHALL allow global payment of a completed POS transaction with valid posted-checkout Sale mappings and positive aggregate live due when each reachable Sale either meets normal payment eligibility or is fully returned with zero current total and zero canonical live due. A fully returned zero-value Sale SHALL qualify for this exception whether archived or unarchived, as soon as its return quantity and financial corrections are committed, without requiring return completion. The system SHALL apply this rule consistently to register payment actions, payment candidates, form access, preview, and submission.

#### Scenario: Returned split reaches zero before return completion
- **GIVEN** a split Sale is fully returned with committed zero quantity, zero total, and zero canonical live due while its return is not yet completed
- **AND** an eligible sibling Sale has Rp100,000 live due
- **WHEN** an authorized user opens and previews global POS payment
- **THEN** the transaction is payable for its remaining Rp100,000 balance
- **AND** return completion is not required

#### Scenario: Archived returned split remains non-blocking
- **GIVEN** a fully returned split Sale has zero total and zero canonical live due and is archived
- **AND** an eligible sibling Sale has positive live due
- **WHEN** the transaction appears in global payment and the user opens, previews, and submits a valid payment
- **THEN** the archived returned Sale does not block collection
- **AND** its original Sale link and history remain available

#### Scenario: Payment is allocated only to outstanding eligible siblings
- **GIVEN** a transaction includes a fully returned zero-value Sale and eligible sibling Sales with outstanding debt
- **WHEN** a valid POS payment is submitted
- **THEN** payment follows existing owner priority across outstanding eligible siblings
- **AND** the returned zero-value Sale receives no new payment or positive payment allocation audit entry

#### Scenario: All split balances are zero
- **WHEN** every reachable Sale has zero canonical live due
- **THEN** the POS transaction remains non-payable and is excluded from payable candidates
- **AND** it retains its existing financial projection and history

#### Scenario: Returned Sale retains a bill or outstanding debt
- **WHEN** a fully returned reachable Sale has a nonzero total or positive canonical live due
- **THEN** it does not qualify for the exception
- **AND** the transaction is rejected for global payment under existing lifecycle eligibility rules

#### Scenario: Unrelated ineligible Sale does not qualify
- **WHEN** a reachable Sale is archived or otherwise lifecycle-ineligible and is not fully returned, even if its total and live due are zero
- **THEN** it does not qualify for the exception
- **AND** existing transaction payment restrictions remain in effect

#### Scenario: Broken Sale mapping remains blocking
- **WHEN** the POS transaction has missing or inconsistent generated Sale mappings alongside a returned zero-value Sale
- **THEN** the transaction remains invalid for global payment
- **AND** the returned Sale is not removed from mapping diagnostics

#### Scenario: Submission checks fresh locked state
- **GIVEN** a preview accepted a returned zero-value Sale alongside a payable sibling
- **WHEN** locked submission finds that the returned Sale no longer qualifies, the sibling no longer meets payment eligibility, or the submitted allocation exceeds current live due
- **THEN** the complete batch is rejected atomically
- **AND** no partial payments or payment allocation audit entries are persisted
