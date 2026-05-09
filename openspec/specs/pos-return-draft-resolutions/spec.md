## ADDED Requirements

### Requirement: Draft List Actions

The system SHALL show POS Return list actions for draft returns only. A POS Return in `draft` status and draft approval state MUST expose `Edit` to users with `pos.returns.edit`, `Delete` to users with `pos.returns.delete`, and `Ajukan Persetujuan` to users authorized to submit POS return drafts. The system MUST NOT show these draft actions for pending approval, approved, rejected, awaiting receiving, awaiting settlement, awaiting dispatch, manual correction required, archived, cancelled, completed, or deleted returns.

#### Scenario: Draft row shows permitted draft actions
- **WHEN** an authorized user views `/pos/returns`
- **AND** a POS Return row has `status` of `draft` and draft approval state
- **THEN** the row shows the draft actions allowed by the user's POS return permissions
- **AND** the row includes `Ajukan Persetujuan` when the user is allowed to submit draft POS returns

#### Scenario: Non-draft row hides draft actions
- **WHEN** an authorized user views `/pos/returns`
- **AND** a POS Return row is not in `draft` status
- **THEN** the row does not show `Edit`, `Delete`, or `Ajukan Persetujuan` draft actions

#### Scenario: Crafted draft action is rejected for non-draft return
- **WHEN** a user submits a draft edit, delete, or submit-to-approval request for a POS Return that is not in `draft` status
- **THEN** the system blocks the action with a clear invalid lifecycle action response

### Requirement: Draft Submit Moves Return To Pending Approval

The system SHALL provide an `Ajukan Persetujuan` action that moves a valid draft POS Return from `status = draft` and `approval_status = draft` to `status = pending_approval` and `approval_status = pending`. This action MUST validate the persisted draft before changing status, MUST record the submitting actor and timestamp when supported by existing audit fields, and MUST NOT create Sales Return records, Sale Return Details, stock mutations, dispatch quantity reductions, payment settlements, replacement dispatches, serial-status mutations, or inventory transaction history.

#### Scenario: Submit valid draft for approval
- **WHEN** an authorized user clicks `Ajukan Persetujuan` for a valid draft POS Return
- **THEN** the system changes the POS Return status to `pending_approval`
- **AND** changes the approval status to `pending`
- **AND** no execution-side records or mutations are created

#### Scenario: Submit empty or invalid draft is blocked
- **WHEN** an authorized user clicks `Ajukan Persetujuan` for a draft POS Return with no actionable return lines or invalid persisted line data
- **THEN** the system keeps the POS Return in draft status
- **AND** shows a clear validation message
- **AND** no execution-side records or mutations are created

#### Scenario: Submit stale draft is blocked
- **WHEN** an authorized user clicks `Ajukan Persetujuan` for a draft POS Return whose source snapshot or returnable source data is stale
- **THEN** the system keeps the POS Return in draft status
- **AND** shows a clear message that the draft must be refreshed or edited before submission

### Requirement: Create And Edit Share Return Form Surface

The system SHALL render POS Return create and edit line selection through a shared form surface so both screens use the same grouping, resolution controls, quantity behavior, replacement serial input behavior, bundle trace display, component availability display, cash total summary, validation message placement, and loading states. Create MAY include the transaction lookup step before the shared surface is shown. Edit MUST preload the existing draft selections and omit transaction lookup.

#### Scenario: Edit line controls match create line controls
- **WHEN** an authorized user opens the edit page for a draft POS Return
- **THEN** the return-line groups, serial resolution controls, non-serial quantity controls, replacement serial controls, bundle trace display, totals, and validation placement match the create form behavior for the same source transaction

#### Scenario: Edit preloads saved draft selections
- **WHEN** an authorized user opens the edit page for a draft POS Return with saved line selections
- **THEN** the shared form surface preloads those selections
- **AND** changing and saving the form updates the same draft without execution-side mutations

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

The system SHALL build and persist POS return draft lines using original source identity rather than product-level aggregation. Source identity MUST distinguish rows by original POS transaction line, checkout sale, sale, sale detail, dispatch detail when present, returned serial when present, bundle context, source setting, source location, and tax context. For serialized POS source rows, the draft UI grouping key MUST be the original POS transaction line plus returned serial identity, so the form mirrors the receipt line shape.

#### Scenario: Same SKU with bundle and non-bundle source rows
- **WHEN** a POS transaction contains the same serialized product in bundled and non-bundled POS lines
- **THEN** the draft UI and persisted draft lines keep the bundled serials separate from the non-bundled serials
- **AND** the system does not merge them into one product-level return row

#### Scenario: Serialized bundle source uses original POS line identity
- **WHEN** a POS transaction line sells a serialized product as a bundle parent
- **THEN** each sold parent serial is grouped under that original POS transaction line in the draft UI
- **AND** the system uses the POS transaction line bundle metadata as the source of truth for bundle identity
- **AND** split Sales Detail rows created only for bundle component allocation do not appear as top-level returnable cards

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

#### Scenario: Bundled serial cash amount uses POS unit price
- **WHEN** a user selects `cash_return` for a serialized bundled parent line
- **THEN** the draft review amount uses the full original POS unit price for that POS transaction line
- **AND** source allocation metadata remains available for later accounting or settlement workflows

### Requirement: Bundle Components Follow Actionable Serialized Parents

The system SHALL auto-carry required bundle component trace data when a serialized bundle parent line has an actionable resolution. If the serialized bundle parent line has the `none` resolution, component rows MUST remain absent from executable draft lines and available only through the source snapshot. Bundle component trace for serialized parents MUST be derived from the original POS transaction line bundle metadata and aligned to the original POS line's source allocation context.

#### Scenario: Actionable bundled serial carries components
- **WHEN** a user sets a serialized bundled parent line to `cash_return` or `product_replacement`
- **THEN** the draft includes the required bundle component trace data for that source bundle instance

#### Scenario: No-action bundled serial omits component execution rows
- **WHEN** a serialized bundled parent line remains `none`
- **THEN** the system does not create executable component draft lines for that parent

#### Scenario: Bundled replacement shows component availability
- **WHEN** a user sets a serialized bundled parent line to `product_replacement`
- **THEN** the draft UI shows each required bundle component quantity for that returned serial
- **AND** shows the remaining available component quantity
- **AND** computes availability using the POS source setting and source location allocation context
- **AND** does not show source location names
- **AND** does not reserve or mutate component stock during draft

#### Scenario: Component availability is informational during draft
- **WHEN** a bundled parent line uses `product_replacement`
- **AND** one or more component availability counts are low or unavailable
- **THEN** the draft still permits save when all parent replacement serial validation rules pass
- **AND** later approval, receiving, or replacement execution workflows may revalidate component availability outside this change

#### Scenario: Bundled cash return does not require component availability
- **WHEN** a user sets a serialized bundled parent line to `cash_return`
- **THEN** the draft may show component trace for review
- **AND** the draft does not require component availability lookup to save

### Requirement: Non-Serial Lines Store Only Actionable Quantities

The system SHALL persist non-serial POS Return draft lines only when the line has an actionable resolution and positive quantity. Non-serial draft lines MUST preserve original source sale, dispatch, bundle component, source setting, source location, and tax context.

#### Scenario: Non-serial no-action rows are not persisted
- **WHEN** a non-serial source line has no action or zero quantity
- **THEN** the system omits it from persisted draft return lines

#### Scenario: Non-serial actionable row is source-aligned
- **WHEN** a user saves a non-serial actionable return quantity
- **THEN** the persisted draft line keeps the original source sale, dispatch, bundle, source setting, source location, and tax context

### Requirement: Draft And Rejected Edit Rules

The system SHALL allow POS Returns in `draft` status and draft approval state to be edited. Editing MUST revalidate source snapshot freshness and replacement serial availability. This change does not add rejected return edit behavior; rejected returns MUST NOT use the draft edit action introduced by this change.

#### Scenario: Edit draft return
- **WHEN** an authorized user edits and saves a draft POS Return
- **THEN** the system updates the draft header and rebuilds draft lines from the submitted selections
- **AND** no execution-side mutation occurs

#### Scenario: Draft edit action rejects rejected return
- **WHEN** a user attempts to use the draft edit action for a rejected POS Return
- **THEN** the system blocks the draft edit action
- **AND** keeps the POS Return status and approval status unchanged

### Requirement: Draft And Rejected Delete Rules

The system SHALL allow draft POS Returns to be hard-deleted because they have no execution effects. This change does not add rejected return delete behavior; rejected returns MUST NOT use the draft hard-delete action introduced by this change. Delete behavior for approved POS Returns remains outside this change.

#### Scenario: Hard delete draft
- **WHEN** an authorized user deletes a draft POS Return
- **THEN** the system removes the draft header and draft lines
- **AND** no execution-side mutation occurs

#### Scenario: Draft delete action rejects rejected return
- **WHEN** a user attempts to use the draft delete action for a rejected POS Return
- **THEN** the system blocks the draft delete action
- **AND** keeps the POS Return status and approval status unchanged

#### Scenario: Approved delete remains out of scope
- **WHEN** an authorized user attempts draft delete behavior on an approved POS Return
- **THEN** the system blocks direct delete because approved archive behavior is outside this change
