## ADDED Requirements

### Requirement: Return-receipt preparation is universally blind
Every authorized return-receipt preparer SHALL receive the same blind preparation projection regardless of `stockTransfers.view-system-stock`, containing only permitted document context and operator-entered observations while omitting the source return-dispatch products, quantities, serials, allocations, reservations, custody, stock, obligations, and differences.

#### Scenario: Preparers with different visibility open a receipt
- **WHEN** blind, privileged, and Super Admin preparers open the same return receipt
- **THEN** every preparation payload omits all source-manifest and system-state information

#### Scenario: Receipt scan resolves
- **WHEN** any preparer scans a product or serial
- **THEN** the response contains sanitized observation data without expected, unexpected, matching, missing, excess, custody, or obligation classification

#### Scenario: Receipt scan is ineligible
- **WHEN** authoritative scan validation fails
- **THEN** the browser receives neutral guidance that does not distinguish protected location, condition, tax, custody, reservation, or manifest facts

### Requirement: Return-receipt approval respects stock visibility
Return-receipt approval permission SHALL NOT imply stock visibility: blind approvers SHALL receive only neutral authoritative match or discrepancy guidance, while stock-visible approvers MAY receive exact source-versus-observed, stock, tax, serial, custody, obligation, reservation, and difference detail.

#### Scenario: Blind approver reviews receipt
- **WHEN** an approver lacks stock visibility
- **THEN** protected keys and distinctive values are absent from HTML, JSON, session, validation, and client state

#### Scenario: Privileged approver reviews discrepancy
- **WHEN** an approver has stock visibility
- **THEN** exact source, observation, allocation, tax, custody, reservation, and difference details may be shown

#### Scenario: Crafted approval supplies protected state
- **WHEN** any client supplies expected manifest, comparison, stock, tax, obligation, reservation, claim, or allocation values
- **THEN** the system ignores or rejects them and derives approval solely from locked server data
