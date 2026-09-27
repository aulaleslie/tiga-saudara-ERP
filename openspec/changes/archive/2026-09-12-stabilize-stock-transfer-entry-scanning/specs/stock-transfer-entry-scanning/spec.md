## MODIFIED Requirements

### Requirement: Transfer entry supports text search and scanner input
The system SHALL provide one focused lookup input that resolves exact scanner submissions and retains debounced tokenized product search as a fallback. Exact scans and selected search results SHALL be converted to minimal intent identifiers and SHALL NOT use client-provided product, conversion, stock, condition, or serial metadata as mutation authority.

#### Scenario: Scan a product barcode
- **WHEN** the operator submits an exact product barcode for a stock-managed product available for the selected condition at the selected origin
- **THEN** the system authoritatively resolves the current product and stock, adds or increments that product by one base unit, and restores focus to the scanner input

#### Scenario: Scan a conversion barcode
- **WHEN** the operator submits an exact unit-conversion barcode whose current conversion factor resolves to a supported positive whole base-unit quantity
- **THEN** the system authoritatively resolves the conversion and its product, adds or increments the product by that conversion factor, and displays permission-appropriate scan context

#### Scenario: Reject a fractional or invalid conversion
- **WHEN** a scanned conversion is missing, non-positive, fractional, non-finite, or no longer belongs to the resolved product
- **THEN** the system does not change any transfer row and displays permission-appropriate actionable feedback without truncating, rounding, or coercing the factor

#### Scenario: Scan a serial number
- **WHEN** the operator submits an exact serial that currently belongs to a stock-managed product, selected origin, and selected condition and is otherwise available
- **THEN** the system authoritatively reloads the serial, adds its product if necessary, selects the serial once, and derives base quantity from unique selected serials

#### Scenario: Prevent duplicate serial scanning
- **WHEN** the same serial is captured more than once in the transfer
- **THEN** the system preserves a single selection and unchanged quantity and notifies the operator with permission-appropriate feedback

#### Scenario: Fall back to tokenized product search
- **WHEN** input is not submitted as an exact eligible product barcode, conversion barcode, or serial
- **THEN** the system presents origin-and-condition-available stock-managed products for which every nonempty search token matches product name, product code, barcode, category, or brand, without automatically choosing an ambiguous result

#### Scenario: Select a tokenized search result
- **WHEN** an operator selects one product from tokenized results
- **THEN** the system sends only its identity as operator intent and authoritatively revalidates the product, origin, condition, and stock before adding, focusing, or incrementing its eligible row

### Requirement: Scanner quantities use authoritative base-unit normalization
The system SHALL store and approve stock-transfer quantity intent in whole base units. Product barcodes SHALL represent one base unit, and conversion barcodes SHALL use the current authoritative positive whole-number conversion factor while retaining only non-authoritative scan context needed to explain conversion-derived quantities.

#### Scenario: Repeated conversion scans accumulate base quantity
- **WHEN** an operator scans an authoritative conversion with factor 12 twice
- **THEN** the line records a requested base quantity of 24 and any authorized scan-context presentation explains that two conversion units produced the quantity

#### Scenario: Manual quantity and scanned quantity converge
- **WHEN** an operator enters or scans quantities for the same non-serialized product under the same transfer condition
- **THEN** validation and approval operate on one normalized requested base quantity rather than separate incompatible quantity representations

#### Scenario: Conversion changes after search or prior scan
- **WHEN** a conversion factor is changed or removed after it was presented but before the next row mutation
- **THEN** the next mutation reloads the current conversion and either applies its current valid whole factor or rejects the operation without trusting prior client state

#### Scenario: Crafted multiplier is submitted
- **WHEN** a client supplies a scan multiplier or conversion metadata that differs from the authoritative conversion record
- **THEN** the system ignores the supplied value and calculates the row effect only from current authoritative records

### Requirement: Server-side validation is authoritative
The system MUST reload authoritative locations, products, stock, conversions, and serial records when scanning, selecting, updating, saving, or submitting a transfer and MUST NOT trust client-provided stock snapshots, allocation fields, tax provenance, condition flags, product serialization flags, conversion factors, scan multipliers, or serial metadata. Each independently callable Livewire mutation boundary SHALL reject invalid intent without changing existing rows. A blind transfer client SHALL be able to submit operator intent without carrying protected system-stock fields.

#### Scenario: Client stock snapshot is absent, stale, or modified
- **WHEN** submitted form state omits stock fields or contains a stock or allocation breakdown different from current authoritative records
- **THEN** the system reloads server data, recalculates allocation, and either saves the corrected valid intent or rejects an invalid request without partial persistence or unauthorized disclosure

#### Scenario: Duplicate product lines are submitted
- **WHEN** a request contains duplicate non-serialized product lines with the same transfer mode
- **THEN** the system normalizes them into one deterministic line or rejects them with a permission-appropriate validation error and never persists ambiguous duplicates

#### Scenario: Crafted product-selection event is submitted
- **WHEN** a client directly calls a row mutation with modified product attributes, serialization flag, condition flag, stock data, or scan multiplier
- **THEN** the system uses only canonical identifiers to reload authoritative origin, product, condition, stock, and applicable conversion and leaves rows unchanged when that intent is ineligible

#### Scenario: Authoritative state changes between entry operations
- **WHEN** product, location, stock, conversion, or serial state changes after one entry operation
- **THEN** the next entry operation is evaluated against the changed server state without trusting the preceding browser projection

### Requirement: Transfer serial selection SHALL use canonical condition-aware availability
Stock-transfer scanner, autocomplete, table mutation, draft validation, approval, dispatch, and return-dispatch boundaries SHALL exclude missing and otherwise unavailable serials. Normal transfer mode SHALL accept only sellable serials; broken transfer mode SHALL accept only available-broken serials. Each authoritative mutation boundary SHALL reload and validate tenant product ownership, row product, current origin, status, dispatch reservation, return-process state, transfer condition, and uniqueness rather than trusting saved UI metadata. Browser projections SHALL include operator-entered serial identity but SHALL expose authoritative serial availability and tax provenance only to users with `stockTransfers.view-system-stock`.

#### Scenario: Normal transfer scan accepts a sellable serial
- **WHEN** the operator scans an active-compatible, good, undispatched, non-returning serial at the selected origin
- **THEN** the transfer selects that serial once in normal mode and exposes only permission-appropriate serial metadata

#### Scenario: Normal transfer scan rejects active broken serial
- **WHEN** the operator scans an `ACTIVE` serial with `is_broken=true` while using normal mode
- **THEN** the transfer leaves all row state unchanged and presents detailed condition feedback only when the operator has stock visibility, otherwise neutral feedback

#### Scenario: Broken transfer scan accepts available broken serial
- **WHEN** the operator explicitly uses broken mode and scans an available-broken serial at the selected origin
- **THEN** the transfer selects it once while deriving tax and location provenance authoritatively without exposing that provenance to a blind operator

#### Scenario: Missing serial is rejected in every transfer mode
- **WHEN** the operator scans or submits a serial with `status=MISSING`
- **THEN** normal and broken transfer modes reject it, no transfer row or quantity changes, and blind feedback does not reveal the authoritative status

#### Scenario: Serial belongs to another row product or origin
- **WHEN** a crafted selection supplies a serial that belongs to another product or is not currently at the selected origin
- **THEN** the table mutation rejects it immediately without adding the serial or changing derived quantity

#### Scenario: Serial is reserved or already returning
- **WHEN** a serial is reserved for dispatch or participates in another return process at selection time
- **THEN** the table mutation rejects it immediately and presents permission-appropriate feedback

#### Scenario: Duplicate serial is selected through different entry paths
- **WHEN** the same serial is submitted through scanning, autocomplete, repeated delivery, or another row
- **THEN** the system retains one serial identity across the transfer and derives quantity from the unique selection exactly once

#### Scenario: Serial becomes unavailable before dispatch
- **WHEN** a selected serial becomes missing, sold, dispatched elsewhere, changes stock condition, or enters a return process before transfer dispatch
- **THEN** authoritative save, submission, approval, or dispatch validation rejects the transfer effect atomically and presents permission-appropriate feedback

### Requirement: Transfer entry SHALL follow hardened adjustment interaction patterns
The system SHALL adapt the location-gated searchable and scannable interaction patterns used by stock adjustment and breakage while retaining the transfer resolver and all transfer-specific base-unit conversion, allocation, duplicate, serial-availability, authoritative validation, and stock-visibility rules. Captured scanner submissions SHALL be processed at most once in first-in-first-out order against the row state produced by preceding accepted scans.

#### Scenario: Process rapid product scans
- **WHEN** an operator captures multiple supported product or conversion barcodes before preceding scan feedback completes
- **THEN** the system processes each captured value at most once in capture order, accumulates every accepted base-unit effect, and restores scanner focus after permission-appropriate feedback

#### Scenario: Process rapid serial scans
- **WHEN** an operator rapidly captures distinct eligible serials followed by a duplicate
- **THEN** the system selects each distinct serial once in capture order, derives quantity from the unique set, rejects the duplicate without changing quantity, and continues processing later captures

#### Scenario: Repeated scan operation is delivered again
- **WHEN** the same queued scan operation is delivered more than once during the active form interaction
- **THEN** the system applies its row effect at most once while preserving subsequent queued operations

#### Scenario: A queued scan becomes ineligible
- **WHEN** authoritative origin, condition, stock, conversion, or serial state changes before a queued scan is processed
- **THEN** the system rejects that scan against current state without changing prior accepted rows and continues with the remaining queue

#### Scenario: Select a product from text search
- **WHEN** an operator selects an origin-available product from the debounced tokenized results
- **THEN** the system adds it once in the selected transfer mode or focuses or increments its existing eligible row according to serialization rules without exposing protected system stock to a blind operator

#### Scenario: Resolver rejects an ineligible product or serial
- **WHEN** the transfer-specific resolver determines that a product, conversion, or serial is unavailable for the selected origin or mode
- **THEN** the system preserves existing rows and displays actionable Bahasa Indonesia feedback containing detailed system-stock context only when the operator has stock visibility and neutral non-quantitative guidance otherwise

#### Scenario: Blind rapid scan results remain non-revealing
- **WHEN** a blind operator receives success, duplicate, or rejection feedback while queued scans are processed
- **THEN** browser-visible state and events contain no protected stock quantity, bucket, maximum, shortage, allocation, serial availability, condition provenance, or tax provenance
