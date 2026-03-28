## ADDED Requirements

### Requirement: POS scan input SHALL provide deterministic helper action
The POS shell scan section MUST provide an explicit helper action control that triggers scan resolution without requiring keyboard Enter behavior.

#### Scenario: Manual tablet input uses helper action
- **WHEN** cashier types a barcode or serial into the POS scan input on a tablet browser and taps the helper action
- **THEN** the system MUST execute scan resolution for the typed value

#### Scenario: Empty input helper action is blocked with guidance
- **WHEN** cashier taps the helper action while the scan input is empty
- **THEN** the system MUST NOT call scan resolution and MUST show guidance to enter a barcode or serial first

### Requirement: Enter and helper action SHALL use equivalent scan resolution behavior
Keyboard Enter and helper-action triggers MUST follow the same scan resolution contract and status-feedback behavior for successful and failed resolution outcomes.

#### Scenario: Product exact match parity
- **WHEN** the same valid product barcode is submitted via Enter and via helper action
- **THEN** both submissions MUST produce equivalent cart-add behavior and success feedback semantics

#### Scenario: Not-found parity
- **WHEN** the same unknown code is submitted via Enter and via helper action
- **THEN** both submissions MUST produce equivalent not-found feedback semantics

### Requirement: POS scan section SHALL use professional action-rail layout
The POS scan section MUST render scan controls in a structured action rail with clear priority ordering so the layout remains tidy and operationally scannable.

#### Scenario: Action priority is visually clear
- **WHEN** the scan section is rendered
- **THEN** the direct scan helper action MUST be visually primary compared with secondary search-list action controls

#### Scenario: Stable placement for future camera action
- **WHEN** the scan section is rendered before camera scanning is implemented
- **THEN** the layout MUST reserve a stable action position for future camera scan control without requiring structural rearrangement of existing controls

### Requirement: Scan action rail SHALL remain usable in supported tablet landscape layouts
In supported POS tablet landscape layouts, scan input and actions MUST remain reachable and readable without overlap or clipping.

#### Scenario: Landscape layout keeps actions accessible
- **WHEN** POS sell shell is viewed in supported tablet landscape viewport
- **THEN** the scan input and action controls MUST remain fully visible with tappable controls

#### Scenario: Status feedback remains visible after scan action
- **WHEN** cashier triggers scan resolution from the action rail
- **THEN** scan status feedback MUST remain visible in the designated status area below the scan controls
