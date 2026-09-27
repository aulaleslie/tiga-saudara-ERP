## ADDED Requirements

### Requirement: Exact transfer scan collisions SHALL require operator resolution
The system SHALL evaluate all eligible exact product-barcode, conversion-barcode, and serial matches without fixed type precedence. It SHALL apply an exact scan directly only when one eligible candidate remains and SHALL require an explicit, permission-safe operator choice when multiple candidates remain.

#### Scenario: Exact scan has one eligible candidate
- **WHEN** an exact scan resolves to one eligible candidate after tenant, origin, condition, stock-management, conversion, serial, and visibility filtering
- **THEN** the system applies that candidate through the authoritative mutation boundary without opening ambiguity selection

#### Scenario: Exact scan collides across identifier types
- **WHEN** one scanned value exactly identifies more than one eligible product barcode, conversion barcode, or serial
- **THEN** the system changes no row until the operator explicitly selects one permission-safe candidate

#### Scenario: Exact scan has duplicate candidates in one identifier type
- **WHEN** duplicate eligible records in one identifier namespace match the scanned value
- **THEN** the system treats the result as ambiguous rather than selecting the first database record

#### Scenario: Cancel ambiguity selection
- **WHEN** the operator cancels an ambiguity choice
- **THEN** the system changes no row, completes that queued scan without applying an effect, and restores scanner focus before continuing later captures

#### Scenario: Candidate becomes ineligible before selection
- **WHEN** an ambiguity candidate is selected after its authoritative eligibility has changed
- **THEN** the system rejects it without changing rows, gives permission-appropriate feedback, and continues the queue

### Requirement: Transfer product search SHALL be deliberate and visibility-safe
The system SHALL provide product search as an explicit interaction separate from exact scanner submission. Search SHALL use debounced tokenized matching across product name, product code, barcode, category, and brand, SHALL return only origin-and-condition-eligible stock-managed products, and SHALL expose no protected system-stock data to a blind operator.

#### Scenario: Unknown exact scan resembles one product
- **WHEN** an exact scan has no exact eligible identifier match but would produce one tokenized product-search result
- **THEN** the system reports the scan as unresolved and does not open, select, or mutate from the fuzzy result

#### Scenario: Search with multiple tokens
- **WHEN** the operator deliberately searches using multiple nonempty tokens
- **THEN** every token must match at least one supported product, category, or brand field for a result to appear

#### Scenario: Select a new non-serialized search result
- **WHEN** the operator selects an eligible non-serialized product that has no row
- **THEN** the system authoritatively revalidates it, creates the row with one base unit, reports success, and restores scanner focus after closing search

#### Scenario: Select an existing search result
- **WHEN** the operator selects a product whose eligible row already exists
- **THEN** the system focuses that row without implicitly increasing its quantity

#### Scenario: Blind operator searches products
- **WHEN** an operator without system-stock visibility opens, filters, or selects product-search results
- **THEN** browser-visible search state contains product identity needed for selection but no stock quantity, maximum, shortage, allocation, condition provenance, location provenance, or tax provenance

## MODIFIED Requirements

### Requirement: Transfer entry supports text search and scanner input
The system SHALL provide a dedicated focused scanner input for exact product-barcode, conversion-barcode, and serial submission and a separate explicit product-search interaction for tokenized discovery. Exact scans, ambiguity choices, and selected search results SHALL be converted to minimal canonical intent identifiers and SHALL NOT use client-provided product, conversion, stock, condition, or serial metadata as mutation authority.

#### Scenario: Scan a product barcode
- **WHEN** the operator submits an unambiguous exact product barcode for an eligible non-serialized stock-managed product at the selected origin and condition
- **THEN** the system authoritatively resolves current product and stock, adds or increments that product by one base unit, displays permission-appropriate feedback, and restores scanner focus

#### Scenario: Scan a serialized product barcode
- **WHEN** the operator submits an unambiguous exact product barcode for an eligible serialized product
- **THEN** the system creates or focuses its row at quantity zero without selecting a serial or incrementing quantity

#### Scenario: Scan a conversion barcode
- **WHEN** the operator submits an unambiguous exact unit-conversion barcode whose current conversion factor is a supported positive whole base-unit quantity
- **THEN** the system authoritatively resolves the conversion and product, adds or increments by that factor, and displays permission-appropriate conversion feedback

#### Scenario: Reject a fractional or invalid conversion
- **WHEN** a scanned conversion is missing, non-positive, fractional, non-finite, or no longer belongs to the resolved product
- **THEN** the system does not change any transfer row and displays permission-appropriate actionable feedback without truncating, rounding, or coercing the factor

#### Scenario: Scan a serial number
- **WHEN** the operator submits an unambiguous exact serial that currently belongs to an eligible stock-managed product, selected origin, and selected condition
- **THEN** the system authoritatively reloads the serial, adds its product row if necessary, selects the serial once, derives base quantity from unique selected serials, and restores scanner focus

#### Scenario: Prevent duplicate serial scanning
- **WHEN** the same serial is captured more than once in the transfer
- **THEN** the system preserves one selection and unchanged quantity and notifies the operator with permission-appropriate feedback

#### Scenario: Submit an unknown exact scan
- **WHEN** scanner input does not exactly identify an eligible product barcode, conversion barcode, or serial
- **THEN** the system changes no row, provides permission-appropriate not-found feedback, and does not fall through to product search

#### Scenario: Open tokenized product search
- **WHEN** the operator deliberately opens product search and enters search text
- **THEN** the system presents origin-and-condition-eligible products for which every nonempty token matches product name, product code, barcode, category, or brand

#### Scenario: Select a tokenized search result
- **WHEN** the operator selects one product from tokenized results
- **THEN** the system sends only its identity as intent and authoritatively revalidates product, origin, condition, and stock before creating or focusing its eligible row

### Requirement: Transfer serial selection SHALL use canonical condition-aware availability
Stock-transfer scanning, ambiguity selection, row-level serial management, draft validation, approval, dispatch, and return-dispatch boundaries SHALL exclude missing and otherwise unavailable serials. Normal transfer mode SHALL accept only sellable serials; broken transfer mode SHALL accept only available-broken serials. Each authoritative mutation boundary SHALL reload and validate global product identity (active and stock-managed), origin location ownership by active tenant setting, row product, current origin, status, dispatch reservation, return-process state, transfer condition, and uniqueness rather than trusting saved UI metadata. Browser projections SHALL include operator-selected serial identity but SHALL expose authoritative availability and provenance only to users with `stockTransfers.view-system-stock`.

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
- **WHEN** the operator scans or selects a serial with `status=MISSING`
- **THEN** normal and broken modes reject it, no row or quantity changes, and blind feedback does not reveal authoritative status

#### Scenario: Serial belongs to another row product or origin
- **WHEN** a crafted selection supplies a serial belonging to another product or not currently at the selected origin
- **THEN** the authoritative mutation rejects it without adding the serial or changing derived quantity

#### Scenario: Serial is reserved or already returning
- **WHEN** a serial is reserved for dispatch or participates in another return process at selection time
- **THEN** the authoritative mutation rejects it and presents permission-appropriate feedback

#### Scenario: Duplicate serial is selected through different entry paths
- **WHEN** the same serial is submitted through scanning, ambiguity selection, row-level search, repeated delivery, or another row
- **THEN** the system retains one serial identity across the transfer and derives quantity from the unique selection exactly once

#### Scenario: Open row-level serial management
- **WHEN** the operator opens serial management for a serialized product row
- **THEN** the system shows selected serial identities and a fallback search limited to existing serials currently eligible for that row, origin, and condition

#### Scenario: Remove a selected serial
- **WHEN** the operator removes a serial through row-level management
- **THEN** the system removes that identity once and recalculates row quantity from remaining unique selected serials

#### Scenario: Enter an unregistered serial value
- **WHEN** an operator or crafted client submits serial text that does not identify an existing eligible serial record
- **THEN** the system rejects it without creating a serial, changing the row, or exposing protected availability details

#### Scenario: Serial becomes unavailable before dispatch
- **WHEN** a selected serial becomes missing, sold, dispatched elsewhere, changes stock condition, or enters a return process before transfer dispatch
- **THEN** authoritative save, submission, approval, or dispatch validation rejects the transfer effect atomically and presents permission-appropriate feedback

### Requirement: Transfer entry SHALL follow hardened adjustment interaction patterns
The system SHALL adapt the location-gated scanner, explicit ambiguity, deliberate product search, row-level serial management, feedback, modal, and focus patterns used by stock adjustment and breakage while retaining transfer-specific base-unit conversion, allocation, duplicate, serial-availability, authoritative validation, and stock-visibility rules. One authoritative entry coordinator SHALL process captured scanner submissions at most once in first-in-first-out order against row state produced by preceding accepted scans.

#### Scenario: Process rapid product scans
- **WHEN** an operator captures multiple supported product or conversion barcodes before preceding feedback completes
- **THEN** the coordinator processes each captured value at most once in capture order, accumulates every accepted base-unit effect, and restores scanner focus after permission-appropriate feedback

#### Scenario: Process rapid serial scans
- **WHEN** an operator rapidly captures distinct eligible serials followed by a duplicate
- **THEN** the coordinator selects each distinct serial once in capture order, derives quantity from the unique set, rejects the duplicate without changing quantity, and continues later captures

#### Scenario: Repeated scan operation is delivered again
- **WHEN** the same queued scan operation is delivered more than once during the active form interaction
- **THEN** the system applies its row effect at most once while preserving subsequent queued operations

#### Scenario: Ambiguity pauses the queue
- **WHEN** a queued exact scan requires operator disambiguation while later scans have already been captured
- **THEN** later captures remain ordered and unapplied until the operator selects or cancels, after which the queue resumes

#### Scenario: A queued scan becomes ineligible
- **WHEN** authoritative origin, condition, stock, conversion, or serial state changes before a queued scan is processed
- **THEN** the system rejects that scan against current state without changing prior accepted rows and continues with remaining queue entries

#### Scenario: Select a product from deliberate search
- **WHEN** an operator selects an origin-available product from explicit tokenized search
- **THEN** the coordinator adds an absent eligible row according to serialization rules or focuses an existing row, without exposing protected system stock or silently incrementing an existing row

#### Scenario: Resolver rejects an ineligible product or serial
- **WHEN** the transfer resolver determines that a product, conversion, or serial is unavailable for selected origin or condition
- **THEN** the system preserves rows and displays actionable Bahasa Indonesia feedback containing detailed system-stock context only for an operator with stock visibility and neutral non-quantitative guidance otherwise

#### Scenario: Blind rapid scan results remain non-revealing
- **WHEN** a blind operator receives success, duplicate, ambiguity, or rejection feedback while queued scans are processed
- **THEN** browser-visible state and events contain no protected stock quantity, bucket, maximum, shortage, allocation, serial availability, condition provenance, location provenance, or tax provenance

#### Scenario: Origin or condition remounts entry
- **WHEN** the operator confirms a valid origin or creation-condition change
- **THEN** the parent clears prior rows and mounts a fresh entry coordinator for the new authoritative context

#### Scenario: Destination-only change preserves entry
- **WHEN** the operator changes or clears only the destination
- **THEN** the entry coordinator and all current product and serial rows remain intact while destination compatibility is revalidated separately

#### Scenario: Livewire morph replaces scanner markup
- **WHEN** Livewire initializes or morphs the entry interface after an operation or modal closure
- **THEN** scanner capture remains bound once to the current element and focus returns without duplicate submission handlers
