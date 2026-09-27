## MODIFIED Requirements

### Requirement: Receiving mirrors actual dispatched provenance
For workflow version `2`, approved forward receipt SHALL use the approved source dispatch as immutable quantity provenance, then apply those exact totals to destination buckets according to the approved route-policy snapshot: preserve source buckets for same-business routes, use only the applicable tax bucket for cross-business PKP destinations, and use only the applicable non-tax bucket for cross-business non-PKP destinations. Good/broken condition MUST remain unchanged.

#### Scenario: Same-business receipt
- **WHEN** an exact receipt approves under `PRESERVE` classification
- **THEN** destination stock receives the immutable source tax/non-tax allocation in the corresponding condition buckets

#### Scenario: Cross-business PKP destination
- **WHEN** an exact receipt approves for a PKP destination
- **THEN** the full received total is applied only to the destination tax bucket for the transfer condition

#### Scenario: Cross-business non-PKP destination
- **WHEN** an exact receipt approves for a non-PKP destination
- **THEN** the full received total is applied only to the destination non-tax bucket for the transfer condition

#### Scenario: Serialized reclassification
- **WHEN** a serialized receipt approves under tax or non-tax classification
- **THEN** each exact live serial receives the snapshotted destination tax ID or `null` respectively and immutable history preserves its prior and resulting tax identity

#### Scenario: Client supplies different provenance
- **WHEN** a receipt request supplies tax, allocation, stock, policy, or obligation values
- **THEN** the system ignores or rejects them and applies only locked authoritative source and route-policy records

### Requirement: Version 2 forward-receipt approval is the atomic destination boundary
For workflow version `2`, only approval of an exact pending `FORWARD_RECEIPT` SHALL apply destination-classified inventory, reclassify and move exact serials, close custody, remove active claims, create receipt transactions and snapshots, create any full-return obligations, approve/history-stamp the movement, and project the header to `COMPLETED` or `AWAITING_RETURN` according to the immutable policy.

#### Scenario: Submit receipt without approval
- **WHEN** a forward-receipt draft is submitted
- **THEN** it becomes pending without adding stock, reclassifying or moving serials, changing custody or claims, creating obligations, or changing transfer status

#### Scenario: Approve no-return receipt
- **WHEN** an exact receipt approves under a no-return policy
- **THEN** destination effects commit once, no obligation is created, and the transfer becomes `COMPLETED`

#### Scenario: Approve mandatory-return receipt
- **WHEN** an exact receipt approves under a mandatory-return policy
- **THEN** destination effects and full-product obligations commit once and the transfer becomes `AWAITING_RETURN`

#### Scenario: Receipt approval fails
- **WHEN** comparison, policy, tax resolution, provenance, stock, serial, custody, obligation, aggregate, or concurrency validation fails
- **THEN** no partial inventory, transaction, serial, custody, claim, obligation, history, movement, or header effect remains

