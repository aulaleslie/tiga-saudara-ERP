## ADDED Requirements

### Requirement: Quick-add amount buttons for common payment increments
The payment amount input SHALL be accompanied by a row of quick-add buttons that allow cashiers to quickly increment the payment amount by common values (1K, 5K, 10K, 50K) without manual typing.

#### Scenario: User clicks +1.000 button
- **WHEN** current amount is 0 and remainder is 150.000
- **AND** user clicks the [+1.000] button
- **THEN** the amount input updates to "1.000"
- **AND** validation runs immediately
- **AND** [Lanjut Pembayaran] button state updates

#### Scenario: User stacks multiple quick-add clicks
- **WHEN** current amount is 0
- **AND** user clicks [+10.000], then [+10.000] again, then [+5.000]
- **THEN** the amount input displays "25.000"
- **AND** raw numeric value is 25000
- **AND** validation confirms no overpayment

#### Scenario: Quick-add respects payment method rules
- **WHEN** payment method is non-cash (e.g., Card) and remainder is 100.000
- **AND** user clicks [+50.000]
- **THEN** amount becomes "50.000"
- **AND** user clicks [+50.000] again
- **AND** validation runs
- **THEN** [Lanjut Pembayaran] button enables (amount = remainder)

#### Scenario: Quick-add respects cash payment method rules
- **WHEN** payment method is cash and remainder is 100.000
- **AND** user clicks [+50.000], then [+50.000] (total 100.000)
- **THEN** amount displays "100.000"
- **AND** [Lanjut Pembayaran] button enables (cash allows exact amount)

### Requirement: Remainder fill button
The payment buttons row SHALL include a [Sisa] button that automatically fills the amount input with the exact remainder amount, completing the final payment in a single click.

#### Scenario: Fill remainder completes payment
- **WHEN** remainder is 150.000
- **AND** user clicks [Sisa] button
- **THEN** amount input displays "150.000"
- **AND** [Lanjut Pembayaran] button becomes enabled
- **AND** user can immediately submit without manual entry

#### Scenario: Sisa button uses current remainder
- **WHEN** payment chain already has some payments committed
- **AND** remainder shows as 75.000 (after previous payments)
- **AND** user clicks [Sisa]
- **THEN** amount is filled with exactly 75.000
- **AND** not with the original grand total

#### Scenario: Sisa with cash payment method
- **WHEN** payment method is cash
- **AND** remainder is 50.000
- **AND** user clicks [Sisa]
- **THEN** amount becomes 50.000
- **AND** validation confirms amount >= remainder (cash rule)
- **AND** submit button enables

### Requirement: Quick-add button styling and layout
The quick-add buttons SHALL be displayed in a horizontal row below the amount input field with consistent styling and spacing.

#### Scenario: Button row layout
- **WHEN** the payment modal is opened
- **THEN** below [Jumlah Pembayaran] input field, there is a row of buttons:
  - [+1.000], [+5.000], [+10.000], [+50.000], [Sisa]
- **AND** buttons are properly spaced (flex layout with gap)
- **AND** [Sisa] button is visually distinct (different color/style) as it's the fill-remainder action

#### Scenario: Buttons are accessible and clickable
- **WHEN** user hovers over any quick-add button
- **THEN** button shows visual feedback (highlight/color change)
- **AND** clicking any button is responsive and immediate (< 100ms update)

### Requirement: Quick-add validation integration
Quick-add buttons SHALL trigger the same amount validation as manual entry, ensuring that resulting amounts respect the payment method's rules (cash >= remainder, non-cash <= remainder).

#### Scenario: Non-cash payment cannot exceed remainder via quick-add
- **WHEN** payment method is Card and remainder is 100.000
- **AND** amount is 95.000
- **AND** user clicks [+10.000] (would result in 105.000)
- **THEN** validation runs
- **AND** [Lanjut Pembayaran] remains disabled
- **AND** error message displays (overpayment for non-cash)

#### Scenario: Cash payment allows overpayment via quick-add
- **WHEN** payment method is Cash and remainder is 50.000
- **AND** current amount is 40.000
- **AND** user clicks [+50.000] (would result in 90.000)
- **THEN** amount becomes 90.000
- **AND** validation confirms amount >= remainder (passes cash rule)
- **AND** [Lanjut Pembayaran] enables
- **AND** system will handle overpayment/change in next stage
