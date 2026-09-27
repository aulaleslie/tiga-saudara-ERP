# stock-transfer-route-policy Specification

## Purpose
Immutable per-transfer route-policy snapshot resolved at approval, deriving destination classification, mandatory-return decision, and applicable tax identity from origin/destination business and PKP status, and applied prospectively without reinterpreting existing transfers.

## Requirements

### Requirement: Transfer approval snapshots immutable route policy
The system SHALL create exactly one immutable route-policy snapshot for the approved transfer revision before a new workflow version `2` transfer becomes operational, containing origin and destination location/business identities, PKP flags, same-business status, stock condition, mandatory-return decision, destination classification, applicable tax snapshot, approval actor, and timestamp.

#### Scenario: Settings change after approval
- **WHEN** either participating business changes PKP status or tax configuration after transfer approval
- **THEN** dispatch, receipt, classification, and return obligations continue using the approved snapshot

#### Scenario: Approval is replayed
- **WHEN** the same transfer approval is repeated with the same idempotency identity
- **THEN** exactly one equivalent route-policy snapshot exists

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

### Requirement: Destination tax identity is resolved deterministically
For a cross-business PKP destination, approval MUST snapshot the destination's configured default applicable tax or, when absent, the first applicable active destination tax in stable primary-key order; approval MUST fail when no applicable tax exists. A non-PKP destination SHALL snapshot non-tax classification without a tax identity.

#### Scenario: PKP destination has a default tax
- **WHEN** an eligible configured default tax exists at approval
- **THEN** its ID, name, rate, and default resolver provenance are snapshotted

#### Scenario: PKP destination has no default tax
- **WHEN** applicable active destination taxes exist but none is marked default
- **THEN** the lowest-ID applicable tax is snapshotted with fallback resolver provenance

#### Scenario: PKP destination has no applicable tax
- **WHEN** approval cannot resolve an applicable destination tax
- **THEN** approval fails atomically without creating a policy snapshot or changing transfer state

### Requirement: Route policy applies prospectively
The system SHALL assign the PKP-capable workflow version only to newly approved transfers and MUST NOT backfill, reopen, or reinterpret existing transfer records.

#### Scenario: Existing transfer lacks a policy snapshot
- **WHEN** this capability is deployed
- **THEN** the existing transfer retains its stored workflow version and lifecycle behavior

### Requirement: Version 3 freezes route policy per allocation without automatic returns
For version 3, approval SHALL freeze source/destination location and business identities, condition, cross-business flag, and destination tax-classification evidence per allocation. Cross-business marking SHALL depend solely on different business identities, regardless of PKP status. Same-business routes SHALL preserve source buckets; cross-business routes SHALL use the established destination PKP tax or non-tax classification and deterministic applicable tax resolution. Automatic return obligations and return actions SHALL not be created for version 3.

#### Scenario: Mixed routes in one document
- **WHEN** one allocation stays within a business and another crosses to a different business
- **THEN** each has its own immutable cross-business classification and the document completes on receipt without return obligations

#### Scenario: Cross-business non-PKP transfer
- **WHEN** source and destination businesses differ and both are non-PKP
- **THEN** the allocation is marked cross-business, receipt uses destination non-tax stock, and no automatic return is required

#### Scenario: Tax settings change after dispatch
- **WHEN** destination tax settings change before confirmed receipt
- **THEN** receipt uses the approved allocation's immutable tax snapshot

#### Scenario: Legacy outstanding return
- **WHEN** a legacy transfer has an existing mandatory-return policy or outstanding obligation
- **THEN** that stored obligation and its original completion process remain unchanged
