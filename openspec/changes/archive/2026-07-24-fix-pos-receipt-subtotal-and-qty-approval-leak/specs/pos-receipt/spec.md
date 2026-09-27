## ADDED Requirements

### Requirement: Receipt line total column reflects Rupiah line total

The POS receipt SHALL display each line item's total (subtotal) column as the line's Rupiah total value. The receipt MUST NOT divide the persisted line total by 100 when the snapshot already stores it in Rupiah. This applies uniformly to completed-checkout receipts, draft receipts, and loaded-transaction receipts.

#### Scenario: Single-unit line prints full Rupiah total

- **WHEN** a completed transaction line has quantity 1 and unit price Rp335.000 and its snapshot stores the line total as `335000` Rupiah
- **THEN** the receipt line's total column displays Rp335.000
- **AND** the receipt MUST NOT display Rp3.350 for that line

#### Scenario: Line total matches per-unit breakdown and grand total

- **WHEN** a receipt renders a line whose per-unit breakdown reads `1 PCS(S) @ Rp335.000` and whose only line is that item
- **THEN** the line total column equals the receipt grand total (Rp335.000)

#### Scenario: Draft and loaded-transaction receipts use the same Rupiah line total

- **WHEN** a draft or loaded transaction line snapshot stores the line total in Rupiah
- **THEN** its receipt preview displays that Rupiah value in the line total column using the same conversion as a completed receipt

#### Scenario: Packed breakdown per-unit price remains correctly scaled

- **WHEN** a packed line stores breakdown prices (`box_price_applied` / `loose_price_applied`) in minor units
- **THEN** the packed unit-breakdown per-unit prices continue to be converted from minor units to Rupiah
- **AND** the line total column still displays the Rupiah line total without an additional division by 100
