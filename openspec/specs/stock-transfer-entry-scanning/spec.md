# stock-transfer-entry-scanning Specification

## Purpose
TBD - created by archiving change harden-stock-transfer-lifecycle-and-scanning. Update Purpose after archive.
## Requirements
### Requirement: Authorized origin users can create and edit stock-transfer requests
The system SHALL allow a user with `stockTransfers.create` to create a stock-transfer request from a location owned by the active tenant, and SHALL allow a user with `stockTransfers.edit` to edit a transfer only while its current lifecycle state is editable and its origin belongs to the active tenant.

#### Scenario: Create a pending transfer for the active origin tenant
- **WHEN** an authorized user submits valid distinct origin and destination locations and at least one valid product quantity
- **THEN** the system creates the transfer and its lines atomically in `PENDING` status for the active origin tenant

#### Scenario: Edit an editable origin transfer
- **WHEN** an authorized origin-tenant user opens and updates a `PENDING` or acknowledged `DRAFT` transfer
- **THEN** the system hydrates the existing products, quantity intent, scan context, and serial selections into the shared form and saves the complete updated request atomically

#### Scenario: Block destination or unrelated tenant editing
- **WHEN** a user whose active tenant does not own the transfer origin directly requests the edit or update endpoint
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

