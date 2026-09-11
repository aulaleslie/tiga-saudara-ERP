# stock-transfer-entry-scanning Specification

## Purpose
TBD - created by archiving change harden-stock-transfer-lifecycle-and-scanning. Update Purpose after archive.
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

### Requirement: Transfer entry supports text search and scanner input
The system SHALL provide one focused lookup input that resolves exact scanner submissions and retains debounced product name or code search as a fallback.

#### Scenario: Scan a product barcode
- **WHEN** the operator submits an exact product barcode for a stock-managed product available at the selected origin
- **THEN** the system adds or increments that product by one base unit and restores focus to the scanner input

#### Scenario: Scan a conversion barcode
- **WHEN** the operator submits an exact unit-conversion barcode whose conversion factor resolves to a supported whole base-unit quantity
- **THEN** the system adds or increments the product by that conversion factor and displays the scanned unit, scan count, factor, and resulting base quantity

#### Scenario: Reject a fractional or invalid conversion
- **WHEN** a scanned conversion is missing, non-positive, or does not resolve to a whole base-unit quantity supported by stock transfers
- **THEN** the system does not change the transfer lines and displays an actionable validation error

#### Scenario: Scan a serial number
- **WHEN** the operator submits an exact active serial number belonging to a stock-managed product at the selected origin location
- **THEN** the system adds the product if necessary, selects that exact serial once, derives its tax and broken provenance, and increments base quantity by one

#### Scenario: Prevent duplicate serial scanning
- **WHEN** the same serial number is scanned more than once in the transfer
- **THEN** the system preserves a single selection and notifies the operator that the serial is already selected

#### Scenario: Fall back to product search
- **WHEN** input is not submitted as an exact product barcode, conversion barcode, or serial number
- **THEN** the system presents origin-available stock-managed products matching product name or product code without automatically choosing an ambiguous result

### Requirement: Scanner quantities use authoritative base-unit normalization
The system SHALL store and approve stock-transfer quantity intent in base units while retaining scan context needed to explain conversion-derived quantities.

#### Scenario: Repeated conversion scans accumulate base quantity
- **WHEN** an operator scans a conversion with factor 12 twice
- **THEN** the line records a requested base quantity of 24 and displays that two conversion units produced the quantity

#### Scenario: Manual quantity and scanned quantity converge
- **WHEN** an operator enters or scans quantities for the same non-serialized product
- **THEN** validation and approval operate on one normalized requested base quantity rather than separate incompatible quantity representations

### Requirement: Transfer entry previews non-tax-first allocation
For non-serialized quantities, the system SHALL estimate allocation from current origin stock by consuming the applicable non-tax bucket before the corresponding taxed bucket, without reserving or deducting inventory.

#### Scenario: Non-tax stock fully covers requested normal quantity
- **WHEN** available normal non-tax stock is at least the requested base quantity
- **THEN** the preview allocates the request entirely to normal non-tax quantity and shows no mandatory tax-return quantity

#### Scenario: Requested normal quantity spills into taxed stock
- **WHEN** requested normal quantity exceeds available normal non-tax stock but total normal stock is sufficient
- **THEN** the preview allocates all available normal non-tax stock first, allocates the balance to normal taxed stock, and persistently warns that the taxed portion will require return for a cross-tenant transfer

#### Scenario: Broken-stock transfer uses separate buckets
- **WHEN** the operator intentionally selects broken-stock mode
- **THEN** the preview consumes broken non-tax stock before broken taxed stock and does not consume saleable stock

#### Scenario: Normal scanning never consumes broken stock
- **WHEN** normal saleable stock is insufficient but broken stock exists
- **THEN** the system reports insufficient normal stock instead of silently allocating broken stock

### Requirement: Server-side validation is authoritative
The system MUST reload authoritative locations, products, stock, conversions, and serial records when saving or submitting a transfer and MUST NOT trust client-provided stock snapshots, tax provenance, conversion factors, or serial metadata.

#### Scenario: Client stock snapshot is stale or modified
- **WHEN** submitted form state contains a stock breakdown different from current authoritative records
- **THEN** the system recalculates the preview from server data and either saves the corrected intent or rejects an invalid request without partial persistence

#### Scenario: Duplicate product lines are submitted
- **WHEN** a request contains duplicate non-serialized product lines with the same transfer mode
- **THEN** the system normalizes them into one deterministic line or rejects them with a validation error and never persists ambiguous duplicates

### Requirement: Transfer serial selection SHALL use canonical condition-aware availability
Stock-transfer scanner, autocomplete, draft validation, approval, dispatch, and return-dispatch boundaries SHALL exclude missing and otherwise unavailable serials. Normal transfer mode SHALL accept only sellable serials; broken transfer mode SHALL accept only available-broken serials. Each authoritative mutation boundary SHALL revalidate current serial state rather than trusting saved UI metadata.

#### Scenario: Normal transfer scan accepts a sellable serial
- **WHEN** the operator scans an active-compatible, good, undispatched, non-returning serial at the selected origin
- **THEN** the transfer selects that serial once in normal mode

#### Scenario: Normal transfer scan rejects active broken serial
- **WHEN** the operator scans an `ACTIVE` serial with `is_broken=true` while using normal mode
- **THEN** the transfer does not select it and explains in Bahasa Indonesia that it belongs to broken stock

#### Scenario: Broken transfer scan accepts available broken serial
- **WHEN** the operator explicitly uses broken mode and scans an available-broken serial at the selected origin
- **THEN** the transfer selects it once and derives its authoritative tax and location provenance

#### Scenario: Missing serial is rejected in every transfer mode
- **WHEN** the operator scans or submits a serial with `status=MISSING`
- **THEN** normal and broken transfer modes reject it as unavailable
- **AND** no transfer quantity or serial selection changes

#### Scenario: Serial becomes unavailable before dispatch
- **WHEN** a selected serial becomes missing, sold, dispatched elsewhere, or enters a return process before transfer dispatch
- **THEN** authoritative dispatch validation rejects the transfer effect atomically

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
The system SHALL adapt the location-gated searchable and scannable interaction patterns used by stock adjustment and breakage while retaining the transfer resolver and all transfer-specific base-unit conversion, allocation, duplicate, serial-availability, and authoritative validation rules.

#### Scenario: Process rapid scanner submissions
- **WHEN** an operator rapidly submits multiple supported barcode or serial scans
- **THEN** the system processes each captured value deterministically against the state produced by preceding scans and restores scanner focus after feedback

#### Scenario: Select a product from text search
- **WHEN** an operator selects an origin-available product from the debounced name or code results
- **THEN** the system adds it once in the selected transfer mode or focuses/increments its existing eligible row according to serialization rules

#### Scenario: Resolver rejects an ineligible product or serial
- **WHEN** the transfer-specific resolver determines that a product, conversion, or serial is unavailable for the selected origin or mode
- **THEN** the system preserves existing rows and displays actionable Bahasa Indonesia feedback

