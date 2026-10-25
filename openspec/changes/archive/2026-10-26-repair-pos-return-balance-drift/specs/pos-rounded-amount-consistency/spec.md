## MODIFIED Requirements

### Requirement: Affected draft repair is explicit and auditable
The system SHALL provide a setting-scoped repair operation for explicitly selected affected draft IDs, defaulting to read-only preview and requiring explicit apply mode for mutation. It SHALL recover missing amounts only from captured pricing evidence that reconciles to the existing header, preserve existing authoritative/manual amounts, and refuse ambiguous recovery. It SHALL NOT repair completed, cancelled, or loaded/active drafts. Apply SHALL lock and revalidate state, preserve before/after audit evidence and actor identity, and refresh the snapshot hash atomically.

#### Scenario: Preview and repair a reconcilable draft
- **WHEN** an operator previews a draft matching transaction 3393's captured inputs and an increment of 100
- **THEN** the preview SHALL show the affected row changing from fallback 25992 to authoritative 26000 and an unchanged header of 318000 without writing data
- **AND** explicit apply after successful state revalidation SHALL persist the missing amounts and audit evidence
- **AND** repeating apply SHALL make no additional monetary change

#### Scenario: Repair evidence is insufficient
- **WHEN** candidate recovered amounts do not reconcile to the saved header or historical pricing evidence is ambiguous
- **THEN** repair SHALL refuse mutation and identify the reason

#### Scenario: State changes after preview
- **WHEN** the transaction hash changes or the draft becomes loaded, active, cancelled, or completed before repair applies
- **THEN** repair SHALL refuse mutation and require a fresh eligible preview

#### Scenario: Completed transactions remain immutable
- **WHEN** deployment occurs or a completed transaction is selected for draft repair
- **THEN** its recorded row values, totals, payments, postings, and return snapshots SHALL remain unchanged

#### Scenario: Dedicated completed-sale balance repair
- **WHEN** the separate explicit POS return balance repair proves historical completed-sale header drift
- **THEN** it SHALL be permitted to correct only the proven header and balance fields with audit evidence
- **AND** authoritative rows, completed returns, payments, postings and checkout snapshots SHALL remain unchanged
