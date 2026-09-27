## MODIFIED Requirements

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
The system MUST reload authoritative locations, products, stock, conversions, and serial records when scanning, selecting, updating, saving, or submitting a transfer and MUST NOT trust client-provided stock snapshots, allocation fields, tax provenance, conversion factors, or serial metadata. A blind transfer client SHALL be able to submit operator intent without carrying protected system-stock fields.

#### Scenario: Client stock snapshot is absent, stale, or modified
- **WHEN** submitted form state omits stock fields or contains a stock or allocation breakdown different from current authoritative records
- **THEN** the system reloads server data, recalculates allocation, and either saves the corrected valid intent or rejects an invalid request without partial persistence or unauthorized disclosure

#### Scenario: Duplicate product lines are submitted
- **WHEN** a request contains duplicate non-serialized product lines with the same transfer mode
- **THEN** the system normalizes them into one deterministic line or rejects them with a permission-appropriate validation error and never persists ambiguous duplicates

### Requirement: Transfer serial selection SHALL use canonical condition-aware availability
Stock-transfer scanner, autocomplete, draft validation, approval, dispatch, and return-dispatch boundaries SHALL exclude missing and otherwise unavailable serials. Normal transfer mode SHALL accept only sellable serials; broken transfer mode SHALL accept only available-broken serials. Each authoritative mutation boundary SHALL revalidate current serial state rather than trusting saved UI metadata. Browser projections SHALL include operator-entered serial identity but SHALL expose authoritative serial availability and tax provenance only to users with `stockTransfers.view-system-stock`.

#### Scenario: Normal transfer scan accepts a sellable serial
- **WHEN** the operator scans an active-compatible, good, undispatched, non-returning serial at the selected origin
- **THEN** the transfer selects that serial once in normal mode and exposes only permission-appropriate serial metadata

#### Scenario: Normal transfer scan rejects active broken serial
- **WHEN** the operator scans an `ACTIVE` serial with `is_broken=true` while using normal mode
- **THEN** the transfer does not select it and presents detailed condition feedback only when the operator has stock visibility, otherwise neutral feedback

#### Scenario: Broken transfer scan accepts available broken serial
- **WHEN** the operator explicitly uses broken mode and scans an available-broken serial at the selected origin
- **THEN** the transfer selects it once while deriving tax and location provenance authoritatively without exposing that provenance to a blind operator

#### Scenario: Missing serial is rejected in every transfer mode
- **WHEN** the operator scans or submits a serial with `status=MISSING`
- **THEN** normal and broken transfer modes reject it as unavailable, no transfer quantity or serial selection changes, and blind feedback does not reveal the authoritative status

#### Scenario: Serial becomes unavailable before dispatch
- **WHEN** a selected serial becomes missing, sold, dispatched elsewhere, or enters a return process before transfer dispatch
- **THEN** authoritative dispatch validation rejects the transfer effect atomically and presents permission-appropriate feedback

### Requirement: Transfer entry SHALL follow hardened adjustment interaction patterns
The system SHALL adapt the location-gated searchable and scannable interaction patterns used by stock adjustment and breakage while retaining the transfer resolver and all transfer-specific base-unit conversion, allocation, duplicate, serial-availability, authoritative validation, and stock-visibility rules.

#### Scenario: Process rapid scanner submissions
- **WHEN** an operator rapidly submits multiple supported barcode or serial scans
- **THEN** the system processes each captured value deterministically against the state produced by preceding scans and restores scanner focus after permission-appropriate feedback

#### Scenario: Select a product from text search
- **WHEN** an operator selects an origin-available product from the debounced name or code results
- **THEN** the system adds it once in the selected transfer mode or focuses or increments its existing eligible row according to serialization rules without exposing protected system stock to a blind operator

#### Scenario: Resolver rejects an ineligible product or serial
- **WHEN** the transfer-specific resolver determines that a product, conversion, or serial is unavailable for the selected origin or mode
- **THEN** the system preserves existing rows and displays actionable Bahasa Indonesia feedback containing detailed system-stock context only when the operator has stock visibility and neutral non-quantitative guidance otherwise
