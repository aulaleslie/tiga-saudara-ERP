## ADDED Requirements

### Requirement: Replacement entitlement follows the current held occurrence
The system SHALL support successive replacements or cash returns of the customer-held serialized occurrence through the original POS transaction without a return-count limit. Completed replacements SHALL preserve one customer-held unit. Historical serial reuse MUST NOT permanently disqualify a new occurrence; active duplicate claims on the same held occurrence MUST remain blocked. Lookup, approval and current receipts SHALL agree on the effective serial and preserve completed history.

#### Scenario: Replacement returns to an earlier serial identity
- **GIVEN** replacement A to B is completed
- **AND** A passes existing replacement eligibility checks
- **WHEN** replacement B to A is completed
- **THEN** the customer-held serial SHALL be A
- **AND** both events SHALL remain visible in order
- **AND** A SHALL remain eligible for a subsequent return from its new held occurrence

#### Scenario: Cash return after repeated replacement
- **WHEN** the customer cash-returns the current held unit after completed replacements
- **THEN** that unit's remaining entitlement SHALL be consumed once
- **AND** the receipt SHALL retain its completed replacement and cash-return history

#### Scenario: Multiple serialized units remain independent
- **GIVEN** a POS item has customer-held serials A, B and C
- **WHEN** A is replaced by eligible D and B by eligible E
- **THEN** the return SHALL record explicit A-to-D and B-to-E pairs
- **AND** C SHALL remain unaffected
- **AND** the same replacement serial SHALL not fulfill two units

### Requirement: Repeated replacements preserve existing eligibility and disposition rules
The system MUST apply existing replacement SKU, stock eligibility, condition, owner, location and reservation checks on every replacement. Existing receiving behavior SHALL determine the returned serial's good or bad disposition without automatic repair or restoration introduced by repeated-return support.

#### Scenario: Eligible previously returned serial can be selected again
- **WHEN** a previously returned serial passes existing replacement selection and execution validation
- **THEN** its prior return history alone SHALL not prevent selecting it

#### Scenario: Ineligible serial remains unavailable
- **WHEN** a proposed replacement serial fails existing checks because it is sold, reserved or otherwise ineligible
- **THEN** it SHALL be rejected without changing its condition or stock state

#### Scenario: Returned good and bad dispositions remain unchanged
- **WHEN** existing return processing assigns a returned serial to good or bad stock
- **THEN** repeated-return support SHALL preserve that assigned disposition
- **AND** SHALL not automatically make bad stock eligible for replacement
