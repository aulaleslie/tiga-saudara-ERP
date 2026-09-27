## MODIFIED Requirements

### Requirement: Receipt line total column reflects Rupiah line total

The POS receipt SHALL display each line item's total (subtotal) column in the same Rupiah unit used by the receipt grand total, payment values, and checkout totals. When a transaction snapshot stores a line total in minor units, the receipt data mapping MUST convert it to Rupiah exactly once before rendering. This applies uniformly to completed-checkout receipts, draft receipts, and loaded-transaction receipts.

#### Scenario: Single-unit line prints the checkout's Rupiah total

- **WHEN** a completed transaction line has quantity 1, unit price Rp45.000, and a snapshot line total of `4500000` minor units
- **THEN** the receipt line's total column displays Rp45.000
- **AND** it MUST NOT display Rp4.500.000

#### Scenario: Line total matches per-unit breakdown and grand total

- **WHEN** a receipt renders a line whose per-unit breakdown reads `1 PCS(S) @ Rp45.000` and whose only line is that item
- **THEN** the line total column equals the receipt grand total (Rp45.000)

#### Scenario: Draft and loaded-transaction receipts use the same Rupiah line total

- **WHEN** a draft or loaded transaction line snapshot stores its line total in minor units
- **THEN** its receipt preview displays the equivalent Rupiah value in the line total column using the same conversion as a completed receipt

#### Scenario: Packed breakdown per-unit price remains correctly scaled

- **WHEN** a packed line stores breakdown prices (`box_price_applied` / `loose_price_applied`) and its line total in minor units
- **THEN** the packed unit-breakdown prices and line total column are each converted to Rupiah exactly once
- **AND** the line total remains consistent with the receipt grand total
