## ADDED Requirements

### Requirement: Forward-receipt preparation is universally blind
Every authorized forward-receipt preparer SHALL receive the same blind preparation projection regardless of `stockTransfers.view-system-stock`, containing only permitted document context and operator-entered receipt observations while omitting the approved dispatch manifest, expected products, quantities, serials, allocation, destination stock, claim provenance, and differences.

#### Scenario: Preparers with different visibility open the same receipt
- **WHEN** a blind preparer and a stock-visible preparer independently open the same forward-receipt attempt
- **THEN** both preparation payloads omit all source-manifest and system-stock information

#### Scenario: Receipt preparer scans an observation
- **WHEN** any preparer scans a product or serial
- **THEN** the resulting payload contains only sanitized observation data and does not classify the entry as expected, unexpected, matching, missing, or excess

#### Scenario: Crafted preparation payload requests protected data
- **WHEN** a receipt preparer supplies expected values, visibility flags, stock snapshots, allocation, claim identity, or comparison results
- **THEN** the system ignores the client data and returns the universally blind server projection

### Requirement: Forward-receipt approval respects stock visibility
Forward-receipt approval permission SHALL NOT imply stock visibility: a blind approver SHALL receive only a neutral server-authoritative match/failure result, while an otherwise-authorized approver with `stockTransfers.view-system-stock` MAY receive exact dispatch-versus-receipt, stock, allocation, serial, custody, and difference details.

#### Scenario: Blind approver reviews receipt
- **WHEN** an approver lacks stock visibility
- **THEN** the browser receives only neutral non-quantitative match or discrepancy guidance

#### Scenario: Privileged approver reviews receipt
- **WHEN** an approver also holds stock visibility
- **THEN** the browser may receive the exact source and observed manifest, destination stock, applied provenance, serial sets, and differences needed for review

#### Scenario: Blind approval fails authoritative validation
- **WHEN** comparison, destination inventory, serial, custody, or route validation fails for a blind approver
- **THEN** no expected quantity, stock, allocation, serial provenance, claim, or difference value is disclosed

