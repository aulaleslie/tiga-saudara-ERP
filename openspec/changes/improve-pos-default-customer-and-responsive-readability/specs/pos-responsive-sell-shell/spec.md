## ADDED Requirements

### Requirement: POS sell typography remains proportionally readable
The POS sell workspace SHALL use a consistent responsive typography hierarchy in which totals remain most prominent, headings and action labels remain stronger than ordinary content, and metadata remains subordinate while still readable on supported desktop and tablet-landscape viewports.

#### Scenario: Standard desktop viewport
- **WHEN** the POS sell workspace is displayed at 1366 by 768 CSS pixels
- **THEN** section headings, customer content, cart content, controls, statuses, and supporting metadata MUST be visibly larger than the previous compact presentation
- **AND** totals, headings, ordinary content, and metadata MUST retain their relative visual hierarchy

#### Scenario: Short supported viewport
- **WHEN** the POS sell workspace is displayed at 1280 by 720 CSS pixels or 1024 by 768 CSS pixels
- **THEN** responsive rules MUST preserve readable text without overlapping adjacent controls or truncating essential action labels

#### Scenario: POS overlays use the same readable hierarchy
- **WHEN** a cashier opens a POS sell modal, search result list, customer result list, or other sell-workspace overlay
- **THEN** its user-facing text and controls MUST follow the same proportional readability hierarchy

### Requirement: Checkout actions remain fully visible
The POS payment card SHALL reserve a non-shrinking action area for the save-and-new and checkout controls so both controls remain fully visible and operable throughout supported desktop and tablet-landscape viewport sizes.

#### Scenario: Checkout action at standard height
- **WHEN** the POS sell workspace is displayed at 1366 by 768 CSS pixels
- **THEN** the complete checkout button, including its bottom edge and label, MUST be visible without scrolling the whole page

#### Scenario: Checkout action at short height
- **WHEN** the POS sell workspace is displayed at 1280 by 720 CSS pixels or another supported landscape viewport with limited height
- **THEN** the payment action controls MUST NOT shrink, clip, or extend below the viewport
- **AND** non-action payment content MUST compact or use bounded internal overflow before the action area is compromised

#### Scenario: Action labels need additional width
- **WHEN** the save-and-new and checkout labels do not fit side by side at their readable font size
- **THEN** the action layout MUST adapt without clipping either label or reducing either control below its usable height

### Requirement: Responsive changes preserve POS interaction behavior
Typography and layout changes SHALL preserve existing POS visibility rules, authorization states, focus behavior, cart interactions, payment behavior, and portrait-orientation lock behavior.

#### Scenario: Disabled and permission-locked actions
- **WHEN** existing cart validation or permissions disable a POS action
- **THEN** the responsive layout MUST retain the action's existing disabled or permission-locked behavior

#### Scenario: Unsupported portrait viewport
- **WHEN** the existing POS portrait lock-screen breakpoint is active
- **THEN** the lock screen MUST remain available instead of exposing a compressed sell workspace

