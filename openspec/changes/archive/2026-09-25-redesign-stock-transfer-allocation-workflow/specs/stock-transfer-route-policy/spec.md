# Spec Delta

## ADDED Requirements

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
