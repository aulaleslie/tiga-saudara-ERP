# sales-return-history-audit Specification

## Purpose
Provides read-only evidence for historical return anomalies so operators can distinguish proven inconsistencies from ambiguous records before any separate corrective work.

## Requirements

### Requirement: Historical return audit is read only
Authorized users SHALL be able to audit returns by business and source-sale or return identifiers. Audit MUST report evidence and affected identities for suspected stock/serial mismatch, duplicate payouts, premature completion, over-return claims, and mixed standard/POS histories. It MUST NOT mutate domain records or perform repairs.

#### Scenario: Same-serial phantom stock suspected
- **WHEN** historical receiving and same-serial outbound evidence suggests an unreconciled warehouse balance
- **THEN** audit reports the return, source sale, serial, location, movements, and the suspected discrepancy without changing them

#### Scenario: Financial or completion anomaly
- **WHEN** evidence suggests duplicate payouts or completion with missing settlement coverage
- **THEN** audit identifies the relevant records and evidence without changing status, payments, or balances

### Requirement: Audit distinguishes proof from ambiguity
Audit SHALL classify findings as proven, suspected, or ambiguous and describe missing evidence. Aggregate stock alone MUST NOT prove a historical error where later movements could explain the balance. Existing mixed histories MUST be identified before unsafe new financial or entitlement actions are allowed.

#### Scenario: Later stock movements obscure a discrepancy
- **WHEN** current stock cannot establish whether a historical dispatch was deducted
- **THEN** audit reports ambiguity rather than proposing an automatic stock correction

#### Scenario: Both workflows target one source sale
- **WHEN** historical standard and POS return claims share source-sale entitlement
- **THEN** audit reports both lineages and the overlap
- **AND** unsafe new financial or entitlement actions are blocked with a reason until separately resolved

### Requirement: Deployment preserves historical evidence
Deployment, migration, browsing, audit, and preview SHALL leave historical completed returns, payments, source-sale values, custody evidence, and archived statuses unchanged. Legacy unreceived standard returns MUST use the new custody policy when first received; already-received legacy records MUST NOT be silently converted into new held balances.

#### Scenario: Older completed return is read
- **WHEN** an older completed return is displayed or audited after deployment
- **THEN** its recorded status, amounts, payments, and stock history remain unchanged

#### Scenario: Older unreceived return receives goods
- **WHEN** an approved legacy standard return is physically received for the first time after deployment
- **THEN** it records explicit new-policy held custody without rewriting prior evidence

#### Scenario: Already-received legacy return lacks custody evidence
- **WHEN** a new mutation would require inferring held balances for an already-received legacy return
- **THEN** the system blocks that mutation with an actionable audit reason instead of adding or removing stock speculatively
