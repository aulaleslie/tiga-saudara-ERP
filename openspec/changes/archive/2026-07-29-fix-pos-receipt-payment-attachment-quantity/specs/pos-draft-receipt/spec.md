## ADDED Requirements

### Requirement: Draft receipt monetary rows use customer-facing Rupiah values
The draft receipt SHALL render each product-row total in the same Rupiah unit as the transaction snapshot totals and SHALL normalize a minor-unit line total exactly once before display.

#### Scenario: Draft receipt line total matches its draft grand total
- **WHEN** a draft transaction has one Rp45.000 line whose snapshot line total is `4500000` minor units
- **THEN** the draft receipt displays Rp45.000 in the product-row total column
- **AND** the displayed row total equals the draft receipt grand total
