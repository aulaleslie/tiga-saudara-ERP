# cross-business-purchase-sale-documents Specification

## Purpose
TBD - created by archiving change cross-business-purchase-sale-documents. Update Purpose after archive.
## Requirements
### Requirement: Permission-governed target business selection
The system SHALL register a `documents.business.override` permission. Purchase and Sale create/edit forms SHALL expose a required searchable single-business selector only to a user granted that permission. The selector SHALL list only businesses accessible to the authenticated user, except that a Super Admin SHALL be able to select every business. A user without the permission SHALL continue to use only the active session business and SHALL not be able to override it through a forged request.

#### Scenario: Authorized user selects an assigned business
- **WHEN** a user with `documents.business.override` opens a Purchase or Sale create form
- **THEN** the form SHALL show a searchable required business selector containing the businesses accessible to that user

#### Scenario: Unprivileged user uses the active business
- **WHEN** a user without `documents.business.override` opens a Purchase or Sale create or edit form
- **THEN** the form SHALL not show a business override selector and the active session business SHALL remain the effective document business

#### Scenario: Submitted business is not accessible
- **WHEN** a create or update request submits a business not accessible to the authenticated user or submits any override without the permission
- **THEN** the system SHALL reject the request and SHALL not create, update, renumber, or move the document

### Requirement: Selected business defines document and tax context
For an authorized form, the selected business SHALL be the effective setting for document persistence, business-specific reference generation, PKP state, tax validation, and business-scoped form controls. Selecting a business SHALL NOT change `session('setting_id')`.

#### Scenario: Document is created for a non-active business
- **WHEN** an authorized user selects an accessible business other than the active session business and creates a valid Purchase or Sale
- **THEN** the saved document SHALL use the selected business's `setting_id` and target-business reference prefix while the session active business remains unchanged

#### Scenario: Target business is non-PKP
- **WHEN** the effective business is non-PKP
- **THEN** the form SHALL hide tax options, remove tax-specific cart state, and persist the document without PKP tax data or tax reference data

#### Scenario: Target business is PKP
- **WHEN** the effective business is PKP
- **THEN** the form SHALL show target-business tax options and reject submission until each applicable cart line has a valid tax selection for that business

### Requirement: Business changes rehydrate taxation without repricing
When an authorized user changes the selected business before saving, the system SHALL preserve products, quantities, manually entered unit prices, discounts, shipping, and other non-tax cart values. The system SHALL rehydrate only tax context for the target business and SHALL recompute tax-derived amounts through the existing document normalization behavior.

#### Scenario: Business changes from PKP to non-PKP
- **WHEN** an authorized user changes a populated cart from a PKP business to a non-PKP business
- **THEN** the system SHALL remove tax assignments and tax-derived values without changing non-tax cart values

#### Scenario: Business changes from non-PKP to PKP
- **WHEN** an authorized user changes a populated cart from a non-PKP business to a PKP business
- **THEN** the system SHALL preserve non-tax cart values, load target-business tax options, and require valid target-business tax selections before save

### Requirement: Draft documents can move business and receive a new reference
An authorized user SHALL be allowed to select another accessible business only while editing a document in the existing drafted status. If the selected business differs from the document's current business, the update transaction SHALL change `setting_id` and assign a new unique reference using the target business's document prefix. The system SHALL retain existing update restrictions for all non-draft statuses.

#### Scenario: Draft document moves to another business
- **WHEN** an authorized user updates a drafted Purchase or Sale with a different accessible selected business
- **THEN** the system SHALL save the target `setting_id`, rehydrate tax context, and atomically replace the reference with a unique target-business reference

#### Scenario: Rejected document returned to draft moves business
- **WHEN** a previously rejected document has returned to drafted status and an authorized user selects another accessible business during update
- **THEN** the system SHALL allow the business move and assign a new target-business reference

#### Scenario: Non-draft document attempts a business move
- **WHEN** an authorized user submits a different business for an approved, received, dispatched, paid/settled, or any other non-draft Purchase or Sale
- **THEN** the system SHALL reject the business move and leave the document's setting and reference unchanged

### Requirement: Cross-business saves preserve current list navigation
After a successful cross-business create or draft move, the system SHALL keep the existing redirect to the active-business Purchase or Sale list and SHALL clearly notify the user of the target business and saved document reference.

#### Scenario: Saved document is outside the active list scope
- **WHEN** a user saves a document for a business other than the active session business
- **THEN** the system SHALL redirect as the current workflow does and display a success notification that identifies the target business and document reference

