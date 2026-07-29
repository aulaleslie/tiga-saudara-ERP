## ADDED Requirements

### Requirement: POS quantities render as raw normalized values
The POS UI SHALL render quantity data without locale grouping, thousand separators, or forced decimal precision. It SHALL preserve a meaningful fractional value when one exists.

#### Scenario: Integer quantity is displayed
- **WHEN** a POS receipt, transaction detail, return view, bundle component, or item-sales report displays quantity `1`
- **THEN** it displays `1`
- **AND** it MUST NOT display `1.000`, `1,000`, `1.00`, or `1,00`

#### Scenario: Fractional quantity is displayed
- **WHEN** an in-scope POS UI displays a normalized quantity `1.5`
- **THEN** it displays `1.5`
- **AND** it MUST NOT add grouping or trailing zeroes

#### Scenario: Monetary values remain formatted as currency
- **WHEN** a POS view displays a quantity beside a price, subtotal, or total
- **THEN** only the quantity uses raw rendering
- **AND** monetary values retain their existing currency formatting
