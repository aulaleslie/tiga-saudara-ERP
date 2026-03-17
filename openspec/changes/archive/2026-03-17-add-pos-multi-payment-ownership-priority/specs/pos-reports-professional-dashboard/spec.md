## ADDED Requirements

### Requirement: Payment method summaries MUST aggregate tender entries from mixed-method checkouts
The payment method detail tab SHALL aggregate totals from checkout payment entries so one checkout can contribute to multiple payment methods when mixed tender is used.

#### Scenario: One checkout contributes to two methods
- **WHEN** a checkout is finalized with transfer 70000 and cash 30000
- **THEN** the `Metode Pembayaran` summary includes 70000 under transfer and 30000 under cash
- **AND** the checkout is not forced into a single payment-method bucket.

#### Scenario: Payment-method aggregation equals posted payment totals
- **WHEN** report date range includes mixed-method checkouts
- **THEN** sum of all payment-method totals equals sum of posted payment amounts for the same range
- **AND** this remains true regardless of checkout-level split ownership groups.

### Requirement: Cash versus non-cash KPI breakdown MUST use payment-entry source of truth
Any KPI or detail metric that distinguishes cash and non-cash SHALL derive from payment-entry method classification, not a single method attached to checkout header.

#### Scenario: Mixed checkout affects both cash and non-cash KPIs
- **WHEN** a checkout includes both cash and non-cash payment entries
- **THEN** cash KPI increases only by cash payment component
- **AND** non-cash KPI increases only by non-cash payment component.
