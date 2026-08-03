## MODIFIED Requirements

### Requirement: Display Pelunasan 30 Hari Terakhir card
The system SHALL display a summary card showing the count and total paid amount of purchase invoices settled (fully paid) in the last 30 days, for invoices in status `APPROVED`, `RECEIVED PARTIALLY`, or `RECEIVED`. All displayed totals SHALL be computed directly from stored decimal rupiah amounts with no legacy 100× rescaling.

Date source priority:
1. If `purchase_payments` table contains ACTIVE rows with `date >= 30 days ago`, use `purchase_payments.date` — count distinct purchases, sum `purchase_payments.amount`
2. Otherwise, fall back to `purchases.date >= 30 days ago` with `payment_status = PAID`, summing `paid_amount`

#### Scenario: Card uses purchase_payments when available
- **WHEN** `purchase_payments` has ACTIVE rows with dates in the last 30 days
- **THEN** the card counts and sums from `purchase_payments` using `date`
- **AND** the displayed total equals the stored rupiah sum of those payment amounts

#### Scenario: Card falls back to purchases.date
- **WHEN** `purchase_payments` has no ACTIVE rows in the last 30 days
- **THEN** the card counts PAID purchases with `date >= 30 days ago` and sums `paid_amount`

#### Scenario: Card shows correct 30-day window
- **WHEN** the purchase index page loads
- **THEN** only invoices/payments from the last 30 calendar days (inclusive) are counted
