## MODIFIED Requirements

### Requirement: Tax-allocation drift uses version-aware dispatch review
For legacy workflow version `1`, the system SHALL preserve established tax-allocation drift acknowledgement. For workflow version `2`, the system SHALL derive and persist authoritative origin bucket allocation during forward-dispatch approval, SHALL treat bucket drift independently from product/quantity/serial request comparison, and SHALL expose exact allocation only to a user with stock visibility.

#### Scenario: Legacy taxed allocation increases
- **WHEN** a version `1` dispatch would increase taxed quantity or its legacy mandatory-return impact
- **THEN** the existing permission-aware drift acknowledgement behavior remains unchanged

#### Scenario: Version 2 physical count matches but allocation changes
- **WHEN** product, quantity, condition, and serial comparison passes but locked origin bucket allocation differs from the approved preview
- **THEN** bucket drift alone does not make the physical count a mismatch and approval may apply the authoritative allocation when total eligible stock is sufficient

#### Scenario: Blind version 2 approver encounters allocation drift
- **WHEN** authoritative allocation differs for an approver without stock visibility
- **THEN** no exact bucket, quantity, difference, hash, or return-impact information is sent to the browser

#### Scenario: Stock changes before approval locks
- **WHEN** total eligible stock becomes insufficient before version `2` approval obtains its locks
- **THEN** approval fails atomically without applying a stale allocation

### Requirement: Dispatch persists immutable actual provenance
Successful dispatch SHALL persist actual base quantities by tax and broken bucket, selected serial snapshots, dispatcher and approver identities, timestamps, and inventory transaction references independently from the approved preview. Version `1` SHALL retain its established serial-location behavior, while version `2` approved forward dispatch SHALL represent serialized goods through exclusive in-transit custody and leave live serial location at the last confirmed origin until receipt approval.

#### Scenario: Persist mixed actual allocation
- **WHEN** dispatch moves three non-tax and two taxed base units
- **THEN** the applicable immutable dispatch line records three non-tax and two taxed units and inventory transactions reflect the same origin bucket changes

#### Scenario: Dispatch selected serials through legacy workflow
- **WHEN** a workflow version `1` serialized line is dispatched
- **THEN** the established live-serial movement and exact dispatch snapshot behavior remains unchanged

#### Scenario: Dispatch selected serials through version 2
- **WHEN** a workflow version `2` serialized forward-dispatch movement is approved
- **THEN** the system validates each locked serial at the origin, derives authoritative tax and condition provenance, activates exclusive transit custody, retains origin as the live serial's last confirmed location, and records immutable serial and inventory history

## ADDED Requirements

### Requirement: Version 2 forward-dispatch approval is the atomic origin boundary
For workflow version `2`, only approval of an exact pending forward-dispatch movement SHALL deduct origin inventory, activate serial custody, create inventory transactions, approve the movement, record history, and project the transfer header to `DISPATCHED`, and all effects MUST occur in one locked idempotent transaction.

#### Scenario: Submit dispatch without approval
- **WHEN** a version `2` forward-dispatch draft is submitted
- **THEN** it becomes pending without deducting stock, creating inventory transactions, activating custody, or changing transfer status

#### Scenario: Approve dispatch once
- **WHEN** an exact and fulfillable pending forward dispatch is approved
- **THEN** its complete origin inventory, custody, audit, movement, and header effects commit together exactly once

#### Scenario: Approval fails or races
- **WHEN** validation fails, a later line fails, or concurrent approval loses the locked race
- **THEN** no partial inventory, custody, transaction, history, movement, or header effect remains

