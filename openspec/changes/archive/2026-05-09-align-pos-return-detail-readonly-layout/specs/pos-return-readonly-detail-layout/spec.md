## ADDED Requirements

### Requirement: Detail Uses Readonly Shared Layout Shape
The system SHALL render the POS Return detail body using a readonly layout shape that mirrors the create/edit return form surface, including transaction information, payment information, grouped product cards, serial/non-serial line presentation, bundle trace presentation, and return summary placement. The detail body MUST NOT expose editable inputs, selectable resolution controls, scan controls, or Livewire mutation controls.

#### Scenario: Detail mirrors create edit structure without controls
- **WHEN** an authorized user opens a POS Return detail page
- **THEN** the page shows transaction information, payment information, grouped returned product cards, and return totals in the same general order as create/edit
- **AND** the page does not show editable quantity inputs, resolution buttons, replacement serial scan inputs, or save buttons

### Requirement: Detail Header Keeps Lifecycle Toolbar
The system SHALL keep lifecycle actions on the POS Return detail page in a header toolbar that includes the return reference, created timestamp, status badges, and only the actions permitted for the current user and return lifecycle state.

#### Scenario: Detail header shows permitted lifecycle actions
- **WHEN** an authorized user opens a POS Return detail page for a return with available lifecycle actions
- **THEN** the header shows the return reference, created timestamp, status badges, and the lifecycle actions allowed by permissions and status
- **AND** the readonly body remains separate from those actions

#### Scenario: Detail header hides unavailable lifecycle actions
- **WHEN** a user opens a POS Return detail page without permission for an action or when the return state does not allow that action
- **THEN** the header does not show that action

### Requirement: Resolutions Display As Readonly Badges
The system SHALL display each POS Return line resolution as a readonly badge or equivalent state label. The detail page MUST show only the selected resolution for each returned line and MUST NOT show disabled versions of the create/edit segmented resolution controls.

#### Scenario: Cash return line shows cash badge
- **WHEN** a POS Return line has `cash_return` resolution
- **THEN** the detail line shows a readonly `Tunai` or equivalent cash return badge
- **AND** the line shows the returned cash amount when available

#### Scenario: Product replacement line shows replacement badge
- **WHEN** a POS Return line has `product_replacement` resolution
- **THEN** the detail line shows a readonly `Ganti Produk` or equivalent replacement badge
- **AND** the line shows replacement serial details when applicable

### Requirement: Detail Shows Returned Lines First With Collapsible Snapshot Context
The system SHALL show returned or otherwise actionable POS Return lines first on the detail page. When original source snapshot context is available, the system SHALL provide a collapsed section that can show the full original transaction snapshot context, including non-returned lines marked as not returned.

#### Scenario: Returned lines are primary
- **WHEN** a POS Return detail page loads
- **THEN** lines persisted on the POS Return appear in the primary grouped product-card review area

#### Scenario: Full snapshot context is collapsed
- **WHEN** the POS Return has source snapshot context available
- **THEN** the page provides a collapsed section for the original transaction snapshot
- **AND** non-returned original lines in that section are identified as not returned

### Requirement: Non Serial Lines Show Returned Quantity Only
The system SHALL display non-serial POS Return detail quantities as the returned quantity for that return line. The primary readonly line display MUST NOT require users to compare returned quantity against original available quantity.

#### Scenario: Non serial line shows returned quantity
- **WHEN** a non-serial POS Return line has quantity `2`
- **THEN** the primary detail line shows the returned quantity as `2`

### Requirement: Serial Rows Keep Returned And Replacement Serial Together
The system SHALL display returned serial identity and replacement serial identity in the same serial row when a serial-tracked POS Return line uses product replacement. If no replacement serial applies, the row SHALL still show the returned serial identity when available.

#### Scenario: Replacement serial shown with returned serial
- **WHEN** a serial-tracked POS Return line has a returned serial and a replacement serial
- **THEN** the detail row shows both serial identities in the same row

#### Scenario: Cash serial return shows returned serial only
- **WHEN** a serial-tracked POS Return line uses cash return
- **THEN** the detail row shows the returned serial identity without requiring a replacement serial display

### Requirement: Linked Sales Returns Are Visible At Summary And Line Levels
The system SHALL show linked Sales Return documents in a summary area and SHALL expose line-level linkage where the POS Return line can be traced to a linked Sales Return or Sales Return Detail.

#### Scenario: Linked Sales Return summary appears
- **WHEN** a POS Return has linked Sales Return documents
- **THEN** the detail page shows each linked Sales Return reference, status, sale reference, and location when available

#### Scenario: Line shows execution linkage
- **WHEN** a POS Return line is linked to a Sales Return Detail or linked Sales Return context
- **THEN** the line-level readonly display includes enough reference information to trace the execution document

### Requirement: Technical Audit Details Are Collapsed
The system SHALL keep technical audit details such as source snapshot hash and detailed source identifiers available in a collapsed audit/details section instead of making them the primary content of the detail page.

#### Scenario: Snapshot hash is available but secondary
- **WHEN** a POS Return detail page has a source snapshot hash
- **THEN** the hash is available in a collapsed audit/details section
- **AND** it is not displayed as the primary line-review content
