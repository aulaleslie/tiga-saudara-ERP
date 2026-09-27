## MODIFIED Requirements

### Requirement: Sales tax report row inclusion
The report SHALL include taxable detail rows within the inclusive selected date range and active `setting_id` only when the related sale or purchase meets fulfilled-transaction report eligibility. It SHALL use current persisted detail tax values after settlement modification. Archived completed full returns and unfulfilled or partially fulfilled documents SHALL be excluded.

#### Scenario: Fully dispatched sale detail is included as Penjualan
- **WHEN** an eligible sale has a taxable persisted detail inside the selected range and active setting
- **THEN** the report includes the detail's current tax contribution under `Penjualan`

#### Scenario: Fully received purchase detail is included as Pembelian
- **WHEN** an eligible purchase has a taxable persisted detail inside the selected range and active setting
- **THEN** the report includes the detail's current tax contribution under `Pembelian`

#### Scenario: Partial fulfillment is excluded
- **WHEN** a sale is partially dispatched or a purchase is partially received
- **THEN** its detail rows are excluded from tax totals

#### Scenario: Other setting data is excluded
- **WHEN** an otherwise eligible taxable document belongs to a different `setting_id`
- **THEN** it is excluded from the report and exports
