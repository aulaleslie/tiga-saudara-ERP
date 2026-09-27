# Spec Delta

## Purpose

Define serialized POS Return claim eligibility across repeated sale, return, and resale cycles while preventing duplicate claims against the same fulfilled dispatch occurrence.

## ADDED Requirements

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
