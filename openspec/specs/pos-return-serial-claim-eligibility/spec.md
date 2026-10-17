# POS Return Serial Claim Eligibility Specification

## Purpose

Define serialized POS Return claim eligibility across repeated sale, return, replacement, and resale cycles while preventing duplicate claims against the same fulfilled dispatch occurrence and tracing customer-held serial occurrences.

## Requirements

### Requirement: Serialized Return Claims SHALL Be Scoped To Source Dispatch

The system SHALL identify a serialized POS Return claim by both the original serial identity and the persisted source dispatch occurrence. It MUST block another active, non-reversed return claim for the same serial from the same source dispatch, including ordinary serialized lines and synthesized serialized bundle-component lines. A completed return from an earlier dispatch MUST NOT block a return of the same serial from a later dispatch after that serial was received into sellable stock and sold again.

#### Scenario: Duplicate return from same dispatch is blocked
- **WHEN** a POS Return attempts to claim a serial from a source dispatch already claimed by another active, non-reversed POS Return
- **THEN** the system rejects the new claim as a duplicate
- **AND** no draft or execution-side mutation from the rejected attempt is persisted

#### Scenario: Resold serial from later dispatch is returnable
- **WHEN** a serial was returned from an earlier dispatch, received into sellable stock, and subsequently sold through a different source dispatch
- **AND** a POS Return claims that serial from the later source dispatch
- **THEN** the system accepts the serial claim subject to the normal eligibility and validation rules for the later dispatch
- **AND** the completed claim associated with the earlier dispatch does not make the serial permanently ineligible

#### Scenario: Resold bundle-component serial uses later dispatch occurrence
- **WHEN** a serialized bundle component was returned from an earlier component dispatch and subsequently sold through a different component dispatch
- **AND** POS Return submission synthesizes the component return line for the later sale
- **THEN** the system evaluates duplicate-claim exclusivity against the later component dispatch
- **AND** it does not reject the line solely because the same serial identity appears on the completed earlier return

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
