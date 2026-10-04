# Spec Delta

## ADDED Requirements

### Requirement: POS hardware and helper scan submissions SHALL be processed in order
The POS sell screen MUST retain each complete barcode or serial submission made through keyboard Enter or the scan helper while a prior submission is processing, and MUST process retained submissions once each in arrival order. It MUST preserve the existing unit, bundle, and serial selection steps and MUST NOT apply a queued submission to a different transaction context.

#### Scenario: Two rapid scans of the same product
- **WHEN** a cashier submits the same product barcode twice with Enter before the first cart addition finishes
- **THEN** the cart MUST reflect two successful additions, subject to existing stock and pricing rules
- **AND** neither submission is silently discarded

#### Scenario: Different scans arrive while busy
- **WHEN** a second valid barcode is submitted while the first is processing
- **THEN** the second submission MUST be processed after the first finishes
- **AND** the resulting cart MUST reflect the same order of additions

#### Scenario: Selection is required
- **WHEN** a scan requires unit or bundle selection
- **THEN** later queued scans MUST wait until that selection is completed or cancelled
- **AND** the later scans MUST retain their captured values

#### Scenario: Scan fails
- **WHEN** a queued scan is not found or its cart action fails
- **THEN** the cashier MUST receive feedback for that scan
- **AND** the queue MUST continue to the next captured scan

#### Scenario: Transaction context changes
- **WHEN** checkout, cart clearing, save-and-new, or draft loading establishes a new transaction context
- **THEN** pending scans captured for the previous context MUST NOT mutate the new cart

#### Scenario: Camera duplicate suppression remains active
- **WHEN** the camera decoder repeatedly reports the same code during its active suppression window
- **THEN** the POS MUST continue treating those detections as one accepted camera scan
