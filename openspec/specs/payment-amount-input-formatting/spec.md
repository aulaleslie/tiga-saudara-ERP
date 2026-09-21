# payment-amount-input-formatting Specification

## Purpose

Provide a consistent and lossless editing contract for monetary amount fields on Sales, Purchase, and POS payment-creation pages.

## Requirements

### Requirement: Payment amounts separate editing and display representations
Editable payment amount fields SHALL show the canonical numeric value without a currency symbol or thousand separators while focused and SHALL show a localized value with Indonesian thousand separators while blurred.

#### Scenario: Whole amount moves between focus and blur
- **WHEN** a payment amount with canonical value `1250000` loses focus
- **THEN** the field SHALL display `1.250.000`
- **AND WHEN** the field receives focus again
- **THEN** the field SHALL display `1250000`

#### Scenario: Fractional amount moves between focus and blur
- **WHEN** a payment amount with canonical value `1250000.5` loses focus
- **THEN** the field SHALL display `1.250.000,50`
- **AND WHEN** the field receives focus again
- **THEN** the field SHALL display `1250000.5`

### Requirement: Payment amount precision is preserved canonically
Payment creation SHALL preserve every fractional digit entered in a valid canonical decimal amount independently from its localized display. A fractional amount SHALL display rounded to exactly two decimal places on blur, while a whole amount SHALL not display a decimal suffix. Display rounding SHALL NOT change the canonical value used when the field is focused, calculated, previewed, or submitted.

#### Scenario: Two-decimal value retains trailing decimal zero
- **WHEN** an operator enters canonical amount `1500.10` and leaves the field
- **THEN** the field SHALL display `1.500,10`
- **AND** submission SHALL send a canonical numeric representation equivalent to `1500.10`

#### Scenario: Whole value omits decimal suffix
- **WHEN** an operator enters canonical amount `1500` and leaves the field
- **THEN** the field SHALL display `1.500`
- **AND** submission SHALL send canonical value `1500`

#### Scenario: Amount contains more than two fractional digits
- **WHEN** an operator enters canonical amount `1000.999` and leaves the field
- **THEN** the field SHALL display `1.001,00`
- **AND WHEN** the field receives focus again
- **THEN** the field SHALL display the full canonical value `1000.999`
- **AND** submission SHALL preserve a numeric representation equivalent to `1000.999`

### Requirement: Payment calculations use canonical amounts
All client-side payment totals, previews, balance comparisons, maximum checks, and submission payloads SHALL use canonical numeric values rather than parsing the currently displayed localized text as an ordinary decimal number.

#### Scenario: Formatted allocation participates in a total
- **WHEN** allocation fields display `1.250.000,50` and `500.000`
- **THEN** the displayed allocation total SHALL be based on canonical values `1250000.50` and `500000`
- **AND** the submitted values SHALL represent the same amounts.

#### Scenario: Display formatting cannot bypass the balance limit
- **WHEN** a displayed localized payment amount represents a canonical value greater than the current payable balance
- **THEN** the existing maximum-balance behavior SHALL evaluate the canonical value
- **AND** formatting SHALL NOT cause the value to be interpreted as a smaller amount.

### Requirement: Invalid payment amount edits remain actionable
The payment form SHALL NOT silently convert a non-empty invalid amount edit into zero or another accepted amount. It SHALL prevent an invalid canonical amount from being submitted until the operator corrects or clears it.

#### Scenario: Invalid text is entered
- **WHEN** an operator enters a non-empty value that cannot be normalized as a valid payment amount
- **THEN** the form SHALL indicate that the value is invalid
- **AND** the invalid value SHALL NOT be submitted as zero.

#### Scenario: Validation rerender retains the attempted amount
- **WHEN** server validation returns the operator to a payment creation form with a valid prior amount
- **THEN** the field SHALL restore the same canonical amount
- **AND** apply the blurred localized display without changing its monetary value.
