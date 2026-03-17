## ADDED Requirements

### Requirement: EDC Reference Input for Non-Cash Payments
When a user selects a non-cash payment method (e.g., BRI, BNI, MANDIRI—any method where `is_cash = false`), the payment modal SHALL display an additional input field labeled "Referensi EDC (digit terakhir)" asking the user to manually enter the last digits from the EDC receipt. CASH payments (where `is_cash = true`) SHALL skip this field.

#### Scenario: Non-cash method selected, EDC field appears
- **WHEN** user selects "BRI" from the method dropdown
- **THEN** input field "Referensi EDC (digit terakhir)" appears below the amount input, enabling user to type reference digits

#### Scenario: CASH method selected, EDC field does not appear
- **WHEN** user selects "CASH" from the method dropdown
- **THEN** no EDC reference field appears; only amount input is shown

#### Scenario: EDC field disappears on method change
- **WHEN** user selects non-cash method (EDC field appears), then changes method to CASH
- **THEN** EDC reference field immediately disappears

### Requirement: EDC Reference Format Validation
The EDC reference input SHALL validate format only (no external gateway integration). Format rules: non-empty, alphanumeric, max 20 characters. Client-side validation SHALL provide immediate feedback; server-side SHALL validate before commit.

#### Scenario: Valid EDC reference accepted
- **WHEN** user enters "123456" in EDC reference field for BRI payment
- **THEN** input shows no error, [Proceed] button is enabled

#### Scenario: Empty EDC reference rejected
- **WHEN** user selects BRI, enters amount, but leaves EDC reference field empty and clicks [Proceed]
- **THEN** modal shows error: "Referensi EDC tidak boleh kosong" and prevents submission

#### Scenario: EDC reference too long rejected
- **WHEN** user enters more than 20 characters in EDC reference field
- **THEN** input shows error: "Referensi EDC maksimal 20 karakter"

#### Scenario: Invalid characters in EDC reference rejected
- **WHEN** user enters special characters (e.g., "#@$") in EDC reference field
- **THEN** input shows error: "Referensi EDC hanya boleh berisi huruf dan angka"

### Requirement: EDC Reference Transmitted with Payment Commit
When submitting a stage payment for a non-cash method, the EDC reference SHALL be included in the request payload sent to `POST /pos/sell/checkout/stage-payment`. The backend SHALL persist this reference with the payment record for audit and reconciliation purposes.

#### Scenario: EDC reference sent with non-cash payment request
- **WHEN** user submits BRI payment of 1,000,000 IDR with EDC reference "987654"
- **THEN** request payload includes: { method: "BRI", amount: 1000000, reference: "987654", ... }

#### Scenario: EDC reference is stored in payment record
- **WHEN** payment is committed successfully
- **THEN** payment record in database contains reference field: "987654"

### Requirement: No External EDC Gateway Validation
The system SHALL NOT attempt to validate the EDC reference against an external payment gateway or EDC service. Validation is format-only. Actual EDC receipt matching and reconciliation happen offline during accounting/terminal reconciliation process.

#### Scenario: EDC reference is not called to external service
- **WHEN** user submits payment with EDC reference
- **THEN** no HTTP call is made to external EDC service; backend validates format only

#### Scenario: User can enter any valid-format reference
- **WHEN** user enters EDC reference "999999" (which may not actually exist on EDC machine)
- **THEN** system accepts it as valid format, commits payment, and backend does not call external service to verify

### Requirement: EDC Reference Display in Payment Chain
Once a non-cash payment is committed and appears in the payment chain history, the displayed information SHALL include the payment method, amount, AND the EDC reference (truncated for readability if needed, e.g., "BRI 1,000,000 (ref: ...654)").

#### Scenario: EDC reference shown in payment chain
- **WHEN** user has committed BRI payment with EDC reference "987654"
- **THEN** payment chain displays: "✓ BRI 1,000,000 (ref: ...654)"

#### Scenario: CASH payment shows no reference in chain
- **WHEN** user has committed CASH payment of 1,000,000
- **THEN** payment chain displays: "✓ CASH 1,000,000" with no reference notation
