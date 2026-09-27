## ADDED Requirements

### Requirement: Approved receiving details retain their inventory transaction provenance
The system SHALL store a durable one-to-one link from an approved receiving detail to the `BUY` inventory transaction created when that detail is approved.

#### Scenario: New receiving approval creates provenance link
- **WHEN** the system approves a receiving detail and creates its `BUY` inventory transaction
- **THEN** the receiving detail and transaction SHALL be linked persistently

#### Scenario: Existing receipt has no durable transaction link
- **WHEN** a legacy approved receiving detail has no persisted transaction link
- **THEN** the system SHALL permit a feature that needs the link to attempt conservative evidence-based resolution
- **AND** the system SHALL refuse a mutation when the result is absent or ambiguous
