## ADDED Requirements

### Requirement: Payment chain displays with visual hierarchy
The payment chain display SHALL show each committed payment (method, amount, reference) with clear visual separation and hierarchy, making it easy to scan and verify multiple payments.

#### Scenario: Single payment display
- **WHEN** one payment has been committed (e.g., Cash Rp100,000)
- **THEN** the "Pembayaran Sudah Diproses" section displays:
  - Payment method name: "Cash" (bold/prominent)
  - Payment amount: "Rp100.000" (formatted, distinct line)
  - Reference (if applicable): shown on separate line or clearly secondary
- **AND** each piece of information is visually distinct (not cramped)

#### Scenario: Multiple payments display
- **WHEN** two payments are committed (Cash Rp100,000 + Card Rp75,000)
- **THEN** both payments are shown as separate badges or cards
- **AND** each badge clearly shows: method, amount, reference (if any)
- **AND** badges are horizontally arranged with proper spacing
- **AND** scrolling or wrapping is enabled if payment count grows

#### Scenario: Reference number is visible but secondary
- **WHEN** a non-cash payment has a reference number (EDC approval code)
- **AND** the payment is displayed
- **THEN** reference appears on a separate line or in smaller font
- **AND** it's clearly labeled (e.g., "Ref:", "Nomor:", "Reference:")
- **AND** reference does not overwhelm the payment method and amount

#### Scenario: No reference display for cash payments
- **WHEN** payment method is Cash
- **AND** no EDC reference is applicable
- **THEN** only method and amount are shown
- **AND** no empty reference field or placeholder text appears

### Requirement: Payment chain badge styling
Each payment in the chain SHALL use a distinct visual style (badge or card) to separate it from surrounding content and make the payment information stand out.

#### Scenario: Payment badge appearance
- **WHEN** a payment displays in the chain
- **THEN** it appears in a styled container (badge with color, rounded corners, padding)
- **AND** checkmark (✓) icon or success indicator is visible
- **AND** background color indicates success/completion state (e.g., green/success color)

#### Scenario: Multiple badges are scannable
- **WHEN** the payment chain has 3+ payments
- **THEN** each badge is visually distinct with consistent spacing
- **AND** user can quickly count and verify all payments at a glance
- **AND** no badges overlap or crowd together

### Requirement: Formatted amount display in payment chain
Payment amounts in the chain SHALL display with thousand separators (e.g., Rp100.000) matching the formatting used in the amount input field.

#### Scenario: Amount formatting consistency
- **WHEN** user enters "150000" in the amount input
- **AND** commits the payment
- **AND** the payment appears in the chain
- **THEN** amount displays as "Rp150.000" (not "Rp150000")

#### Scenario: Large payment amounts are readable
- **WHEN** a payment is 5000000 (five million)
- **AND** it displays in the chain
- **THEN** amount shows as "Rp5.000.000" (Indonesian format)
- **AND** text remains readable and not cramped

### Requirement: Payment chain reflects payment state accurately
The payment chain display SHALL always reflect the current committed payments from the `paymentChain.payments` array and update immediately after each successful payment submission.

#### Scenario: Payment appears after submission
- **WHEN** user selects a payment method, enters amount, and clicks [Lanjut Pembayaran]
- **AND** backend returns success response
- **THEN** the payment chain display immediately updates
- **AND** the new payment appears in "Pembayaran Sudah Diproses" section

#### Scenario: Payment chain cleared on fresh modal open
- **WHEN** a transaction completes and success modal is shown
- **AND** user starts a new transaction (new modal open)
- **THEN** the payment chain shows "Belum ada pembayaran" (empty state)
- **AND** badges from previous transaction do not appear

#### Scenario: Reload recovery restores payment chain
- **WHEN** user is mid-payment (payment modal open with 1 committed payment)
- **AND** page reloads accidentally
- **AND** modal reopens via reload recovery
- **THEN** the payment chain displays the committed payment(s) from session
- **AND** remainder updates accordingly
