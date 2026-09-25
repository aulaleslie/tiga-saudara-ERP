# Spec Delta

## MODIFIED Requirements

### Requirement: Version 3 drafts and submission preserve goods intent
The system SHALL offer Simpan Draf and Ajukan Persetujuan on every workflow version `3` creation and edit path, including the standard create action and debt-dashboard prefill. Drafts SHALL permit zero-quantity product rows and incomplete serial selection, including an all-zero manifest, and SHALL restore those rows and valid selected serials when editing resumes. Submission SHALL require every retained product row to have a positive whole quantity, one document condition, and quantity equal to the distinct selected serial count for every serialized product. Creation and submission SHALL be atomic. Submission SHALL freeze a revision of the goods manifest, and material edits to pending goods SHALL return the document to draft and invalidate saved allocation approval context.

#### Scenario: Save an all-zero standard draft
- **WHEN** a creator enters products through the standard V3 create form, leaves every quantity at zero, and chooses Simpan Draf
- **THEN** the system persists the transfer and selected product rows in `DRAFT` status without stock, serial-custody, allocation, or debt effects

#### Scenario: Save incomplete serial entry
- **WHEN** a creator saves a serialized row with quantity five and four selected serials as a draft
- **THEN** the incomplete intent is retained without stock or custody effects

#### Scenario: Reopen zero and incomplete rows
- **WHEN** an authorized editor reopens a V3 draft containing zero-quantity rows or incomplete serialized rows
- **THEN** the form restores the products, quantities, and valid selected serial identities for continued entry

#### Scenario: Submit a zero-quantity row
- **WHEN** creation or edit submission contains any retained product row with quantity zero
- **THEN** submission fails with actionable Bahasa Indonesia feedback and preserves the draft and all its rows without a pending revision

#### Scenario: Submit incomplete serial entry
- **WHEN** creation or edit submission contains quantity five and four selected serials
- **THEN** submission fails with actionable Bahasa Indonesia feedback and no pending revision or partial creation

#### Scenario: Create and submit valid goods
- **WHEN** every retained product row has a positive whole quantity and every serialized row has an equal distinct valid serial count
- **THEN** exactly one numbered pending transfer and its creation/submission history are committed without requiring a prior Save Draft step

#### Scenario: Change goods while allocation is in progress
- **WHEN** an authorized editor materially changes the pending manifest
- **THEN** it becomes a new draft revision and any saved allocation configuration cannot authorize that revision without resubmission and review

## ADDED Requirements

### Requirement: Version 3 creation accepts validated zero-quantity product prefill
The workflow version `3` create surface SHALL accept a server-authorized product-only selection launched from cross-business debt-dashboard rows. It SHALL initialize one distinct entry row per valid selected product at quantity zero while retaining the ordinary form's existing condition behavior and the same draft and submission contract as standard V3 creation. The prefill context MUST NOT create a transfer or carry business, route, debt quantity, condition, or debt authority.

#### Scenario: Hydrate selected non-serialized products
- **WHEN** an authorized creator opens the V3 create form from a valid dashboard selection
- **THEN** each selected non-serialized product appears once at quantity zero and can be edited through the existing quantity controls

#### Scenario: Hydrate selected serialized products
- **WHEN** the valid selection contains a serialized product
- **THEN** its row starts with quantity zero and no serial, and existing serial scanning or selection derives quantity from distinct valid serials

#### Scenario: Save a zero serialized row
- **WHEN** a serialized product row remains at quantity zero in a draft
- **THEN** it is saved without serials; selecting a valid serial through the existing flow increases the derived quantity above zero

#### Scenario: Submit prefilled goods
- **WHEN** the user completes positive quantities and exact serialized selection for the prefilled rows
- **THEN** the existing V3 save or submission flow validates and persists the manifest exactly as if the products had been added manually

#### Scenario: Prefill does not dictate allocation routes
- **WHEN** an approver later configures source and destination allocations
- **THEN** only the existing authoritative V3 allocation workflow determines route businesses and the dashboard launch context is not trusted as execution authority
