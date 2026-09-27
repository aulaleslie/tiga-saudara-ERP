## MODIFIED Requirements

### Requirement: Transfers support a staged versioned movement foundation
The system SHALL store a workflow version on every stock transfer and SHALL assign workflow version `2` prospectively to new same-business, cross-business non-PKP-to-non-PKP, and PKP-involved transfers once the applicable route-policy snapshot can be committed at approval. Existing transfers SHALL retain their stored workflow version and behavior without backfill or reinterpretation.

#### Scenario: New no-return transfer uses version 2
- **WHEN** a new transfer is same-business or both participating businesses are non-PKP
- **THEN** approval snapshots the route and enables workflow version `2` forward dispatch and receipt

#### Scenario: New PKP-involved transfer uses version 2
- **WHEN** a new cross-business transfer involving PKP is approved after Delivery 7 activation
- **THEN** approval snapshots destination classification and mandatory-return policy before enabling workflow version `2`

#### Scenario: Policy snapshot cannot be created
- **WHEN** route or tax policy cannot be resolved authoritatively during approval
- **THEN** approval fails without changing workflow version or transfer state

#### Scenario: No historical transfer migration
- **WHEN** Delivery 7 is deployed
- **THEN** existing transfer records receive no legacy transaction backfill, policy snapshot, obligation, reopening, or reinterpretation

