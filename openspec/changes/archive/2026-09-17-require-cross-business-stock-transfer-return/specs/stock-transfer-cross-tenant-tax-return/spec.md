## MODIFIED Requirements

### Requirement: Cross-tenant return obligations derive from approved route policy and full receipt
For a transfer whose origin and destination belong to different businesses, the system SHALL create full-product return obligations when the immutable route-policy snapshot requires return, including when both businesses are non-PKP. For newly approved transfers the snapshot SHALL require return for every cross-business route. Obligation quantities SHALL equal the exact approved forward-receipt quantities independent of tax provenance, and serialized obligations SHALL identify product and condition without requiring the original forward serial for later return dispatch. Previously approved snapshots SHALL retain their stored return decision.

#### Scenario: Different non-PKP businesses under the new policy
- **WHEN** an exact forward receipt approves between two non-PKP businesses whose route-policy snapshot requires return
- **THEN** the full received product quantities become return obligations and the transfer awaits return

#### Scenario: PKP to non-PKP
- **WHEN** an exact forward receipt approves from a PKP business to a non-PKP business
- **THEN** every received product's full quantity becomes obligated after being classified as destination non-tax stock

#### Scenario: Non-PKP to PKP
- **WHEN** an exact forward receipt approves from a non-PKP business to a PKP business
- **THEN** every received product's full quantity becomes obligated after being classified as destination tax stock

#### Scenario: PKP to PKP
- **WHEN** an exact forward receipt approves between distinct PKP businesses
- **THEN** every received product's full quantity becomes obligated as destination tax stock

#### Scenario: Serialized obligation
- **WHEN** a mandatory-route receipt contains serialized stock
- **THEN** the obligation records product, quantity, and condition while later return dispatch may satisfy it with eligible substitute serials

#### Scenario: Existing no-return snapshot
- **WHEN** an exact forward receipt approves for a transfer previously approved with `mandatory_return = false`
- **THEN** the system creates no return obligation and preserves that transfer's approved lifecycle decision

### Requirement: Destination receipt determines whether return is awaited
After exact destination receipt, the system SHALL complete a same-business transfer, SHALL place a newly approved cross-business transfer into `AWAITING_RETURN` with full-product obligations regardless of PKP status, and SHALL honor the stored return decision of a previously approved route-policy snapshot.

#### Scenario: Receive cross-business non-PKP transfer under the new policy
- **WHEN** the destination approves an exact transfer between distinct non-PKP businesses whose snapshot requires return
- **THEN** the system marks the transfer `AWAITING_RETURN` and records full outstanding product quantities

#### Scenario: Receive PKP-involved cross-business transfer
- **WHEN** the destination approves an exact cross-business transfer whose snapshotted route involves PKP
- **THEN** the system marks the transfer `AWAITING_RETURN` and records full outstanding product quantities

#### Scenario: Receive same-business transfer
- **WHEN** origin and destination locations belong to the same business and destination receipt succeeds
- **THEN** the system preserves source tax provenance, creates no obligation, and completes the transfer

#### Scenario: Receive transfer approved before activation
- **WHEN** the destination approves an exact transfer whose previously approved snapshot does not require return
- **THEN** the system creates no return obligation and completes the transfer
