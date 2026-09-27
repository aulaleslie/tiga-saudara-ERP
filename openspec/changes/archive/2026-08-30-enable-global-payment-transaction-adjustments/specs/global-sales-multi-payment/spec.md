## MODIFIED Requirements

### Requirement: Dedicated cross-setting read-only sale inspection
The system SHALL provide dedicated global sale detail and payment-history contexts that do not apply the active session setting restriction and that permit only payment operations, defined inline metadata updates, and narrowly authorized monetary/date adjustments.

#### Scenario: User opens a sale belonging to another setting
- **WHEN** an authorized user opens a base-eligible sale whose setting differs from the active session setting
- **THEN** the global detail displays the sale and related dispatch and payment information
- **AND** the page presents company and validation context from the sale's actual setting

#### Scenario: Authorized user edits the sale note globally
- **WHEN** a user has `salePayments.global.access` and `sales.edit` and views a non-archived sale through global detail
- **THEN** the existing sale note editor is available
- **AND** a saved note updates the viewed sale even when its setting differs from the active session setting

#### Scenario: Authorized user opens monetary adjustment globally
- **WHEN** a user has `salePayments.global.access`, `sales.edit`, and `sales.dispatched.monetary.edit` and views an eligible dispatched or partially dispatched sale
- **THEN** global detail displays `Ubah Nilai (Moneter)`
- **AND** the dedicated global entry route opens the existing monetary-only sale form using the sale's actual setting context
- **AND** no full-edit controls become available

#### Scenario: Authorized user opens sale date adjustment globally
- **WHEN** a user has `salePayments.global.access` and at least one applicable sale reporting-date or due-date override permission for an eligible sale
- **THEN** global detail displays the combined date-adjustment process
- **AND** it exposes only the date fields the user is authorized to change
- **AND** the dedicated global endpoint can save an authorized adjustment when the sale belongs to another setting

#### Scenario: Global access alone remains read-only
- **WHEN** a user has `salePayments.global.access` but lacks an applicable inline, monetary, reporting-date, or due-date permission
- **THEN** the corresponding controls remain read-only or absent
- **AND** direct or tampered global adjustment requests are forbidden

#### Scenario: Unrelated sale mutations remain unavailable
- **WHEN** an authorized user views a sale through the global detail route
- **THEN** full update, approval, dispatch, archive, delete, and attachment-management controls are absent
- **AND** payment creation is available only when the sale remains payable and the user has `salePayments.create`

#### Scenario: Normal sale routes remain setting-scoped
- **WHEN** a user requests an existing normal sale detail, edit, date-adjustment, or inline-edit route for a sale outside the active setting
- **THEN** the normal ownership behavior remains in force
- **AND** cross-setting access is granted only through dedicated routes protected by `salePayments.global.access`

#### Scenario: Adjustment changes the current global result membership
- **WHEN** an authorized global monetary adjustment succeeds and the sale no longer qualifies for its current global detail or selected payment view
- **THEN** the system preserves the committed adjustment
- **AND** it redirects to a safe Global Sales Payment destination with success feedback instead of returning an authorization or not-found failure
