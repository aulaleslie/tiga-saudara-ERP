## ADDED Requirements

### Requirement: Aged receivables card is actionable
The reports landing page SHALL render the `Usia piutang` card under the Penjualan tab as an actionable report card for users with `saleReports.access`, linking to the aged receivables report route instead of displaying placeholder treatment.

#### Scenario: Authorized user sees actionable aged receivables card
- **WHEN** a user with `saleReports.access` views the Reports landing Penjualan tab
- **THEN** the `Usia piutang` card is shown
- **AND** the card links to the aged receivables report route
- **AND** the card displays the standard report call-to-action instead of `Belum tersedia`

#### Scenario: Unauthorized user does not see aged receivables card
- **WHEN** a user without `saleReports.access` views the Reports landing page
- **THEN** the `Usia piutang` card is not shown
