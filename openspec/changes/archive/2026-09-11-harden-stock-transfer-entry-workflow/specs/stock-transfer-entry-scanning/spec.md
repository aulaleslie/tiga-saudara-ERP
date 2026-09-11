## MODIFIED Requirements

### Requirement: Authorized origin users can create and edit stock-transfer requests
The system SHALL allow a user with `stockTransfers.create` to create a `DRAFT` stock-transfer request with an active origin owned by the active tenant, one explicit stock condition, and at least one valid product quantity while allowing destination to remain unset. The system SHALL require `stockTransfers.edit` to edit or submit a transfer, SHALL allow mutation only while its current lifecycle state is editable, and SHALL require the origin to belong to the active tenant.

#### Scenario: Save a draft without destination
- **WHEN** an authorized user saves a new transfer with a valid active origin, one transfer mode, and at least one valid product quantity but no destination
- **THEN** the system atomically creates the transfer and its lines in `DRAFT` status with no destination

#### Scenario: Save a draft with destination
- **WHEN** an authorized user saves a draft with a destination
- **THEN** the system authoritatively validates that the destination is active, distinct from the origin, and compatible with the origin before storing it

#### Scenario: Submit a complete draft for approval
- **WHEN** an authorized origin-tenant editor submits a `DRAFT` transfer with valid distinct origin and destination locations, one transfer mode, and at least one valid product quantity
- **THEN** the system revalidates the complete request and atomically transitions it to `PENDING`

#### Scenario: Reject submission without destination
- **WHEN** an authorized user attempts to submit a `DRAFT` transfer whose destination is unset or no longer valid
- **THEN** the system keeps the transfer and lines unchanged in `DRAFT` status and returns an actionable validation error

#### Scenario: Edit an editable origin transfer
- **WHEN** an authorized origin-tenant user opens and updates a `PENDING` or acknowledged `DRAFT` transfer
- **THEN** the system hydrates the existing mode, products, quantity intent, scan context, serial selections, and optional destination into the shared form and saves the complete updated request atomically

#### Scenario: Block destination or unrelated tenant editing
- **WHEN** a user whose active tenant does not own the transfer origin directly requests the edit, update, or submission endpoint
- **THEN** the system rejects the request without changing the transfer or its lines

#### Scenario: Block mutation after approval or dispatch
- **WHEN** a user attempts to edit a transfer that is approved, archived, dispatched, received, awaiting return, return-dispatched, or completed
- **THEN** the system rejects the mutation regardless of whether the user can see the transfer

## ADDED Requirements

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
The system SHALL maintain one form-wide and persisted stock condition for every new or editable transfer: good stock or breakage stock. Product lookup, quantities, serials, mapping, draft validation, and submission SHALL use only the selected condition and SHALL reject mixed-condition payloads.

#### Scenario: Enter products in good-stock mode
- **WHEN** good-stock mode is selected
- **THEN** product lookup and allocation use only saleable stock and accept only saleable serials

#### Scenario: Enter products in breakage-stock mode
- **WHEN** breakage-stock mode is selected
- **THEN** product lookup and allocation use only broken stock and accept only available-broken serials

#### Scenario: Submit a mixed-condition payload
- **WHEN** a client submits good and broken quantities or serials that conflict with the transfer's selected mode
- **THEN** authoritative validation rejects the request without partially changing the transfer or its lines

#### Scenario: Hydrate an editable transfer
- **WHEN** an operator opens a new-format editable transfer
- **THEN** the form restores its persisted mode before hydrating and enabling its product rows

#### Scenario: View a historical mixed-condition transfer
- **WHEN** a historical transfer contains both good and broken stock buckets
- **THEN** the system preserves and renders its historical contents without silently rewriting its stock condition or inventory history

### Requirement: Stock-context changes SHALL reset product rows deterministically
The system SHALL clear stock-transfer product rows when the selected origin or transfer mode actually changes, because those values determine stock and serial eligibility, and SHALL preserve rows when only the destination changes.

#### Scenario: Change origin with entered rows
- **WHEN** the operator changes or clears the origin after entering products
- **THEN** the system clears every product and serial row, clears row validation state, resets destination, and reloads product entry for the new origin

#### Scenario: Change transfer mode with entered rows
- **WHEN** the operator changes between good-stock and breakage-stock mode after entering products
- **THEN** the system clears every product and serial row and reloads product entry for the selected mode while preserving valid location selections

#### Scenario: Re-select the current origin or mode
- **WHEN** the form receives the same origin or mode value it already holds
- **THEN** the system preserves the existing product rows

#### Scenario: Change destination with entered rows
- **WHEN** the operator selects, changes, or clears only the destination
- **THEN** the system preserves all product and serial rows while revalidating destination compatibility separately

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
