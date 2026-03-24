# pos-session-cash-reconciliation Specification

## Purpose
TBD - created by archiving change fix-pos-session-summary-display. Update Purpose after archive.
## Requirements
### Requirement: Display opening float on session summary
The session summary page SHALL display the opening float amount (Saldo Awal) extracted from the OPEN_FLOAT cash events for the session.

#### Scenario: Opening float displays correctly
- **WHEN** user views a closed POS session summary
- **THEN** the Perhitungan Kas card displays opening float value calculated from all OPEN_FLOAT events with direction IN

#### Scenario: No opening float
- **WHEN** user views a session with no opening float events
- **THEN** opening float displays as Rp 0.00

### Requirement: Display cash sales total on session summary
The session summary page SHALL display the total cash sales amount (Penjualan Kas) calculated from CASH_SALE_IN events for the session.

#### Scenario: Cash sales amount displays
- **WHEN** user views a closed session summary
- **THEN** the Perhitungan Kas card shows total cash sales from all CASH_SALE_IN events with direction IN

#### Scenario: No cash sales events
- **WHEN** a session has no cash sales events
- **THEN** cash sales displays as Rp 0.00

### Requirement: Display safe drops total on session summary
The session summary page SHALL display the total amount picked up (Pengambilan Kas) extracted from SAFE_DROP_OUT events for the session.

#### Scenario: Safe drops display
- **WHEN** user views a closed session summary
- **THEN** the Perhitungan Kas card shows total safe drops from all SAFE_DROP_OUT events with direction OUT

#### Scenario: No safe drops
- **WHEN** session has no safe drop events
- **THEN** safe drops displays as Rp 0.00

### Requirement: Input non-cash transaction total
The session summary page SHALL provide an editable input field for the user to enter the total amount of non-cash transactions not captured in the cash event timeline.

#### Scenario: Non-cash input available
- **WHEN** user views a session summary
- **THEN** the Perhitungan Kas card contains an input field labeled "Penjualan Non-Kas" with placeholder "0.00"

#### Scenario: Non-cash amount is numeric
- **WHEN** user enters a non-cash amount
- **THEN** the input accepts numeric values >= 0 with 2 decimal places

### Requirement: Display cash reconciliation formula
The session summary page SHALL calculate and display the cash reconciliation result using the formula: `opening_float + cash_sales + non_cash - safe_drops`.

#### Scenario: Reconciliation calculates correctly
- **WHEN** user enters values for opening float, cash sales, non-cash, and safe drops are displayed
- **THEN** the reconciliation card shows the calculated expected cash total
- **AND** the formula matches: opening_float + cash_sales + non_cash - safe_drops = expected_cash

#### Scenario: Reconciliation with no safe drops
- **WHEN** a session has no safe drops (pickup = 0)
- **THEN** reconciliation calculates as: opening_float + cash_sales + non_cash = expected_cash

#### Scenario: Reconciliation with zero values
- **WHEN** all cash event components are zero
- **THEN** reconciliation shows Rp 0.00

### Requirement: Display expected cash vs actual comparison
The session summary page SHALL display both the expected cash total (calculated from reconciliation) and the actual total sales amount for comparison.

#### Scenario: Expected cash matches sales total
- **WHEN** user views a balanced session (expected_cash equals sales_total)
- **THEN** both values display in the reconciliation card with matching amounts

#### Scenario: Expected cash differs from sales total
- **WHEN** expected cash does not match actual sales total
- **THEN** both values display so user can identify variance

### Requirement: Reconciliation card positioning
The Perhitungan Kas (cash reconciliation) card SHALL be positioned on the session summary page between the session overview and the timeline/transactions sections.

#### Scenario: Card layout in summary
- **WHEN** user views the session summary page
- **THEN** the Perhitungan Kas card is visible in a prominent location with proper spacing
- **AND** the card uses Bootstrap styling consistent with other cards on the page

### Requirement: Currency formatting
All currency values in the reconciliation card SHALL be formatted as Indonesian Rupiah (Rp) with thousands separator and 2 decimal places.

#### Scenario: Currency display
- **WHEN** reconciliation card renders currency values
- **THEN** amounts display as "Rp X.XXX.XXX,XX" format (e.g., "Rp 7.000.000,00")

