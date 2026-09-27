# Spec Delta

## MODIFIED Requirements

### Requirement: Payment amounts separate editing and display representations
Editable payment amount fields SHALL display Indonesian-grouped text continuously while being edited and SHALL preserve a separate canonical decimal representation. Operators SHALL enter `.` as the decimal key, while the visible field SHALL render that separator as `,` and SHALL render `.` as the thousands separator.

#### Scenario: Whole amount moves between focus and blur
- **WHEN** an operator enters `1250000`
- **THEN** the field displays `1.250.000` without waiting for blur
- **AND** the canonical payment amount is `1250000`
- **AND** focus and blur leave that display and canonical value unchanged

#### Scenario: Fractional amount moves between focus and blur
- **WHEN** an operator enters `1250000.5`
- **THEN** the field displays `1.250.000,5` without waiting for blur
- **AND** the canonical payment amount is `1250000.5`
- **AND** focus and blur leave that display and canonical value unchanged

#### Scenario: Display does not change on focus or blur
- **WHEN** a payment field displaying `1.250.000,50` receives or loses focus
- **THEN** it continues displaying `1.250.000,50`
- **AND** its canonical value remains `1250000.50`

### Requirement: Payment amount precision is preserved canonically
Payment creation SHALL preserve every fractional digit and trailing zero entered in an accepted canonical decimal amount. Real-time display grouping SHALL NOT round or otherwise change the fractional text, canonical value, calculations, previews, or submitted value.

#### Scenario: Two-decimal value retains trailing decimal zero
- **WHEN** an operator enters `1500.10`
- **THEN** the field displays `1.500,10`
- **AND** submission sends canonical value `1500.10`

#### Scenario: Whole value omits decimal suffix
- **WHEN** an operator enters `1500`
- **THEN** the field displays `1.500`
- **AND** submission sends canonical value `1500`

#### Scenario: Amount contains more than two fractional digits
- **WHEN** an operator enters `1000.999`
- **THEN** the field displays `1.000,999`
- **AND** submission preserves canonical value `1000.999`

#### Scenario: Decimal entry is temporarily incomplete
- **WHEN** an operator enters `1500.`
- **THEN** the field displays `1.500,`
- **AND** the operator can continue entering fractional digits
- **AND** submission without further fractional digits normalizes the value to `1500`

### Requirement: Invalid payment amount edits remain actionable
Payment amount fields SHALL accept only digits and at most one operator-entered `.` decimal separator. Any insertion that would violate this grammar SHALL be ignored as one operation without altering the last accepted display, canonical value, or caret position and without presenting an error response.

#### Scenario: Second decimal point is attempted
- **WHEN** a field contains canonical value `120000.23`
- **AND** the operator attempts to enter another `.`
- **THEN** the field remains displayed as `120.000,23`
- **AND** its canonical value remains `120000.23`

#### Scenario: Invalid text is entered
- **WHEN** an operator attempts to insert a letter, whitespace, comma, sign, exponent marker, or other symbol
- **THEN** the field preserves its previous accepted value
- **AND** it shows no alert, validation message, invalid style, or reset

#### Scenario: Validation rerender retains the attempted amount
- **WHEN** server validation rerenders a payment form with canonical old input `120000.23`
- **THEN** the field displays `120.000,23`
- **AND** its canonical value remains `120000.23`

#### Scenario: Invalid paste is attempted
- **WHEN** pasted content would introduce a disallowed character or a second decimal point
- **THEN** the complete paste operation is ignored
- **AND** the previous accepted value remains available for editing and submission

#### Scenario: Deletion and selection remain available
- **WHEN** an operator uses Backspace, Delete, navigation, or selection replacement
- **THEN** the operation is allowed when its resulting canonical candidate follows the accepted grammar
- **AND** the resulting display is regrouped immediately
