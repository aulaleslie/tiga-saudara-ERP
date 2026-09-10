# breakage-adjustment-workflow Specification

## Purpose
Modernize breakage adjustment entry with the same searchable/scannable location, product, and serial-entry experience as Stock Adjustment, while enforcing breakage's narrower rule: move available stock from good to broken at one location without changing tax classification or total physical quantity. Give approvers a clear, durable, Bahasa Indonesia explanation of the inventory effect they authorize, and persist an immutable record of what approval actually applied.

## Requirements

### Requirement: Breakage entry is location-bound and searchable
The system SHALL provide breakage create and edit forms with a searchable standard-location selector whose choices belong to the active setting and exclude consignment locations. Product or serial entry SHALL require a selected location. Changing a populated form's location SHALL require confirmation and, when confirmed, clear all products, quantities, selected serials, errors, and location-derived preview state.

#### Scenario: Select a standard location
- **WHEN** an authorized user searches for and selects a standard location belonging to the active setting
- **THEN** the selected label and location identifier persist and product entry becomes available

#### Scenario: Reject an unauthorized destination
- **WHEN** a client submits a consignment location or a location outside the active setting
- **THEN** the system rejects the request without creating or changing a breakage document

#### Scenario: Change a populated location
- **WHEN** the user confirms a location change after adding breakage rows
- **THEN** the system clears all location-dependent entry state and uses the new location
- **AND** cancelling the confirmation preserves the prior location and rows

### Requirement: Breakage supports product search and unified scanning
The breakage editor SHALL provide tokenized product search and one main input that resolves primary product barcodes, supported conversion barcodes, and existing serial numbers. It SHALL preserve scanner focus after successful entry, retain/select invalid input for correction, and require an explicit candidate choice for ambiguous matches.

#### Scenario: Search for a product
- **WHEN** a user selects an active stock-managed product from search results after choosing a location
- **THEN** the editor adds one zero-quantity row or focuses the existing row without resetting its state

#### Scenario: Scan an ordinary product
- **WHEN** a non-serialized product barcode is scanned
- **THEN** its breakage quantity increases by one base unit without creating a duplicate row

#### Scenario: Scan a conversion barcode
- **WHEN** a non-serialized product conversion barcode with a supported integer base-unit factor is scanned
- **THEN** its breakage quantity increases by that factor

#### Scenario: Scan a serialized product barcode
- **WHEN** a serialized product or conversion barcode is scanned
- **THEN** its row is added or focused without increasing quantity
- **AND** quantity remains derived from valid serial scans

#### Scenario: Resolve an ambiguous code
- **WHEN** scanned text has multiple valid product, conversion, or serial interpretations
- **THEN** the editor changes no row until the user selects one candidate

### Requirement: Breakage quantity uses one PKP-governed bucket
The system SHALL expose one base-unit breakage quantity per non-serialized product and SHALL derive its internal bucket from the current `is_pkp` value of the selected location's setting. A PKP location SHALL move only tax good stock to tax broken stock; a Non-PKP location SHALL move only non-tax good stock to non-tax broken stock. Breakage SHALL NOT move quantity between tax classifications.

#### Scenario: Enter breakage at a PKP location
- **WHEN** a user enters breakage quantity 3 at a PKP location
- **THEN** the document records 3 in the tax movement and zero in the non-tax movement

#### Scenario: Enter breakage at a Non-PKP location
- **WHEN** a user enters breakage quantity 3 at a Non-PKP location
- **THEN** the document records 3 in the non-tax movement and zero in the tax movement

#### Scenario: Encounter stock in an unexpected bucket
- **WHEN** requested breakage would rely on an unexpected non-tax bucket at a PKP location or tax bucket at a Non-PKP location
- **THEN** the system reports a data conflict and does not reclassify or move that quantity

### Requirement: Serialized breakage accepts only selected-location good serials
For serialized products, the system SHALL accept only an existing serial for the selected product that belongs to the selected location and is sellable according to the canonical serial availability rules. Unknown, wrong-product, wrong-location, inactive, missing, dispatched, returning, already-broken, or duplicate serials SHALL NOT contribute quantity. The line quantity SHALL equal the number of accepted serials and SHALL be read-only.

#### Scenario: Scan an eligible good serial
- **WHEN** an active, undispatched, non-returning, non-broken serial for the product is scanned at its current selected location
- **THEN** the serial is added once and the product's breakage quantity increases by one

#### Scenario: Scan a serial from another location
- **WHEN** a serial belongs to a location other than the breakage document's selected location
- **THEN** the editor rejects it with a location-specific message and changes no quantity

#### Scenario: Scan an unavailable or broken serial
- **WHEN** a serial is dispatched, returning, unavailable by lifecycle status, or already broken
- **THEN** the editor rejects it with the applicable reason and changes no quantity

#### Scenario: Scan an unknown or duplicate serial
- **WHEN** serial text is unknown or already selected in the document
- **THEN** the editor creates no serial and does not increase breakage quantity

#### Scenario: Serial classification conflicts with location PKP
- **WHEN** an otherwise eligible serial's persisted tax classification conflicts with the selected location setting's current PKP classification
- **THEN** the system reports a data conflict and SHALL NOT change the serial's tax classification

### Requirement: Submission preserves intent without mutating inventory
Creating or editing a breakage adjustment SHALL atomically persist the pending document and its positive requested movements without changing product stock, serial condition, product aggregates, or inventory transactions. Submission SHALL authoritatively validate active-setting location ownership, product scope, PKP allocation, current good-stock sufficiency, and serialized line membership and count.

#### Scenario: Save a valid breakage request
- **WHEN** an authorized user submits valid non-serialized and serialized breakage lines
- **THEN** the system stores a pending breakage document without changing inventory

#### Scenario: Submitted quantity exceeds available good stock
- **WHEN** a requested non-serialized quantity exceeds the valid good bucket at the selected location
- **THEN** submission fails with a product-specific message and no document or inventory mutation is committed

#### Scenario: Tamper with serialized quantity or identity
- **WHEN** submitted serialized quantity differs from accepted serial count or a serial no longer satisfies product and location rules
- **THEN** submission fails without persisting partial document changes or inventory mutations

### Requirement: Review explains and gates the approval consequence
For pending breakage documents, authorized reviewers SHALL see a Bahasa Indonesia summary and per-product current good/broken quantities, requested movement, projected good/broken result, PKP bucket, drift, and blocking conflicts. Serialized rows SHALL show each serial's selected location and `Bagus → Rusak` transition. If any current line or serial is invalid, the view SHALL identify the reason and approval SHALL be unavailable through both the user interface and server endpoint.

#### Scenario: Review a valid pending document
- **WHEN** current stock and serial state can satisfy every requested movement
- **THEN** the reviewer sees that total physical quantity and tax classification remain unchanged and can approve with appropriate permission

#### Scenario: Review an excessive breakage request
- **WHEN** requested breakage exceeds the currently available good bucket
- **THEN** the reviewer sees the available amount, shortage, and blocked status
- **AND** approval cannot be performed

#### Scenario: Review a serial conflict
- **WHEN** a selected serial is no longer good or no longer belongs to the selected location
- **THEN** the reviewer sees the serial and conflict reason and approval is blocked for the entire document

### Requirement: Approval atomically moves good stock to broken stock
Approval SHALL reload and lock the pending document, selected location and setting context, affected product stocks, and selected serials, then revalidate the complete movement in one database transaction. For each valid line, it SHALL subtract the requested quantity from the PKP-governed good bucket and add the identical quantity to the corresponding broken bucket. For selected serials it SHALL change only `is_broken` from false to true, preserving `location_id`, `tax_id`, and available lifecycle status. It SHALL update aggregates, inventory transactions, notifications, and document status consistently without changing total physical product ownership.

#### Scenario: Approve PKP breakage
- **WHEN** PKP location stock has good tax 10 and broken tax 2 and an approver authorizes quantity 3
- **THEN** approval results in good tax 7 and broken tax 5 with the physical total unchanged

#### Scenario: Approve serialized breakage
- **WHEN** all selected serials remain eligible good serials at the selected location
- **THEN** approval marks each selected serial broken and moves an equal quantity from good to broken without changing serial location or tax classification

#### Scenario: State changes before approval
- **WHEN** stock becomes insufficient or any selected serial becomes invalid before locked approval validation
- **THEN** the entire approval rolls back, the document remains pending, and no stock, serial, transaction, aggregate, or notification mutation is retained

### Requirement: Successful approval retains immutable evidence
Successful breakage approval SHALL persist approver identity, approval time, location and PKP context, and a versioned immutable result containing each product's actual before, movement, and after values plus selected serial condition transitions. Approved document views SHALL use this stored result for applied evidence even after later inventory activity. Legacy approved documents without an approval result SHALL be clearly presented as legacy records rather than given reconstructed applied values.

#### Scenario: View an approved breakage after later stock activity
- **WHEN** inventory changes after a breakage was approved
- **THEN** its approved view continues to show the actual before, movement, and after values stored by that approval

#### Scenario: View a legacy approved breakage
- **WHEN** an approved legacy breakage has no immutable result
- **THEN** the view labels the available information as legacy and does not claim current stock is the historical applied result
