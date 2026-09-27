## MODIFIED Requirements

### Requirement: Cross-tenant return obligations derive from approved route policy and full receipt
For a transfer whose origin and destination belong to different businesses, the system SHALL create full-product return obligations when the immutable route-policy snapshot shows either business is PKP, and SHALL create no return obligation when both businesses are non-PKP. Obligation quantities SHALL equal the exact approved forward-receipt quantities independent of tax provenance, and serialized obligations SHALL identify product and condition without requiring the original forward serial for later return dispatch.

#### Scenario: Different non-PKP businesses
- **WHEN** an exact forward receipt approves between two non-PKP businesses
- **THEN** no physical return obligation is created and the transfer can complete

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

### Requirement: Destination receipt determines whether return is awaited
After exact destination receipt, the system SHALL complete a same-business or cross-business non-PKP-to-non-PKP transfer, and SHALL place a cross-business transfer involving any PKP business into `AWAITING_RETURN` with full-product obligations.

#### Scenario: Receive cross-business non-PKP transfer
- **WHEN** the destination approves an exact transfer between distinct non-PKP businesses
- **THEN** the system marks the transfer complete after applying non-tax destination stock

#### Scenario: Receive PKP-involved cross-business transfer
- **WHEN** the destination approves an exact transfer whose snapshotted route involves PKP
- **THEN** the system marks the transfer `AWAITING_RETURN` and records full outstanding product quantities

#### Scenario: Receive same-business transfer
- **WHEN** origin and destination locations belong to the same business and destination receipt succeeds
- **THEN** the system preserves source tax provenance, creates no obligation, and completes the transfer

### Requirement: Return obligations and fulfillment are auditable
The system SHALL preserve route-policy identity, dispatched source provenance, destination reclassification, obligated full quantity, later returned quantity, actors, timestamps, serial history, and inventory transaction references for each transfer line.

#### Scenario: Review a mandatory transfer after forward receipt
- **WHEN** an authorized user views a PKP-involved cross-business transfer awaiting return
- **THEN** the audit projection identifies the snapshotted route, full required quantities, destination classification, and outstanding state

### Requirement: Historical transfers remain compatible
The system SHALL apply the new policy only prospectively and SHALL preserve existing transfer records, workflow versions, document numbers, and lifecycle state without destructive backfill or automatic reinterpretation.

#### Scenario: Existing transfer lacks new policy records
- **WHEN** the change is deployed
- **THEN** that transfer retains its stored behavior and does not acquire new mandatory-return work

#### Scenario: New transfer is approved after activation
- **WHEN** a new route is approved after Delivery 7 activation
- **THEN** its immutable policy snapshot determines workflow version, receipt classification, and obligation behavior

## REMOVED Requirements

### Requirement: Return dispatch moves only outstanding obligated provenance
**Reason**: The taxed-only and exact-original-serial contract conflicts with the confirmed full-product obligation and substitute-serial policy.

**Migration**: Delivery 8 will define return dispatch against full product/condition obligations, requiring full outstanding quantity while allowing eligible substitute serials.

### Requirement: Return receipt completes the obligation atomically
**Reason**: Return receipt execution belongs to Delivery 9 and must compare against the approved return-dispatch manifest rather than the superseded taxed-only obligation model.

**Migration**: Delivery 9 will define blind return receipt and final obligation completion atomically.

