## ADDED Requirements

### Requirement: Thermal Receipt Layout Redesign
The POS receipt view SHALL match the specified professional thermal layout, including a centered header with business contact details, a dashed line separator, and right-aligned totals.

#### Scenario: Business header alignment
- **WHEN** the receipt is rendered
- **THEN** the company name, address, email, and phone are centered at the top

#### Scenario: Date formatting
- **WHEN** the receipt is rendered
- **THEN** the date is formatted as "day Month, year HH:mm" (e.g., "01 Dec, 2025 22:38")

### Requirement: Unit Conversion Detail Breakdown
Each item line on the receipt SHALL include the base quantity and unit name, as well as any applicable unit conversion breakdown indented below the line.

#### Scenario: Item with unit breakdown
- **WHEN** an item is sold as a conversion (e.g., BOX containing 10 RIMs)
- **THEN** the receipt shows the primary line item and an indented breakdown: "{qty} {unit}(S) @ {unit_price}"

### Requirement: Correct Multi-Payment Nominal Display
The receipt SHALL display the correct non-zero nominal amount for every payment method used in the checkout process.

#### Scenario: Multi-payment nominal verification
- **WHEN** a checkout has multiple payments (e.g., CASH and QRIS)
- **THEN** both methods are listed with their actual paid amounts, not 0
