## MODIFIED Requirements

### Requirement: Authorized origin users prepare a forward-dispatch physical count
For a workflow version `2` approved transfer, the system SHALL allow an actor with `stockTransfers.dispatch.create` operating under the origin business to create or edit the single open forward-dispatch draft using approved product identities and the transfer's stock condition. The HTML preparation page MUST render both newly created and resumed drafts when Eloquent lazy loading is disabled, with requested quantities shown only to users authorized to view system stock.

#### Scenario: Origin dispatcher opens preparation
- **WHEN** an origin-side user with dispatch-create permission opens an eligible approved transfer
- **THEN** the system creates or resumes its forward-dispatch draft bound to the exact approved transfer revision

#### Scenario: Newly created draft renders with lazy loading disabled
- **WHEN** an authorized origin dispatcher opens the HTML preparation page for a transfer whose forward-dispatch draft must be created and Eloquent lazy loading is disabled
- **THEN** the page renders its product identities, physical counts, confirmation state, and selected serials without a lazy-loading exception

#### Scenario: Resumed draft renders with lazy loading disabled
- **WHEN** an authorized origin dispatcher reopens the HTML preparation page for an existing forward-dispatch draft and Eloquent lazy loading is disabled
- **THEN** the page renders the stored line and serial observations without a lazy-loading exception

#### Scenario: Requested quantities follow stock visibility
- **WHEN** an authorized dispatcher opens the HTML preparation page
- **THEN** approved requested quantities are shown only if that dispatcher has system-stock visibility, while a dispatcher without that visibility receives the blind preparation page

#### Scenario: Unauthorized or wrong-side preparation
- **WHEN** a user lacks dispatch-create permission or the active business does not own the origin location
- **THEN** the system rejects access and mutation without exposing or changing movement or inventory data

#### Scenario: Legacy transfer uses legacy dispatch
- **WHEN** a workflow version `1` transfer is dispatched
- **THEN** the existing legacy route, permission, lifecycle, inventory, and serial behavior remains authoritative
