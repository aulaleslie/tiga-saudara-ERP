## MODIFIED Requirements

### Requirement: Movement attempts prevent competing operational documents
The system MUST serialize revisions within each movement lineage, MUST permit at most one open `DRAFT` or `PENDING` attempt and one approved attempt per lineage, and SHALL scope both `RETURN_DISPATCH` and `RETURN_RECEIPT` lineages by return batch so multiple batches can progress independently while corrections retain increasing revisions.

#### Scenario: Competing receipt attempt for one batch
- **WHEN** an open return receipt exists for a source batch
- **THEN** another receipt attempt for that batch is rejected

#### Scenario: Receipt for another batch
- **WHEN** a different approved return-dispatch batch remains active
- **THEN** an independent return-receipt lineage may be created

#### Scenario: Correction revision
- **WHEN** a rejected receipt is corrected
- **THEN** the next unique revision is created in the same return-batch lineage

### Requirement: Movement foundation exposes gated forward dispatch and receipt
The system SHALL expose authorized workflow version `2` forward dispatch, forward receipt, return dispatch, and return receipt surfaces with movement-type, tenant, operational-side, aggregate, lineage, source, lifecycle, permission, and visibility guards.

#### Scenario: Authorized original-side return receipt
- **WHEN** an original-location user has receive-create or receive-approval permission for the corresponding action and selects an approved return batch
- **THEN** the return-receipt surface is available under its universally blind or approval projection

#### Scenario: Wrong-side return receipt
- **WHEN** a user outside the original transfer location's active business invokes a receipt action
- **THEN** access is rejected without exposing or mutating the source manifest

#### Scenario: Legacy workflow invokes movement route
- **WHEN** a workflow version `1` transfer invokes the version `2` return-receipt surface
- **THEN** it is rejected and established legacy behavior remains authoritative

## ADDED Requirements

### Requirement: Return receipt source linkage is exact
Every return-receipt revision SHALL retain the approved return-dispatch movement, shared return-batch discriminator, transfer revision/policy context, reversed operational locations, and stock condition.

#### Scenario: Source dispatch is not approved
- **WHEN** receipt creation references a draft, pending, rejected, or cancelled return dispatch
- **THEN** creation fails without persisting a receipt

#### Scenario: Batch identity mismatches source
- **WHEN** a crafted receipt carries a return-batch identity different from its source dispatch
- **THEN** submission or approval fails without effects
