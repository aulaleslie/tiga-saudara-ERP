## MODIFIED Requirements

### Requirement: Mandatory-return policy follows the approved route matrix
The snapshot SHALL require no return when the origin and destination locations belong to the same business, regardless of PKP status, and SHALL require full return when they belong to different businesses, regardless of either business's PKP status. PKP status SHALL continue to determine destination classification for cross-business routes. The new rule SHALL apply to transfers approved after activation; already approved route-policy snapshots SHALL remain unchanged.

#### Scenario: Same-business route
- **WHEN** origin and destination locations belong to the same business regardless of PKP status
- **THEN** the snapshot marks classification as `PRESERVE` and mandatory return as false

#### Scenario: Cross-business non-PKP route
- **WHEN** both distinct businesses are non-PKP
- **THEN** the snapshot marks destination classification as `NON_TAX` and mandatory return as true

#### Scenario: Cross-business route involving PKP
- **WHEN** the businesses differ and either business is PKP
- **THEN** the snapshot classifies for the destination PKP status and requires full return

#### Scenario: Pending transfer approved after activation
- **WHEN** a pending cross-business non-PKP to non-PKP transfer is approved after the new rule is activated
- **THEN** its immutable route-policy snapshot requires full return

#### Scenario: Previously approved route
- **WHEN** a transfer was approved with an immutable route-policy snapshot before activation
- **THEN** its stored mandatory-return decision remains unchanged through dispatch and receipt
