## ADDED Requirements

### Requirement: Sale Document Shows Returned And Replacement Serial Lineage
The Sale document SHALL show returned original serials and POS replacement serials as distinct serial badge states. Returned original serials MUST appear red. Replacement serials dispatched by POS Return replacement execution MUST appear blue. The display MUST derive from audited return and replacement lineage rather than requiring manual display-only edits.

#### Scenario: Returned original serial appears red
- **WHEN** a POS Return cash return or product replacement receives an original serial from a Sale
- **THEN** the Sale document shows that original serial as returned with a red badge
- **AND** the badge remains visible even when the active dispatched quantity has been reduced

#### Scenario: Replacement serial appears blue
- **WHEN** POS Return product replacement execution dispatches a replacement serial on the source Sale
- **THEN** the Sale document shows the replacement serial as replacement lineage with a blue badge
- **AND** the badge identifies the serial as a replacement rather than an ordinary active sale serial

### Requirement: Replacement Serial Tracking Is Linked To Source Sale
The system SHALL create or update serial tracking for replacement serials on the original source Sale when POS Return replacement execution creates the replacement dispatch. Replacement serial tracking MUST preserve dispatch date, source Sale, replacement serial identity, and lineage back to the POS Return line or returned serial.

#### Scenario: Replacement serial has sale tracking
- **WHEN** final approval dispatches a replacement serial for a source Sale
- **THEN** the replacement serial has Sales Order Serial Tracking for that source Sale
- **AND** the tracking supports Sale document blue replacement display

#### Scenario: Original serial remains returned
- **WHEN** a returned original serial has been replaced
- **THEN** the original serial tracking for the source Sale keeps its return date
- **AND** the replacement serial tracking does not erase the original returned serial lineage

### Requirement: Historical Dispatch Serial Display Survives Active Quantity Reduction
The Sale document SHALL preserve historical serial badge display for serials associated with dispatch rows even when POS cash-return execution reduces active dispatch quantity. Display logic MUST NOT rely only on `dispatch_details.dispatched_quantity` matching the count of historical serials.

#### Scenario: Partial serial cash return shows mixed badges
- **WHEN** a dispatch originally contains serials A, B, and C and POS cash return receives serial A
- **THEN** the Sale document can show dispatch active quantity 2
- **AND** serial A appears red while serials B and C remain active or ordinary sale serials

#### Scenario: Quantity and serial count differ after return
- **WHEN** active dispatched quantity is lower than the number of historical serial badges after a return
- **THEN** the Sale document renders the quantity and serial badges without treating the mismatch as display corruption
