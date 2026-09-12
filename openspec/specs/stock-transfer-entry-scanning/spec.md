# stock-transfer-entry-scanning Specification

## Purpose
Define requirements and interaction contracts for stock-transfer entry, product and unit-conversion barcode scanning, serial-number selection, deliberate product search, exact scan ambiguity resolution, and permission-safe visibility controls.

## Requirements
### Requirement: Authorized origin users can create and edit stock-transfer requests
The system SHALL allow a user with `stockTransfers.create` to create a `DRAFT` stock-transfer request with an active origin owned by the active tenant, one explicit stock condition, and at least one valid product quantity while allowing destination to remain unset. The system SHALL require `stockTransfers.edit` to discover, open, edit, or submit an existing transfer, SHALL allow mutation only while its current lifecycle state is `DRAFT` or `PENDING`, and SHALL require the origin to belong to the active tenant. A material mutation to a `PENDING` transfer SHALL atomically return it to `DRAFT` and require explicit resubmission; a no-op save SHALL NOT change lifecycle state, revision, lines, or history.

#### Scenario: Save a draft without destination
- **WHEN** an authorized user saves a new transfer with a valid active origin, one transfer mode, and at least one valid product quantity but no destination
- **THEN** the system atomically creates the transfer and its lines in `DRAFT` status with no destination

#### Scenario: Save a draft with destination
- **WHEN** an authorized user saves a draft with a destination
- **THEN** the system authoritatively validates that the destination is active, distinct from the origin, and compatible with the origin before storing it

#### Scenario: Discover a saved draft edit action
- **WHEN** an origin-tenant user with `stockTransfers.edit` views the transfer list containing a `DRAFT` or `PENDING` transfer
- **THEN** the system presents an edit action for that transfer

#### Scenario: Hide edit action without permission
- **WHEN** a user without `stockTransfers.edit` views a `DRAFT` or `PENDING` transfer in the list
- **THEN** the system does not present its edit action

#### Scenario: Submit a complete draft for approval
- **WHEN** an authorized origin-tenant editor submits a `DRAFT` transfer with valid distinct origin and destination locations, one transfer mode, and at least one valid product quantity
- **THEN** the system revalidates the complete request and atomically transitions it to `PENDING`

#### Scenario: Reject submission without destination
- **WHEN** an authorized user attempts to submit a `DRAFT` transfer whose destination is unset or no longer valid
- **THEN** the system keeps the transfer and lines unchanged in `DRAFT` status and returns an actionable validation error

#### Scenario: Edit a saved draft
- **WHEN** an authorized origin-tenant user materially updates a `DRAFT` transfer
- **THEN** the system saves the complete updated request atomically, advances its revision, records a `DRAFT` to `DRAFT` edit, and keeps it in `DRAFT`

#### Scenario: Revise a pending transfer
- **WHEN** an authorized origin-tenant user materially updates a `PENDING` transfer
- **THEN** the system atomically saves the revised request, advances its revision, records a `PENDING` to `DRAFT` transition, and requires explicit resubmission before approval

#### Scenario: Open pending edit without mutation
- **WHEN** an authorized user opens a `PENDING` transfer for editing but does not save a material change
- **THEN** the system leaves its status, revision, lines, and history unchanged

#### Scenario: Save an unchanged editable transfer
- **WHEN** an authorized user submits an edit whose normalized header and line state is identical to the persisted transfer
- **THEN** the system leaves its status, revision, lines, and history unchanged

#### Scenario: Reject a stale concurrent edit
- **WHEN** approval or another edit advances the persisted revision before an editor saves
- **THEN** the system rejects the stale save without partially changing the transfer, lines, status, revision, or history

#### Scenario: Block destination or unrelated tenant editing
- **WHEN** a user whose active tenant does not own the transfer origin directly requests the edit, update, or submission endpoint
- **THEN** the system rejects the request without changing the transfer or its lines

#### Scenario: Block mutation after approval or dispatch
- **WHEN** a user attempts to edit a transfer that is approved, archived, dispatched, received, awaiting return, return-dispatched, or completed
- **THEN** the system rejects the mutation regardless of whether the user can see the transfer

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

### Requirement: Transfer entry previews non-tax-first allocation
For non-serialized quantities, the system SHALL estimate allocation from current authoritative origin stock by consuming the applicable non-tax bucket before the corresponding taxed bucket, without reserving or deducting inventory. The allocation preview and any resulting tax-return quantity or bucket warning SHALL be projected only to users authorized by `stockTransfers.view-system-stock`; blind users SHALL retain their requested quantity and neutral validation state without receiving the allocation breakdown.

#### Scenario: Privileged non-tax stock fully covers requested normal quantity
- **WHEN** available normal non-tax stock is at least the requested base quantity and the operator has stock visibility
- **THEN** the preview allocates the request entirely to normal non-tax quantity and shows no mandatory tax-return quantity

#### Scenario: Privileged requested normal quantity spills into taxed stock
- **WHEN** requested normal quantity exceeds available normal non-tax stock but total normal stock is sufficient and the operator has stock visibility
- **THEN** the preview allocates all available normal non-tax stock first, allocates the balance to normal taxed stock, and persistently warns that the taxed portion will require return for a cross-tenant transfer

#### Scenario: Blind request is allocated without a visible preview
- **WHEN** a blind operator enters a valid non-serialized requested quantity
- **THEN** the system computes and validates non-tax-first allocation authoritatively but omits every allocation bucket and tax-return quantity from browser-visible state

#### Scenario: Broken-stock transfer uses separate buckets
- **WHEN** the operator intentionally selects broken-stock mode
- **THEN** authoritative allocation consumes broken non-tax stock before broken taxed stock, does not consume saleable stock, and exposes the breakdown only when the operator has stock visibility

#### Scenario: Normal scanning never consumes broken stock
- **WHEN** normal saleable stock is insufficient but broken stock exists
- **THEN** the system rejects the requested normal-stock effect instead of silently allocating broken stock and presents permission-appropriate feedback

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

### Requirement: Transfer location selection SHALL be searchable and scope-aware
The system SHALL use the stock-opname searchable location-dropdown interaction for stock-transfer origin and destination fields while enforcing transfer-specific active-location, tenant, exclusion, and compatibility rules at both the selector and authoritative service boundaries.

#### Scenario: Search origins within the active tenant
- **WHEN** an operator searches the origin dropdown
- **THEN** the system lists only active locations owned by the active tenant that are eligible as transfer origins

#### Scenario: Search cross-business destinations
- **WHEN** an operator searches the destination dropdown after choosing an origin
- **THEN** the system lists active permitted destinations with business-aware labels and excludes the selected origin

#### Scenario: Origin changes after destination selection
- **WHEN** the operator changes or clears the selected origin
- **THEN** the system clears the prior destination so it must be selected again from the newly filtered choices

#### Scenario: Reject a crafted out-of-scope location
- **WHEN** a client submits an inactive, unauthorized, identical, or consignment-incompatible location that was not eligible in the dropdown
- **THEN** authoritative validation rejects the draft save or submission without partially changing the transfer

### Requirement: Product entry SHALL require an origin location
The system SHALL keep stock-transfer product search, barcode and serial scanning, and row entry unavailable until a valid origin is selected, and SHALL enforce the same prerequisite in Livewire and service methods rather than relying only on disabled presentation.

#### Scenario: Open a new transfer without origin
- **WHEN** the operator opens the transfer form before selecting an origin
- **THEN** the system displays disabled product entry with guidance to select an origin first

#### Scenario: Attempt product entry without origin
- **WHEN** a client invokes search, scan, serial selection, or row mutation without a valid origin
- **THEN** the system rejects the operation with Bahasa Indonesia feedback and does not add or change any row

#### Scenario: Select a valid origin
- **WHEN** the operator selects a valid active origin owned by the active tenant
- **THEN** the system enables searchable and scannable product entry using stock available at that origin

### Requirement: Each editable transfer SHALL use one explicit stock condition
The system SHALL require one form-wide stock condition, good stock or breakage stock, when a new transfer is created. The create form SHALL expose the condition as an accessible segmented choice, while every persisted transfer SHALL treat its condition as immutable and SHALL render it only as read-only context during ordinary editing. Product lookup, quantities, serials, mapping, draft validation, and submission SHALL use only the selected or persisted condition and SHALL reject mixed-condition or condition-changing payloads.

#### Scenario: Choose good-stock mode during creation
- **WHEN** an operator selects good-stock mode on a new transfer
- **THEN** the segmented control visibly and semantically identifies good stock as selected and product lookup accepts only saleable stock and serials

#### Scenario: Choose breakage-stock mode during creation
- **WHEN** an operator selects breakage-stock mode on a new transfer
- **THEN** the segmented control visibly and semantically identifies breakage stock as selected and product lookup accepts only available broken stock and serials

#### Scenario: Operate the condition choice accessibly
- **WHEN** a keyboard or assistive-technology user interacts with the create form
- **THEN** the system exposes labeled form controls with keyboard operation, visible focus, and selected, disabled, and validation states that do not rely on color alone

#### Scenario: Change creation condition after entering rows
- **WHEN** an operator attempts to switch condition on an unsaved creation form after entering product or serial rows
- **THEN** the system requires confirmation before clearing all rows and applying the new condition, and cancellation preserves the prior condition and rows

#### Scenario: Hydrate an editable transfer
- **WHEN** an operator opens a persisted `DRAFT` or `PENDING` transfer
- **THEN** the form displays its persisted condition as read-only context and hydrates product rows using that condition without exposing a condition toggle

#### Scenario: Attempt to change condition on an existing transfer
- **WHEN** a crafted Livewire or HTTP request submits a stock condition different from the persisted transfer
- **THEN** authoritative validation rejects the request without changing the transfer, its lines, revision, status, or history

#### Scenario: Submit a mixed-condition payload
- **WHEN** a client submits good and broken quantities or serials that conflict with the transfer's selected or persisted condition
- **THEN** authoritative validation rejects the request without partially changing the transfer or its lines

#### Scenario: View a historical mixed-condition transfer
- **WHEN** a historical transfer contains both good and broken stock buckets
- **THEN** the system preserves and renders its historical contents as view-only without offering ordinary editing or silently rewriting its condition or inventory history

### Requirement: Stock-context changes SHALL reset product rows deterministically
The system SHALL clear stock-transfer product rows when the selected origin or creation-time stock condition actually changes, because those values determine stock and serial eligibility, and SHALL preserve rows when only the destination changes. Persisted transfers SHALL NOT permit origin or condition changes through ordinary editing.

#### Scenario: Change origin with entered rows during creation
- **WHEN** the operator changes or clears the origin after entering products on a new-transfer form
- **THEN** the system clears every product and serial row, clears row validation state, resets destination, and reloads product entry for the new origin

#### Scenario: Confirm creation-time condition change with entered rows
- **WHEN** the operator confirms a change between good-stock and breakage-stock mode after entering products on a new-transfer form
- **THEN** the system clears every product and serial row and reloads product entry for the selected mode while preserving valid location selections

#### Scenario: Cancel creation-time condition change
- **WHEN** the operator declines confirmation for a condition change after entering products
- **THEN** the system preserves the prior condition and every existing product and serial row

#### Scenario: Re-select the current origin or condition
- **WHEN** the creation form receives the same origin or condition value it already holds
- **THEN** the system preserves the existing product rows

#### Scenario: Change destination with entered rows
- **WHEN** the operator selects, changes, or clears only the destination
- **THEN** the system preserves all product and serial rows while revalidating destination compatibility separately

#### Scenario: Attempt an edit-time context change
- **WHEN** a client attempts to change the origin or stock condition of a persisted transfer
- **THEN** the system rejects the request without clearing or changing its persisted rows

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
