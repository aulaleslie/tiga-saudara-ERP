# stock-transfer-cross-tenant-tax-return Specification

## Purpose
TBD - created by archiving change harden-stock-transfer-lifecycle-and-scanning. Update Purpose after archive.
## Requirements
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

### Requirement: Return receipt classifies inventory for the original business
Approval of an exact return receipt SHALL preserve good/broken condition and classify the entire accepted quantity according to the original location's authoritative business PKP status at processing time.

#### Scenario: Return to non-PKP origin
- **WHEN** the original business is non-PKP at processing time
- **THEN** all accepted good or broken quantity enters its corresponding non-tax bucket and returned serial tax identity becomes null

#### Scenario: Return to PKP origin
- **WHEN** the original business is PKP at processing time
- **THEN** all accepted good or broken quantity enters its corresponding taxed bucket and returned serials receive the resolved tax identity

### Requirement: PKP origin tax resolves deterministically during receipt approval
For a PKP origin, approval MUST lock authoritative configuration, select the configured default applicable tax or otherwise the lowest-ID applicable active tax, fail when neither exists, and snapshot the selected tax ID, name, rate, resolver provenance, setting, and processing time on the receipt.

#### Scenario: Configured default tax exists
- **WHEN** a valid applicable default tax is configured at processing time
- **THEN** that tax is snapshotted and applied to the receipt inventory and serials

#### Scenario: Default tax is absent
- **WHEN** no configured default exists but applicable active taxes exist
- **THEN** the lowest-ID applicable tax is selected and fallback provenance is recorded

#### Scenario: No applicable tax exists
- **WHEN** a PKP origin has neither a valid default nor an applicable active tax
- **THEN** approval fails atomically without inventory, serial, custody, reservation, obligation, movement, or header effects

#### Scenario: Tax configuration later changes
- **WHEN** tax master data changes after receipt approval
- **THEN** historical receipt classification remains represented by its immutable snapshot

