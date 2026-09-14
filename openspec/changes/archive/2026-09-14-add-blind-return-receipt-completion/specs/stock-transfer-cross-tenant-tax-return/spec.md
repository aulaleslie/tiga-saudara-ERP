## ADDED Requirements

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
