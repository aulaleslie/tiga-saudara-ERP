## ADDED Requirements

### Requirement: Preserve POS Sell Rendered Contract During View Decomposition
The system SHALL allow `Modules/Pos/Resources/views/sell.blade.php` to be decomposed into Blade partials only when the rendered POS sell screen preserves its browser-facing contract.

#### Scenario: CSS partial preserves page style output
- **WHEN** the POS sell page renders after the inline page CSS is moved into a Blade partial included from the original `@push('page_css')` location
- **THEN** the rendered page SHALL still contain the same POS sell CSS rules in the same page CSS stack order

#### Scenario: Static partials preserve DOM selectors
- **WHEN** POS sell shell or modal markup is moved into Blade partials
- **THEN** the rendered page SHALL preserve the existing DOM IDs, classes, modal IDs, data attributes, and form/input/button IDs used by the POS sell JavaScript

#### Scenario: Permission-gated markup remains equivalent
- **WHEN** POS sell navigation, transaction, save, checkout, session, or cash pickup controls are moved into partials
- **THEN** the rendered controls SHALL remain governed by the same Blade permission checks and runtime variables as before extraction

### Requirement: Decompose POS Sell View Incrementally
The system SHALL split the POS sell view in small verified slices rather than moving unrelated CSS, markup, and JavaScript in a single change.

#### Scenario: First extraction is CSS only
- **WHEN** implementation begins
- **THEN** the first extraction SHALL move only the existing inline CSS block into a Blade partial and SHALL NOT move modal markup, shell markup, or JavaScript in the same step

#### Scenario: One markup group is extracted per step
- **WHEN** implementation continues after CSS extraction
- **THEN** each subsequent extraction SHALL move one coherent static markup group, such as one modal or one shell/card section, before verification is run again

#### Scenario: JavaScript extraction is excluded
- **WHEN** this change is implemented
- **THEN** the large inline POS sell JavaScript closure SHALL remain in the Blade-rendered page with the same execution order and server-rendered route/config values

### Requirement: Verify Refactor Safety Against Baseline
The system SHALL verify that each extraction slice does not introduce new POS sell rendering regressions.

#### Scenario: Baseline is recorded before extraction
- **WHEN** implementation starts
- **THEN** the current POS sell render/test baseline SHALL be recorded, including any known pre-existing failures unrelated to the decomposition

#### Scenario: Render equivalence is checked after each slice
- **WHEN** a CSS, modal, or shell partial extraction is completed
- **THEN** the POS sell page SHALL be rendered and compared against the prior slice to confirm no meaningful DOM, style, script include, route output, or permission-gated markup changes were introduced

#### Scenario: Existing targeted tests do not gain new failures
- **WHEN** a partial extraction slice is completed
- **THEN** the targeted POS sell feature tests SHALL be run or otherwise evaluated so that no new failure is introduced beyond documented baseline failures
