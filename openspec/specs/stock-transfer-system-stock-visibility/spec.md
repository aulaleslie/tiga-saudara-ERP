# stock-transfer-system-stock-visibility Specification

## Purpose
Define the permission-gated boundary that keeps exact stock-transfer system stock, allocation, and serial-provenance information visible only to authorized users, while allowing every authorized editor to see and revise operator-entered draft values and keeping authoritative server validation intact for blind operators.

## Requirements

### Requirement: Transfer system-stock visibility SHALL use centralized authorization
The system SHALL register `stockTransfers.view-system-stock` in the centralized permission configuration and SHALL use the existing application authorization mechanism whenever transfer system-stock information is projected. Existing transfer workflow permissions SHALL NOT imply this visibility permission, and the application-wide Super Admin authorization bypass SHALL grant visibility without requiring a separate explicit assignment.

#### Scenario: Permission is available to role management
- **WHEN** the centralized permissions are synchronized
- **THEN** `stockTransfers.view-system-stock` exists in the Transfer Stok permission group and can be assigned through existing role management

#### Scenario: Workflow permission does not imply stock visibility
- **WHEN** a non-Super-Admin user holds any combination of transfer access, create, edit, show, approval, dispatch, receive, or archive permissions but lacks `stockTransfers.view-system-stock`
- **THEN** the user retains those authorized workflow actions but receives the blind transfer projection

#### Scenario: Explicitly privileged user sees system stock
- **WHEN** a user holds `stockTransfers.view-system-stock` and is otherwise authorized for the requested transfer surface
- **THEN** the user receives the privileged transfer projection for that surface

#### Scenario: Super Admin bypasses explicit assignment
- **WHEN** a user has the `Super Admin` role but has no direct `stockTransfers.view-system-stock` assignment
- **THEN** the existing global authorization bypass grants the privileged transfer projection

### Requirement: Transfer projections SHALL classify and omit protected system information
For users without `stockTransfers.view-system-stock`, every stock-transfer server projection SHALL omit exact available quantities, good/broken and tax/non-tax stock buckets, system-computed allocation breakdowns, serial availability and tax provenance, maximum or remaining quantities, shortage figures, approved movement expectations, return obligations, and allocation-drift comparisons. Protected keys and equivalent formatted values SHALL be absent rather than represented by zero, null, placeholders, masks, hashes, or hidden markup.

#### Scenario: Blind create state omits origin stock
- **WHEN** a blind user selects an origin and adds a good or broken non-serialized product
- **THEN** rendered HTML and Livewire state contain no exact origin quantity, stock-bucket value, maximum, remaining value, or computed allocation breakdown

#### Scenario: Blind serialized state omits provenance
- **WHEN** a blind user searches for or selects an eligible serial in a transfer draft
- **THEN** Livewire state and events contain only the serial identity needed for the operator's entry and omit tax ID, taxable classification, authoritative availability state, and other protected provenance

#### Scenario: Privileged form retains operational detail
- **WHEN** a user with stock visibility opens the same create or edit workflow
- **THEN** the form may include exact stock and allocation information required by the existing privileged experience

#### Scenario: Protected fields are omitted instead of masked
- **WHEN** a blind projection is serialized
- **THEN** protected fields do not exist in the projection and are not emitted as null, zero, placeholder, formatted, encrypted, signed, or hashed substitutes

### Requirement: Authorized editors SHALL share operator-entered draft values
A user who is authorized by existing transfer edit, tenant, location, and lifecycle rules SHALL be allowed to see and revise the product identity, requested base quantity, selected transfer condition, and selected serial-number identity stored in an editable transfer draft, regardless of which authorized editor entered those values. Shared draft visibility SHALL NOT expose system stock, computed allocation, or serial provenance.

#### Scenario: Another editor opens a shared draft
- **WHEN** an authorized editor opens an editable transfer draft created or previously revised by another authorized editor
- **THEN** the editor sees the stored product identities, requested quantities, condition, and selected serial numbers while protected system information remains omitted

#### Scenario: User without edit authority views transfer
- **WHEN** a user can show a transfer but is not authorized to edit it
- **THEN** shared-edit authority is not inferred and the detail projection follows the user's stock-visibility permission and the surface-specific visibility rules

### Requirement: Blind protection SHALL cover every existing transfer browser surface
The system SHALL apply permission-aware projections to transfer create, edit, detail, dispatch, receipt, return dispatch, return receipt, rejection/correction, archive, allocation-drift, and browser-accessible audit or export surfaces that exist in this delivery. A blind detail or lifecycle projection SHALL omit exact requested and movement quantities, serial manifests, allocation buckets, expected values, return obligations, and differences while retaining non-stock document metadata and lifecycle context needed for authorized navigation and action.

#### Scenario: Blind user opens transfer detail
- **WHEN** a user with `stockTransfers.show` but without stock visibility opens a transfer containing distinctive quantities, bucket allocations, obligations, and serial manifests
- **THEN** the rendered response contains none of those protected values while retaining permitted document, product-identity, status, actor, and timestamp context

#### Scenario: Blind user encounters allocation drift
- **WHEN** dispatch validation detects allocation drift for a user without stock visibility
- **THEN** the browser response and session contain no planned allocation, actual allocation, difference, availability figure, or protected allocation payload and present only neutral guidance

#### Scenario: Privileged user reviews lifecycle detail
- **WHEN** an otherwise-authorized user with stock visibility opens an existing lifecycle or drift surface
- **THEN** the current detailed operational comparison remains available

### Requirement: Transfer mutations SHALL rely only on authoritative server data
Stock-transfer scan, selection, quantity update, save, submit, approval, dispatch, receipt, and return mutation boundaries SHALL reload and validate applicable location, product, conversion, stock, condition, and serial data on the server. Client-provided stock snapshots, allocation fields, maximums, tax provenance, serial state, or other protected metadata MUST NOT be trusted, including when submitted by a privileged client.

#### Scenario: Blind draft saves without stock snapshot
- **WHEN** a blind editor saves valid operator intent whose Livewire state contains no stock or allocation fields
- **THEN** the system derives the authoritative allocation and persists the valid draft without requiring protected fields from the client

#### Scenario: Client injects protected metadata
- **WHEN** a crafted request supplies modified stock, allocation, tax, condition-provenance, or serial-availability fields
- **THEN** the system ignores or rejects those fields, reloads authoritative data, and never persists an effect based on the injected values

#### Scenario: Stock changes after form hydration
- **WHEN** stock or serial availability changes after a blind or privileged form was loaded
- **THEN** the next authoritative mutation uses current server state and succeeds or fails atomically without trusting the earlier presentation

### Requirement: Blind browser feedback SHALL be neutral and non-quantitative
When authoritative transfer validation fails for a user without `stockTransfers.view-system-stock`, browser-facing errors, validation attributes, session flashes, exception messages, badges, summaries, and client-directed logs SHALL identify the affected entry or corrective action without disclosing exact available, expected, missing, excess, remaining, allocated, or bucket quantities or protected serial provenance. Detailed server diagnostics MAY be retained under existing non-browser log controls.

#### Scenario: Blind quantity exceeds authoritative stock
- **WHEN** a blind operator enters a quantity that authoritative validation cannot fulfill
- **THEN** the browser receives a neutral non-quantitative error and no maximum, available, shortage, remaining, or allocation value

#### Scenario: Blind serial is ineligible
- **WHEN** a blind operator submits a serial that fails authoritative product, location, condition, status, reservation, or duplication validation
- **THEN** the browser receives neutral corrective feedback without revealing the serial's authoritative stock state or tax provenance

#### Scenario: Privileged validation may remain detailed
- **WHEN** the same validation failure is presented to a user with stock visibility
- **THEN** the system may include the existing exact operational detail where it is safe and useful

### Requirement: Visibility verification SHALL detect payload leakage
Focused automated verification SHALL cover both absence for blind users and compatibility for privileged and Super Admin users across rendered HTML, Livewire public state and snapshots, component events, validation data, and session/browser payloads. Tests SHALL use distinctive protected sentinel values and SHALL cover crafted mutations, good and broken conditions, and serialized and non-serialized products.

#### Scenario: Blind payload is inspected
- **WHEN** focused tests exercise a blind transfer surface using distinctive stock quantities, tax identifiers, and serial provenance
- **THEN** none of the protected sentinel values or protected keys appear in HTML or serialized client-visible state

#### Scenario: Privileged and Super Admin paths are inspected
- **WHEN** focused tests exercise the corresponding surface with an explicitly privileged user and with a Super Admin
- **THEN** both receive privileged visibility without losing otherwise-authorized workflow behavior

#### Scenario: Human browser verification is performed
- **WHEN** the delivery is reviewed in a browser with representative blind, privileged, and Super Admin accounts
- **THEN** the reviewer verifies create, edit, detail, and applicable lifecycle interactions without requiring a full automated suite run

### Requirement: Forward-dispatch preparation has blind and privileged projections
The system SHALL project forward-dispatch preparation according to `stockTransfers.view-system-stock`, and movement action permissions MUST NOT imply access to protected request, stock, allocation, serial-expectation, or difference information.

#### Scenario: Blind dispatcher prepares a count
- **WHEN** an authorized dispatcher lacks stock visibility
- **THEN** the dispatcher sees approved product identities, condition, their own entered quantities and serials, and confirmation state but receives no requested quantities, expected serials, stock quantities, buckets, allocation, remaining/maximum values, or differences

#### Scenario: Privileged dispatcher prepares a count
- **WHEN** an otherwise-authorized dispatcher has stock visibility
- **THEN** the preparation projection may include approved request quantities and serials, authoritative origin stock, buckets, and allocation information

#### Scenario: Blind payload is inspected
- **WHEN** rendered HTML, Livewire state, snapshots, events, validation data, and session payloads are inspected for a blind dispatcher
- **THEN** protected keys and distinctive protected values are absent rather than hidden, masked, hashed, or represented by placeholders

### Requirement: Forward-dispatch approval comparison respects stock visibility
An actor with dispatch-approval permission but without stock visibility SHALL receive only a neutral server-authoritative match or failure result, while an otherwise-authorized approver with stock visibility MAY receive exact expected-versus-counted and allocation detail.

#### Scenario: Blind approver reviews exact count
- **WHEN** server comparison and locked stock validation pass for an approver without stock visibility
- **THEN** the approver may approve without receiving exact request, count, stock, bucket, allocation, serial-expectation, or difference values

#### Scenario: Blind approver reviews mismatch
- **WHEN** comparison or authoritative fulfillment fails for an approver without stock visibility
- **THEN** the browser receives neutral non-quantitative guidance and the movement remains pending without protected detail

#### Scenario: Privileged approver reviews mismatch
- **WHEN** an approver also holds stock visibility
- **THEN** the comparison may show exact request, physical count, stock, allocation, expected and counted serials, and line-level differences needed for review

#### Scenario: Crafted approval payload supplies protected data
- **WHEN** any approver injects expected quantities, stock values, bucket allocation, serial provenance, or comparison results
- **THEN** the system ignores the client values and derives the comparison and allocation from locked authoritative server data
