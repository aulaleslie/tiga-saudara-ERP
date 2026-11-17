# Sold Serial Number Report Spec Delta

## Purpose

Provide a product-filtered report of sold serial numbers that reflects physical returns and the latest approved replacement while preserving each original sale date.

## ADDED Requirements

### Requirement: Report access and business scope
The system SHALL provide a sold serial number report in Reports using existing sales-report access permission and authorized business selection rules. Preview and export SHALL sanitize business scope on the server, default to the active authorized business, and use the existing sales-report fallback for an empty selection.

#### Scenario: Authorized access
- **WHEN** a user with sales-report access opens the report
- **THEN** the report is available with the active authorized business selected

#### Scenario: Unauthorized access
- **WHEN** a user without sales-report access requests the page or invokes export
- **THEN** access is denied through the existing authorization rules

#### Scenario: Tampered scope
- **WHEN** a user submits both an assigned business and an unassigned business
- **THEN** only the assigned business contributes preview or export data

### Requirement: Required product and date filters
The report SHALL require one or more selected products or bundles and a valid inclusive start/end sale-date range. Product search SHALL use the global catalog, matching every whitespace-separated token against product name or code. Missing products, missing dates, or a reversed range SHALL prevent generation and export with validation feedback.

#### Scenario: Multiple products and boundary dates
- **WHEN** two products and a date range are applied
- **THEN** only matching serial occurrences of those products on or between the boundary dates are shown

#### Scenario: Invalid filters
- **WHEN** no product is selected, a date is missing, or the end date precedes the start date
- **THEN** no report is generated and the invalid input is identified

#### Scenario: Global product search
- **WHEN** search tokens match a product name and code in any order
- **THEN** that product is selectable regardless of the business recorded on its catalog entry
- **AND** its code is displayed alongside its name in the selection options

### Requirement: Approved sold serial occurrences
The report SHALL include serial occurrences from approved regular-sales and POS dispatches. Pending or rejected dispatches and units without a recorded SN SHALL not contribute rows. Each occurrence SHALL be identified within its sale and dispatch lineage, so returns or resales of the same serial do not affect unrelated occurrences.

#### Scenario: Regular sale and POS
- **WHEN** matching regular-sales and POS transactions have approved dispatches
- **THEN** both contribute their serial occurrences

#### Scenario: Partial dispatch
- **WHEN** only part of a sale has an approved dispatch
- **THEN** its approved serial occurrences are included and undispatched serials are excluded

#### Scenario: Repeated serial text across products
- **WHEN** two selected products each have a sold serial with the same text
- **THEN** both occurrences remain distinct

### Requirement: Physical return receipt removes the occurrence
A sold serial occurrence SHALL remain visible during a pending or approved return request and SHALL be omitted once physical receipt of that occurrence is recorded, unless an approved replacement represents it. Return approval or financial settlement alone SHALL not determine receipt. Return evidence SHALL be matched to the specific sale occurrence.

#### Scenario: Return requested but not received
- **WHEN** a sold SN has a requested or approved return without physical receipt
- **THEN** that SN remains visible

#### Scenario: Partial serial return received
- **WHEN** SN A is physically received from a dispatch containing A and B
- **THEN** A is omitted and B remains visible

#### Scenario: Returned serial resold
- **WHEN** SN A is received back from sale one and later dispatched on sale two
- **THEN** sale one's A remains omitted and sale two's A is shown using sale two's date

### Requirement: Latest approved replacement substitutes the original
For a received returned occurrence, the report SHALL show only its latest approved replacement SN in the same logical row. It SHALL omit both the returned SN and pending or rejected replacement SNs. Replacement dispatches SHALL not create additional sale rows. A received replacement without a subsequent approved replacement SHALL leave the logical row absent.

#### Scenario: Replacement issued later
- **WHEN** October sale SN A is received back and replacement B is approved in November
- **THEN** the October report shows B instead of A
- **AND** November does not receive an additional sale row from that replacement

#### Scenario: Gap before dispatch approval
- **WHEN** A is received and replacement B is pending or rejected
- **THEN** neither A nor B is shown

#### Scenario: Replacement chain
- **WHEN** A is replaced by B and received B is later replaced by approved C
- **THEN** only C represents that original sale occurrence

#### Scenario: Latest replacement returned without another dispatch
- **WHEN** replacement C is received back without another approved replacement
- **THEN** that original sale occurrence is omitted

### Requirement: Original sale date drives every date operation
Filtering, sorting, displayed day/month/year, and export SHALL use the original sale calendar date. Replacement dates, return dates, dispatch dates, event timestamps, and financial reporting-date overrides SHALL not replace it. The report SHALL reflect current outcomes, including later returns and replacements outside the selected period.

#### Scenario: Different operational and financial dates
- **WHEN** a sale dated October 15 is dispatched October 17, has a November reporting-date override, and is replaced in December
- **THEN** its latest qualifying SN is included under October 15 only

#### Scenario: Later return changes a prior period
- **WHEN** a November return physically receives an SN sold in October without an approved replacement
- **THEN** a newly generated October report omits that SN

### Requirement: Bundle components and current product names
Selecting a bundle SHALL include its dispatched serial-tracked components using their current component product names. Selecting a component directly SHALL include its matching occurrences, including those sold in bundles. Overlapping bundle/component selections SHALL not duplicate an occurrence. Units without SNs SHALL not produce blank-SN rows.

#### Scenario: Bundle and component selected together
- **WHEN** the selection includes a bundle and one of its components
- **THEN** each matching component serial occurrence appears once with its current product name

#### Scenario: Product renamed
- **WHEN** a sold product is renamed before generation
- **THEN** preview and export use its current catalog name

### Requirement: Five-column preview and matching Excel export
The report SHALL provide a paginated preview and XLSX download containing exactly Product name, SN, Tgl, Bulan, Tahun in that order. Day, month, and year SHALL be separate numeric values. Rows SHALL sort by original sale date ascending, product name, and SN with stable tie ordering. XLSX SHALL preserve SN text, including leading zeros, and include all matching rows across preview pages.

#### Scenario: Exact output and ordering
- **WHEN** a matching occurrence has sale date 2026-11-17
- **THEN** its final three values are numeric 17, 11, and 2026
- **AND** no type, transaction reference, or additional business column is added

#### Scenario: Export parity and serial fidelity
- **WHEN** the applied result spans several preview pages and includes SN 000123
- **THEN** Excel includes the same complete ordered row set with SN 000123 preserved as text

#### Scenario: Unapplied filters
- **WHEN** export is requested before applying filters or after changing them
- **THEN** export is blocked until the current filters are applied

#### Scenario: Empty result
- **WHEN** valid applied filters match no qualifying serial occurrences
- **THEN** the preview shows an empty state and Excel contains the five headings without fabricated data rows

### Requirement: Reporting preserves transaction behavior
Generating or exporting this report SHALL not alter sales, return receipt, settlements, replacement dispatches, stock, serial history, or existing report behavior.

#### Scenario: Read-only generation
- **WHEN** users generate or export the serial report
- **THEN** transaction and inventory records remain unchanged
