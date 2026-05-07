## ADDED Requirements

### Requirement: Draft Creation Has No Execution Mutations

The system SHALL create POS Return drafts by persisting only POS Return header and POS Return line data. Draft creation MUST NOT create linked Sales Return records, Sale Return Details, stock mutations, dispatch quantity reductions, payment settlements, or inventory transaction history.

#### Scenario: Draft save does not create execution records
- **WHEN** an authorized user saves a valid POS return draft
- **THEN** the system persists a POS Return header and draft POS Return lines
- **AND** no linked Sales Return or Sale Return Detail records are created
- **AND** no stock, dispatch, payment, serial-status, or transaction-history mutation occurs

#### Scenario: Draft requires at least one action
- **WHEN** an authorized user attempts to save a POS return draft where every line has no action
- **THEN** the system blocks the save with a clear validation message

### Requirement: Draft Lines Preserve Source Identity

The system SHALL build and persist POS return draft lines using original source identity rather than product-level aggregation. Source identity MUST distinguish rows by original POS transaction line, checkout sale, sale, sale detail, dispatch detail when present, returned serial when present, bundle context, source setting, source location, and tax context.

#### Scenario: Same SKU with bundle and non-bundle source rows
- **WHEN** a POS transaction contains the same serialized product in bundled and non-bundled POS lines
- **THEN** the draft UI and persisted draft lines keep the bundled serials separate from the non-bundled serials
- **AND** the system does not merge them into one product-level return row

#### Scenario: Same SKU from different source sale context
- **WHEN** a POS transaction generated multiple owner or sale-aligned source rows for the same product
- **THEN** each draft line preserves its original sale, sale detail, checkout sale, owner/source setting, source location, and tax context

### Requirement: Serial Lines Have Independent Resolutions

The system SHALL represent each original sold serial for a serial-tracked product as an individually resolvable draft line. Each serial draft line MUST have one resolution value: `none`, `product_replacement`, or `cash_return`. The default resolution for serial draft lines MUST be `none`.

#### Scenario: Serial defaults to no action
- **WHEN** a valid POS return draft is opened for a transaction with serial-tracked products
- **THEN** each source serial line defaults to the `none` resolution

#### Scenario: Different serials use different resolutions
- **WHEN** a user selects different resolutions for different sold serials of the same product in one POS Return document
- **THEN** the system persists each serial's selected resolution independently

### Requirement: Serial Product Replacement Requires Replacement Serial

The system SHALL require a replacement serial during create and edit when a serial-tracked source line uses the `product_replacement` resolution. The replacement serial MUST belong to the same product as the returned serial, MUST be active/available, and MUST NOT be the same serial as the returned serial. The system MUST NOT require replacement serial origin, owner, or location to match the returned serial during this change.

#### Scenario: Replacement serial selected by scanner input
- **WHEN** a user scans or enters a replacement serial for a serial-tracked line with `product_replacement`
- **THEN** the system accepts the serial only when it belongs to the same product, is active/available, and differs from the returned serial

#### Scenario: Missing replacement serial
- **WHEN** a user saves a draft with a serial-tracked line set to `product_replacement` and no replacement serial
- **THEN** the system blocks the save with a clear validation message for that line

#### Scenario: Resolution changed away from product replacement
- **WHEN** a user changes a serial-tracked line from `product_replacement` to `none` or `cash_return`
- **THEN** the system clears any replacement serial selection for that line

### Requirement: Cash Return Draft Lines Store Expected Amount

The system SHALL calculate and store or expose the expected cash return amount for each actionable `cash_return` draft line using the original POS monetary allocation for that source line.

#### Scenario: Cash return amount uses original allocation
- **WHEN** a user selects `cash_return` for a draft line
- **THEN** the system calculates the expected amount from the original POS source line allocation
- **AND** the amount is available for draft review

### Requirement: Bundle Components Follow Actionable Serialized Parents

The system SHALL auto-carry required bundle component trace data when a serialized bundle parent line has an actionable resolution. If the serialized bundle parent line has the `none` resolution, component rows MUST remain absent from executable draft lines and available only through the source snapshot.

#### Scenario: Actionable bundled serial carries components
- **WHEN** a user sets a serialized bundled parent line to `cash_return` or `product_replacement`
- **THEN** the draft includes the required bundle component trace data for that source bundle instance

#### Scenario: No-action bundled serial omits component execution rows
- **WHEN** a serialized bundled parent line remains `none`
- **THEN** the system does not create executable component draft lines for that parent

### Requirement: Non-Serial Lines Store Only Actionable Quantities

The system SHALL persist non-serial POS Return draft lines only when the line has an actionable resolution and positive quantity. Non-serial draft lines MUST preserve original source sale, dispatch, bundle component, source setting, source location, and tax context.

#### Scenario: Non-serial no-action rows are not persisted
- **WHEN** a non-serial source line has no action or zero quantity
- **THEN** the system omits it from persisted draft return lines

#### Scenario: Non-serial actionable row is source-aligned
- **WHEN** a user saves a non-serial actionable return quantity
- **THEN** the persisted draft line keeps the original source sale, dispatch, bundle, source setting, source location, and tax context

### Requirement: Draft And Rejected Edit Rules

The system SHALL allow POS Returns in `draft` status to be edited. The system SHALL allow POS Returns in `rejected` status to be edited, and saving a rejected return edit MUST reset the POS Return to `draft` status and draft approval state. Editing MUST revalidate source snapshot freshness and replacement serial availability.

#### Scenario: Edit draft return
- **WHEN** an authorized user edits and saves a draft POS Return
- **THEN** the system updates the draft header and rebuilds draft lines from the submitted selections
- **AND** no execution-side mutation occurs

#### Scenario: Edit rejected return resets to draft
- **WHEN** an authorized user edits and saves a rejected POS Return
- **THEN** the system persists the edited selections
- **AND** resets the POS Return status and approval status to draft values

### Requirement: Draft And Rejected Delete Rules

The system SHALL allow draft POS Returns to be hard-deleted because they have no execution effects. The system SHALL allow rejected POS Returns to be deleted through an audited soft-delete style marker. Delete behavior for approved POS Returns is outside this change.

#### Scenario: Hard delete draft
- **WHEN** an authorized user deletes a draft POS Return
- **THEN** the system removes the draft header and draft lines
- **AND** no execution-side mutation occurs

#### Scenario: Audited delete rejected return
- **WHEN** an authorized user deletes a rejected POS Return
- **THEN** the system records deletion actor, timestamp, and reason or audit marker
- **AND** excludes the rejected return from active draft workflows

#### Scenario: Approved delete remains out of scope
- **WHEN** an authorized user attempts draft delete behavior on an approved POS Return
- **THEN** the system blocks direct delete because approved archive behavior is outside this change
