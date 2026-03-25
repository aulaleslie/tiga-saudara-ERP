## MODIFIED Requirements

### Requirement: POS Reports Dashboard daily sales MUST accurately report cash totals
The daily sales report section SHALL display summary KPIs and detailed table with cash_total reflecting actual cash received (payment amount minus change given to customer).

#### Scenario: Daily sales cash total reflects change deduction
- **WHEN** user views the "Penjualan Harian" (Daily Sales) tab
- **THEN** the cash_total field for each day shows the aggregate of all cash payments minus change amounts for transactions on that date

#### Scenario: Daily sales with mixed payment scenarios
- **WHEN** a date includes transactions with: (1) exact payment, (2) cash overpayment, (3) multi-payment with cash
- **THEN** cash_total correctly aggregates: (1) full amount, (2) amount after deducting change, (3) cash portion minus its allocated change

#### Scenario: Daily sales total validation
- **WHEN** user compares daily sales cash_total to session events for that day
- **THEN** values match when summing CASH_SALE_IN events minus CHANGE_OUT events from the session

#### Scenario: Date filter updates cash totals correctly
- **WHEN** user changes date filters and refreshes
- **THEN** cash_total updates to reflect change-adjusted amounts for the new date range
