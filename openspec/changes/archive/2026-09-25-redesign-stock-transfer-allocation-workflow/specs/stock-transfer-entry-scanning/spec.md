# Spec Delta

## ADDED Requirements

### Requirement: Version 3 entry is independent of locations
For workflow version 3, the system SHALL accept goods entry without source or destination selection and SHALL apply this contract in place of legacy origin-gating and origin-owned entry rules. The creator SHALL select one condition, Barang Baik or Barang Rusak, and enter active stock-managed products using deliberate search, exact barcode/conversion/serial scans, or manual quantities across all businesses. Canonical identity resolution, ambiguity selection, whole-base-unit conversion, duplicate protection, and condition-aware serial eligibility SHALL remain authoritative. Location and business provenance SHALL not be exposed on entry surfaces.

#### Scenario: Start entry without locations
- **WHEN** an authorized creator opens the new transfer form
- **THEN** goods entry is available without a location selector and the form offers the two condition choices

#### Scenario: Scan serial from another business
- **WHEN** a valid available serial belongs to a location of a business not assigned to the creator
- **THEN** it can be selected once if the creator has create permission in the active business, without exposing its location

#### Scenario: Resolve ambiguous barcode
- **WHEN** a scan matches several eligible canonical identities across businesses
- **THEN** no quantity changes until the user selects an unambiguous product or serial using a location-free projection

#### Scenario: Convert barcode into quantity
- **WHEN** a valid non-serialized conversion barcode is scanned repeatedly
- **THEN** its authoritative positive whole-base-unit factor accumulates in one product row without trusting a client multiplier

#### Scenario: Modal product search is separate from scanning
- **WHEN** the user opens Cari Produk (Stok Dikelola) and searches by name, product code, barcode, category, or brand
- **THEN** matching products across businesses are listed without location or stock provenance, and none is added until the user explicitly selects one, even when there is a single match; Enter only searches
- **AND WHEN** the modal closes by selection, the close control, Tutup, or Escape
- **THEN** focus returns to the dedicated scan input

#### Scenario: Scan input is exact-only
- **WHEN** text is entered into the dedicated scan input
- **THEN** only exact product barcodes, conversion barcodes, and serial numbers are resolved, with no name-search fallback

#### Scenario: Scanner terminator and focus
- **WHEN** a scanner sends a code followed by an Enter, CR, or LF terminator
- **THEN** the scan is processed exactly once without submitting the form, the scan input is cleared, and focus returns to it unless the user is working in the search modal or another input

#### Scenario: Ambiguity pauses subsequent scans
- **WHEN** a scan is ambiguous and more scans arrive before the operator decides
- **THEN** later scans are held in arrival order without clearing the candidates, the choice or cancellation is bound to the ambiguous scan's operation token, and held scans resume afterwards

#### Scenario: Failed scan request is retained
- **WHEN** a scan, choice, or cancellation request fails
- **THEN** the failed item and later scans are retained, visible feedback offers retry or cancellation, a retry reuses the same operation token without applying twice, and processing resumes only after a successful retry or explicit cancellation
- **AND** a slow request is never treated as failed: later requests stay blocked until the original request settles, so no late response can change the form after a retry or cancellation
- **AND** when the transport can no longer send requests, whichever request on the page failed (including product search or quantity edits), no retry is offered and the operator is told to reload, with the unrecorded scans listed
- **AND** the framework's generic error dialog is suppressed only for failed scan, choice, and save requests, so the retry feedback stays visible, while session-expiry handling is unchanged

#### Scenario: Requests never overlap
- **WHEN** a scan arrives while a candidate choice, cancellation, retry, or another scan is in flight
- **THEN** it is held and sent only after that request settles, and no queued scan is dropped or sent twice

#### Scenario: Scan intake is frozen during save
- **WHEN** a scan arrives while Simpan Draf or Ajukan Persetujuan is running, or after a successful save while the page redirects
- **THEN** the scan is refused with visible feedback to rescan, instead of being accepted and lost

#### Scenario: Save waits for scan processing
- **WHEN** Simpan Draf or Ajukan Persetujuan is requested while scans are queued or in flight
- **THEN** the action runs only after the queue becomes idle; it is refused with an explanation while a scan awaits a choice or a failure decision, and the server refuses to save while an ambiguous scan is pending

### Requirement: Version 3 drafts and submission preserve goods intent
The system SHALL offer Simpan Draf and Ajukan Persetujuan on creation and edit. Drafts SHALL permit incomplete serial selection for valid product rows; submission SHALL require positive whole quantities, a single condition, and quantity equal to the distinct selected serial count for every serialized product. Creation and submission SHALL be atomic. Submission SHALL freeze a revision of the goods manifest, and material edits to pending goods SHALL return the document to draft and invalidate saved allocation approval context.

#### Scenario: Save incomplete serial entry
- **WHEN** a creator saves a serialized row with quantity five and four selected serials as a draft
- **THEN** the incomplete intent is retained without stock or custody effects

#### Scenario: Submit incomplete serial entry
- **WHEN** creation or edit submission contains quantity five and four selected serials
- **THEN** submission fails with actionable Bahasa Indonesia feedback and no pending revision or partial creation

#### Scenario: Create and submit valid goods
- **WHEN** complete valid goods are submitted directly from creation
- **THEN** exactly one numbered pending transfer and its creation/submission history are committed without a prior Save Draft step

#### Scenario: Change goods while allocation is in progress
- **WHEN** an authorized editor materially changes the pending manifest
- **THEN** it becomes a new draft revision and any saved allocation configuration cannot authorize that revision without resubmission and review
