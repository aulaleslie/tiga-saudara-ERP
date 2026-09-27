## MODIFIED Requirements

### Requirement: Payment method search input visibility
The payment method search input field SHALL render with an opaque white background and proper border styling, ensuring it is clearly visible and distinct from the modal background.

#### Scenario: Method input has opaque background
- **WHEN** the staged payment modal opens
- **AND** the "Metode Pembayaran" input field is visible
- **THEN** the input has a solid white background (not transparent)
- **AND** the input has a visible border (1px solid gray or similar)
- **AND** text entered into the field is clearly readable

#### Scenario: Method input contrast meets accessibility standards
- **WHEN** text is entered into the payment method search field
- **THEN** text color contrasts adequately with the white background
- **AND** placeholder text is visible without having to focus the field
- **AND** keyboard focus indicator (outline/border) is visible when field is focused

### Requirement: Amount input displays formatted numeric values
The payment amount input field SHALL format and display numeric values with thousand separators in Indonesian locale (1000 displays as 1.000) while preserving the raw numeric value for backend submission.

#### Scenario: Amount displays with thousand separators as user types
- **WHEN** user enters "150000" into the amount field
- **THEN** the field displays "150.000" with separators
- **AND** the raw numeric value 150000 is stored internally
- **AND** backend receives 150000 (not "150.000")

### Requirement: Payment amount input has quick-add convenience buttons
The amount input field SHALL be accompanied by quick-add buttons that allow rapid entry of common payment increments and automatic remainder fill.

#### Scenario: Quick-add buttons are available and functional
- **WHEN** the staged payment modal opens
- **THEN** below the "Jumlah Pembayaran" input, buttons are visible:
  - [+1.000], [+5.000], [+10.000], [+50.000], [Sisa]
- **AND** clicking any button updates the amount immediately
- **AND** [Sisa] button fills to exact remainder amount

### Requirement: Payment chain rendering shows clear visual hierarchy
The payment chain display (committed payments) SHALL render each payment with clear separation of method, amount, and reference information, making multiple payments easy to scan.

#### Scenario: Payment chain displays readable payment information
- **WHEN** one or more payments are committed
- **THEN** each payment appears in a distinct badge or card
- **AND** payment method name is prominent/bold
- **AND** amount displays with thousand separators (Rp100.000)
- **AND** reference number (if present) is shown but secondary
- **AND** multiple payments are horizontally arranged with proper spacing
