# Spec Delta

## MODIFIED Requirements

### Requirement: Reusable nominal field component for currency/numeric inputs
The system SHALL provide a reusable Blade component `<x-nominal-field>` that formats editable nominal values continuously using Indonesian separators while preserving a separate canonical decimal value for calculations and submission. The editable text SHALL contain no currency symbol, SHALL accept only digits and at most one operator-entered `.` decimal separator, and SHALL silently leave the last accepted value unchanged when an insertion is not allowed.

#### Scenario: Component displays formatted value on page load
- **WHEN** a page loads with canonical nominal value `120000.23`
- **THEN** the visible field displays `120.000,23`
- **AND** its canonical value remains `120000.23`

#### Scenario: Whole amount formats during typing
- **WHEN** an operator enters the digit sequence `120000`
- **THEN** the visible field is regrouped after each accepted edit
- **AND** the resulting display is `120.000`
- **AND** the canonical value is `120000`

#### Scenario: Field shows raw number on focus
- **WHEN** an operator focuses a field displaying `120.000,23`
- **THEN** the visible field remains `120.000,23` instead of revealing raw text
- **AND** the canonical value remains `120000.23`

#### Scenario: Field returns to formatted display on blur
- **WHEN** an operator blurs a field displaying `120.000,23`
- **THEN** the visible field remains `120.000,23`
- **AND** no focus/blur reformatting changes its canonical value

#### Scenario: Incomplete decimal remains editable
- **WHEN** an operator has entered `120000.` and has not yet entered a fractional digit
- **THEN** the visible field displays `120.000,`
- **AND** the operator can continue entering fractional digits

#### Scenario: Second decimal point is silently ignored
- **WHEN** the current canonical value is `120000.23`
- **AND** the operator attempts to insert another `.`
- **THEN** the display remains `120.000,23`
- **AND** the canonical value and caret position remain unchanged
- **AND** no alert, validation message, invalid style, or reset is shown

#### Scenario: Non-numeric insertion is silently ignored
- **WHEN** an operator attempts to insert a letter, whitespace, comma, sign, exponent marker, or other symbol
- **THEN** the visible and canonical values remain unchanged
- **AND** no visible error response is produced

#### Scenario: Editing operations remain natural
- **WHEN** an operator uses selection replacement, Backspace, Delete, or caret navigation in a formatted nominal field
- **THEN** the accepted result is formatted immediately
- **AND** the caret remains adjacent to the logical digit or decimal position being edited

#### Scenario: Paste obeys the same character gate
- **WHEN** an operator pastes text consisting of digits with at most one `.` decimal separator
- **THEN** the complete proposed edit is accepted and formatted immediately
- **AND WHEN** the pasted text contains any other character or another decimal point
- **THEN** the complete proposed edit is silently ignored

#### Scenario: Raw numeric value submitted on form submission
- **WHEN** a field displays `120.000,23` and its form is submitted
- **THEN** the server receives canonical value `120000.23`
- **AND WHEN** a field ends in an incomplete displayed decimal separator such as `120.000,`
- **THEN** submission normalizes it to canonical whole value `120000`

#### Scenario: Component respects disabled state
- **WHEN** a nominal field is disabled
- **THEN** the field displays its localized value but cannot be edited
- **AND** its hidden canonical value remains available to the form where required by the existing integration

#### Scenario: Component integrates currency settings
- **WHEN** application currency settings contain a symbol or different separators
- **THEN** the editable nominal text still uses the required symbol-free Indonesian `.` grouping and `,` decimal display
- **AND** existing server-side currency and storage settings remain unchanged

#### Scenario: Component provides null-safe fallback
- **WHEN** currency settings are unavailable or null
- **THEN** the component initializes without an error
- **AND** uses the required symbol-free Indonesian separator profile

#### Scenario: Component validates numeric input
- **WHEN** an operator attempts an edit outside the digits-plus-one-dot grammar
- **THEN** the operation is silently ignored
- **AND** the last accepted display and canonical values are retained

#### Scenario: Component survives dynamic rendering
- **WHEN** a nominal field is inserted or rerendered by Livewire or another existing dynamic view
- **THEN** it receives the same real-time formatting behavior exactly once
- **AND** its canonical value is not lost during initialization

### Requirement: Nominal field behavior consistency across product create and edit
The product pricing fields (Harga Beli, Harga Jual, Harga Jual Partai Besar, Harga Jual Reseller) and conversion table prices SHALL exhibit identical real-time formatting and canonical-value behavior on both create and edit pages.

#### Scenario: Create page shows raw on focus
- **WHEN** an operator enters `65000.25` in a product create price field
- **THEN** the field displays `65.000,25` while editing
- **AND** its canonical value is `65000.25`

#### Scenario: Edit page shows raw on focus
- **WHEN** an operator replaces an existing product edit price with `65000.25`
- **THEN** the field displays `65.000,25` while editing
- **AND** its behavior is identical to the create page

#### Scenario: Conversion table pricing field behaves identically
- **WHEN** an operator edits a conversion-table price
- **THEN** that row is formatted continuously using the same rules
- **AND** its canonical value and editing state do not affect another conversion row
